<?php
/**
 * AI Mail Cleaner — enduser panel page.
 * Served by EMPS nginx via fpm-root.sock: THIS RUNS AS ROOT (PHP 7.4).
 * Rules obeyed here (same as GitDeploy):
 *   - identity comes only from the Webuzo session SDK, never from the request
 *   - a mailbox must be a valid address on one of the logged-in user's domains,
 *     with its mail home inside that user's home (mc_user_mailboxes)
 *   - the page never touches mail itself: it queues jobs for the root runner
 *   - every state change is POST + CSRF token; every output is escaped
 */

error_reporting(0);

include_once('/usr/local/webuzo/sdk/sessions.php');
require '/usr/local/mailcleaner/lib/common.php';
require MC_BASE.'/lib/db.php';

$sess = new Webuzo_Sessions();
$logged_in = $sess->isLogin();
if(empty($logged_in)) $sess->show_login();

$user    = isset($sess->user['user']) ? (string)$sess->user['user'] : '';
$domains = (isset($sess->user['domains']) && is_array($sess->user['domains'])) ? $sess->user['domains'] : array();
if($user === '' || $user === 'root' || !mc_valid_user($user)){
	die('AI Mail Cleaner: this page is only available to hosting users.');
}
// The session lists domains as keys or values depending on the panel version.
$dlist = array();
foreach($domains as $k => $v){ $dlist[] = is_string($k) && strpos($k, '.') !== false ? $k : (is_string($v) ? $v : ''); }

$csrf_key = trim((string)@file_get_contents(MC_STATE.'/.csrf_key'));
if($csrf_key === '') die('AI Mail Cleaner: server key missing. Ask your administrator to run /usr/local/mailcleaner/install.sh --repair');
$TOK = hash_hmac('sha256', (string)(isset($sess->SESS['sid']) ? $sess->SESS['sid'] : ''), $csrf_key);

/* ------------------------------------------------------------------ helpers */

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function mc_post($k){ return isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : ''; }
function mc_get($k){ return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : ''; }
function mc_tok_field(){ global $TOK; echo '<input type="hidden" name="tok" value="'.h($TOK).'">'; }

function mc_asset_v(){
	$t = @filemtime(MC_BASE.'/web/enduser/assets/style.css');
	return $t ? (int)$t : preg_replace('~[^0-9a-zA-Z.]~', '', mc_version());
}

function mc_check_csrf($json = false){
	global $TOK;
	$t = mc_post('tok');
	if(!hash_equals($TOK, $t)){
		if($json){ mc_json(array('ok' => false, 'error' => 'stale form, reload the page'), 403); }
		mc_stop('403 — bad form token', 'This form is stale or was not sent from the panel. Reload the page and try again.', 403);
	}
}

function mc_json($data, $code = 200){
	http_response_code($code);
	header('Content-Type: application/json; charset=UTF-8');
	header('Cache-Control: no-store');
	echo json_encode($data);
	exit;
}

function mc_redirect($qs){
	header('Location: '.$qs);
	echo '<!doctype html><meta http-equiv="refresh" content="0;url='.h($qs).'"><p><a href="'.h($qs).'">Continue</a></p>';
	exit;
}

function mc_when($ts){ return empty($ts) ? '—' : date('Y-m-d H:i', (int)$ts); }
function mc_ago($ts){
	$d = max(0, time() - (int)$ts);
	if($d < 45) return 'just now';
	if($d < 3600){ $n = (int)round($d / 60); return $n.' min ago'; }
	if($d < 86400){ $n = (int)round($d / 3600); return $n.' hour'.($n === 1 ? '' : 's').' ago'; }
	$n = (int)round($d / 86400); return $n.' day'.($n === 1 ? '' : 's').' ago';
}
function mc_rel($ts){ return empty($ts) ? '—' : '<time title="'.h(mc_when($ts)).'">'.h(mc_ago($ts)).'</time>'; }

function mc_note($html, $type = 'info'){
	$map = array('info' => 'alert-info', 'ok' => 'alert-success', 'warn' => 'alert-warning', 'err' => 'alert-danger');
	echo '<div class="alert '.($map[$type] ?? 'alert-info').' mc-note" role="alert">'.$html.'</div>';
}

function mc_head($subtitle = ''){
	global $user;
	header('Content-Type: text/html; charset=UTF-8');
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
		.'<meta name="viewport" content="width=device-width, initial-scale=1">'
		.'<title>AI Mail Cleaner</title>'
		.'<link rel="stylesheet" href="/themes/default/bootstrap/css/bootstrap.min.css">'
		.'<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&amp;family=Poppins:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">'
		.'<link rel="stylesheet" href="/themes/default/css/style.css">'
		.'<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">'
		.'<link rel="stylesheet" href="assets/style.css?v='.mc_asset_v().'">'
		.'<script>if(window.self!==window.top)document.documentElement.className="embedded";</script>'
		.'</head><body><div class="mc-wrap">'
		.'<div class="mc-head d-flex align-items-center justify-content-between flex-wrap mb-4">'
		.'<div class="mc-brand d-flex align-items-center">'
		.'<img src="/mailcleaner/assets/mailcleaner_48.png" alt="" width="40" height="40" class="me-3">'
		.'<div><h1 class="panel-head mb-0">AI Mail Cleaner</h1><div class="soft-card-desc">'
		.h($subtitle !== '' ? $subtitle : 'Find spam and phishing in your mailboxes and move it out of the way')
		.' &middot; user '.h($user).'</div></div></div>'
		.'<div class="mc-nav"><a class="flat-butt-primary" href="?act=list"><i class="fas fa-inbox me-1"></i>My mailboxes</a> '
		.'<a class="flat-butt-primary" href="?act=help"><i class="fas fa-book me-1"></i>Help</a></div></div>';
}

function mc_foot($extra_js = ''){
	echo '<div class="mc-powered">Powered by <a href="https://www.digitalbkk.com" target="_blank" rel="noopener">Digitalbkk Co., Ltd.</a>'
		.' &middot; developed by the Digitalbkk team &middot; author Hasif Ahmed</div>'
		.'<div class="mc-foot">AI Mail Cleaner '.h(mc_version()).' &middot; never deletes mail &middot; server time '.h(date('Y-m-d H:i')).'</div>'
		.'</div>'.$extra_js.'</body></html>';
}

function mc_card($title, $sub = ''){
	echo '<div class="card soft-card mb-4"><div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">'
		.'<h5 class="mb-0 panel-head" style="font-size:16px">'.$title.'</h5>'
		.($sub !== '' ? '<div class="mc-sub">'.$sub.'</div>' : '')
		.'</div></div><div class="card-body px-4 py-3">';
}
function mc_card_end(){ echo '</div></div>'; }

function mc_stop($title, $msg, $code = 400){
	http_response_code($code);
	mc_head($title);
	mc_card('<i class="fas fa-exclamation-triangle panel-icon me-2"></i>'.h($title));
	mc_note(h($msg), 'err');
	echo '<a class="flat-butt-primary" href="?act=list"><i class="fas fa-arrow-left me-1"></i>Back to mailboxes</a>';
	mc_card_end();
	mc_foot();
	exit;
}

function mc_pill($cls, $icon, $text){
	return '<span class="mc-pill '.$cls.'"><i class="fas '.$icon.'" aria-hidden="true"></i><span>'.h($text).'</span></span>';
}

function mc_verdict_tag($v, $conf = null){
	if($v === null || $v === '') return '';
	$labels = array('spam' => 'Spam', 'phishing' => 'Phishing', 'suspicious' => 'Suspicious', 'not_spam' => 'Not spam', 'error' => 'No answer');
	$cls = isset($labels[$v]) ? $v : 'error';
	return '<span class="mc-verdict mc-v-'.$cls.'">'.h($labels[$cls]).($conf !== null && $v !== 'error' ? ' '.(int)round($conf * 100).'%' : '').'</span>';
}

// Plain-language reason codes (lib/engine.php mc_decide_ai + mc_scan).
function mc_why($why){
	$map = array(
		'sa_high' => 'SpamAssassin is sure this is spam',
		'sa_clean' => 'SpamAssassin says it looks fine',
		'sa_unsure' => 'SpamAssassin is unsure (no AI set up)',
		'allow_list' => 'Sender is on your never-move list',
		'ai_spam' => 'AI: spam', 'ai_phishing' => 'AI: phishing',
		'ai_spam_unsure' => 'AI leans spam, not sure enough to move on its own',
		'ai_phishing_unsure' => 'AI leans phishing, not sure enough to move on its own',
		'ai_suspicious_unsure' => 'AI found mixed signals',
		'ai_clean' => 'AI: looks fine', 'ai_both_clean' => 'Both AIs: looks fine',
		'ai_both_spam' => 'Both AIs: spam', 'ai_both_phishing' => 'Both AIs: spam or phishing',
		'ai_disagree' => 'The two AIs disagree', 'ai_vs_sa' => 'SpamAssassin says spam, the AI says it is fine',
		'ai_unavailable' => 'AI could not answer', 'unreadable' => 'Message could not be read',
	);
	return $map[$why] ?? (string)$why;
}

// "Wed, 21 Oct 2026 22:58:02 -0700" -> "21 Oct 2026"
function mc_short_date($d){
	$ts = strtotime((string)$d);
	return $ts ? date('j M Y', $ts) : mc_one_line($d, 20);
}

// One sentence describing what "Apply" will do.
function mc_plan_text($plan, $rf_short){
	$p = array();
	if($plan['spam']) $p[] = $plan['spam'].' to '.$rf_short;
	if($plan['file']) $p[] = $plan['file'].' into '.$plan['folders'].' folder'.($plan['folders'] === 1 ? '' : 's');
	return $p ? 'Apply will move '.implode(' and ', $p).'. Everything else stays where it is.' : 'Nothing is set to move yet.';
}

function mc_bytes($n){ $n = (int)$n; if($n < 1024) return $n.' B'; if($n < 1048576) return round($n / 1024).' KB'; return round($n / 1048576, 1).' MB'; }

/* ------------------------------------------------------------------ access */

$mine = mc_user_mailboxes($user, $dlist ?: null);   // email => mail home, decided server-side only

// Returns [email, box-or-null]; stops unless the mailbox belongs to this user.
function mc_require_box($email){
	global $mine, $user;
	$email = strtolower(trim((string)$email));
	if(!mc_valid_email($email) || !isset($mine[$email])) mc_stop('Not your mailbox', 'That mailbox does not exist or is not on one of your domains.', 404);
	$box = mc_load_box($email);
	if($box && $box['user'] !== $user) mc_stop('Not your mailbox', 'That mailbox belongs to another account.', 403);
	return array($email, $box);
}

function mc_require_run($run_id, $email){
	if(!mc_valid_run($run_id)) mc_stop('Not found', 'That clean-up does not exist.', 404);
	$run = mc_db_run($run_id);
	if(!$run || $run['box'] !== $email) mc_stop('Not found', 'That clean-up does not exist.', 404);
	return $run;
}

function mc_waiting_scan($email){
	$q = mc_db()->prepare("SELECT id FROM runs WHERE box = ? AND kind = 'scan' AND state = 'review' ORDER BY started DESC LIMIT 1");
	$q->execute(array($email));
	$r = $q->fetch();
	return $r ? $r['id'] : '';
}

