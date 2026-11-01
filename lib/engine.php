<?php
/**
 * AI Mail Cleaner — the pipeline. Runs inside bin/worker.php (root CLI), one
 * mailbox at a time under that mailbox's lock.
 *
 *   scan    : read new messages -> SpamAssassin -> AI (primary, maybe second) -> decision
 *   apply   : move the picked messages of a scan into the review folder
 *   undo    : move every message an applied scan moved back where it came from
 *   restore : move one message back ("not spam")
 *
 * Nothing here deletes mail. Moves go through doveadm and are verified.
 */

define('MC_CHUNK', 40);   // messages prepared + classified per batch (progress granularity)

class McLog {
	private $fh = null;
	public function __construct($file){
		$dir = dirname($file);
		if(!is_dir($dir)) @mkdir($dir, 0700, true);
		$this->fh = @fopen($file, 'a');
		if($this->fh) @chmod($file, 0600);
	}
	public function __invoke($line){
		$l = date('H:i:s').'  '.$line."\n";
		if($this->fh) fwrite($this->fh, $l);
		if(PHP_SAPI === 'cli') fwrite(STDOUT, $l);
	}
}

/* ------------------------------------------------------------- SpamAssassin */

// Score via spamc (spamd must be running). Returns float or null.
function mc_spamc_score($raw){
	if(!is_executable('/bin/spamc') && !is_executable('/usr/bin/spamc')) return null;
	$bin = is_executable('/usr/bin/spamc') ? '/usr/bin/spamc' : '/bin/spamc';
	$rc = mc_exec(array($bin, '-c', '-x', '-s', '1000000'), $out, $err, $raw, 60);
	// -c: prints "score/threshold"; exit 1 = spam, 0 = ham, anything else = error
	if(($rc === 0 || $rc === 1) && preg_match('~^\s*([-+]?[0-9]+(?:\.[0-9]+)?)/~', $out, $m)) return (float)$m[1];
	return null;
}

/* ------------------------------------------------------------- decisions */

function mc_spamish($v){ return $v === 'spam' || $v === 'phishing'; }

/**
 * Combine the AI answers into move | review | keep (+ a short reason code).
 * Rules, in order:
 *   no usable answer           -> review (ai_unavailable): never moved on its own
 *   one answer:  spam/phishing at >= the mailbox's confidence bar -> move
 *                spam/phishing below the bar, or suspicious        -> review
 *                not_spam                                         -> keep
 *   two answers: both spam/phishing -> move; both not_spam -> keep; otherwise review
 */
function mc_decide_ai($box, $v1, $v2){
	$ok = array();
	if($v1 && !empty($v1['ok'])) $ok[] = $v1;
	if($v2 && !empty($v2['ok'])) $ok[] = $v2;
	if(!$ok) return array('review', 'ai_unavailable');
	if(count($ok) === 1){
		$r = $ok[0];
		if(mc_spamish($r['verdict']) && $r['confidence'] >= (float)$box['ai_min_conf']) return array('move', 'ai_'.$r['verdict']);
		if(mc_spamish($r['verdict']) || $r['verdict'] === 'suspicious') return array('review', 'ai_'.$r['verdict'].'_unsure');
		return array('keep', 'ai_clean');
	}
	$a = mc_spamish($ok[0]['verdict']); $b = mc_spamish($ok[1]['verdict']);
	if($a && $b) return array('move', 'ai_both_'.($ok[0]['verdict'] === 'phishing' || $ok[1]['verdict'] === 'phishing' ? 'phishing' : 'spam'));
	if($ok[0]['verdict'] === 'not_spam' && $ok[1]['verdict'] === 'not_spam') return array('keep', 'ai_both_clean');
	return array('review', 'ai_disagree');
}

function mc_need_second($s, $primary, $v1){
	$second = $s['second'];
	if($second === 'none' || $second === $primary || !mc_ai_ready($second, $s)) return false;
	if($s['second_when'] === 'always') return true;
	if(!$v1 || empty($v1['ok'])) return true;                       // primary failed: fall back
	if($v1['verdict'] === 'phishing' || $v1['verdict'] === 'suspicious') return true;
	return $v1['confidence'] < (float)$s['unsure_below'];
}

/* ------------------------------------------------------------- scan */

