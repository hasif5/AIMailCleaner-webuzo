<?php
/**
 * AI Mail Cleaner — shared helpers.
 * Used by bin/runner.php + bin/worker.php (root, CLI) and web/enduser + web/admin index.php (root FPM).
 * Must stay PHP 7.4 compatible: the panel runs on the EMPS PHP 7.4 build.
 *
 * Nothing here ever hands a user-supplied string to a shell: every external
 * command is run with proc_open() and an argv array (see mc_exec()).
 */

define('MC_BASE',   '/usr/local/mailcleaner');
/*
 * State root lives in /var/lib, NOT under /var/webuzo: Webuzo's daily cron resets
 * ownership of everything under /var/webuzo to webuzo:webuzo 0770, which would
 * expose the API keys. Same lesson GitDeploy learned — do not move it.
 * Everything in here is root-only: the panel pages run on the root FPM pool and
 * there is no non-root endpoint (no webhook) that would need to read any of it.
 */
// MC_TEST_STATE / MC_TEST_MAILDIR: test-harness overrides, honoured on the command
// line only (a web request cannot set the environment of the root FPM pool).
define('MC_TEST', PHP_SAPI === 'cli' && getenv('MC_TEST_STATE'));
define('MC_STATE',    MC_TEST ? rtrim(getenv('MC_TEST_STATE'), '/') : '/var/lib/mailcleaner');
define('MC_BOXES',    MC_STATE.'/boxes');      // per-mailbox settings  <email>.json
define('MC_STATUS',   MC_STATE.'/status');     // per-mailbox live status <email>.json
define('MC_SPOOL',    MC_STATE.'/spool');      // queued jobs <email>.json
define('MC_LOCKS',    MC_STATE.'/locks');
define('MC_LOGS',     MC_STATE.'/logs');       // logs/<email>/<run>.log
define('MC_DB',       MC_STATE.'/mailcleaner.sqlite');
define('MC_SETTINGS', MC_STATE.'/settings.json');   // server-wide AI settings + API keys
define('MC_PAUSE_FILE', MC_STATE.'/.paused');
define('MC_USERS',    '/var/webuzo/users');
define('MC_DOVEADM',  '/usr/bin/doveadm');

/* ----------------------------------------------------------- validation */

function mc_valid_user($u){
	return is_string($u) && preg_match('~^[a-z_][a-z0-9_-]{0,31}$~', $u);
}

function mc_valid_domain($d){
	return is_string($d) && preg_match('~^[a-z0-9]([a-z0-9-]{0,62}\.)+[a-z0-9-]{2,63}$~', $d)
		&& strpos($d, '..') === false && strlen($d) <= 253;
}

/**
 * Mailbox ids are lowercase email addresses. Kept strict on purpose: the id is
 * used as a file name and passed to doveadm -u.  No slash, no "..", no leading dot.
 */
function mc_valid_email($e){
	if(!is_string($e) || strlen($e) > 254) return false;
	if(!preg_match('~^([a-z0-9][a-z0-9._+-]{0,63})@([a-z0-9.-]+)$~', $e, $m)) return false;
	if(strpos($m[1], '..') !== false) return false;
	return mc_valid_domain($m[2]);
}

function mc_email_domain($e){ $p = strrpos($e, '@'); return $p === false ? '' : substr($e, $p + 1); }

/**
 * IMAP folder names as doveadm lists them (e.g. "INBOX", "INBOX.Junk").
 * Printable, no control chars, no wildcard characters doveadm would expand.
 */
function mc_valid_folder($f){
	return is_string($f) && $f !== '' && strlen($f) <= 200
		&& !preg_match('~[\x00-\x1f\x7f*%"\\\\]~', $f) && $f[0] !== '.' && $f[0] !== '/';
}

function mc_valid_run($r){ return is_string($r) && preg_match('~^[0-9]{8}-[0-9]{6}-[a-z]{2,8}-[0-9a-f]{4}$~', $r); }

/* ----------------------------------------------------------- json files */

function mc_load_json($file){
	if(!is_file($file)) return null;
	$d = json_decode((string)@file_get_contents($file), true);
	return is_array($d) ? $d : null;
}

