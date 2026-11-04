<?php
/**
 * AI Mail Cleaner — server administrator page (Webuzo admin panel, ports 2004/2005).
 * Served by EMPS nginx via fpm-root.sock: THIS RUNS AS ROOT (PHP 7.4).
 *
 * Holds the server-wide AI settings: API keys for Anthropic (Claude) and Google
 * (Gemini), model choice, spend caps. Only the server owner (root) may use it —
 * the keys are billed to whoever owns them, so resellers get no access.
 * API keys are write-only here: the page shows a masked form and never sends a
 * stored key back to the browser.
 */

error_reporting(0);

include_once('/usr/local/webuzo/sdk/sessions.php');
require '/usr/local/mailcleaner/lib/common.php';
require MC_BASE.'/lib/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function mc_post($k){ return isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : ''; }
function mc_get($k){ return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : ''; }

function mc_refuse($title, $msg, $code = 403){
	http_response_code($code);
	header('Content-Type: text/html; charset=UTF-8');
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
		.'<title>AI Mail Cleaner</title><link rel="stylesheet" href="/themes/default/bootstrap/css/bootstrap.min.css">'
		.'</head><body><div style="max-width:640px;margin:40px auto;padding:0 18px">'
		.'<h1 style="font-size:20px;margin-bottom:10px">AI Mail Cleaner — '.h($title).'</h1>'
		.'<div class="alert alert-warning" role="alert">'.h($msg).'</div></div></body></html>';
	exit;
}

$sess = new Webuzo_Sessions();
if(!method_exists($sess, 'is_admin_panel') || !$sess->is_admin_panel()){
	mc_refuse('Not available here', 'This page only runs in the administrator panel (port 2004/2005). Hosting users open AI Mail Cleaner from their own panel.');
}
$logged_in = $sess->isLogin();
if(empty($logged_in)) $sess->show_login();
$user = isset($sess->user['user']) ? (string)$sess->user['user'] : '';
if($user !== 'root'){
	mc_refuse('No access', 'AI settings and API keys are server-wide, so only the server owner (root) can manage them.');
}

$csrf_key = trim((string)@file_get_contents(MC_STATE.'/.csrf_key'));
if($csrf_key === '') mc_refuse('Not set up yet', 'Run /usr/local/mailcleaner/install.sh --repair on the server.', 500);
$TOK = hash_hmac('sha256', 'admin|'.(string)(isset($sess->SESS['sid']) ? $sess->SESS['sid'] : ''), $csrf_key);
function mc_tok_field(){ global $TOK; echo '<input type="hidden" name="tok" value="'.h($TOK).'">'; }

function mc_redirect($qs){ header('Location: '.$qs); echo '<a href="'.h($qs).'">Continue</a>'; exit; }

$act = mc_get('act');
$is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/* ------------------------------------------------------------------ POST */