function mc_scan($box, $job, $run, $log){
	$email = $box['email'];
	$s = mc_settings();
	$db = mc_db();
	$st = mc_status($email);
	$incremental = !empty($job['incremental']);
	$max = max(50, min(50000, (int)$s['max_per_run']));
	$preview = max(300, min(6000, (int)$s['preview_chars']));
	$primary = $s['primary'];
	$ai_on = ($primary !== 'none' && mc_ai_ready($primary, $s));

	$log('Mailbox '.$email.' — '.($incremental ? 'new mail since the last check' : 'full scan').', up to '.$max.' messages');
	$log('AI: '.($ai_on ? $primary.' ('.mc_ai_model($primary, $s).')' : 'off (SpamAssassin only)')
		.($ai_on && $s['second'] !== 'none' && mc_ai_ready($s['second'], $s) ? ', second opinion '.$s['second'].' ('.mc_ai_model($s['second'], $s).', '.$s['second_when'].')' : ''));

	$all = mc_dove_folders($email);
	if(!$all) throw new RuntimeException('could not list folders (is the mailbox still there?)');
	$review = $box['review_folder'] !== '' ? $box['review_folder'] : mc_dove_prefix($all).'AI-Spam-Review';
	$folders = array();
	foreach($box['folders'] as $f){
		if($f === $review) continue;
		if(in_array($f, $all, true)) $folders[] = $f; else $log('  skipping folder '.$f.' (no longer exists)');
	}
	if(!$folders) throw new RuntimeException('none of the selected folders exist');

	// GUIDs already looked at in this mailbox (any earlier run) are never re-classified.
	$known = array();
	$q = $db->prepare('SELECT guid FROM msgs WHERE box = ?');
	$q->execute(array($email));
	while($r = $q->fetch()) $known[$r['guid']] = true;

	$cands = array();
	$fstate = (isset($st['folders']) && is_array($st['folders'])) ? $st['folders'] : array();
	foreach($folders as $f){
		$fs = mc_dove_status($email, $f);
		if(!$fs){ $log('  '.$f.': cannot read status, skipped'); continue; }
		$from = 1;
		if($incremental){
			if(isset($fstate[$f]) && (int)$fstate[$f]['uidvalidity'] === $fs['uidvalidity']){
				$from = (int)$fstate[$f]['last_uid'] + 1;
			}else{
				// First automatic check of this folder: never sweep old mail automatically.
				// Existing messages are left for a scan the owner starts (and reviews) by hand.
				$from = $fs['uidnext'];
				$log('  '.$f.': first automatic check — only mail arriving from now on is checked automatically');
			}
		}
		$rows = ($from >= $fs['uidnext']) ? array() : mc_dove_headers($email, $f, $from);
		if($rows === null){ $log('  '.$f.': cannot read headers, skipped'); continue; }
		$new = 0;
		foreach($rows as $m){
			if(isset($known[$m['guid']])) continue;
			$m['folder'] = $f; $m['uidvalidity'] = $fs['uidvalidity'];
			$cands[] = $m; $new++;
		}
		$fstate[$f] = array('uidvalidity' => $fs['uidvalidity'], 'last_uid' => max(0, $fs['uidnext'] - 1));
		$log('  '.$f.': '.$fs['messages'].' messages, '.$new.' not checked before');
	}
	// newest first, so a capped run looks at what matters most
	usort($cands, function($a, $b){ return $b['uid'] <=> $a['uid']; });
	$skipped_cap = max(0, count($cands) - $max);
	if($skipped_cap) $log('  '.$skipped_cap.' older messages left for the next scan (limit '.$max.' per run)');
	$cands = array_slice($cands, 0, $max);
	$total = count($cands);

	$tot = array('looked' => 0, 'move' => 0, 'review' => 0, 'keep' => 0, 'sa_spamc' => 0, 'sa_header' => 0, 'sa_error' => 0,
		'ai1' => 0, 'ai2' => 0, 'ai_err' => 0, 'allow' => 0, 'left_for_next' => $skipped_cap, 'folders' => $folders, 'review_folder' => $review);
	mc_write_status($email, array('progress' => array('done' => 0, 'total' => $total, 'phase' => 'classifying'), 'heartbeat' => time()));

	$ins = $db->prepare('INSERT OR IGNORE INTO msgs(run, box, folder, uidvalidity, uid, guid, msgid, mdate, sender, subject, size, sa, sa_src,'
		.' v1_prov, v1_model, v1_verdict, v1_conf, v1_reason, v2_prov, v2_model, v2_verdict, v2_conf, v2_reason, decision, why, pick)'
		.' VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

	for($off = 0; $off < $total; $off += MC_CHUNK){
		$chunk = array_slice($cands, $off, MC_CHUNK);
		$prep = array();     // idx => row with decision fields
		$ask = array();      // idx => user_text for the AI

		foreach($chunk as $i => $m){
			$m['sa_src'] = $m['sa'] !== null ? 'header' : null;
			$m['v1'] = null; $m['v2'] = null;
			$from_addr = mc_addr($m['sender']);
			if(mc_allowed($from_addr, $box['allow'])){
				$m['decision'] = 'keep'; $m['why'] = 'allow_list';
				$tot['allow']++;
				$prep[$i] = $m; continue;
			}
			$raw = null;
			if($m['sa'] === null){
				$raw = mc_dove_raw($email, $m['folder'], $m['uid']);
				$sc = $raw !== null ? mc_spamc_score($raw) : null;
				if($sc === null){ $m['sa_src'] = 'error'; $tot['sa_error']++; }
				else { $m['sa'] = $sc; $m['sa_src'] = 'spamc'; $tot['sa_spamc']++; }
			}else{
				$tot['sa_header']++;
			}
			$sa = $m['sa'];
			if($sa !== null && $sa >= (float)$box['sa_high']){
				$m['decision'] = 'move'; $m['why'] = 'sa_high';
			}elseif(($sa !== null && $sa >= (float)$box['sa_low']) || $box['ai_scope'] === 'all' || $sa === null){
				if($ai_on){
					if($raw === null) $raw = mc_dove_raw($email, $m['folder'], $m['uid']);
					if($raw === null){ $m['decision'] = 'review'; $m['why'] = 'unreadable'; }
					else {
						$ask[$i] = mc_ai_user_text($email, mc_mime_digest($raw, $preview), $sa);
						$m['decision'] = null;
					}
				}else{
					// no AI: SpamAssassin's grey zone goes to the user, never moved on its own
					$m['decision'] = ($sa !== null && $sa >= (float)$box['sa_low']) ? 'review' : 'keep';
					$m['why'] = $m['decision'] === 'review' ? 'sa_unsure' : 'sa_clean';
				}
			}else{
				$m['decision'] = 'keep'; $m['why'] = 'sa_clean';
			}
			$prep[$i] = $m;
			unset($raw);
		}

		if($ask){
			$r1 = mc_ai_run_many($primary, $s, $ask, null, $log);
			$tot['ai1'] += count($r1);
			$ask2 = array();
			foreach($r1 as $i => $r){
				$prep[$i]['v1'] = $r;
				if(empty($r['ok'])){ $tot['ai_err']++; if(!empty($r['error'])) $log('  '.$primary.': '.$r['error'].' (uid '.$prep[$i]['uid'].')'); }
				if(mc_need_second($s, $primary, $r)) $ask2[$i] = $ask[$i];
			}
			if($ask2){
				$r2 = mc_ai_run_many($s['second'], $s, $ask2, null, $log);
				$tot['ai2'] += count($r2);
				foreach($r2 as $i => $r){
					$prep[$i]['v2'] = $r;
					if(empty($r['ok'])){ $tot['ai_err']++; if(!empty($r['error'])) $log('  '.$s['second'].': '.$r['error'].' (uid '.$prep[$i]['uid'].')'); }
				}
			}
			foreach($ask as $i => $_){
				list($prep[$i]['decision'], $prep[$i]['why']) = mc_decide_ai($box, $prep[$i]['v1'], $prep[$i]['v2']);
			}
		}

		$db->beginTransaction();
		foreach($prep as $m){
			$v1 = $m['v1']; $v2 = $m['v2'];
			$ins->execute(array($run, $email, $m['folder'], $m['uidvalidity'], $m['uid'], $m['guid'], $m['msgid'], $m['mdate'],
				$m['sender'], $m['subject'], $m['size'], $m['sa'], $m['sa_src'],
				$v1 ? $v1['provider'] ?? null : null, $v1 ? $v1['model'] ?? null : null,
				$v1 ? (!empty($v1['ok']) ? $v1['verdict'] : 'error') : null, $v1 ? $v1['confidence'] : null,
				$v1 ? (!empty($v1['ok']) ? $v1['reason'] : ($v1['error'] ?? '')) : null,
				$v2 ? $v2['provider'] ?? null : null, $v2 ? $v2['model'] ?? null : null,
				$v2 ? (!empty($v2['ok']) ? $v2['verdict'] : 'error') : null, $v2 ? $v2['confidence'] : null,
				$v2 ? (!empty($v2['ok']) ? $v2['reason'] : ($v2['error'] ?? '')) : null,
				$m['decision'], $m['why'], $m['decision'] === 'move' ? 1 : 0));
			$tot[$m['decision']]++;
			$tot['looked']++;
		}
		$db->commit();

		mc_write_status($email, array('progress' => array('done' => $tot['looked'], 'total' => $total, 'phase' => 'classifying',
			'move' => $tot['move'], 'review' => $tot['review']), 'heartbeat' => time()));
		$log('  '.$tot['looked'].'/'.$total.' checked — move '.$tot['move'].', review '.$tot['review'].', keep '.$tot['keep']);
	}

	mc_write_status($email, array('folders' => $fstate));
	$log('Done: '.$tot['looked'].' checked. Move '.$tot['move'].', needs your look '.$tot['review'].', keep '.$tot['keep']
		.'. SpamAssassin: '.$tot['sa_header'].' from headers, '.$tot['sa_spamc'].' scored now'.($tot['sa_error'] ? ', '.$tot['sa_error'].' failed' : '')
		.'. AI calls: '.$tot['ai1'].($tot['ai2'] ? ' + '.$tot['ai2'].' second opinions' : '').($tot['ai_err'] ? ' ('.$tot['ai_err'].' without an answer)' : '').'.');
	return $tot;
}

/* ------------------------------------------------------------- recheck */

/**
 * Ask the AI again about messages of an earlier check that have not been moved.
 *   scope 'unsure' : the "look like spam" and "need your look" lists
 *   scope 'all'    : those plus the "kept" list
 * Messages on the never-move list are left alone. Each message is read from where
 * it is now (by uid, falling back to its GUID). Decisions are updated in place, so
 * the check's lists simply change; nothing is moved here.
 */
function mc_recheck($box, $scan_run, $scope, $log){
	$email = $box['email'];
	$s = mc_settings();
	$db = mc_db();
	$run = mc_db_run($scan_run);
	if(!$run || $run['box'] !== $email || $run['kind'] !== 'scan') throw new RuntimeException('check not found');
	$primary = $s['primary'];
	if($primary === 'none' || !mc_ai_ready($primary, $s)) throw new RuntimeException('AI is not set up on this server (no Gemini key)');
	$preview = max(300, min(6000, (int)$s['preview_chars']));

	$where = $scope === 'all' ? "decision IN ('move','review','keep')" : "decision IN ('move','review')";
	$q = $db->prepare("SELECT * FROM msgs WHERE run = ? AND box = ? AND action IS NULL AND (why IS NULL OR why <> 'allow_list') AND ".$where.' ORDER BY uid DESC');
	$q->execute(array($scan_run, $email));
	$rows = $q->fetchAll();
	$total = count($rows);
	$log('Recheck with AI: '.$total.' message(s) from the check of '.date('Y-m-d H:i', (int)$run['started'])
		.' ('.($scope === 'all' ? 'spam, unsure and kept' : 'spam and unsure').')');
	$log('AI: '.$primary.' ('.mc_ai_model($primary, $s).')'
		.($s['second'] !== 'none' && mc_ai_ready($s['second'], $s) ? ', second opinion '.$s['second'].' ('.mc_ai_model($s['second'], $s).', '.$s['second_when'].')' : ''));

	$tot = array('looked' => 0, 'ai1' => 0, 'ai2' => 0, 'ai_err' => 0, 'gone' => 0, 'changed' => 0, 'move' => 0, 'review' => 0, 'keep' => 0);
	mc_write_status($email, array('progress' => array('done' => 0, 'total' => $total, 'phase' => 'rechecking'), 'heartbeat' => time()));
	$upd = $db->prepare('UPDATE msgs SET v1_prov=?, v1_model=?, v1_verdict=?, v1_conf=?, v1_reason=?, v2_prov=?, v2_model=?, v2_verdict=?, v2_conf=?, v2_reason=?,'
		.' decision=?, why=?, pick=? WHERE id = ? AND action IS NULL');

	for($off = 0; $off < $total; $off += MC_CHUNK){
		$chunk = array_slice($rows, $off, MC_CHUNK);
		$ask = array();
		foreach($chunk as $i => $m){
			$raw = mc_dove_raw($email, $m['folder'], (int)$m['uid']);
			if($raw === null){
				$where_now = mc_dove_find_guid($email, $m['guid']);
				if($where_now) $raw = mc_dove_raw($email, $where_now[0], $where_now[1]);
			}
			if($raw === null){ $tot['gone']++; continue; }
			$sa = $m['sa'] !== null ? (float)$m['sa'] : null;
			$ask[$i] = mc_ai_user_text($email, mc_mime_digest($raw, $preview), $sa);
			unset($raw);
		}
		$v1 = array(); $v2 = array();
		if($ask){
			$v1 = mc_ai_run_many($primary, $s, $ask, null, $log);
			$tot['ai1'] += count($v1);
			$ask2 = array();
			foreach($v1 as $i => $r){
				if(empty($r['ok'])){ $tot['ai_err']++; if(!empty($r['error'])) $log('  '.$primary.': '.$r['error'].' (uid '.$chunk[$i]['uid'].')'); }
				if(mc_need_second($s, $primary, $r)) $ask2[$i] = $ask[$i];
			}
			if($ask2){
				$v2 = mc_ai_run_many($s['second'], $s, $ask2, null, $log);
				$tot['ai2'] += count($v2);
				foreach($v2 as $i => $r){
					if(empty($r['ok'])){ $tot['ai_err']++; if(!empty($r['error'])) $log('  '.$s['second'].': '.$r['error'].' (uid '.$chunk[$i]['uid'].')'); }
				}
			}
		}
		$db->beginTransaction();
		foreach($ask as $i => $_){
			$m = $chunk[$i];
			$a = $v1[$i] ?? null; $b = $v2[$i] ?? null;
			if(!$a || empty($a['ok'])){
				// No usable first answer and no second one: keep the earlier decision.
				if(!$b || empty($b['ok'])){ $tot['looked']++; $tot[$m['decision']]++; continue; }
			}
			list($dec, $why) = mc_decide_ai($box, $a, $b);
			// SpamAssassin was certain: the AI may only confirm, or move it to "need your look".
			if($m['sa'] !== null && (float)$m['sa'] >= (float)$box['sa_high'] && $dec === 'keep'){ $dec = 'review'; $why = 'ai_vs_sa'; }
			$cell = function($r, $k){
				if(!$r) return null;
				if($k === 'verdict') return !empty($r['ok']) ? $r['verdict'] : 'error';
				if($k === 'reason') return !empty($r['ok']) ? $r['reason'] : ($r['error'] ?? '');
				if($k === 'conf') return $r['confidence'];
				return $r[$k] ?? null;
			};
			$upd->execute(array($cell($a, 'provider'), $cell($a, 'model'), $cell($a, 'verdict'), $cell($a, 'conf'), $cell($a, 'reason'),
				$cell($b, 'provider'), $cell($b, 'model'), $cell($b, 'verdict'), $cell($b, 'conf'), $cell($b, 'reason'),
				$dec, $why, $dec === 'move' ? 1 : 0, (int)$m['id']));
			if($dec !== $m['decision']) $tot['changed']++;
			$tot[$dec]++;
			$tot['looked']++;
		}
		$db->commit();
		mc_write_status($email, array('progress' => array('done' => min($total, $off + count($chunk)), 'total' => $total, 'phase' => 'rechecking'), 'heartbeat' => time()));
		$log('  '.min($total, $off + count($chunk)).'/'.$total.' rechecked — '.$tot['changed'].' changed so far');
	}

	// Refresh the check's own totals and reopen it if something now needs a decision.
	$t = is_array($run['totals']) ? $run['totals'] : array();
	foreach(array('move', 'review', 'keep') as $d){
		$c = $db->prepare('SELECT COUNT(*) FROM msgs WHERE run = ? AND box = ? AND decision = ?');
		$c->execute(array($scan_run, $email, $d));
		$t[$d] = (int)$c->fetchColumn();
	}
	$t['rechecked'] = time();
	unset($t['dismissed'], $t['dismissed_by']);
	$open = $db->prepare("SELECT COUNT(*) FROM msgs WHERE run = ? AND box = ? AND action IS NULL AND decision IN ('move','review')");
	$open->execute(array($scan_run, $email));
	mc_db_run_update($scan_run, array('totals' => $t, 'state' => ((int)$open->fetchColumn() > 0 ? 'review' : 'done')));

	$log('Done: '.$tot['looked'].' rechecked, '.$tot['changed'].' changed. Now: look like spam '.$tot['move'].', need your look '.$tot['review'].', keep '.$tot['keep']
		.($tot['gone'] ? '. '.$tot['gone'].' no longer in the mailbox' : '')
		.'. AI calls: '.$tot['ai1'].($tot['ai2'] ? ' + '.$tot['ai2'].' second opinions' : '').($tot['ai_err'] ? ' ('.$tot['ai_err'].' without an answer)' : '').'.');
	return $tot;
}

/* ------------------------------------------------------------- sort into folders */

/**
 * Ask the AI which of the mailbox's folders each message belongs in (or a new
 * folder, or leave it). Only messages of this check that are not set to go to the
 * spam folder and have not been moved are asked about. Nothing is moved here: the
 * suggestion is stored and, when the AI is fairly sure, pre-selected as the
 * message's destination for the owner to confirm.
 */
function mc_sort($box, $scan_run, $log){
	$email = $box['email'];
	$s = mc_settings();
	$db = mc_db();
	$run = mc_db_run($scan_run);
	if(!$run || $run['box'] !== $email || $run['kind'] !== 'scan') throw new RuntimeException('check not found');
	$primary = $s['primary'];
	if($primary === 'none' || !mc_ai_ready($primary, $s)) throw new RuntimeException('AI is not set up on this server (no Gemini key)');
	$preview = max(300, min(6000, (int)$s['preview_chars']));

	$all = mc_dove_folders($email);
	if(!$all) throw new RuntimeException('could not list folders');
	$review = (string)($run['totals']['review_folder'] ?? $box['review_folder']);
	$targets = mc_sort_targets($all, $review);
	$lower = array();
	foreach($targets as $d => $f) $lower[strtolower($d)] = $f;

	$q = $db->prepare('SELECT * FROM msgs WHERE run = ? AND box = ? AND action IS NULL AND pick = 0 ORDER BY uid DESC');
	$q->execute(array($scan_run, $email));
	$rows = $q->fetchAll();
	$total = count($rows);
	$log('Suggest folders with AI: '.$total.' message(s), '.count($targets).' folder(s) to choose from');
	$log('AI: '.$primary.' ('.mc_ai_model($primary, $s).')');

	$tot = array('looked' => 0, 'existing' => 0, 'new' => 0, 'leave' => 0, 'ai_err' => 0, 'gone' => 0, 'preset' => 0);
	mc_write_status($email, array('progress' => array('done' => 0, 'total' => $total, 'phase' => 'sorting'), 'heartbeat' => time()));
	$upd = $db->prepare('UPDATE msgs SET sug_kind = ?, sug_folder = ?, sug_conf = ?, sug_reason = ?, dest = ? WHERE id = ? AND action IS NULL');
	$names = array_keys($targets);

	for($off = 0; $off < $total; $off += MC_CHUNK){
		$chunk = array_slice($rows, $off, MC_CHUNK);
		$ask = array();
		foreach($chunk as $i => $m){
			$raw = mc_dove_raw($email, $m['folder'], (int)$m['uid']);
			if($raw === null){
				$w = mc_dove_find_guid($email, $m['guid']);
				if($w) $raw = mc_dove_raw($email, $w[0], $w[1]);
			}
			if($raw === null){ $tot['gone']++; continue; }
			$ask[$i] = mc_ai_sort_text($email, mc_mime_digest($raw, $preview), $names);
			unset($raw);
		}
		$res = $ask ? mc_ai_run_many($primary, $s, $ask, null, $log, 'sort') : array();
		$db->beginTransaction();
		foreach($res as $i => $r){
			$m = $chunk[$i];
			$tot['looked']++;
			if(empty($r['ok'])){
				$tot['ai_err']++;
				if(!empty($r['error'])) $log('  '.$primary.': '.$r['error'].' (uid '.$m['uid'].')');
				continue;
			}
			$kind = $r['action']; $full = '';
			if($kind !== 'leave'){
				$key = strtolower(trim($r['folder']));
				if(isset($lower[$key])){ $kind = 'existing'; $full = $lower[$key]; }
				else {
					$full = mc_folder_from_display($r['folder'], $all);
					if($full === '') $kind = 'leave';
					elseif(in_array($full, $all, true)) $kind = isset(array_flip($targets)[$full]) ? 'existing' : 'leave';
					else $kind = 'new';
					if($kind === 'leave') $full = '';
				}
			}
			// Pre-select fairly sure suggestions; the owner still confirms before anything moves.
			$dest = ($kind !== 'leave' && $r['confidence'] >= 0.7 && $full !== $m['folder']) ? $full : null;
			if($dest !== null) $tot['preset']++;
			$upd->execute(array($kind, $full !== '' ? $full : null, $r['confidence'], $r['reason'], $dest, (int)$m['id']));
			$tot[$kind]++;
		}
		$db->commit();
		$done = min($total, $off + count($chunk));
		mc_write_status($email, array('progress' => array('done' => $done, 'total' => $total, 'phase' => 'sorting'), 'heartbeat' => time()));
		$log('  '.$done.'/'.$total.' done');
	}
	$t = is_array($run['totals']) ? $run['totals'] : array();
	$t['sorted'] = time();
	unset($t['dismissed'], $t['dismissed_by']);
	mc_db_run_update($scan_run, array('totals' => $t, 'state' => 'review'));
	$log('Done: '.$tot['existing'].' fit an existing folder, '.$tot['new'].' would get a new folder, '.$tot['leave'].' best left where they are'
		.($tot['gone'] ? ', '.$tot['gone'].' no longer in the mailbox' : '').($tot['ai_err'] ? ', '.$tot['ai_err'].' without an answer' : '')
		.'. '.$tot['preset'].' pre-selected for you to confirm. Nothing was moved.');
	return $tot;
}

/**
 * File messages into the folders the owner chose (dest), creating new folders as
 * needed. Messages set to go to the spam folder (pick = 1) are not touched here.
 */
function mc_file($box, $scan_run, $log){
	$email = $box['email'];
	$db = mc_db();
	$run = mc_db_run($scan_run);
	if(!$run || $run['box'] !== $email || $run['kind'] !== 'scan') throw new RuntimeException('check not found');
	$q = $db->prepare("SELECT id, folder, uidvalidity, uid, guid, dest FROM msgs WHERE run = ? AND box = ? AND action IS NULL AND pick = 0 AND dest IS NOT NULL AND dest <> '' ORDER BY dest, folder, uid");
	$q->execute(array($scan_run, $email));
	$rows = $q->fetchAll();
	if(!$rows) return array('filed' => 0, 'failed' => 0, 'gone' => 0, 'created' => array());

	$all = mc_dove_folders($email);
	$review = (string)($run['totals']['review_folder'] ?? $box['review_folder']);
	$targets = array_flip(mc_sort_targets($all, $review));
	$prefix = mc_dove_prefix($all);
	$mark = $db->prepare('UPDATE msgs SET action = ?, acted = ?, err = ?, filed_to = ? WHERE id = ?');
	$filed = 0; $failed = 0; $gone = 0; $created = array();
	$log('Filing '.count($rows).' message(s) into folders');

	$by = array();
	foreach($rows as $r) $by[$r['dest']][$r['folder']][] = $r;
	$done = 0; $total = count($rows);
	foreach($by as $dest => $sources){
		$is_new = !in_array($dest, $all, true);
		// Only the owner's own ordinary folders, or a new one under the personal prefix.
		$ok_dest = mc_valid_folder($dest) && ($is_new ? ($prefix === '' || strpos($dest, $prefix) === 0) : isset($targets[$dest]));
		if(!$ok_dest || !mc_dove_ensure_folder($email, $dest)){
			foreach($sources as $list) foreach($list as $r){ $mark->execute(array('error', time(), 'cannot use the folder '.$dest, null, $r['id'])); $failed++; }
			$log('  cannot use the folder '.$dest);
			continue;
		}
		if($is_new){ $created[] = $dest; $log('  created folder '.mc_folder_display($dest, $prefix)); $all[] = $dest; }
		foreach($sources as $folder => $list){
			$fs = mc_dove_status($email, $folder);
			$same = $fs && (int)$fs['uidvalidity'] === (int)$list[0]['uidvalidity'];
			foreach(array_chunk($list, 200) as $batch){
				if($same){
					$byuid = array();
					foreach($batch as $r) $byuid[(int)$r['uid']] = $r;
					$moved = array_flip(mc_dove_move_uids($email, $folder, $dest, array_keys($byuid), $err));
					$db->beginTransaction();
					foreach($byuid as $u => $r){
						if(isset($moved[$u])){ $mark->execute(array('filed', time(), null, $dest, $r['id'])); $filed++; }
						else { $mark->execute(array('error', time(), $err !== '' ? mc_one_line($err, 200) : 'still in '.$folder, null, $r['id'])); $failed++; }
					}
					$db->commit();
				}else{
					foreach($batch as $r){
						$w = mc_dove_find_guid($email, $r['guid'], $folder);
						if(!$w){ $mark->execute(array('gone', time(), 'no longer in '.$folder, null, $r['id'])); $gone++; continue; }
						if(mc_dove_move_guid($email, $r['guid'], $folder, $dest, $err)){ $mark->execute(array('filed', time(), null, $dest, $r['id'])); $filed++; }
						else { $mark->execute(array('error', time(), mc_one_line($err, 200), null, $r['id'])); $failed++; }
					}
				}
				$done += count($batch);
				mc_write_status($email, array('progress' => array('done' => $done, 'total' => $total, 'phase' => 'filing'), 'heartbeat' => time()));
			}
		}
		$log('  '.mc_folder_display($dest, $prefix).': done');
	}
	$run = mc_db_run($scan_run);
	$t = $run['totals'];
	$t['filed'] = (int)($t['filed'] ?? 0) + $filed;
	mc_db_run_update($scan_run, array('totals' => $t));
	$log('Filed '.$filed.($failed ? ', '.$failed.' failed' : '').($gone ? ', '.$gone.' no longer there' : '').'. Nothing was deleted.');
	return array('filed' => $filed, 'failed' => $failed, 'gone' => $gone, 'created' => $created);
}

/* ------------------------------------------------------------- apply */

function mc_apply($box, $scan_run, $log){
	$email = $box['email'];
	$db = mc_db();
	$run = mc_db_run($scan_run);
	if(!$run || $run['box'] !== $email || $run['kind'] !== 'scan') throw new RuntimeException('scan run not found');
	$review = $run['totals']['review_folder'] ?? '';
	if($review === '' || !mc_valid_folder($review)) $review = $box['review_folder'];
	if($review === '' || !mc_valid_folder($review)) throw new RuntimeException('no review folder configured');
	if(!mc_dove_ensure_folder($email, $review)) throw new RuntimeException('could not create the folder '.$review);

	$q = $db->prepare('SELECT id, folder, uidvalidity, uid, guid FROM msgs WHERE run = ? AND pick = 1 AND action IS NULL ORDER BY folder, uid');
	$q->execute(array($scan_run));
	$rows = $q->fetchAll();
	$log('Moving '.count($rows).' message(s) to '.$review);
	$mark = $db->prepare('UPDATE msgs SET action = ?, acted = ?, err = ? WHERE id = ?');
	$moved = 0; $failed = 0; $gone = 0;

	$by = array();
	foreach($rows as $r) $by[$r['folder']][] = $r;
	$done = 0; $total = count($rows);
	foreach($by as $folder => $list){
		$fs = mc_dove_status($email, $folder);
		$same = $fs && (int)$fs['uidvalidity'] === (int)$list[0]['uidvalidity'];
		if(!$same) $log('  '.$folder.': folder was rebuilt since the scan; finding messages one by one');
		foreach(array_chunk($list, 200) as $batch){
			if($same){
				$uids = array(); $byuid = array();
				foreach($batch as $r){ $uids[] = (int)$r['uid']; $byuid[(int)$r['uid']] = $r; }
				$ok = array_flip(mc_dove_move_uids($email, $folder, $review, $uids, $err));
				$db->beginTransaction();
				foreach($byuid as $u => $r){
					if(isset($ok[$u])){
						// uid gone from the source: confirm it really arrived (and not deleted by someone else)
						$mark->execute(array('moved', time(), null, $r['id'])); $moved++;
					}else{
						$mark->execute(array('error', time(), $err !== '' ? mc_one_line($err, 200) : 'still in '.$folder, $r['id'])); $failed++;
					}
				}
				$db->commit();
			}else{
				foreach($batch as $r){
					$where = mc_dove_find_guid($email, $r['guid'], $folder);
					if(!$where){ $mark->execute(array('gone', time(), 'no longer in '.$folder, $r['id'])); $gone++; continue; }
					if(mc_dove_move_guid($email, $r['guid'], $folder, $review, $err)){ $mark->execute(array('moved', time(), null, $r['id'])); $moved++; }
					else { $mark->execute(array('error', time(), mc_one_line($err, 200), $r['id'])); $failed++; }
				}
			}
			$done += count($batch);
			mc_write_status($email, array('progress' => array('done' => $done, 'total' => $total, 'phase' => 'moving'), 'heartbeat' => time()));
			$log('  '.$done.'/'.$total.' handled');
		}
	}
	$t = $run['totals'];
	$t['moved'] = (int)($t['moved'] ?? 0) + $moved;
	$t['move_failed'] = $failed;
	$t['applied'] = time();
	mc_db_run_update($scan_run, array('state' => 'done', 'totals' => $t));
	$log('Moved '.$moved.($failed ? ', '.$failed.' failed' : '').($gone ? ', '.$gone.' no longer there' : '').'. Nothing was deleted.');
	return array('moved' => $moved, 'failed' => $failed, 'gone' => $gone, 'review_folder' => $review);
}

/* ------------------------------------------------------------- undo / restore */

function mc_restore_rows($box, $rows, $log){
	$email = $box['email'];
	$db = mc_db();
	$mark = $db->prepare('UPDATE msgs SET action = ?, acted = ?, err = ?, pick = 0 WHERE id = ?');
	$back = 0; $failed = 0; $done = 0;
	foreach($rows as $r){
		$where = mc_dove_find_guid($email, $r['guid']);
		if(!$where){
			$mark->execute(array('gone', time(), 'not found in any folder (deleted?)', $r['id'])); $failed++;
		}elseif($where[0] === $r['folder']){
			$mark->execute(array('restored', time(), null, $r['id'])); $back++;   // already home
		}else{
			if(!mc_dove_ensure_folder($email, $r['folder'])) { $failed++; continue; }
			if(mc_dove_move_guid($email, $r['guid'], $where[0], $r['folder'], $err)){
				$mark->execute(array('restored', time(), null, $r['id'])); $back++;
			}else{
				$mark->execute(array($r['action'] ?? 'moved', time(), 'put back failed: '.mc_one_line($err, 180), $r['id'])); $failed++;
			}
		}
		$done++;
		if($done % 25 === 0){
			mc_write_status($email, array('progress' => array('done' => $done, 'total' => count($rows), 'phase' => 'restoring'), 'heartbeat' => time()));
			$log('  '.$done.'/'.count($rows).' handled');
		}
	}
	return array('restored' => $back, 'failed' => $failed);
}

function mc_undo($box, $scan_run, $log){
	$run = mc_db_run($scan_run);
	if(!$run || $run['box'] !== $box['email']) throw new RuntimeException('scan run not found');
	$q = mc_db()->prepare("SELECT id, folder, guid, action FROM msgs WHERE run = ? AND action IN ('moved', 'filed')");
	$q->execute(array($scan_run));
	$rows = $q->fetchAll();
	$log('Putting back '.count($rows).' message(s) from this scan');
	$res = mc_restore_rows($box, $rows, $log);
	$t = $run['totals'];
	$t['restored'] = (int)($t['restored'] ?? 0) + $res['restored'];
	mc_db_run_update($scan_run, array('totals' => $t));
	$log('Put back '.$res['restored'].($res['failed'] ? ', '.$res['failed'].' could not be put back' : '').'.');
	return $res;
}

function mc_restore_one($box, $msg_id, $log){
	$q = mc_db()->prepare("SELECT id, run, folder, guid, subject, action FROM msgs WHERE id = ? AND box = ? AND action IN ('moved', 'filed')");
	$q->execute(array((int)$msg_id, $box['email']));
	$rows = $q->fetchAll();
	if(!$rows){ $log('Nothing to put back (message not moved, or already restored).'); return array('restored' => 0, 'failed' => 0); }
	$log('Putting back "'.$rows[0]['subject'].'"');
	$res = mc_restore_rows($box, $rows, $log);
	$log($res['restored'] ? 'Put back.' : 'Could not put it back.');
	return $res;
}

/* ------------------------------------------------------------- notify */

function mc_notify($box, $subject, $text){
	$to = trim((string)$box['notify_email']);
	if($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('~[\r\n]~', $to)) return;
	$host = php_uname('n');
	$msg = "To: ".$to."\nFrom: AI Mail Cleaner <root@".$host.">\nSubject: ".mc_one_line($subject, 150)
		."\nContent-Type: text/plain; charset=UTF-8\nAuto-Submitted: auto-generated\n\n".$text."\n";
	mc_exec(array('/usr/sbin/sendmail', '-t', '-i'), $o, $e, $msg, 60);
}