// Atomic write (tmp in the same dir + rename). A short write never replaces good state.
function mc_save_json($file, $data, $mode = 0600){
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if($json === false) return false;
	$tmp = $file.'.tmp.'.getmypid();
	$n = @file_put_contents($tmp, $json);
	if($n === false || $n !== strlen($json)){ @unlink($tmp); return false; }
	@chmod($tmp, $mode);
	return @rename($tmp, $file);
}

/* ----------------------------------------------------------- external commands */

/**
 * Run a command (argv array — never a shell string). Returns exit code; fills
 * $out / $err. $stdin may be a string fed to the process. Hard timeout in seconds.
 */
function mc_exec(array $argv, &$out, &$err, $stdin = null, $timeout = 120){
	$out = ''; $err = '';
	if(MC_TEST && $argv[0] === MC_DOVEADM && getenv('MC_TEST_MAILDIR') && ($argv[1] ?? '') !== 'user'){
		// harness: point doveadm at a throwaway maildir, quota plugin off
		array_splice($argv, 1, 0, array('-o', 'mail_location=maildir:'.getenv('MC_TEST_MAILDIR'), '-o', 'mail_plugins='.getenv('MC_TEST_PLUGINS')));
	}
	$desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$env  = array('PATH' => '/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin', 'LANG' => 'C.UTF-8', 'HOME' => '/root');
	$pipes = array();
	$p = @proc_open($argv, $desc, $pipes, '/tmp', $env);
	if(!is_resource($p)){ $err = 'cannot start '.$argv[0]; return 127; }
	if($stdin !== null && $stdin !== ''){
		// Write in chunks; spamc and friends read while we write.
		stream_set_blocking($pipes[0], true);
		$len = strlen($stdin);
		for($i = 0; $i < $len; $i += 65536){
			if(@fwrite($pipes[0], substr($stdin, $i, 65536)) === false) break;
		}
	}
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$deadline = microtime(true) + $timeout;
	while(true){
		$r = array($pipes[1], $pipes[2]); $w = null; $x = null;
		$left = $deadline - microtime(true);
		if($left <= 0){
			proc_terminate($p, 9);
			$err .= "\n[timed out after ".$timeout."s]";
			fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
			return 124;
		}
		$n = @stream_select($r, $w, $x, (int)min(1, $left), 200000);
		if($n === false) break;
		foreach($r as $s){
			$chunk = fread($s, 262144);
			if($s === $pipes[1]) $out .= (string)$chunk; else $err .= (string)$chunk;
		}
		if(feof($pipes[1]) && feof($pipes[2])) break;
	}
	fclose($pipes[1]); fclose($pipes[2]);
	$code = proc_close($p);
	return (int)$code;
}

/* ----------------------------------------------------------- settings */

/**
 * Models offered in the admin page drop-downs. A custom model id can also be
 * typed in. Prices are USD per million tokens [input, output], used only to
 * ESTIMATE spend for the monthly caps and the usage table; the admin can edit them.
 */
function mc_known_models(){
	return array(
		'anthropic' => array(
			'claude-haiku-5-5'  => array('Claude Haiku 5.5 — fastest, cheapest', 0.10, 0.50),
			'claude-sonnet-5-5' => array('Claude Sonnet 5.5 — stronger, ~20x Haiku price', 2.00, 10.00),
			'claude-opus-5-5'   => array('Claude Opus 5.5 — strongest, ~40x Haiku price', 4.00, 20.00),
		),
		'gemini' => array(
			'gemini-3.8-flash'      => array('Gemini 3.8 Flash', 0.75, 3.75),
			'gemini-4-argon'        => array('Gemini 4 Argon — strongest, paid tier only', 2.00, 10.00),
			'gemini-3.6-flash'      => array('Gemini 3.6 Flash', 0.0, 0.0),
			'gemini-3.5-flash-lite' => array('Gemini 3.5 Flash-Lite', 0.0, 0.0),
		),
	);
}

function mc_valid_model_id($m){ return is_string($m) && preg_match('~^[a-z0-9][a-z0-9._-]{1,79}$~', $m); }

