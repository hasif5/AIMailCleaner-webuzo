<?php
/**
 * AI Mail Cleaner — SQLite store (root-only file, WAL mode).
 * Shared by the panel (PHP 7.4 FPM) and the workers (CLI). Keep 7.4 compatible.
 */

function mc_db(){
	static $pdo = null;
	if($pdo !== null) return $pdo;
	$new = !is_file(MC_DB);
	$pdo = new PDO('sqlite:'.MC_DB, null, null, array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_TIMEOUT => 30,
	));
	$pdo->exec('PRAGMA journal_mode=WAL');
	$pdo->exec('PRAGMA synchronous=NORMAL');
	$pdo->exec('PRAGMA busy_timeout=30000');
	$pdo->exec('PRAGMA foreign_keys=ON');
	mc_db_schema($pdo);
	if($new) @chmod(MC_DB, 0600);
	return $pdo;
}

function mc_db_schema(PDO $db){
	$db->exec("
CREATE TABLE IF NOT EXISTS runs (
	id        TEXT PRIMARY KEY,
	box       TEXT NOT NULL,
	kind      TEXT NOT NULL,          -- scan | apply | undo | restore
	trig      TEXT NOT NULL DEFAULT 'manual',
	state     TEXT NOT NULL,          -- working | review | done | failed
	parent    TEXT,                   -- apply/undo: the scan run it acts on
	started   INTEGER NOT NULL,
	finished  INTEGER,
	totals    TEXT,
	error     TEXT
);
CREATE INDEX IF NOT EXISTS runs_box ON runs(box, started);

CREATE TABLE IF NOT EXISTS msgs (
	id          INTEGER PRIMARY KEY AUTOINCREMENT,
	run         TEXT NOT NULL,
	box         TEXT NOT NULL,
	folder      TEXT NOT NULL,
	uidvalidity INTEGER NOT NULL,
	uid         INTEGER NOT NULL,
	guid        TEXT NOT NULL,
	msgid       TEXT,
	mdate       TEXT,
	sender      TEXT,
	subject     TEXT,
	size        INTEGER,
	sa          REAL,
	sa_src      TEXT,                 -- header | spamc | error
	v1_prov TEXT, v1_model TEXT, v1_verdict TEXT, v1_conf REAL, v1_reason TEXT,
	v2_prov TEXT, v2_model TEXT, v2_verdict TEXT, v2_conf REAL, v2_reason TEXT,
	decision    TEXT,                 -- move | review | keep
	why         TEXT,                 -- short machine reason: sa_high, ai_spam, allow, ...
	pick        INTEGER NOT NULL DEFAULT 0,   -- 1 = will be moved when the run is applied
	action      TEXT,                 -- moved | filed | restored | error | gone
	acted       INTEGER,
	err         TEXT,
	UNIQUE(box, guid)
);
CREATE INDEX IF NOT EXISTS msgs_run ON msgs(run, decision);

CREATE TABLE IF NOT EXISTS usage (
	month     TEXT NOT NULL,          -- YYYY-MM
	provider  TEXT NOT NULL,
	model     TEXT NOT NULL,
	calls     INTEGER NOT NULL DEFAULT 0,
	errors    INTEGER NOT NULL DEFAULT 0,
	in_tok    INTEGER NOT NULL DEFAULT 0,
	out_tok   INTEGER NOT NULL DEFAULT 0,
	cache_tok INTEGER NOT NULL DEFAULT 0,
	cost      REAL NOT NULL DEFAULT 0,
	PRIMARY KEY(month, provider, model)
);
");
	// Columns added after 1.0.0's first install: folder suggestions and filing.
	//   sug_kind   existing | new | leave   (AI folder suggestion; NULL = not asked)
	//   sug_folder full folder name the AI suggests (may not exist yet when sug_kind = new)
	//   dest       the owner's choice of folder to file the message into (NULL = none)
	//   filed_to   where it was filed (action = 'filed')
	$have = array();
	foreach($db->query('PRAGMA table_info(msgs)') as $c) $have[$c['name']] = true;
	$add = array('sug_kind' => 'TEXT', 'sug_folder' => 'TEXT', 'sug_conf' => 'REAL', 'sug_reason' => 'TEXT', 'dest' => 'TEXT', 'filed_to' => 'TEXT');
	foreach($add as $col => $type){
		if(!isset($have[$col])) $db->exec('ALTER TABLE msgs ADD COLUMN '.$col.' '.$type);
	}
}

function mc_db_run($id){
	$st = mc_db()->prepare('SELECT * FROM runs WHERE id = ?');
	$st->execute(array($id));
	$r = $st->fetch();
	if(!$r) return null;
	$r['totals'] = $r['totals'] ? (json_decode($r['totals'], true) ?: array()) : array();
	return $r;
}

function mc_db_runs($box, $limit = 30){
	$st = mc_db()->prepare('SELECT * FROM runs WHERE box = ? ORDER BY started DESC LIMIT '.(int)$limit);
	$st->execute(array($box));
	$out = array();
	foreach($st->fetchAll() as $r){
		$r['totals'] = $r['totals'] ? (json_decode($r['totals'], true) ?: array()) : array();
		$out[] = $r;
	}
	return $out;
}

function mc_db_run_start($id, $box, $kind, $trig, $parent = null){
	$st = mc_db()->prepare('INSERT INTO runs(id, box, kind, trig, state, parent, started) VALUES(?,?,?,?,?,?,?)');
	return $st->execute(array($id, $box, $kind, $trig, 'working', $parent, time()));
}

function mc_db_run_update($id, $fields){
	$set = array(); $vals = array();
	foreach($fields as $k => $v){
		if(!in_array($k, array('state', 'finished', 'totals', 'error'), true)) continue;
		$set[] = $k.' = ?';
		$vals[] = ($k === 'totals' && is_array($v)) ? json_encode($v) : $v;
	}
	if(!$set) return false;
	$vals[] = $id;
	return mc_db()->prepare('UPDATE runs SET '.implode(', ', $set).' WHERE id = ?')->execute($vals);
}

/**
 * Add spend to this month's usage row. Cost is an estimate from the price table.
 */
function mc_db_add_usage($provider, $model, $in, $out, $cache, $cost, $error = false){
	$m = date('Y-m');
	$db = mc_db();
	$db->prepare('INSERT OR IGNORE INTO usage(month, provider, model) VALUES(?,?,?)')->execute(array($m, $provider, $model));
	$db->prepare('UPDATE usage SET calls = calls + 1, errors = errors + ?, in_tok = in_tok + ?, out_tok = out_tok + ?,'
		.' cache_tok = cache_tok + ?, cost = cost + ? WHERE month = ? AND provider = ? AND model = ?')
		->execute(array($error ? 1 : 0, (int)$in, (int)$out, (int)$cache, (float)$cost, $m, $provider, $model));
}

function mc_db_month_cost($provider){
	$st = mc_db()->prepare('SELECT COALESCE(SUM(cost), 0) AS c FROM usage WHERE month = ? AND provider = ?');
	$st->execute(array(date('Y-m'), $provider));
	$r = $st->fetch();
	return (float)($r['c'] ?? 0);
}

function mc_db_usage($months = 3){
	$st = mc_db()->query('SELECT * FROM usage ORDER BY month DESC, provider, model');
	$out = array();
	foreach($st->fetchAll() as $r){
		$out[$r['month']][] = $r;
		if(count($out) > $months) { array_pop($out); break; }
	}
	return $out;
}