if($is_post){
	if(!hash_equals($TOK, mc_post('tok'))) mc_refuse('Stale form', 'Reload the page and try again.', 403);
	$s = mc_settings();

	if($act === 'save'){
		$provs = array('anthropic', 'gemini');
		foreach($provs as $p){
			$k = trim(mc_post($p.'_key'));
			if(mc_post($p.'_clear') === '1') $s[$p.'_key'] = '';
			elseif($k !== ''){
				// keys are opaque, but they go into an HTTP header: printable ASCII only
				if(!preg_match('~^[\x21-\x7e]{10,300}$~', $k)) mc_refuse('Bad key', 'The '.$p.' API key contains characters that cannot be right. Paste it again.', 400);
				$s[$p.'_key'] = $k;
			}
			$m = trim(mc_post($p.'_model'));
			if($m === '_custom') $m = trim(mc_post($p.'_model_custom'));
			if(mc_valid_model_id($m)) $s[$p.'_model'] = $m;
			$pin = mc_post($p.'_price_in'); $pout = mc_post($p.'_price_out');
			if(is_numeric($pin) && is_numeric($pout) && (float)$pin >= 0 && (float)$pout >= 0){
				list($din, $dout) = mc_price(array('prices' => array()), $s[$p.'_model']);
				if(abs((float)$pin - $din) > 1e-9 || abs((float)$pout - $dout) > 1e-9) $s['prices'][$s[$p.'_model']] = array((float)$pin, (float)$pout);
				else unset($s['prices'][$s[$p.'_model']]);
			}
			$cap = mc_post('cap_'.$p);
			if(is_numeric($cap)) $s['cap_'.$p] = max(0.0, min(100000.0, (float)$cap));
		}
		$e = mc_post('anthropic_effort');
		if(in_array($e, array('low', 'medium', 'high'), true)) $s['anthropic_effort'] = $e;
		$g = mc_post('gemini_thinking');
		if(in_array($g, array('low', 'medium', 'high'), true)) $s['gemini_thinking'] = $g;
		$pr = mc_post('primary');
		// Claude can be the main AI only while its key is saved; Gemini is the default.
		$s['primary'] = ($pr === 'anthropic' && $s['anthropic_key'] !== '') ? 'anthropic' : 'gemini';
		$se = mc_post('second');
		if(in_array($se, array('other', 'none'), true)) $s['second'] = $se === 'none' ? 'none' : mc_ai_other($s['primary']);
		$sw = mc_post('second_when');
		if(in_array($sw, array('unsure', 'always'), true)) $s['second_when'] = $sw;
		$ub = (float)mc_post('unsure_below');
		if(in_array($ub, array(0.7, 0.8, 0.9, 0.95), true)) $s['unsure_below'] = $ub;
		$pc = (int)mc_post('preview_chars');
		if($pc >= 300 && $pc <= 6000) $s['preview_chars'] = $pc;
		$cc = (int)mc_post('concurrency');
		if($cc >= 1 && $cc <= 16) $s['concurrency'] = $cc;
		$mx = (int)mc_post('max_per_run');
		if($mx >= 50 && $mx <= 50000) $s['max_per_run'] = $mx;
		if(!mc_save_settings($s)) mc_refuse('Could not save', 'Saving the settings failed.', 500);
		mc_redirect('?act=&saved=1');
	}
	if($act === 'test'){
		$p = mc_post('provider');
		if(!in_array($p, array('anthropic', 'gemini'), true)) mc_redirect('?');
		$s['test'][$p] = array('pending' => true, 'ts' => time());
		mc_save_settings($s);
		mc_queue_job('_test', 'test', array('provider' => $p));
		mc_redirect('?act=&testing='.$p);
	}
	if($act === 'pause'){ mc_set_paused(true, 'root'); mc_redirect('?'); }
	if($act === 'resume'){ mc_set_paused(false); mc_redirect('?'); }
	mc_redirect('?');
}

/* ------------------------------------------------------------------ page */

$s = mc_settings();
$known = mc_known_models();

header('Content-Type: text/html; charset=UTF-8');
$v = (int)@filemtime(MC_BASE.'/web/enduser/assets/style.css');
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
	.'<title>AI Mail Cleaner — admin</title>'
	.'<link rel="stylesheet" href="/themes/default/bootstrap/css/bootstrap.min.css">'
	.'<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700'
	.'&amp;family=Poppins:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">'
	.'<link rel="stylesheet" href="/themes/default/css/style.css">'
	.'<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">'
	.'<link rel="stylesheet" href="assets/style.css?v='.$v.'">'
	// the admin theme lacks the enduser theme's button/card rules; see the file header
	.'<link rel="stylesheet" href="assets/admin-compat.css?v='.(int)@filemtime(MC_BASE.'/web/enduser/assets/admin-compat.css').'">';
$pending = (!empty($s['test']['anthropic']['pending']) || !empty($s['test']['gemini']['pending']));
if($pending) echo '<meta http-equiv="refresh" content="4;url=?">';
echo '<script>if(window.self!==window.top)document.documentElement.className="embedded";</script></head><body><div class="mc-wrap">'
	.'<div class="mc-head d-flex align-items-center mb-4 mc-brand"><img src="assets/mailcleaner_48.png" alt="" width="40" height="40" class="me-3">'
	.'<div><h1 class="panel-head mb-0">AI Mail Cleaner</h1><div class="soft-card-desc">Server settings &middot; version '.h(mc_version()).'</div></div></div>';