function mc_default_settings(){
	return array(
		'anthropic_key'    => '',
		'anthropic_model'  => 'claude-haiku-5-5',
		'anthropic_effort' => 'high',          // low | medium | high
		'gemini_key'       => '',
		'gemini_model'     => 'gemini-3.8-flash',
		'gemini_thinking'  => 'medium',        // low | medium | high
		'primary'          => 'gemini',        // gemini (default, key required) | anthropic — the main AI
		'second'           => 'anthropic',     // the other provider | none — second opinion and fallback
		'second_when'      => 'unsure',        // unsure | always
		'unsure_below'     => 0.80,            // primary confidence below this => ask second
		'cap_anthropic'    => 150.0,           // USD per calendar month, 0 = no cap
		'cap_gemini'       => 50.0,
		'prices'           => array(),         // model => [in, out] overrides
		'preview_chars'    => 1500,            // body characters sent to the AI
		'concurrency'      => 4,               // AI requests in flight per mailbox job
		'max_per_run'      => 5000,            // messages looked at per scan
		'test'             => array(),         // last "Test" result per provider
	);
}

function mc_settings(){
	$s = mc_load_json(MC_SETTINGS);
	if(!is_array($s)) $s = array();
	$s = array_merge(mc_default_settings(), $s);
	// Gemini is the default main AI; the owner may switch the main AI to Claude.
	// Claude can only be main while its key is saved, otherwise Gemini takes over.
	// The second opinion is always the other provider (or none).
	if($s['primary'] !== 'anthropic' || (string)$s['anthropic_key'] === '') $s['primary'] = 'gemini';
	if($s['second'] !== 'none') $s['second'] = mc_ai_other($s['primary']);
	return $s;
}

function mc_save_settings($s){ return mc_save_json(MC_SETTINGS, $s, 0600); }

function mc_ai_other($p){ return $p === 'anthropic' ? 'gemini' : 'anthropic'; }
function mc_ai_name($p){ return $p === 'anthropic' ? 'Claude' : 'Gemini'; }

// "sk-ant-…a1b2" style display; the full key is never sent to a browser.
function mc_mask_key($k){
	$k = (string)$k;
	if($k === '') return '';
	return (strlen($k) > 10 ? substr($k, 0, 6) : '').'…'.substr($k, -4);
}

function mc_price($s, $model){
	if(isset($s['prices'][$model]) && is_array($s['prices'][$model]) && count($s['prices'][$model]) === 2){
		return array((float)$s['prices'][$model][0], (float)$s['prices'][$model][1]);
	}
	foreach(mc_known_models() as $prov => $list){
		if(isset($list[$model])) return array((float)$list[$model][1], (float)$list[$model][2]);
	}
	return array(0.0, 0.0);
}

/* ----------------------------------------------------------- mailboxes */

function mc_box_defaults($email, $user){
	return array(
		'email'         => $email,
		'user'          => $user,
		'folders'       => array('INBOX'),
		'review_folder' => '',               // filled from the mailbox's own separator on first save
		'sa_high'       => 8.0,              // SpamAssassin score >= this: spam without asking the AI
		'sa_low'        => 5.0,              // score below this: clean (unless ai_scope = all)
		'ai_scope'      => 'unsure',         // unsure | all
		'ai_min_conf'   => 0.80,             // AI must be at least this sure to move on its own
		'allow'         => array(),          // never-move senders: "user@x.com" or "@x.com"
		'auto'          => 'off',            // off | label | move
		'auto_every'    => 30,               // minutes
		'notify_email'  => '',
		'created'       => time(),
		'updated'       => time(),
	);
}

function mc_box_file($email){ return MC_BOXES.'/'.$email.'.json'; }

function mc_load_box($email){
	if(!mc_valid_email($email)) return null;
	$b = mc_load_json(mc_box_file($email));
	if(!is_array($b) || ($b['email'] ?? '') !== $email || !mc_valid_user($b['user'] ?? '')) return null;
	return array_merge(mc_box_defaults($email, $b['user']), $b);
}

function mc_save_box($box){
	if(!mc_valid_email($box['email'] ?? '')) return false;
	$box['updated'] = time();
	return mc_save_json(mc_box_file($box['email']), $box, 0600);
}

function mc_all_boxes(){
	$out = array();
	foreach(glob(MC_BOXES.'/*.json') ?: array() as $f){
		$e = basename($f, '.json');
		$b = mc_load_box($e);
		if($b) $out[$e] = $b;
	}
	ksort($out);
	return $out;
}