// "Close without moving" button for a check that is waiting for the owner.
function mc_dismiss_form($email, $run_id, $label = 'Close without moving'){
	echo '<form method="post" action="?act=dismiss" class="d-inline" onsubmit="return confirm(\'Close this check and leave every message where it is? Nothing will be moved.\')">';
	mc_tok_field();
	echo '<input type="hidden" name="box" value="'.h($email).'"><input type="hidden" name="run" value="'.h($run_id).'">'
		.'<button class="flat-butt-primary" type="submit"><i class="fas fa-check me-1"></i>'.h($label).'</button></form>';
}

function mc_ai_on(){
	$s = mc_settings();
	return $s['primary'] !== 'none' && $s[$s['primary'].'_key'] !== '';
}

// Rough cost of asking the main AI about $n messages (about 1,200 tokens in / 300 out each).
function mc_recheck_cost($n){
	$s = mc_settings();
	list($pin, $pout) = mc_price($s, $s[$s['primary'].'_model']);
	return $n * (1200 * $pin + 300 * $pout) / 1000000;
}

function mc_ai_summary(){
	$s = mc_settings();
	$ready = function($p) use ($s){
		if($p === 'anthropic') return $s['anthropic_key'] !== '';
		if($p === 'gemini') return $s['gemini_key'] !== '';
		return false;
	};
	$name = function($p) use ($s){ return $p === 'anthropic' ? 'Claude ('.$s['anthropic_model'].')' : 'Gemini ('.$s['gemini_model'].')'; };
	if($s['primary'] === 'none' || !$ready($s['primary'])) return 'AI is not set up on this server yet, so only SpamAssassin is used. Messages SpamAssassin is unsure about are listed for you to decide.';
	$t = 'AI: '.$name($s['primary']);
	if($s['second'] !== 'none' && $s['second'] !== $s['primary'] && $ready($s['second'])){
		$t .= ', with '.$name($s['second']).' as a second opinion '.($s['second_when'] === 'always' ? 'on every message' : 'when the first is unsure');
	}
	return $t.'. Set by your server administrator.';
}

$act = mc_get('act');
if($act === '') $act = 'list';
$is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/* ------------------------------------------------------------------ JSON endpoints */

if($act === 'poll' && !$is_post){
	list($email, $box) = mc_require_box(mc_get('box'));
	$st = mc_status($email);
	$lines = array();
	if(!empty($st['run']) && mc_valid_run($st['run'])){
		$f = mc_log_file($email, $st['run']);
		if(is_file($f)){
			$sz = filesize($f);
			$fh = fopen($f, 'r');
			if($sz > 32768) fseek($fh, $sz - 32768);
			$txt = stream_get_contents($fh);
			fclose($fh);
			$lines = array_slice(explode("\n", rtrim($txt)), -80);
		}
	}
	mc_json(array('ok' => true, 'state' => $st['state'] ?? '', 'queued' => mc_queued($email),
		'progress' => $st['progress'] ?? null, 'log' => implode("\n", $lines), 'run' => $st['run'] ?? '',
		'view' => $st['last']['view'] ?? ''));
}

// How many messages in the mailbox's checked folders have never been looked at.
if($act === 'count' && !$is_post){
	list($email, $box) = mc_require_box(mc_get('box'));
	if(!$box) mc_json(array('ok' => false));
	require_once MC_BASE.'/lib/dove.php';
	$known = array();
	$q = mc_db()->prepare('SELECT guid FROM msgs WHERE box = ?');
	$q->execute(array($email));
	while($r = $q->fetch()) $known[$r['guid']] = true;
	$out = array(); $sum = 0; $all = 0;
	$prefix = mc_dove_prefix(mc_dove_folders($email));
	foreach($box['folders'] as $f){
		if($f === $box['review_folder']) continue;
		$g = mc_dove_guids($email, $f);
		if($g === null) continue;
		$n = 0;
		foreach($g as $x){ if(!isset($known[$x])) $n++; }
		$out[] = array('folder' => mc_folder_display($f, $prefix), 'total' => count($g), 'unchecked' => $n);
		$sum += $n; $all += count($g);
	}
	mc_json(array('ok' => true, 'unchecked' => $sum, 'total' => $all, 'folders' => $out));
}

if($act === 'pick' && $is_post){
	mc_check_csrf(true);
	list($email, $box) = mc_require_box(mc_post('box'));
	$id = (int)mc_post('id');
	$v = mc_post('v') === '1' ? 1 : 0;
	$q = mc_db()->prepare('UPDATE msgs SET pick = ? WHERE id = ? AND box = ? AND action IS NULL');
	$q->execute(array($v, $id, $email));
	$run = mc_post('run');
	$c = mc_db()->prepare('SELECT COUNT(*) FROM msgs WHERE run = ? AND box = ? AND pick = 1 AND action IS NULL');
	$c->execute(array($run, $email));
	mc_json(array('ok' => $q->rowCount() === 1, 'picked' => (int)$c->fetchColumn()));
}

if($act === 'choose' && $is_post){
	mc_check_csrf(true);
	list($email, $box) = mc_require_box(mc_post('box'));
	$run_id = mc_post('run');
	if(!mc_valid_run($run_id)) mc_json(array('ok' => false, 'error' => 'Unknown check.'), 400);
	$id = (int)mc_post('id');
	$q = mc_db()->prepare('SELECT * FROM msgs WHERE id = ? AND run = ? AND box = ? AND action IS NULL');
	$q->execute(array($id, $run_id, $email));
	$m = $q->fetch();
	if(!$m) mc_json(array('ok' => false, 'error' => 'That message was already handled. Reload the page.'), 409);
	$v = mc_post('v');
	if($v === '__archive') $v = mc_archive_folder($email, $box);
	elseif($v === '__new'){
		$v = mc_typed_folder($email, $box, mc_post('name'));
		if($v === '') mc_json(array('ok' => false, 'error' => 'That folder name cannot be used. Use letters, numbers and spaces, at most two levels (e.g. Clients/Acme). Inbox, Sent, Drafts, Trash, Junk and Spam are not allowed.'), 400);
	}
	if($v === '__spam') $set = array(1, null);
	elseif($v === '__leave') $set = array(0, null);
	else {
		$t = mc_targets_for($email, $box);
		$typed = in_array(mc_post('v'), array('__new', '__archive'), true);
		if(!$typed && !mc_choice_ok($email, $box, $v, $m, $run_id)) mc_json(array('ok' => false, 'error' => 'That folder cannot be used.'), 400);
		$set = array(0, $v);
	}
	mc_db()->prepare('UPDATE msgs SET pick = ?, dest = ? WHERE id = ?')->execute(array($set[0], $set[1], $id));
	$isnew = $set[1] !== null && !in_array($set[1], mc_targets_for($email, $box)['all'], true);
	mc_json(array('ok' => true, 'value' => $set[1] ?? $v, 'label' => $set[1] !== null ? mc_folder_display($set[1], mc_targets_for($email, $box)['prefix']) : '', 'isnew' => $isnew)
		+ mc_plan_counts($run_id, $email));
}

/* ------------------------------------------------------------------ POST actions */