function card($title, $sub = ''){
	echo '<div class="card soft-card mb-4"><div class="row align-items-center panel-heading px-4 py-3"><div class="col-12"><h5 class="mb-0 panel-head" style="font-size:16px">'
		.$title.'</h5>'.($sub !== '' ? '<div class="mc-sub">'.$sub.'</div>' : '').'</div></div><div class="card-body px-4 py-3">';
}
function card_end(){ echo '</div></div>'; }
function note($html, $type = 'info'){
	$map = array('info' => 'alert-info', 'ok' => 'alert-success', 'warn' => 'alert-warning', 'err' => 'alert-danger');
	echo '<div class="alert '.$map[$type].' mc-note" role="alert">'.$html.'</div>';
}

if(mc_get('saved') === '1') note('Settings saved.', 'ok');
$hb = (int)@filemtime(MC_STATE.'/.heartbeat');
if($hb === 0 || time() - $hb > 180) note('<b>The background runner has not run for a while</b> ('.($hb ? h(date('Y-m-d H:i', $hb)) : 'never').'). Checks will not start. Run <code>/usr/local/mailcleaner/install.sh --repair</code>.', 'err');

// ---- pause
if(mc_is_paused()){
	note('<i class="fas fa-pause-circle me-1"></i><b>Paused.</b> No checks, moves or automatic runs start until you resume. Queued jobs wait.', 'warn');
}
echo '<form method="post" action="?act='.(mc_is_paused() ? 'resume' : 'pause').'" class="mb-3">';
mc_tok_field();
echo '<button class="flat-butt-primary btn-sm" type="submit"><i class="fas '.(mc_is_paused() ? 'fa-play' : 'fa-pause').' me-1"></i>'.(mc_is_paused() ? 'Resume AI Mail Cleaner' : 'Pause AI Mail Cleaner on the whole server').'</button></form>';

// ---- AI settings
card('<i class="fas fa-robot me-2"></i>AI providers', 'Keys are stored root-only on this server and are never shown again after saving.');
echo '<form method="post" action="?act=save" autocomplete="off">';
mc_tok_field();

if($s['gemini_key'] === '') note('<b>Gemini API key required.</b> Gemini is the default AI that checks mail. Until its key is saved, only SpamAssassin is used (and Claude is not asked either, unless you make Claude the main AI below).', 'warn');