/**
 * Every mail account on the server whose domain belongs to $user and whose mail
 * home sits inside that user's own home directory. Ownership is decided here and
 * only here: from Webuzo's account record + Dovecot's own user database, never
 * from anything the browser sends.
 *
 * Returns array(email => home).
 */
function mc_user_mailboxes($user, $domains = null){
	if(!mc_valid_user($user)) return array();
	$pw = posix_getpwnam($user);
	if(empty($pw) || (int)$pw['uid'] < 1000) return array();
	$home = rtrim($pw['dir'], '/').'/';

	if($domains === null){
		$info = mc_load_json(MC_USERS.'/'.$user.'/info');
		$domains = (is_array($info) && isset($info['domains']) && is_array($info['domains'])) ? array_keys($info['domains']) : array();
	}
	$mine = array();
	foreach($domains as $d){
		$d = strtolower((string)$d);
		if(mc_valid_domain($d)) $mine[$d] = true;
	}
	if(!$mine) return array();

	$out = array();
	foreach(mc_dovecot_users() as $email => $mhome){
		if(!isset($mine[mc_email_domain($email)])) continue;
		if($mhome === '' || strpos($mhome.'/', $home) !== 0) continue;
		$out[$email] = $mhome;
	}
	ksort($out);
	return $out;
}

/**
 * All Dovecot users with their mail homes (cached 60s; the full listing is one
 * doveadm call per user, so it is worth not repeating on every page view).
 */
function mc_dovecot_users(){
	static $mem = null;
	if($mem !== null) return $mem;
	$cache = MC_STATE.'/.users.cache.json';
	$c = mc_load_json($cache);
	if(is_array($c) && isset($c['ts'], $c['users']) && time() - (int)$c['ts'] < 60 && is_array($c['users'])){
		return $mem = $c['users'];
	}
	$users = array();
	$rc = mc_exec(array(MC_DOVEADM, 'user', '*'), $out, $err, null, 60);
	if($rc === 0){
		foreach(preg_split('~\r?\n~', trim($out)) as $line){
			$e = strtolower(trim($line));
			if(!mc_valid_email($e)) continue;
			$users[$e] = '';
		}
	}
	foreach(array_keys($users) as $e){
		if(mc_exec(array(MC_DOVEADM, 'user', '-f', 'home', $e), $o, $x, null, 20) === 0){
			$users[$e] = rtrim(trim($o), '/');
		}
	}
	if($users && posix_getuid() === 0) mc_save_json($cache, array('ts' => time(), 'users' => $users), 0600);
	return $mem = $users;
}

/* ----------------------------------------------------------- jobs + status */

/**
 * One job file per mailbox: a second "scan" while one is waiting just replaces
 * it. Job kinds: scan | apply | undo | restore | test.
 */
function mc_queue_job($email, $kind, $extra = array()){
	if(!mc_valid_email($email) && $email !== '_test') return false;
	$job = array_merge(array('box' => $email, 'kind' => $kind, 'ts' => time()), $extra);
	return mc_save_json(MC_SPOOL.'/'.$email.'.json', $job, 0600);
}

function mc_queued($email){ return is_file(MC_SPOOL.'/'.$email.'.json'); }

// "Stop" for a running check / recheck / sort: the worker looks for this flag
// between batches, keeps everything finished so far, and ends normally.
function mc_stop_file($email){ return MC_LOCKS.'/'.$email.'.stop'; }
function mc_request_stop($email){ return mc_valid_email($email) && @touch(mc_stop_file($email)); }
function mc_stop_requested($email){ clearstatcache(true, mc_stop_file($email)); return is_file(mc_stop_file($email)); }
function mc_clear_stop($email){ @unlink(mc_stop_file($email)); }

function mc_status($email){
	$s = mc_load_json(MC_STATUS.'/'.$email.'.json');
	return is_array($s) ? $s : array();
}

function mc_write_status($email, $patch){
	$cur = mc_status($email);
	return mc_save_json(MC_STATUS.'/'.$email.'.json', array_merge($cur, $patch), 0600);
}

// A worker that died (reboot, OOM) leaves state=working; treat it as stalled after this long.
define('MC_STALE_SECS', 6 * 3600);

function mc_is_busy($email, $st = null){
	if($st === null) $st = mc_status($email);
	if(mc_queued($email)) return true;
	if(($st['state'] ?? '') !== 'working') return false;
	return (time() - (int)($st['heartbeat'] ?? $st['started'] ?? 0)) < 900;
}