if($is_post){
	mc_check_csrf();
	list($email, $box) = mc_require_box(mc_post('box'));
	$busy = $box ? mc_is_busy($email) : false;

	if($act === 'save'){
		$folders_all = mc_dove_folders_cached($email);
		$new = $box ?: mc_box_defaults($email, $user);
		$sel = isset($_POST['folders']) && is_array($_POST['folders']) ? $_POST['folders'] : array();
		$folders = array();
		foreach($sel as $f){ if(is_string($f) && in_array($f, $folders_all, true)) $folders[] = $f; }
		if(!$folders) $folders = array('INBOX');
		$prefix = mc_prefix_for($folders_all);
		$rf = trim(mc_post('review_folder'));
		$rf = preg_replace('~^INBOX[./]~', '', $rf);
		if($rf === '' || !preg_match('~^[A-Za-z0-9 _-]{1,60}$~', $rf)) $rf = 'AI-Spam-Review';
		$new['review_folder'] = $prefix.$rf;
		$new['folders'] = array_values(array_diff($folders, array($new['review_folder'])));
		$lo = (float)mc_post('sa_low'); $hi = (float)mc_post('sa_high');
		$new['sa_low']  = max(0.0, min(20.0, $lo ?: 5.0));
		$new['sa_high'] = max($new['sa_low'] + 0.5, min(50.0, $hi ?: 8.0));
		$new['ai_scope'] = mc_post('ai_scope') === 'all' ? 'all' : 'unsure';
		$mc = (float)mc_post('ai_min_conf');
		$new['ai_min_conf'] = in_array($mc, array(0.7, 0.8, 0.9, 0.95), true) ? $mc : 0.8;
		$new['allow'] = mc_clean_allow(preg_split('~[\r\n,;]+~', mc_post('allow')));
		$auto = mc_post('auto');
		$new['auto'] = in_array($auto, array('off', 'label', 'move'), true) ? $auto : 'off';
		if($new['auto'] === 'move' && mc_post('ack_move') !== '1' && ($box['auto'] ?? 'off') !== 'move'){
			mc_stop('Please confirm automatic moving', 'Automatic moving moves messages without asking you. Tick the confirmation box under "Automatic checks" to turn it on.', 400);
		}
		$every = (int)mc_post('auto_every');
		$new['auto_every'] = in_array($every, array(15, 30, 60, 180, 720, 1440), true) ? $every : 30;
		$ne = trim(mc_post('notify_email'));
		$new['notify_email'] = ($ne !== '' && filter_var($ne, FILTER_VALIDATE_EMAIL) && !preg_match('~[\r\n]~', $ne)) ? $ne : '';
		$new['user'] = $user;
		$new['email'] = $email;
		if(!mc_save_box($new)) mc_stop('Could not save', 'The settings could not be saved. Please try again.', 500);
		mc_redirect('?act=box&box='.rawurlencode($email).'&saved=1');
	}

	if(!$box) mc_stop('Set it up first', 'Save this mailbox\'s settings once before running a clean-up.', 400);

	if($act === 'remove'){
		if($busy) mc_stop('Busy', 'Wait until the current job has finished.', 409);
		@unlink(mc_box_file($email));
		@unlink(MC_STATUS.'/'.$email.'.json');
		mc_redirect('?act=list&removed=1');
	}
	if($act === 'stop'){
		// Not started yet: just take it off the queue. Running: ask the worker to stop
		// after the current batch; everything finished so far is kept.
		if(mc_queued($email) && @unlink(MC_SPOOL.'/'.$email.'.json')){
			mc_write_status($email, array('state' => 'idle', 'progress' => null, 'error' => ''));
			mc_redirect('?act=box&box='.rawurlencode($email).'&cancelled=1');
		}
		if($busy) mc_request_stop($email);
		mc_redirect('?act=box&box='.rawurlencode($email).'&stopping=1');
	}
	if($busy) mc_stop('Busy', 'This mailbox is already being worked on. Wait until that has finished.', 409);

	if($act === 'scan'){
		$lim = trim(mc_post('limit'));
		$extra = array('trigger' => 'manual', 'by' => $user);
		if($lim !== '' && ctype_digit($lim) && (int)$lim >= 1) $extra['limit'] = min(50000, (int)$lim);
		mc_queue_job($email, 'scan', $extra);
		mc_write_status($email, array('state' => 'working', 'kind' => 'scan', 'run' => '', 'started' => time(), 'heartbeat' => time(),
			'progress' => array('done' => 0, 'total' => 0, 'phase' => 'queued'), 'error' => ''));
		mc_redirect('?act=box&box='.rawurlencode($email).'&started=1');
	}
	$run_id = mc_post('run');
	$run = mc_require_run($run_id, $email);

	if($act === 'bulk' || $act === 'putback'){
		// Bulk: the ticked messages (ids) or every message matching the current filters.
		$tab = mc_post('tab');
		if(mc_tab_where($tab) === null) mc_stop('Bad request', 'Unknown list.', 400);
		$f = mc_filter_in($_POST);
		list($where, $par) = mc_filter($run_id, $email, $tab, $f);
		if(mc_post('scope') !== 'all'){
			$ids = array();
			foreach(explode(',', mc_post('ids')) as $i){ $i = (int)$i; if($i > 0) $ids[$i] = $i; }
			if(!$ids) mc_redirect('?act=run&box='.rawurlencode($email).'&run='.rawurlencode($run_id).'&tab='.rawurlencode($tab).mc_filter_qs($f));
			if(count($ids) > 20000) mc_stop('Too many', 'Select fewer messages, or use "select all matching".', 400);
			$where .= ' AND id IN ('.implode(',', array_values($ids)).')';
		}
		$db = mc_db();
		$back = '?act=run&box='.rawurlencode($email).'&run='.rawurlencode($run_id).'&tab='.rawurlencode($tab).mc_filter_qs($f);

		if($act === 'putback'){
			$q = $db->prepare("SELECT id FROM msgs WHERE ".$where." AND action IN ('moved','filed')");
			$q->execute($par);
			$ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
			if(!$ids) mc_redirect($back);
			mc_queue_job($email, 'restore', array('run' => $run_id, 'msgs' => $ids, 'by' => $user));
			mc_write_status($email, array('state' => 'working', 'kind' => 'restore', 'run' => '', 'started' => time(), 'heartbeat' => time(),
				'progress' => array('done' => 0, 'total' => count($ids), 'phase' => 'queued'), 'error' => ''));
			mc_redirect('?act=box&box='.rawurlencode($email).'&started=1');
		}

		if($tab === 'done') mc_stop('Bad request', 'Those messages were already handled.', 400);
		$v = mc_post('v');
		if($v === '__archive') $v = mc_archive_folder($email, $box);
		elseif($v === '__new'){
			$v = mc_typed_folder($email, $box, mc_post('newname'));
			if($v === '') mc_stop('Folder name not allowed', 'Use letters, numbers and spaces (at most two levels, e.g. "Clients/Acme"). Inbox, Sent, Drafts, Trash, Junk and Spam cannot be used.', 400);
		}
		$base = ' WHERE '.$where.' AND action IS NULL';
		if($v === '__spam') $st = $db->prepare('UPDATE msgs SET pick = 1, dest = NULL'.$base);
		elseif($v === '__leave') $st = $db->prepare('UPDATE msgs SET pick = 0, dest = NULL'.$base);
		elseif($v === '__ai') $st = $db->prepare("UPDATE msgs SET pick = 0, dest = sug_folder".$base." AND sug_kind IN ('existing','new') AND sug_folder IS NOT NULL");
		else {
			if(!in_array(mc_post('v'), array('__new', '__archive'), true) && !mc_choice_ok($email, $box, $v, null, $run_id)) mc_stop('Bad request', 'That folder cannot be used.', 400);
			$st = $db->prepare('UPDATE msgs SET pick = 0, dest = ?'.$base);
			array_unshift($par, $v);
		}
		$st->execute($par);
		mc_redirect($back.'&set='.$st->rowCount());
	}
	if($act === 'sort'){
		if($run['kind'] !== 'scan') mc_stop('Bad request', 'Only a check can be sorted.', 400);
		if(!mc_ai_on()) mc_stop('AI is not set up', 'The server administrator has not added an AI key yet.', 409);
		mc_queue_job($email, 'sort', array('run' => $run_id, 'by' => $user));
		mc_write_status($email, array('state' => 'working', 'kind' => 'sort', 'run' => '', 'started' => time(), 'heartbeat' => time(),
			'progress' => array('done' => 0, 'total' => 0, 'phase' => 'queued'), 'error' => ''));
		mc_redirect('?act=box&box='.rawurlencode($email).'&started=1');
	}
	if($act === 'dismiss'){
		// Close a check without moving anything. No mail is touched: the messages stay
		// where they are and are not offered again by later checks.
		if($run['kind'] === 'scan' && $run['state'] === 'review'){
			$t = is_array($run['totals']) ? $run['totals'] : array();
			$t['dismissed'] = time();
			$t['dismissed_by'] = $user;
			mc_db_run_update($run_id, array('state' => 'done', 'totals' => $t));
		}
		mc_redirect('?act=box&box='.rawurlencode($email).'&closed=1');
	}
	if($act === 'recheck'){
		if($run['kind'] !== 'scan') mc_stop('Bad request', 'Only a check can be rechecked.', 400);
		if(!mc_ai_on()) mc_stop('AI is not set up', 'The server administrator has not added an AI key yet.', 409);
		mc_queue_job($email, 'recheck', array('run' => $run_id, 'scope' => mc_post('scope') === 'all' ? 'all' : 'unsure', 'by' => $user));
		mc_write_status($email, array('state' => 'working', 'kind' => 'recheck', 'run' => '', 'started' => time(), 'heartbeat' => time(),
			'progress' => array('done' => 0, 'total' => 0, 'phase' => 'queued'), 'error' => ''));
		mc_redirect('?act=box&box='.rawurlencode($email).'&started=1');
	}
	if($act === 'apply'){
		mc_queue_job($email, 'apply', array('run' => $run_id, 'by' => $user));
		mc_redirect('?act=box&box='.rawurlencode($email).'&started=1');
	}
	if($act === 'undo'){
		mc_queue_job($email, 'undo', array('run' => $run_id, 'by' => $user));
		mc_redirect('?act=box&box='.rawurlencode($email).'&started=1');
	}
	if($act === 'restore'){
		mc_queue_job($email, 'restore', array('run' => $run_id, 'msg' => (int)mc_post('msg'), 'by' => $user));
		mc_redirect('?act=box&box='.rawurlencode($email).'&started=1');
	}
	mc_stop('Unknown action', 'That action is not supported.', 400);
}

/* ------------------------------------------------------------------ small data helpers */

// Folder list via doveadm, cached briefly per mailbox for the settings page.
function mc_dove_folders_cached($email){
	require_once MC_BASE.'/lib/dove.php';
	return mc_dove_folders($email);
}
function mc_prefix_for($folders){
	require_once MC_BASE.'/lib/dove.php';
	return mc_dove_prefix($folders);
}

function mc_tab_where($tab){
	$t = array(
		'move'   => "decision = 'move' AND action IS NULL",
		'review' => "decision = 'review' AND action IS NULL",
		'keep'   => "decision = 'keep' AND action IS NULL",
		'done'   => "action IS NOT NULL",
	);
	return $t[$tab] ?? null;
}

// Folders this mailbox may file into (display => full), cached for the request.
function mc_targets_for($email, $box){
	static $cache = array();
	if(isset($cache[$email])) return $cache[$email];
	require_once MC_BASE.'/lib/dove.php';
	$all = mc_dove_folders($email);
	return $cache[$email] = array('all' => $all, 'prefix' => mc_dove_prefix($all), 'targets' => mc_sort_targets($all, (string)$box['review_folder']));
}

// A folder choice is allowed if it is one of the owner's ordinary folders, or the
// new folder the AI suggested for this message (already sanitised by the worker).
function mc_choice_ok($email, $box, $v, $m, $run_id = null){
	if(!is_string($v) || !mc_valid_folder($v)) return false;
	$t = mc_targets_for($email, $box);
	if(in_array($v, $t['targets'], true)) return true;
	if(in_array($v, $t['all'], true)) return false;                 // Inbox, Sent, Trash, spam folder...
	if($m && ($m['sug_kind'] ?? '') === 'new' && ($m['sug_folder'] ?? '') === $v) return true;
	// a new folder already chosen (by the AI or the owner) for some message of this check
	if($run_id !== null) return in_array($v, mc_pending_new($email, $box, $run_id), true);
	return false;
}

// The mailbox's Archive folder: an existing top-level "Archive"/"Archives", else a new "Archive".
function mc_archive_folder($email, $box){
	$t = mc_targets_for($email, $box);
	foreach($t['targets'] as $disp => $full) if(preg_match('~^archives?$~i', $disp)) return $full;
	return $t['prefix'].'Archive';
}

// A folder name typed by the owner ("Clients/Acme") -> full folder name, or '' if not allowed.
function mc_typed_folder($email, $box, $name){
	require_once MC_BASE.'/lib/dove.php';
	$t = mc_targets_for($email, $box);
	$name = trim((string)$name);
	foreach($t['targets'] as $disp => $full) if(strcasecmp($disp, $name) === 0) return $full;     // existing, any case
	$full = mc_folder_from_display($name, $t['all']);
	if($full === '') return '';
	if(in_array($full, $t['all'], true) && !in_array($full, $t['targets'], true)) return '';   // Inbox, Sent, spam folder...
	return $full;
}