$role = function($p) use ($s){
	if($s['primary'] === $p) return '<span class="mc-pill mc-pill-run"><span>main AI</span></span>';
	if($s['second'] === $p && $s[$p.'_key'] !== '') return '<span class="mc-pill"><span>second opinion</span></span>';
	return '';
};
$provinfo = array(
	'gemini'    => array('Google Gemini <span class="mc-pill"><span>required &middot; default</span></span> '.$role('gemini'), 'aistudio.google.com → Get API key (use a project with billing turned on: on the free tier Google may use what you send to improve its products)', 'gemini_thinking', 'Thinking level', array('low' => 'low', 'medium' => 'medium (default)', 'high' => 'high')),
	'anthropic' => array('Anthropic Claude <span class="mc-pill"><span>optional</span></span> '.$role('anthropic'), 'console.anthropic.com → API keys. Leave empty to use Gemini alone.', 'anthropic_effort', 'Effort', array('low' => 'low', 'medium' => 'medium', 'high' => 'high (recommended)')),
);
foreach($provinfo as $p => $pi){
	$key = $s[$p.'_key'];
	$model = $s[$p.'_model'];
	list($pin, $pout) = mc_price($s, $model);
	$t = $s['test'][$p] ?? array();
	echo '<h3 class="mc-h3">'.$pi[0].'</h3><div class="row">';
	echo '<div class="col-md-6"><label class="form-label" for="'.$p.'k">API key</label>'
		.'<input class="form-control mono" type="password" id="'.$p.'k" name="'.$p.'_key" placeholder="'.h($key !== '' ? 'saved: '.mc_mask_key($key).' — leave empty to keep' : 'paste the key').'" autocomplete="new-password">'
		.'<div class="hint">'.h($pi[1]).'</div>';
	if($key !== '') echo '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="'.$p.'_clear" value="1" id="'.$p.'c"><label class="form-check-label" for="'.$p.'c">Remove the saved key</label></div>';
	echo '</div><div class="col-md-6"><label class="form-label" for="'.$p.'m">Model</label><select class="form-select form-control mc-model" id="'.$p.'m" name="'.$p.'_model" data-custom="'.$p.'mc">';
	$is_known = isset($known[$p][$model]);
	foreach($known[$p] as $id => $m){
		echo '<option value="'.h($id).'"'.($id === $model ? ' selected' : '').'>'.h($m[0]).' — '.h($id).'</option>';
	}
	echo '<option value="_custom"'.(!$is_known ? ' selected' : '').'>Other model id…</option></select>'
		.'<input class="form-control mono mt-1" id="'.$p.'mc" name="'.$p.'_model_custom" value="'.h($is_known ? '' : $model).'" placeholder="model id" style="'.($is_known ? 'display:none' : '').'">'
		.'<div class="row mt-2"><div class="col-6"><label class="form-label" for="'.$p.'e">'.h($pi[3]).'</label><select class="form-select form-control" id="'.$p.'e" name="'.$pi[2].'">';
	foreach($pi[4] as $val => $lab) echo '<option value="'.$val.'"'.($s[$pi[2]] === $val ? ' selected' : '').'>'.h($lab).'</option>';
	echo '</select></div><div class="col-6"><label class="form-label" for="cap'.$p.'">Monthly cap (USD)</label>'
		.'<input class="form-control" type="number" min="0" step="1" id="cap'.$p.'" name="cap_'.$p.'" value="'.h($s['cap_'.$p]).'"></div></div>'
		.'<div class="row mt-2"><div class="col-6"><label class="form-label" for="'.$p.'pi">$ per million input tokens</label><input class="form-control" type="number" min="0" step="0.01" id="'.$p.'pi" name="'.$p.'_price_in" value="'.h($pin).'"></div>'
		.'<div class="col-6"><label class="form-label" for="'.$p.'po">$ per million output tokens</label><input class="form-control" type="number" min="0" step="0.01" id="'.$p.'po" name="'.$p.'_price_out" value="'.h($pout).'"></div></div>'
		.'<div class="hint">Prices are only used to estimate spend for the cap and the table below. Check your provider\'s price list.</div></div></div>';
	// test result
	echo '<div class="mt-2">';
	if(!empty($t['pending'])) echo '<span class="mc-pill mc-pill-run"><i class="fas fa-circle-notch fa-spin"></i><span>Testing… (up to a minute)</span></span>';
	elseif(!empty($t['ts'])){
		if(!empty($t['ok'])) echo '<span class="mc-pill mc-pill-ok"><i class="fas fa-check-circle"></i><span>Works</span></span> <span class="hint">'
			.h(date('Y-m-d H:i', (int)$t['ts'])).' · '.h($t['model']).' answered "'.h($t['verdict']).'" ('.(int)round(100 * (float)$t['confidence']).'%) in '.round(((int)$t['ms']) / 1000, 1).'s · '
			.(int)($t['tokens']['in'] ?? 0).' in / '.(int)($t['tokens']['out'] ?? 0).' out tokens'.(!empty($t['tokens']['cache']) ? ' / '.(int)$t['tokens']['cache'].' cached' : '').'</span>';
		else echo '<span class="mc-pill mc-pill-fail"><i class="fas fa-times-circle"></i><span>Test failed</span></span> <span class="hint">'.h(date('Y-m-d H:i', (int)$t['ts'])).' · '.h($t['error'] ?? '').'</span>';
	}
	echo '</div>';
}