function mc_new_run_id($kind){
	return date('Ymd-His').'-'.$kind.'-'.bin2hex(random_bytes(2));
}

function mc_log_file($email, $run){ return MC_LOGS.'/'.$email.'/'.$run.'.log'; }

/* ----------------------------------------------------------- pause */

function mc_is_paused(){ return is_file(MC_PAUSE_FILE); }

function mc_set_paused($on, $by = ''){
	if($on) return mc_save_json(MC_PAUSE_FILE, array('ts' => time(), 'by' => mc_valid_user($by) ? $by : ''), 0600);
	return !is_file(MC_PAUSE_FILE) || @unlink(MC_PAUSE_FILE);
}

/* ----------------------------------------------------------- permissions */

/**
 * Re-assert the state tree's permissions every runner tick (cheap; no recursion
 * beyond one level). Everything is root-only. Returns what had to be corrected.
 */
function mc_enforce_state_perms(){
	$fixed = array();
	if(posix_getuid() !== 0) return $fixed;
	$want = array(MC_STATE => 0700, MC_BOXES => 0700, MC_STATUS => 0700, MC_SPOOL => 0700,
		MC_LOCKS => 0700, MC_LOGS => 0700);
	foreach($want as $d => $mode){
		clearstatcache(true, $d);
		$st = @lstat($d);
		if($st === false || ($st['mode'] & 0170000) === 0120000) continue;
		if(($st['mode'] & 07777) !== $mode && @chmod($d, $mode)) $fixed[] = sprintf('%s mode -> %04o', $d, $mode);
		if(($st['uid'] !== 0 || $st['gid'] !== 0) && @chown($d, 0) && @chgrp($d, 0)) $fixed[] = $d.' owner -> root';
	}
	foreach(array_merge(glob(MC_STATE.'/*') ?: array(), glob(MC_STATE.'/.[!.]*') ?: array(),
			glob(MC_BOXES.'/*') ?: array(), glob(MC_STATUS.'/*') ?: array()) as $f){
		if(is_link($f) || !is_file($f)) continue;
		$st = @stat($f);
		if($st && ($st['mode'] & 07777) !== 0600 && @chmod($f, 0600)) $fixed[] = $f.' mode -> 0600';
	}
	return $fixed;
}

/* ----------------------------------------------------------- misc */

// Collapse to one printable line, at most $max characters (UTF-8 aware).
function mc_one_line($s, $max){
	$s = preg_replace('~[\x00-\x1f\x7f]+~', ' ', (string)$s);
	$s = trim(preg_replace('~\s{2,}~', ' ', $s));
	if(function_exists('mb_substr')) return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8').'…' : $s;
	return strlen($s) > $max ? substr($s, 0, $max).'…' : $s;
}


function mc_version(){
	$v = @file_get_contents(MC_BASE.'/VERSION');
	return $v ? trim($v) : 'dev';
}

// Lower-cased bare address from a From/Reply-To header value.
function mc_addr($hdr){
	$hdr = (string)$hdr;
	if(preg_match('~<([^<>@\s]+@[^<>\s]+)>~', $hdr, $m)) return strtolower($m[1]);
	if(preg_match('~([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})~', $hdr, $m)) return strtolower($m[1]);
	return '';
}

/**
 * Allow-list entries: "someone@example.com" or "@example.com" (whole domain,
 * subdomains included). Returns a clean, de-duplicated list; drops junk.
 */
function mc_clean_allow($lines){
	$out = array();
	foreach($lines as $l){
		$l = strtolower(trim((string)$l));
		if($l === '') continue;
		if($l[0] === '@'){
			if(mc_valid_domain(substr($l, 1))) $out[$l] = true;
		}elseif(preg_match('~^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$~', $l)){
			$out[$l] = true;
		}
	}
	return array_slice(array_keys($out), 0, 500);
}

function mc_allowed($from_addr, $allow){
	$a = strtolower((string)$from_addr);
	if($a === '') return false;
	$dom = mc_email_domain($a);
	foreach((array)$allow as $e){
		if($e === $a) return true;
		if($e[0] === '@'){
			$d = substr($e, 1);
			if($dom === $d || substr($dom, -strlen($d) - 1) === '.'.$d) return true;
		}
	}
	return false;
}