// New folders (not created yet) that this check's messages are set to, or the AI proposed.
function mc_pending_new($email, $box, $run_id){
	static $cache = array();
	if(isset($cache[$run_id])) return $cache[$run_id];
	$t = mc_targets_for($email, $box);
	$q = mc_db()->prepare("SELECT dest AS f FROM msgs WHERE run = ? AND box = ? AND action IS NULL AND dest IS NOT NULL AND dest <> ''
		UNION SELECT sug_folder FROM msgs WHERE run = ? AND box = ? AND sug_kind = 'new' AND sug_folder IS NOT NULL");
	$q->execute(array($run_id, $email, $run_id, $email));
	$out = array();
	foreach($q->fetchAll(PDO::FETCH_COLUMN) as $f){
		if(is_string($f) && mc_valid_folder($f) && !in_array($f, $t['all'], true) && ($t['prefix'] === '' || strpos($f, $t['prefix']) === 0)) $out[] = $f;
	}
	sort($out);
	return $cache[$run_id] = $out;
}

// Filters on the review page: tab + search + current choice + AI suggestion.
// Returns array(sql, params) for "WHERE run = ? AND box = ? AND ..." (run/box included).
function mc_filter($run_id, $email, $tab, $f){
	$sql = 'run = ? AND box = ? AND '.mc_tab_where($tab);
	$par = array($run_id, $email);
	if($f['q'] !== ''){
		$sql .= " AND (subject LIKE ? ESCAPE '\\' OR sender LIKE ? ESCAPE '\\')";
		$like = '%'.addcslashes($f['q'], '%_\\').'%';
		$par[] = $like; $par[] = $like;
	}
	if($f['fc'] === '__spam') $sql .= ' AND pick = 1';
	elseif($f['fc'] === '__leave') $sql .= " AND pick = 0 AND (dest IS NULL OR dest = '')";
	elseif($f['fc'] === '__file') $sql .= " AND pick = 0 AND dest IS NOT NULL AND dest <> ''";
	elseif($f['fc'] !== ''){ $sql .= ' AND pick = 0 AND dest = ?'; $par[] = $f['fc']; }
	if($f['fs'] === '__none') $sql .= ' AND sug_kind IS NULL';
	elseif($f['fs'] === '__leave') $sql .= " AND sug_kind = 'leave'";
	elseif($f['fs'] !== ''){ $sql .= ' AND sug_folder = ?'; $par[] = $f['fs']; }
	return array($sql, $par);
}

// Read the filter values from GET or POST.
function mc_filter_in($src){
	$g = function($k) use ($src){ return isset($src[$k]) && is_string($src[$k]) ? $src[$k] : ''; };
	$q = trim(mc_one_line($g('q'), 120));
	$fc = $g('fc'); $fs = $g('fs');
	if($fc !== '' && !in_array($fc, array('__spam', '__leave', '__file'), true) && !mc_valid_folder($fc)) $fc = '';
	if($fs !== '' && !in_array($fs, array('__none', '__leave'), true) && !mc_valid_folder($fs)) $fs = '';
	return array('q' => $q, 'fc' => $fc, 'fs' => $fs);
}

function mc_filter_qs($f){
	$o = '';
	foreach(array('q', 'fc', 'fs') as $k) if($f[$k] !== '') $o .= '&'.$k.'='.rawurlencode($f[$k]);
	return $o;
}

// What "Apply" would do right now.
function mc_plan_counts($run_id, $email){
	$q = mc_db()->prepare("SELECT SUM(pick = 1) AS spam, SUM(pick = 0 AND dest IS NOT NULL AND dest <> '') AS file, COUNT(DISTINCT CASE WHEN pick = 0 THEN dest END) AS folders
		FROM msgs WHERE run = ? AND box = ? AND action IS NULL");
	$q->execute(array($run_id, $email));
	$r = $q->fetch();
	return array('spam' => (int)$r['spam'], 'file' => (int)$r['file'], 'folders' => (int)$r['folders']);
}

/* ------------------------------------------------------------------ pages */

if($act === 'help'){
	mc_head('Help');
	mc_card('<i class="fas fa-book me-2"></i>How AI Mail Cleaner works');
	echo '<ol class="mb-3" style="font-size:14px;line-height:1.7">'
		.'<li><b>Set up a mailbox.</b> Usually just the Inbox. Spam goes to a folder of its own (AI-Spam-Review by default).</li>'
		.'<li><b>Press "Check now".</b> SpamAssassin scores every new message; the AI reads the ones it is unsure about.</li>'
		.'<li><b>Review.</b> Each message has one choice: <i>Spam folder</i>, <i>Leave where it is</i>, or one of your folders. Spam is pre-selected for you; change anything you disagree with.</li>'
		.'<li><b>Optional: AI tools.</b> <i>Suggest folders</i> lets the AI pick one of your folders for each message (or propose a new one, such as "Receipts"). <i>Check again</i> asks the AI again about spam.</li>'
		.'<li><b>Apply.</b> Messages move to the folders you chose. <b>Nothing is ever deleted.</b> Webmail sees the change straight away. Or press <i>Close without changes</i>.</li>'
		.'<li><b>Changed your mind?</b> In the <i>Done</i> tab, press <i>Put back</i> on one message, or <i>Undo all</i> for the whole check.</li>'
		.'</ol>';
	echo '<h3 class="mc-h3">What the AI sees</h3><p style="font-size:14px">Only what it needs to judge one message: sender, subject, a few delivery headers, the links, attachment names and the first part of the text. Whole messages and attachments never leave the server. Messages already marked as clear spam or clearly clean by SpamAssassin are not sent at all.</p>';
	echo '<h3 class="mc-h3">Automatic checks</h3><p style="font-size:14px">You can let the cleaner check new mail every so often. <b>Label only</b> prepares the list and (optionally) emails you; you still confirm. <b>Move automatically</b> moves confident spam without asking; you can still undo every run. Automatic checks only look at mail that arrives after you switch them on — older mail is only ever checked when you start a check yourself.</p>';
	echo '<h3 class="mc-h3">'.h(mc_ai_summary()).'</h3>';
	mc_card_end();
	mc_foot();
	exit;
}

if($act === 'list'){
	mc_head();
	if(mc_get('removed') === '1') mc_note('Mailbox removed from AI Mail Cleaner. No mail was touched.', 'ok');
	if(mc_is_paused()) mc_note('<i class="fas fa-pause-circle me-1"></i>The server administrator has paused AI Mail Cleaner. Checks you start will wait until it is resumed.', 'warn');
	mc_card('<i class="fas fa-inbox me-2"></i>Your mailboxes', h(mc_ai_summary()));
	if(!$mine){
		mc_note('No email accounts were found on your domains. Create one under Email in your panel first.', 'info');
	}else{
		echo '<div class="table-responsive"><table class="mc-table"><thead><tr><th>Mailbox</th><th>Status</th><th class="hide-sm">Last check</th><th>Automatic</th><th></th></tr></thead><tbody>';
		foreach($mine as $email => $home){
			$box = mc_load_box($email);
			$st = $box ? mc_status($email) : array();
			$wait = $box ? mc_waiting_scan($email) : '';
			if(!$box) $pill = mc_pill('mc-pill-idle', 'fa-minus-circle', 'Not set up');
			elseif(mc_is_busy($email, $st)){
				$p = $st['progress'] ?? array();
				$pill = mc_pill('mc-pill-run', 'fa-circle-notch fa-spin', (($p['phase'] ?? '') === 'moving' ? 'Moving ' : (($p['phase'] ?? '') === 'restoring' ? 'Putting back ' : (($p['phase'] ?? '') === 'rechecking' ? 'Checking again ' : (($p['phase'] ?? '') === 'sorting' ? 'Finding folders ' : (($p['phase'] ?? '') === 'filing' ? 'Filing ' : 'Checking ')))))
					.(!empty($p['total']) ? (int)$p['done'].'/'.(int)$p['total'] : '…'));
			}
			elseif($wait !== '') $pill = mc_pill('mc-pill-warn', 'fa-inbox', 'Waiting for you');
			elseif(($st['state'] ?? '') === 'failed') $pill = mc_pill('mc-pill-fail', 'fa-times-circle', 'Last job failed');
			else $pill = mc_pill('mc-pill-ok', 'fa-check-circle', 'Ready');
			$last = $box && !empty($st['last']['finished']) ? mc_rel($st['last']['finished']) : '—';
			$auto = !$box ? '—' : ($box['auto'] === 'off' ? 'Off' : ($box['auto'] === 'label' ? 'Label only, every '.(int)$box['auto_every'].' min' : 'Moves, every '.(int)$box['auto_every'].' min'));
			echo '<tr><td><span class="subj">'.h($email).'</span></td><td>'.$pill.'</td><td class="hide-sm">'.$last.'</td><td>'.h($auto).'</td><td class="num">';
			if(!$box) echo '<a class="flat-butt-primary btn-sm" href="?act=box&amp;box='.h(rawurlencode($email)).'"><i class="fas fa-cog me-1"></i>Set up</a>';
			else {
				if($wait !== '') echo '<a class="flat-butt-warning btn-sm" href="?act=run&amp;box='.h(rawurlencode($email)).'&amp;run='.h(rawurlencode($wait)).'"><i class="fas fa-eye me-1"></i>Review</a> ';
				echo '<a class="flat-butt-primary btn-sm" href="?act=box&amp;box='.h(rawurlencode($email)).'"><i class="fas fa-arrow-right me-1"></i>Open</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	mc_card_end();
	mc_foot();
	exit;
}

if($act === 'box'){
	list($email, $box) = mc_require_box(mc_get('box'));
	require_once MC_BASE.'/lib/dove.php';
	$st = $box ? mc_status($email) : array();
	$busy = $box && mc_is_busy($email, $st);
	mc_head($email);
	if(mc_get('saved') === '1') mc_note('Settings saved.', 'ok');
	if(mc_get('closed') === '1') mc_note('Check closed. Nothing was moved, and those messages will not be offered again.', 'ok');
	if(mc_get('cancelled') === '1') mc_note('Cancelled before it started. Nothing was checked.', 'ok');
	if(mc_is_paused()) mc_note('<i class="fas fa-pause-circle me-1"></i>The server administrator has paused AI Mail Cleaner. Jobs will wait until it is resumed.', 'warn');

	$folders_all = mc_dove_folders($email);
	$prefix = mc_dove_prefix($folders_all);
	$disp = function($f) use ($prefix){ return mc_folder_display($f, $prefix); };

	if($box){
		mc_card('<i class="fas fa-envelope me-2"></i>'.h($email), h(mc_ai_summary()));
		if($busy){
			$p = $st['progress'] ?? array();
			$pct = !empty($p['total']) ? (int)floor(100 * (int)$p['done'] / max(1, (int)$p['total'])) : 0;
			echo '<div id="mcprog"><div class="d-flex justify-content-between" style="font-size:14px;font-weight:500"><span id="mcphase">'
				.h(mc_queued($email) ? 'Waiting for the server to start (up to a minute)…' : 'Working…').'</span><span id="mccount" class="mc-faint"></span></div>'
				.'<div class="mc-bar"><span id="mcbar" style="width:'.$pct.'%"></span></div>';
			$stoppable = in_array($st['kind'] ?? '', array('scan', 'recheck', 'sort'), true);
			if($stoppable){
				$asked = mc_stop_requested($email) || mc_get('stopping') === '1';
				echo '<form method="post" action="?act=stop" class="mc-stoprow" id="mcstopf">';
				mc_tok_field();
				echo '<input type="hidden" name="box" value="'.h($email).'">'
					.'<button class="flat-butt-primary btn-sm" type="submit" id="mcstop"'.($asked ? ' disabled' : '').'><i class="fas fa-stop-circle me-1"></i>'
					.($asked ? 'Stopping after this batch…' : (mc_queued($email) ? 'Cancel' : 'Stop and review what is done')).'</button>'
					.'<span class="hint">'.(($st['kind'] ?? '') === 'scan' ? 'Everything checked so far is kept. The rest waits for the next check.' : 'Everything finished so far is kept.').'</span></form>';
			}
			echo ''
				.'<details class="mc-logwrap"><summary class="mc-faint">Show details</summary><pre id="mclog" style="max-height:260px;margin-top:8px">…</pre></details></div>';
		}else{
			if(($st['state'] ?? '') === 'failed') mc_note('<b>The last job failed:</b> '.h($st['error'] ?? 'unknown error'), 'err');
			$wait = mc_waiting_scan($email);
			if($wait !== ''){
				$wr = mc_db_run($wait);
				$wt = $wr ? $wr['totals'] : array();
				echo '<div class="mc-wait"><div class="mc-wait-t"><i class="fas fa-inbox me-2"></i>A check is waiting for you</div>'
					.'<div class="mc-wait-s">'.(int)($wt['move'] ?? 0).' look like spam, '.(int)($wt['review'] ?? 0).' unsure. Nothing has moved yet.</div>'
					.'<div class="mc-wait-a"><a class="flat-butt" href="?act=run&amp;box='.h(rawurlencode($email)).'&amp;run='.h(rawurlencode($wait)).'">Review</a> ';
				mc_dismiss_form($email, $wait, 'Close without changes');
				echo '</div></div>';
			}
			echo '<div class="mc-checkrow"><form method="post" action="?act=scan">';
			mc_tok_field();
			echo '<input type="hidden" name="box" value="'.h($email).'">'
				.'<button class="'.($wait !== '' ? 'flat-butt-primary' : 'flat-butt').'" type="submit"><i class="fas fa-search me-1"></i>Check now</button>'
				.'<label class="mc-lim">the newest <input class="form-control" type="number" name="limit" id="mclimit" min="1" max="50000" step="1" value="500" list="mclims" placeholder="all"> messages</label>'
				.'<datalist id="mclims"><option value="50"><option value="100"><option value="250"><option value="500"><option value="1000"><option value="2000"></datalist>'
				.'</form>'
				.'<div class="hint mt-0"><span id="mcunchk">Counting messages not checked yet…</span> Folders: '.h(implode(', ', array_map($disp, $box['folders']))).'. Leave the number empty to check them all. You can stop a check at any time and review what is done.</div></div>';
		}

		$runs = mc_db_runs($email, 8);
		if($runs){
			echo '<h3 class="mc-h3">Recent activity</h3><ul class="mc-acts">';
			$kinds = array('scan' => 'Check', 'apply' => 'Applied', 'undo' => 'Undo', 'restore' => 'Put back', 'recheck' => 'Checked again with AI', 'sort' => 'Folder suggestions');
			foreach($runs as $r){
				$t = $r['totals'];
				if($r['kind'] === 'scan'){
					if((int)($t['looked'] ?? 0) === 0 && $r['state'] !== 'failed') $res = 'nothing new';
					else $res = (int)($t['looked'] ?? 0).' checked: '.(int)($t['move'] ?? 0).' spam, '.(int)($t['review'] ?? 0).' unsure'
						.(!empty($t['moved']) ? ', '.(int)$t['moved'].' moved' : '').(!empty($t['filed']) ? ', '.(int)$t['filed'].' filed' : '')
						.(!empty($t['dismissed']) && empty($t['moved']) && empty($t['filed']) ? ', closed without changes' : '');
				}elseif($r['kind'] === 'recheck') $res = (int)($t['looked'] ?? 0).' checked again, '.(int)($t['changed'] ?? 0).' changed';
				elseif($r['kind'] === 'sort') $res = (int)($t['existing'] ?? 0).' fit a folder, '.(int)($t['new'] ?? 0).' new folder, '.(int)($t['leave'] ?? 0).' stay';
				elseif($r['kind'] === 'apply') $res = (int)($t['moved'] ?? 0).' to spam, '.(int)($t['filed'] ?? 0).' into folders'.(!empty($t['failed']) ? ', '.(int)$t['failed'].' failed' : '');
				else $res = (int)($t['restored'] ?? 0).' put back'.(!empty($t['failed']) ? ', '.(int)$t['failed'].' failed' : '');
				$state = $r['state'] === 'review' ? ' '.mc_pill('mc-pill-warn', 'fa-inbox', 'Waiting for you')
					: ($r['state'] === 'failed' ? ' '.mc_pill('mc-pill-fail', 'fa-times-circle', 'Failed') : ($r['state'] === 'working' ? ' '.mc_pill('mc-pill-run', 'fa-circle-notch fa-spin', 'Running') : ''));
				$link = $r['kind'] === 'scan' ? $r['id'] : ($r['parent'] ?: '');
				$href = ($link !== '' && $r['kind'] !== 'restore' && $r['kind'] !== 'undo' && !($r['kind'] === 'scan' && $res === 'nothing new')) ? '?act=run&amp;box='.h(rawurlencode($email)).'&amp;run='.h(rawurlencode($link)) : '';
				echo '<li><span class="mc-acts-w">'.mc_rel($r['started']).'</span><span class="mc-acts-k">'.h($kinds[$r['kind']] ?? $r['kind'])
					.($r['trig'] === 'auto' ? ' <span class="mc-tag">automatic</span>' : '').'</span><span class="mc-acts-r">'.h($res).$state
					.($r['error'] ? '<span class="mc-line-err d-block">'.h($r['error']).'</span>' : '').'</span>'
					.($href !== '' ? '<a class="mc-acts-o" href="'.$href.'">Open <i class="fas fa-chevron-right"></i></a>' : '<span class="mc-acts-o"></span>').'</li>';
			}
			echo '</ul>';
		}
		mc_card_end();
	}

	// ---- settings (folded away once the mailbox is set up)
	$b = $box ?: mc_box_defaults($email, $user);
	$rf_short = $b['review_folder'] !== '' ? $disp($b['review_folder']) : 'AI-Spam-Review';
	$auto_txt = array('off' => 'automatic checks off', 'label' => 'checks new mail every '.(int)$b['auto_every'].' min', 'move' => 'moves spam automatically every '.(int)$b['auto_every'].' min');
	if($box){
		echo '<details class="mc-more mc-settings"'.(mc_get('saved') === '1' ? ' open' : '').'><summary><span class="mc-more-t"><i class="fas fa-sliders-h me-1"></i>Settings</span>'
			.'<span class="mc-more-s">'.h(implode(', ', array_map($disp, $b['folders']))).' &middot; spam goes to '.h($rf_short).' &middot; '.h($auto_txt[$b['auto']] ?? '').'</span></summary><div class="mc-more-body">';
	}else{
		mc_card('<i class="fas fa-sliders-h me-2"></i>Set up '.h($email), 'Nothing is checked or moved until you press "Check now" after saving.');
	}
	echo '<form method="post" action="?act=save">';
	mc_tok_field();
	echo '<input type="hidden" name="box" value="'.h($email).'">';

	echo '<div class="row"><div class="col-md-6"><label class="form-label">Check these folders</label><div class="mc-folders">';
	foreach($folders_all as $f){
		if($f === $b['review_folder']) continue;
		$id = 'f'.substr(md5($f), 0, 8);
		echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="folders[]" value="'.h($f).'" id="'.$id.'"'.(in_array($f, $b['folders'], true) ? ' checked' : '').'>'
			.'<label class="form-check-label" for="'.$id.'">'.h($disp($f)).'</label></div>';
	}
	echo '</div><div class="hint">Usually just the Inbox.</div></div>'
		.'<div class="col-md-6"><label class="form-label" for="rf">Spam goes to this folder</label>'
		.'<input class="form-control" id="rf" name="review_folder" value="'.h($rf_short).'" maxlength="60" pattern="[A-Za-z0-9 _-]{1,60}">'
		.'<div class="hint">Created if it does not exist. Nothing is ever deleted.</div>';

	echo '<label class="form-label mt-3">Automatic checks</label>';
	foreach(array('off' => 'Off: only when I press "Check now"', 'label' => 'Check new mail and list it for me',
		'move' => 'Check new mail and move confident spam without asking') as $v => $l){
		echo '<div class="form-check"><input class="form-check-input mc-auto" type="radio" name="auto" value="'.$v.'" id="au'.$v.'"'.($b['auto'] === $v ? ' checked' : '').'>'
			.'<label class="form-check-label" for="au'.$v.'">'.h($l).'</label></div>';
	}
	echo '<div id="ackmove" class="mc-confirm" style="display:none"><div class="form-check"><input class="form-check-input" type="checkbox" name="ack_move" value="1" id="ackm">'
		.'<label class="form-check-label" for="ackm">I understand spam will be moved without asking me. I can undo each run.</label></div></div>'
		.'<div class="d-flex align-items-center mt-2" style="gap:8px"><label class="form-label mb-0" for="ae">Every</label><select class="form-select form-control form-select-sm" id="ae" name="auto_every" style="width:auto">';
	foreach(array(15 => '15 minutes', 30 => '30 minutes', 60 => 'hour', 180 => '3 hours', 720 => '12 hours', 1440 => 'day') as $v => $l){
		echo '<option value="'.$v.'"'.((int)$b['auto_every'] === $v ? ' selected' : '').'>'.h($l).'</option>';
	}
	echo '</select></div><div class="hint">Only mail arriving after you switch this on.</div></div></div>';

	// advanced
	echo '<details class="mc-more mc-adv"><summary><span class="mc-more-t">Advanced</span><span class="mc-more-s">how strict, never-move list, email summary</span></summary><div class="mc-more-body">'
		.'<div class="row">'
		.'<div class="col-md-4"><label class="form-label" for="sl">SpamAssassin "unsure" from</label><input class="form-control" id="sl" name="sa_low" type="number" step="0.5" min="0" max="20" value="'.h($b['sa_low']).'">'
		.'<div class="hint">Below this score: looks fine.</div></div>'
		.'<div class="col-md-4"><label class="form-label" for="sh">SpamAssassin "certainly spam" from</label><input class="form-control" id="sh" name="sa_high" type="number" step="0.5" min="1" max="50" value="'.h($b['sa_high']).'">'
		.'<div class="hint">At or above: spam without asking the AI.</div></div>'
		.'<div class="col-md-4"><label class="form-label" for="mc">AI must be at least this sure</label><select class="form-select form-control" id="mc" name="ai_min_conf">';
	foreach(array('0.7' => '70%', '0.8' => '80% (recommended)', '0.9' => '90%', '0.95' => '95%') as $v => $l){
		echo '<option value="'.$v.'"'.(abs((float)$b['ai_min_conf'] - (float)$v) < 0.001 ? ' selected' : '').'>'.h($l).'</option>';
	}
	echo '</select><div class="hint">Less sure answers go to "Unsure".</div></div></div>'
		.'<div class="mt-3"><div class="form-check"><input class="form-check-input" type="radio" name="ai_scope" value="unsure" id="as1"'.($b['ai_scope'] !== 'all' ? ' checked' : '').'>'
		.'<label class="form-check-label" for="as1">Ask the AI only when SpamAssassin is unsure <span class="mc-faint">(cheapest)</span></label></div>'
		.'<div class="form-check"><input class="form-check-input" type="radio" name="ai_scope" value="all" id="as2"'.($b['ai_scope'] === 'all' ? ' checked' : '').'>'
		.'<label class="form-check-label" for="as2">Also ask about mail SpamAssassin thinks is fine <span class="mc-faint">(catches more phishing, more AI calls)</span></label></div></div>'
		.'<div class="row mt-3"><div class="col-md-6"><label class="form-label" for="al">Never move mail from</label><textarea class="form-control mono" id="al" name="allow" rows="3" placeholder="boss@example.com&#10;@bank.example">'
		.h(implode("\n", $b['allow'])).'</textarea><div class="hint">One per line: an address, or @domain.</div></div>'
		.'<div class="col-md-6"><label class="form-label" for="ne">Email me after an automatic check finds something</label>'
		.'<input class="form-control" id="ne" name="notify_email" type="email" value="'.h($b['notify_email']).'" placeholder="you@example.com (optional)"></div></div>'
		.'</div></details>';

	echo '<div class="mc-cta"><button class="flat-butt" type="submit"><i class="fas fa-save me-1"></i>'.($box ? 'Save settings' : 'Save and continue').'</button></div></form>';
	if($box){
		echo '<form method="post" action="?act=remove" class="mt-3" onsubmit="return confirm(\'Stop using AI Mail Cleaner for this mailbox? No mail is touched.\')">';
		mc_tok_field();
		echo '<input type="hidden" name="box" value="'.h($email).'"><button class="mc-linkbtn" type="submit"'.($busy ? ' disabled' : '').'>'
			.'Stop using AI Mail Cleaner for this mailbox</button></form>';
		echo '</div></details>';
	}else{
		mc_card_end();
	}

	$js = '<script>(function(){
var r=document.querySelectorAll(".mc-auto"),a=document.getElementById("ackmove");
function t(){var on=false;for(var i=0;i<r.length;i++)if(r[i].checked&&r[i].value==="move")on=true;if(a)a.style.display=on?"block":"none";}
for(var i=0;i<r.length;i++)r[i].addEventListener("change",t);t();
})();</script>';
	if($box && !$busy){
		// "N not checked yet", and what the newest-N box will do with it
		$js .= '<script>(function(){
var el=document.getElementById("mcunchk"),lim=document.getElementById("mclimit");if(!el)return;var n=null,all=0;
function fmt(x){return Number(x).toLocaleString();}
function show(){if(n===null)return;if(n===0){el.innerHTML="<b>Nothing new</b> to check ("+fmt(all)+" messages, all checked before).";return;}
 var v=parseInt(lim&&lim.value,10),k=(v>0&&v<n)?v:n;
 el.innerHTML="<b>"+fmt(n)+"</b> not checked yet (of "+fmt(all)+"). This check will look at <b>"+(k<n?"the newest "+fmt(k):"all "+fmt(n))+"</b>"+(k<n?"; the rest wait for the next check.":".");}
fetch("?act=count&box='.rawurlencode($email).'",{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){
 if(!d.ok){el.textContent="";return;}n=d.unchecked;all=d.total;show();}).catch(function(){el.textContent="";});
if(lim)lim.addEventListener("input",show);
})();</script>';
	}
	if($busy){
		$js .= '<script>(function(){
var url="?act=poll&box='.rawurlencode($email).'",log=document.getElementById("mclog"),bar=document.getElementById("mcbar"),
cnt=document.getElementById("mccount"),ph=document.getElementById("mcphase");
var names={classifying:"Checking messages",rechecking:"Checking again with AI",sorting:"Finding the right folders",filing:"Moving messages into folders",moving:"Moving spam",restoring:"Putting messages back",queued:"Waiting for the server to start (up to a minute)…",starting:"Starting…"};
function step(){fetch(url,{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){
 if(d.log){log.textContent=d.log;log.scrollTop=log.scrollHeight;}
 var p=d.progress||{};if(p.total){bar.style.width=Math.floor(100*p.done/p.total)+"%";cnt.textContent=p.done+" of "+p.total;}
 if(p.phase&&names[p.phase])ph.textContent=names[p.phase];
 if(d.state==="working"||d.queued){setTimeout(step,2000);return;}
 if(d.view&&d.state!=="failed"){location.href="?act=run&box='.rawurlencode($email).'&run="+encodeURIComponent(d.view);}else{location.reload();}
}).catch(function(){setTimeout(step,4000);});}
step();})();</script>';
	}
	mc_foot($js);
	exit;
}