$main = mc_ai_name($s['primary']); $other = mc_ai_name(mc_ai_other($s['primary']));
$has_claude = $s['anthropic_key'] !== '';
echo '<h3 class="mc-h3">Which AI decides</h3><div class="row">'
	.'<div class="col-md-3"><label class="form-label" for="pr">Main AI</label><select class="form-select form-control" id="pr" name="primary">'
	.'<option value="gemini"'.($s['primary'] === 'gemini' ? ' selected' : '').'>Gemini (default)</option>'
	.'<option value="anthropic"'.($s['primary'] === 'anthropic' ? ' selected' : '').($has_claude ? '' : ' disabled').'>Claude'.($has_claude ? '' : ' (save a Claude key first)').'</option></select></div>'
	.'<div class="col-md-3"><label class="form-label" for="se">Second opinion</label><select class="form-select form-control" id="se" name="second">'
	.'<option value="other"'.($s['second'] !== 'none' ? ' selected' : '').'>yes, from the other AI</option>'
	.'<option value="none"'.($s['second'] === 'none' ? ' selected' : '').'>no, main AI only</option></select></div>'
	.'<div class="col-md-3"><label class="form-label" for="sw">Ask the second AI</label><select class="form-select form-control" id="sw" name="second_when">'
	.'<option value="unsure"'.($s['second_when'] === 'unsure' ? ' selected' : '').'>only when the main AI is unsure, says phishing, or fails</option>'
	.'<option value="always"'.($s['second_when'] === 'always' ? ' selected' : '').'>on every message (about double the calls)</option></select></div>'
	.'<div class="col-md-3"><label class="form-label" for="ub">"Unsure" means below</label><select class="form-select form-control" id="ub" name="unsure_below">';
foreach(array('0.7' => '70%', '0.8' => '80%', '0.9' => '90%', '0.95' => '95%') as $val => $lab) echo '<option value="'.$val.'"'.(abs((float)$s['unsure_below'] - (float)$val) < 0.001 ? ' selected' : '').'>'.$lab.'</option>';
echo '</select></div></div>'
	.'<div class="hint mt-2">Now: <b>'.h($main).'</b> checks every message that goes to AI and suggests folders'
	.($s['second'] !== 'none' && $s[mc_ai_other($s['primary']).'_key'] !== '' ? '; <b>'.h($other).'</b> gives the second opinion.' : '; no second opinion.')
	.' You can switch the main AI any time; it applies from the next check. When both answer, a message is only moved on its own if <b>both</b> say spam or phishing; if they disagree, the owner decides. '
	.'If the main AI fails (error, cap reached), the other one is used as a fallback.</div>';

echo '<h3 class="mc-h3">Limits</h3><div class="row">'
	.'<div class="col-md-4"><label class="form-label" for="pc">Characters of the message text sent</label><input class="form-control" type="number" min="300" max="6000" step="100" id="pc" name="preview_chars" value="'.(int)$s['preview_chars'].'"></div>'
	.'<div class="col-md-4"><label class="form-label" for="cc">AI requests at once (per mailbox)</label><input class="form-control" type="number" min="1" max="16" id="cc" name="concurrency" value="'.(int)$s['concurrency'].'"></div>'
	.'<div class="col-md-4"><label class="form-label" for="mx">Messages per check (newest first)</label><input class="form-control" type="number" min="50" max="50000" step="50" id="mx" name="max_per_run" value="'.(int)$s['max_per_run'].'"></div></div>';

echo '<div class="mc-cta"><button class="flat-butt" type="submit"><i class="fas fa-save me-1"></i>Save settings</button></div></form>';
foreach(array('gemini' => 'Gemini', 'anthropic' => 'Claude') as $p => $l){
	echo '<form method="post" action="?act=test" class="d-inline me-2 mt-2">';
	mc_tok_field();
	echo '<input type="hidden" name="provider" value="'.$p.'"><button class="flat-butt-primary btn-sm" type="submit"'.($s[$p.'_key'] === '' ? ' disabled' : '').'>'
		.'<i class="fas fa-vial me-1"></i>Test '.$l.'</button></form>';
}
echo '<div class="hint">A test sends one made-up phishing email (never real mail) with the saved settings. Save first.</div>';
card_end();