if($act === 'run'){
	list($email, $box) = mc_require_box(mc_get('box'));
	if(!$box) mc_redirect('?act=box&box='.rawurlencode($email));
	$run_id = mc_get('run');
	$run = mc_require_run($run_id, $email);
	if($run['kind'] !== 'scan') mc_redirect('?act=box&box='.rawurlencode($email));
	$busy = mc_is_busy($email);
	$t = $run['totals'];
	$db = mc_db();
	$T = mc_targets_for($email, $box);
	$rf = $t['review_folder'] ?? $box['review_folder'];
	$rf_short = mc_folder_display($rf, $T['prefix']);
	$pend = mc_pending_new($email, $box, $run_id);
	$fd = function($f) use ($T){ return mc_folder_display($f, $T['prefix']); };

	$counts = array();
	foreach(array('move', 'review', 'keep', 'done') as $k){
		$c = $db->prepare('SELECT COUNT(*) FROM msgs WHERE run = ? AND box = ? AND '.mc_tab_where($k));
		$c->execute(array($run_id, $email));
		$counts[$k] = (int)$c->fetchColumn();
	}
	$open = $counts['move'] + $counts['review'] + $counts['keep'];
	$plan = mc_plan_counts($run_id, $email);
	$nsug = $db->prepare("SELECT COUNT(*) FROM msgs WHERE run = ? AND box = ? AND action IS NULL AND sug_kind IN ('existing','new')");
	$nsug->execute(array($run_id, $email));
	$nsug = (int)$nsug->fetchColumn();
	$undoable = $db->prepare("SELECT COUNT(*) FROM msgs WHERE run = ? AND box = ? AND action IN ('moved','filed')");
	$undoable->execute(array($run_id, $email));
	$undoable = (int)$undoable->fetchColumn();

	$tab = mc_get('tab');
	if(mc_tab_where($tab) === null) $tab = $counts['move'] ? 'move' : ($counts['review'] ? 'review' : ($counts['keep'] ? 'keep' : 'done'));
	$f = mc_filter_in($_GET);
	$filtered = ($f['q'] !== '' || $f['fc'] !== '' || $f['fs'] !== '');
	list($where, $par) = mc_filter($run_id, $email, $tab, $f);
	$c = $db->prepare('SELECT COUNT(*) FROM msgs WHERE '.$where);
	$c->execute($par);
	$nmatch = (int)$c->fetchColumn();
	$per = (int)mc_get('pp');
	if(!in_array($per, array(100, 250, 500), true)) $per = 100;
	$pages = max(1, (int)ceil($nmatch / $per));
	$page = min($pages, max(1, (int)mc_get('p')));
	$q = $db->prepare('SELECT * FROM msgs WHERE '.$where.' ORDER BY uid DESC LIMIT '.$per.' OFFSET '.(($page - 1) * $per));
	$q->execute($par);
	$rows = $q->fetchAll();

	// facet counts for the filter dropdowns (within the tab)
	$fc_counts = array(); $fs_counts = array();
	if($tab !== 'done'){
		$g = $db->prepare("SELECT CASE WHEN pick = 1 THEN '__spam' WHEN dest IS NULL OR dest = '' THEN '__leave' ELSE dest END AS k, COUNT(*) AS n
			FROM msgs WHERE run = ? AND box = ? AND ".mc_tab_where($tab).' GROUP BY k ORDER BY n DESC');
		$g->execute(array($run_id, $email));
		foreach($g->fetchAll() as $r) $fc_counts[$r['k']] = (int)$r['n'];
	}
	$g = $db->prepare("SELECT CASE WHEN sug_kind IS NULL THEN '__none' WHEN sug_kind = 'leave' THEN '__leave' ELSE sug_folder END AS k, COUNT(*) AS n
		FROM msgs WHERE run = ? AND box = ? AND ".mc_tab_where($tab).' GROUP BY k ORDER BY n DESC');
	$g->execute(array($run_id, $email));
	foreach($g->fetchAll() as $r) $fs_counts[$r['k']] = (int)$r['n'];

	$base = '?act=run&amp;box='.h(rawurlencode($email)).'&amp;run='.h(rawurlencode($run_id));
	$here = $base.'&amp;tab='.h($tab).h(mc_filter_qs($f)).($per !== 100 ? '&amp;pp='.$per : '');
	$hidden = '<input type="hidden" name="box" value="'.h($email).'"><input type="hidden" name="run" value="'.h($run_id).'">';
	$fhidden = '<input type="hidden" name="tab" value="'.h($tab).'"><input type="hidden" name="q" value="'.h($f['q']).'"><input type="hidden" name="fc" value="'.h($f['fc']).'"><input type="hidden" name="fs" value="'.h($f['fs']).'">';

	// <option>s: pending new folders first, then the mailbox's folders
	$folder_opts = function($sel) use ($T, $pend, $fd){
		$o = '';
		foreach($pend as $full) $o .= '<option value="'.h($full).'"'.($sel === $full ? ' selected' : '').'>New folder: '.h($fd($full)).'</option>';
		foreach($T['targets'] as $disp => $full) $o .= '<option value="'.h($full).'"'.($sel === $full ? ' selected' : '').'>'.h($disp).'</option>';
		return $o;
	};
	$label_of = function($k) use ($fd, $T, $pend){
		if($k === '__spam') return 'Spam folder';
		if($k === '__leave') return 'Leave where it is';
		if($k === '__none') return 'Not asked';
		return (in_array($k, $T['all'], true) ? '' : 'New: ').$fd($k);
	};

	// quick-action data: the Archive folder, and folders already used in this check ("recent")
	$arch = mc_archive_folder($email, $box);
	$rq = $db->prepare("SELECT dest, COUNT(*) AS n FROM msgs WHERE run = ? AND box = ? AND pick = 0 AND dest IS NOT NULL AND dest <> '' AND dest <> ? GROUP BY dest ORDER BY n DESC LIMIT 3");
	$rq->execute(array($run_id, $email, $arch));
	$recent = array();
	foreach($rq->fetchAll() as $r) $recent[$r['dest']] = $fd($r['dest']);
	$dl_names = array_keys($T['targets']);
	foreach($pend as $pf) $dl_names[] = $fd($pf);
	$qa = function($v, $m, $label, $cur, $cls = '', $title = '') {
		return '<button type="button" class="mc-qa '.$cls.($cur === $m ? ' on' : '').'" data-v="'.h($v).'" data-m="'.h($m).'"'.($title !== '' ? ' title="'.h($title).'"' : '').'>'.$label.'</button>';
	};

	mc_head($email);
	mc_card('<i class="fas fa-clipboard-check me-2"></i>Review: '.h($email).($run['trig'] === 'auto' ? ' <span class="mc-tag">automatic</span>' : ''),
		'Checked '.(int)($t['looked'] ?? 0).' messages in '.h(implode(', ', array_map($fd, $t['folders'] ?? $box['folders'])))
		.' &middot; '.mc_rel($run['started']));

	if(mc_get('set') !== '') mc_note('Updated '.(int)mc_get('set').' message(s). Nothing has moved yet; press <b>Apply</b> when you are done.', 'ok');
	elseif($run['state'] === 'review' && $open) echo '<div class="mc-lead">Nothing has moved yet. Pick what happens to each message (tick several to change them at once), then press <b>Apply</b>.</div>';
	elseif(!empty($t['dismissed']) && $open) echo '<div class="mc-lead">You closed this check without changes. You can still pick messages and apply.</div>';
	if(!empty($t['left_for_next'])) mc_note((!empty($t['stopped']) ? 'This check was stopped early. ' : '')
		.number_format((int)$t['left_for_next']).' older messages were not checked yet. They are untouched; press "Check now" later to continue with them.', 'info');

	// ---- tabs
	$tabs = array('move' => array('Spam', 'fa-ban'), 'review' => array('Unsure', 'fa-question-circle'), 'keep' => array('Looks fine', 'fa-check'), 'done' => array('Done', 'fa-history'));
	echo '<div class="mc-tabs">';
	foreach($tabs as $k => $l){
		echo '<a class="'.($k === $tab ? 'on' : '').'" href="'.$base.'&amp;tab='.$k.'"><i class="fas '.$l[1].' me-1"></i>'.h($l[0]).' <span class="n">'.$counts[$k].'</span></a>';
	}
	echo '</div>';

	$can_choose = $tab !== 'done' && !$busy;

	// ---- filter bar (GET)
	if($counts[$tab] > 0){
		echo '<form method="get" class="mc-filters"><input type="hidden" name="act" value="run"><input type="hidden" name="box" value="'.h($email).'">'
			.'<input type="hidden" name="run" value="'.h($run_id).'"><input type="hidden" name="tab" value="'.h($tab).'">'
			.'<div class="mc-search"><i class="fas fa-search"></i><input class="form-control form-control-sm" type="search" name="q" value="'.h($f['q']).'" placeholder="Sender or subject"></div>';
		if($tab !== 'done'){
			echo '<select class="form-select form-control form-select-sm" name="fc" title="What each message is set to"><option value="">Set to: anything</option>';
			foreach($fc_counts as $k => $n) echo '<option value="'.h($k).'"'.($f['fc'] === $k ? ' selected' : '').'>Set to: '.h($label_of($k)).' ('.$n.')</option>';
			if($f['fc'] !== '' && !isset($fc_counts[$f['fc']])) echo '<option value="'.h($f['fc']).'" selected>Set to: '.h($label_of($f['fc'])).' (0)</option>';
			echo '</select>';
		}
		if(count($fs_counts) > 1 || !isset($fs_counts['__none'])){
			echo '<select class="form-select form-control form-select-sm" name="fs" title="What the AI suggested"><option value="">AI suggested: anything</option>';
			foreach($fs_counts as $k => $n) echo '<option value="'.h($k).'"'.($f['fs'] === $k ? ' selected' : '').'>AI suggested: '.h($label_of($k)).' ('.$n.')</option>';
			echo '</select>';
		}
		echo '<select class="form-select form-control form-select-sm" name="pp" title="Messages per page">';
		foreach(array(100, 250, 500) as $n) echo '<option value="'.$n.'"'.($per === $n ? ' selected' : '').'>'.$n.' per page</option>';
		echo '</select><button class="flat-butt-primary btn-sm" type="submit">Filter</button>'
			.($filtered ? '<a class="mc-clear" href="'.$base.'&amp;tab='.h($tab).'">Clear</a>' : '').'</form>';
	}

	// ---- selection bar (POST)
	if($rows && ($can_choose || ($tab === 'done' && !$busy && $undoable))){
		echo '<form method="post" action="?act='.($tab === 'done' ? 'putback' : 'bulk').'" class="mc-selbar" id="mcsel">';
		mc_tok_field();
		echo $hidden.$fhidden.'<input type="hidden" name="ids" id="mcids" value=""><input type="hidden" name="scope" id="mcscope" value="ids">'
			.'<label class="mc-selall"><input class="form-check-input" type="checkbox" id="mcall"> <span id="mcselt">Select all on this page</span></label>'
			.($nmatch > count($rows) ? '<a href="#" id="mcallmatch" class="mc-allmatch" style="display:none">Select all '.number_format($nmatch).' matching</a>' : '')
			.'<span class="mc-selact" id="mcselact">';
		if($tab === 'done'){
			echo '<button class="flat-butt-primary btn-sm" type="submit" onclick="return confirm(\'Put the selected messages back where they came from?\')"><i class="fas fa-undo me-1"></i>Put back selected</button>';
		}else{
			echo '<span class="mc-selact-l">Selected:</span>'
				.'<button class="mc-qa mc-qa-spam" type="submit" name="v" value="__spam"><i class="fas fa-ban"></i>Spam</button>'
				.'<button class="mc-qa mc-qa-keep" type="submit" name="v" value="__leave"><i class="fas fa-inbox"></i>Keep</button>'
				.'<button class="mc-qa mc-qa-arch" type="submit" name="v" value="__archive"><i class="fas fa-archive"></i>Archive</button>'
				.($nsug ? '<button class="mc-qa mc-qa-ai" type="submit" name="v" value="__ai" title="Each message goes where the AI suggested"><i class="fas fa-magic"></i>AI\'s pick</button>' : '')
				.'<span class="mc-fbox"><i class="fas fa-folder"></i><input class="form-control form-control-sm" type="text" name="newname" id="mcnew" list="mcfolders" maxlength="80" placeholder="Folder name, new or existing" autocomplete="off"></span>'
				.'<button class="flat-butt btn-sm" type="submit" name="v" value="__new" id="mcbmove">Move</button>';
		}
		echo '</span></form>';
	}

	// ---- messages
	if(!$rows){
		echo '<div class="mc-empty"><i class="fas fa-inbox fa-2x mb-2"></i><div class="mc-empty-t">'.($filtered ? 'No messages match these filters' : 'Nothing here').'</div></div>';
	}else{
		if($filtered) echo '<div class="hint mt-0 mb-2">'.number_format($nmatch).' message(s) match.</div>';
		$selectable = $can_choose || ($tab === 'done' && !$busy);
		echo '<div class="mc-msgs">';
		foreach($rows as $m){
			$v = ($m['v1_verdict'] !== null && $m['v1_verdict'] !== 'error') ? array($m['v1_verdict'], $m['v1_conf'], $m['v1_reason']) : null;
			$v2 = ($m['v2_verdict'] !== null && $m['v2_verdict'] !== 'error') ? array($m['v2_verdict'], $m['v2_conf'], $m['v2_reason']) : null;
			$addr = mc_addr($m['sender']);
			$can_sel = $selectable && ($tab !== 'done' || in_array($m['action'], array('moved', 'filed'), true));
			echo '<div class="mc-msg">'
				.($selectable ? '<div class="mc-msg-sel">'.($can_sel ? '<input class="form-check-input mc-sel" type="checkbox" value="'.(int)$m['id'].'" aria-label="Select this message">' : '').'</div>' : '')
				.'<div class="mc-msg-main">'
				.'<div class="mc-msg-subj">'.h($m['subject'] !== '' ? $m['subject'] : '(no subject)').'</div>'
				.'<div class="mc-msg-from">'.($addr !== '' ? '<a href="'.$base.'&amp;tab='.h($tab).'&amp;q='.h(rawurlencode($addr)).'" title="Show only mail from this sender">'.h($m['sender']).'</a>' : h($m['sender']))
				.' <span class="mc-dot">&middot;</span> '.h(mc_short_date($m['mdate'])).'</div>'
				.'<div class="mc-msg-why">';
			if($v) echo mc_verdict_tag($v[0], $v[1]).' '.h($v[2] !== '' ? $v[2] : mc_why($m['why']));
			else echo '<span class="mc-faint">'.h(mc_why($m['why'])).'</span>';
			if($v2) echo '<div class="mt-1">'.mc_verdict_tag($v2[0], $v2[1]).' <span class="mc-faint">second opinion:</span> '.h($v2[2]).'</div>';
			if($m['sa'] !== null) echo ' <span class="mc-sa" title="SpamAssassin score">SA '.h(number_format((float)$m['sa'], 1)).'</span>';
			echo '</div>';
			if($m['sug_kind'] !== null && $m['action'] === null){
				echo '<div class="mc-msg-sug"><i class="fas fa-folder-open me-1"></i>'
					.($m['sug_kind'] === 'leave' ? 'AI: best left where it is' : 'AI suggests '.($m['sug_kind'] === 'new' ? 'a new folder ' : '').'<b>'.h($fd((string)$m['sug_folder'])).'</b>')
					.($m['sug_reason'] ? ' <span class="mc-faint">— '.h($m['sug_reason']).'</span>' : '').'</div>';
			}
			echo '</div><div class="mc-msg-act">';
			if($m['action'] === null){
				$cur = (int)$m['pick'] === 1 ? '__spam' : (($m['dest'] ?? '') !== '' ? $m['dest'] : '__leave');
				$sug = ($m['sug_kind'] === 'existing' || $m['sug_kind'] === 'new') && $m['sug_folder'] && $m['sug_folder'] !== $arch ? $m['sug_folder'] : '';
				$chips = array($arch => 1);
				if($sug !== '') $chips[$sug] = 1;
				echo '<div class="mc-qas'.($can_choose ? '' : ' off').'" data-id="'.(int)$m['id'].'" data-cur="'.h($cur).'">'
					.$qa('__spam', '__spam', '<i class="fas fa-ban"></i>Spam', $cur, 'mc-qa-spam', 'Move to '.$rf_short)
					.$qa('__leave', '__leave', '<i class="fas fa-inbox"></i>Keep', $cur, 'mc-qa-keep', 'Leave it where it is')
					.$qa('__archive', $arch, '<i class="fas fa-archive"></i>Archive', $cur, 'mc-qa-arch', 'Move to '.$fd($arch).(in_array($arch, $T['all'], true) ? '' : ' (created when you apply)'))
					.($sug !== '' ? $qa($sug, $sug, '<i class="fas fa-magic"></i>'.h($fd($sug)), $cur, 'mc-qa-ai', 'The AI suggests this folder'.($m['sug_kind'] === 'new' ? ' (new)' : '')) : '');
				foreach($recent as $rfull => $rlabel){
					if(isset($chips[$rfull])) continue;
					echo $qa($rfull, $rfull, '<i class="fas fa-redo-alt"></i>'.h($rlabel), $cur, 'mc-qa-rec', 'Used for other messages in this check');
					$chips[$rfull] = 1;
				}
				$custom = ($cur !== '__spam' && $cur !== '__leave' && !isset($chips[$cur])) ? $fd($cur) : '';
				echo '<span class="mc-fbox"><i class="fas fa-folder"></i><input class="form-control form-control-sm mc-fin'.($custom !== '' ? ' on' : '').'" type="text" list="mcfolders" maxlength="80" value="'.h($custom).'" placeholder="Other folder…" autocomplete="off" aria-label="Type a folder name"></span>'
					.'</div>';
			}else{
				$st = array('moved' => 'In '.$rf_short, 'filed' => 'Filed in '.$fd((string)$m['filed_to']), 'restored' => 'Back in '.$fd($m['folder']),
					'error' => 'Problem', 'gone' => 'No longer in the mailbox');
				echo '<div class="mc-done-st mc-done-'.h($m['action']).'">'.h($st[$m['action']] ?? $m['action']).'</div>';
				if($m['err']) echo '<div class="mc-faint" style="font-size:12px">'.h($m['err']).'</div>';
				if(in_array($m['action'], array('moved', 'filed'), true)){
					echo '<form method="post" action="?act=restore" class="mt-1">';
					mc_tok_field();
					echo $hidden.'<input type="hidden" name="msg" value="'.(int)$m['id'].'"><button class="flat-butt-primary btn-sm" type="submit"'.($busy ? ' disabled' : '').' title="Move it back to '.h($fd($m['folder'])).'">'
						.'<i class="fas fa-undo me-1"></i>Put back</button></form>';
				}
			}
			echo '</div></div>';
		}
		echo '</div><datalist id="mcfolders">';
		foreach(array_unique($dl_names) as $n) echo '<option value="'.h($n).'">';
		echo '</datalist>';
		if($pages > 1){
			echo '<div class="mc-pager">Page';
			for($i = 1; $i <= $pages; $i++){
				if($pages > 12 && $i > 2 && $i < $pages - 1 && abs($i - $page) > 2){ if($i === 3 || $i === $pages - 2) echo ' …'; continue; }
				echo $i === $page ? ' <b>'.$i.'</b>' : ' <a href="'.$here.'&amp;p='.$i.'">'.$i.'</a>';
			}
			echo '</div>';
		}
	}

	// ---- AI tools (folded away)
	if(!$busy && $open && mc_ai_on()){
		$nfile = $db->prepare('SELECT COUNT(*) FROM msgs WHERE run = ? AND box = ? AND action IS NULL AND pick = 0');
		$nfile->execute(array($run_id, $email));
		$nfile = (int)$nfile->fetchColumn();
		$nu = $counts['move'] + $counts['review'];
		$ak = $db->prepare("SELECT COUNT(*) FROM msgs WHERE run = ? AND box = ? AND action IS NULL AND decision = 'keep' AND (why IS NULL OR why <> 'allow_list')");
		$ak->execute(array($run_id, $email));
		$nall = $nu + (int)$ak->fetchColumn();
		$fmt = function($c){ return $c < 0.01 ? 'under $0.01' : 'about $'.number_format($c, 2); };
		echo '<details class="mc-more"'.(mc_get('tools') === '1' ? ' open' : '').'><summary><span class="mc-more-t"><i class="fas fa-magic me-1"></i>AI tools</span>'
			.'<span class="mc-more-s">sort into folders, or check the spam verdicts again</span></summary><div class="mc-more-body">';
		if($nfile > 0){
			echo '<div class="mc-tool"><div><b>Suggest folders</b><div class="hint mt-0">The AI picks one of your folders for each message not going to spam ('.$nfile.', '.$fmt(mc_recheck_cost($nfile)).'), or proposes a new folder. Its picks are pre-selected; nothing moves until you press Apply.</div></div>'
				.'<form method="post" action="?act=sort">';
			mc_tok_field();
			echo $hidden.'<button class="flat-butt-primary btn-sm" type="submit"><i class="fas fa-folder-open me-1"></i>Suggest folders</button></form></div>';
		}
		if($nall > 0){
			echo '<div class="mc-tool"><div><b>Check again for spam</b><div class="hint mt-0">Asks the AI again whether these are spam. The lists above are updated.</div></div>'
				.'<form method="post" action="?act=recheck" class="d-flex flex-wrap" style="gap:8px">';
			mc_tok_field();
			echo $hidden.'<select class="form-select form-control form-select-sm" name="scope" style="width:auto;max-width:100%">'
				.($nu > 0 ? '<option value="unsure">Spam + unsure ('.$nu.', '.$fmt(mc_recheck_cost($nu)).')</option>' : '')
				.'<option value="all">Everything not moved ('.$nall.', '.$fmt(mc_recheck_cost($nall)).')</option></select>'
				.'<button class="flat-butt-primary btn-sm" type="submit"><i class="fas fa-redo me-1"></i>Check again</button></form></div>';
		}
		echo '</div></details>';
	}

	// ---- action bar
	echo '<div class="mc-sticky">';
	if($busy){
		echo '<span class="hint mt-0">A job is running for this mailbox. <a href="?act=box&amp;box='.h(rawurlencode($email)).'">Watch it</a>.</span>';
	}else{
		if($open){
			echo '<div class="mc-plan" id="mcplan">'.mc_plan_text($plan, $rf_short).'</div>'
				.'<form method="post" action="?act=apply" id="mcapply" onsubmit="return confirm(document.getElementById(\'mcplan\').textContent + \'\\n\\nNothing is deleted, and you can put messages back later.\')">';
			mc_tok_field();
			echo $hidden.'<button class="flat-butt" type="submit" id="mcapplyb"'.(($plan['spam'] + $plan['file']) ? '' : ' disabled').'><i class="fas fa-check me-1"></i>Apply</button></form>';
			if($run['state'] === 'review') mc_dismiss_form($email, $run_id, 'Close without changes');
			echo '<a class="flat-butt-primary" href="?act=box&amp;box='.h(rawurlencode($email)).'">Later</a>';
		}else{
			echo '<div class="mc-plan">All messages in this check are handled.</div>';
		}
		if($undoable > 0){
			echo '<form method="post" action="?act=undo" onsubmit="return confirm(\'Put all '.$undoable.' moved or filed messages from this check back where they were?\')">';
			mc_tok_field();
			echo $hidden.'<button class="flat-butt-primary" type="submit"><i class="fas fa-undo me-1"></i>Undo all ('.$undoable.')</button></form>';
		}
		if(!$open) echo '<a class="flat-butt-primary" href="?act=box&amp;box='.h(rawurlencode($email)).'"><i class="fas fa-arrow-left me-1"></i>Back to mailbox</a>';
	}
	echo '</div>';
	mc_card_end();

	$js = '<script>(function(){
var tok='.json_encode($TOK).',box='.json_encode($email).',run='.json_encode($run_id).',rf='.json_encode($rf_short).',nmatch='.(int)$nmatch.';
function plan(d){var p=[];if(d.spam)p.push(d.spam+" to "+rf);if(d.file)p.push(d.file+" into "+d.folders+" folder"+(d.folders===1?"":"s"));
 return p.length?"Apply will move "+p.join(" and ")+". Everything else stays where it is.":"Nothing is set to move yet.";}
function setPlan(d){var p=document.getElementById("mcplan");if(p)p.textContent=plan(d);var b=document.getElementById("mcapplyb");if(b)b.disabled=!(d.spam+d.file);}
/* row quick actions: Spam / Keep / Archive / AI pick / recent folders / type a folder */
function mark(g,cur,label){g.setAttribute("data-cur",cur);var hit=false;
 g.querySelectorAll(".mc-qa").forEach(function(b){var on=b.getAttribute("data-m")===cur;b.classList.toggle("on",on);if(on)hit=true;});
 var i=g.querySelector(".mc-fin");if(i){var custom=(cur!=="__spam"&&cur!=="__leave"&&!hit);i.classList.toggle("on",custom);if(document.activeElement!==i||!custom)i.value=custom?label:"";i.setAttribute("data-last",i.value);}
 var row=g.closest(".mc-msg");if(row){row.classList.toggle("to-spam",cur==="__spam");row.classList.toggle("to-folder",cur!=="__spam"&&cur!=="__leave");}}
function addRecent(val,label){if(!val||val==="__spam"||val==="__leave")return;
 var dl=document.getElementById("mcfolders");
 if(dl&&!Array.prototype.some.call(dl.options,function(o){return o.value===label;})){var o=document.createElement("option");o.value=label;dl.appendChild(o);}
 document.querySelectorAll(".mc-qas").forEach(function(g){
  if(g.querySelector(".mc-qa[data-m=\""+CSS.escape(val)+"\"]"))return;
  var b=document.createElement("button");b.type="button";b.className="mc-qa mc-qa-rec";b.setAttribute("data-v",val);b.setAttribute("data-m",val);
  b.title="Used for other messages in this check";b.innerHTML="<i class=\"fas fa-redo-alt\"></i>";b.appendChild(document.createTextNode(label));
  g.insertBefore(b,g.querySelector(".mc-fbox"));
  var recs=g.querySelectorAll(".mc-qa-rec:not(.on)");if(g.querySelectorAll(".mc-qa-rec").length>3&&recs.length)g.removeChild(recs[0]);});}
function choose(g,v,name){if(!g||g.classList.contains("off"))return;
 var fd=new FormData();fd.append("tok",tok);fd.append("box",box);fd.append("run",run);fd.append("id",g.getAttribute("data-id"));fd.append("v",v);if(name!==undefined)fd.append("name",name);
 g.classList.add("busy");
 fetch("?act=choose",{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){g.classList.remove("busy");
  if(!d.ok){alert(d.error||"Could not save that.");var i=g.querySelector(".mc-fin");if(i)i.value=i.getAttribute("data-last")||"";return;}
  if(v==="__new")addRecent(d.value,d.label);
  mark(g,d.value,d.label);setPlan(d);
 }).catch(function(){g.classList.remove("busy");alert("Could not save that. Reload the page.");});}
document.addEventListener("click",function(e){var b=e.target.closest?e.target.closest(".mc-qas .mc-qa"):null;if(!b)return;e.preventDefault();
 var g=b.closest(".mc-qas");if(g.getAttribute("data-cur")===b.getAttribute("data-m"))return;choose(g,b.getAttribute("data-v"));});
function commit(i){var v=i.value.trim();if(v===""||v===i.getAttribute("data-last"))return;i.setAttribute("data-last",v);choose(i.closest(".mc-qas"),"__new",v);}
document.addEventListener("keydown",function(e){var i=e.target;if(!i.classList||!i.classList.contains("mc-fin"))return;
 if(e.key==="Enter"){e.preventDefault();commit(i);}else if(e.key==="Escape"){i.value=i.getAttribute("data-last")||"";i.blur();}});
document.addEventListener("change",function(e){var i=e.target;if(i.classList&&i.classList.contains("mc-fin"))commit(i);});
document.addEventListener("input",function(e){var i=e.target;if(i.classList&&i.classList.contains("mc-fin")&&e.inputType==="insertReplacementText")commit(i);});
document.querySelectorAll(".mc-qas").forEach(function(g){var i=g.querySelector(".mc-fin");mark(g,g.getAttribute("data-cur"),i?i.value:"");});
/* selection */
var form=document.getElementById("mcsel");if(!form)return;
var all=document.getElementById("mcall"),txt=document.getElementById("mcselt"),am=document.getElementById("mcallmatch"),
 ids=document.getElementById("mcids"),scope=document.getElementById("mcscope"),act=document.getElementById("mcselact"),
 nw=document.getElementById("mcnew"),last=null,subv=null;
function boxes(){return Array.prototype.slice.call(document.querySelectorAll(".mc-sel"));}
function upd(){var b=boxes(),n=0;b.forEach(function(x){if(x.checked)n++;});
 if(scope.value==="all"){txt.textContent="All "+nmatch.toLocaleString()+" matching messages selected";if(am){am.textContent="Clear selection";am.style.display="";}}
 else{txt.textContent=n?n+" selected":"Select all on this page";if(am){am.textContent="Select all "+nmatch.toLocaleString()+" matching";am.style.display=(n===b.length&&n>0)?"":"none";}}
 all.checked=n>0&&n===b.length;all.indeterminate=n>0&&n<b.length;
 act.classList.toggle("on",n>0||scope.value==="all");
 document.querySelectorAll(".mc-msg").forEach(function(r){var x=r.querySelector(".mc-sel");r.classList.toggle("sel",!!(x&&x.checked));});}
all.addEventListener("change",function(){boxes().forEach(function(x){x.checked=all.checked;});scope.value="ids";upd();});
if(am)am.addEventListener("click",function(e){e.preventDefault();if(scope.value==="all"){scope.value="ids";boxes().forEach(function(x){x.checked=false;});}else{scope.value="all";boxes().forEach(function(x){x.checked=true;});}upd();});
function pick(x,shift){
 if(shift&&last){var b=boxes(),i=b.indexOf(last),j=b.indexOf(x),lo=Math.min(i,j),hi=Math.max(i,j);for(var k=lo;k<=hi;k++)b[k].checked=x.checked;}
 last=x;scope.value="ids";upd();}
document.addEventListener("click",function(e){var x=e.target;
 if(x.classList&&x.classList.contains("mc-sel")){pick(x,e.shiftKey);return;}
 /* a click anywhere else on a row toggles it, except on links, dropdowns, buttons and forms */
 var row=x.closest?x.closest(".mc-msg"):null;if(!row)return;
 if(x.closest("a,button,select,option,input,label,textarea,form,.mc-msg-act"))return;
 if(window.getSelection&&String(window.getSelection())!=="")return; /* user is selecting text */
 var c=row.querySelector(".mc-sel");if(!c)return;c.checked=!c.checked;pick(c,e.shiftKey);});
/* shift-click on a row should not highlight text */
document.addEventListener("mousedown",function(e){if(e.shiftKey&&e.target.closest&&e.target.closest(".mc-msg")&&!e.target.closest("select,input,a"))e.preventDefault();});
form.addEventListener("click",function(e){var b=e.target.closest?e.target.closest("button[name=v]"):null;if(b)subv=b.value;});
if(nw)nw.addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();document.getElementById("mcbmove").click();}});
form.addEventListener("submit",function(e){var sel=boxes().filter(function(x){return x.checked;}).map(function(x){return x.value;});
 var v=(e.submitter&&e.submitter.value)||subv;
 if(scope.value!=="all"&&!sel.length){e.preventDefault();alert("Tick at least one message first (click a row).");return;}
 if(v==="__new"&&!nw.value.trim()){e.preventDefault();alert("Type a folder name first, e.g. Clients/Acme.");nw.focus();return;}
 ids.value=sel.join(",");
 var n=scope.value==="all"?nmatch:sel.length;
 if(v&&n>=50&&!confirm("Change "+n.toLocaleString()+" messages? (Nothing moves until you press Apply.)"))e.preventDefault();});
upd();})();</script>';
	mc_foot($js);
	exit;
}

mc_stop('Not found', 'That page does not exist.', 404);