// ---- usage
card('<i class="fas fa-coins me-2"></i>AI usage', 'Estimated from token counts and the prices above. Your provider\'s bill is the real number.');
$usage = mc_db_usage(3);
if(!$usage) echo '<div class="hint">No AI calls yet.</div>';
else {
	echo '<div class="table-responsive"><table class="mc-table"><thead><tr><th>Month</th><th>Provider</th><th>Model</th><th class="num">Calls</th><th class="num">Failed</th>'
		.'<th class="num">Input tokens</th><th class="num">Output tokens</th><th class="num">Cached</th><th class="num">Est. cost</th></tr></thead><tbody>';
	foreach($usage as $month => $rows){
		foreach($rows as $r){
			$cap = (float)$s['cap_'.$r['provider']];
			echo '<tr><td>'.h($month).'</td><td>'.h($r['provider']).'</td><td class="mono">'.h($r['model']).'</td><td class="num">'.number_format($r['calls']).'</td>'
				.'<td class="num">'.number_format($r['errors']).'</td><td class="num">'.number_format($r['in_tok']).'</td><td class="num">'.number_format($r['out_tok']).'</td>'
				.'<td class="num">'.number_format($r['cache_tok']).'</td><td class="num">$'.number_format((float)$r['cost'], 2).'</td></tr>';
		}
	}
	echo '</tbody></table></div>';
	foreach(array('anthropic', 'gemini') as $p){
		$c = mc_db_month_cost($p); $cap = (float)$s['cap_'.$p];
		if($cap > 0) echo '<div class="hint">'.h(ucfirst($p)).' this month: $'.number_format($c, 2).' of $'.number_format($cap, 2).' cap'.($c >= $cap ? ' — <b>cap reached, no more calls this month</b>' : '').'</div>';
	}
}
card_end();

// ---- mailboxes
card('<i class="fas fa-inbox me-2"></i>Mailboxes using AI Mail Cleaner', 'Set up by each hosting user in their own panel.');
$boxes = mc_all_boxes();
if(!$boxes) echo '<div class="hint">None yet.</div>';
else {
	echo '<div class="table-responsive"><table class="mc-table"><thead><tr><th>Mailbox</th><th>Account</th><th>Automatic</th><th>Status</th><th>Last job</th></tr></thead><tbody>';
	foreach($boxes as $email => $b){
		$st = mc_status($email);
		$state = mc_is_busy($email, $st) ? 'working' : (($st['state'] ?? '') === 'failed' ? 'failed: '.($st['error'] ?? '') : 'idle');
		echo '<tr><td>'.h($email).'</td><td>'.h($b['user']).'</td><td>'.h($b['auto'] === 'off' ? 'off' : $b['auto'].' / '.$b['auto_every'].' min').'</td>'
			.'<td>'.h($state).'</td><td>'.(!empty($st['last']['finished']) ? h(date('Y-m-d H:i', (int)$st['last']['finished'])).' · '.h($st['last']['kind'] ?? '') : '—').'</td></tr>';
	}
	echo '</tbody></table></div>';
}
card_end();

echo '<div class="mc-powered">Powered by <a href="https://www.digitalbkk.com" target="_blank" rel="noopener">Digitalbkk Co., Ltd.</a> &middot; author Hasif Ahmed</div></div>'
	.'<script>(function(){var s=document.querySelectorAll(".mc-model");for(var i=0;i<s.length;i++){(function(el){var c=document.getElementById(el.getAttribute("data-custom"));'
	.'el.addEventListener("change",function(){c.style.display=el.value==="_custom"?"block":"none";});})(s[i]);}})();</script></body></html>';
