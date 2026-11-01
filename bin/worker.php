<?php
/**
 * AI Mail Cleaner — runs ONE job for ONE mailbox. Root CLI, started detached by
 * bin/runner.php:  worker.php <claimed-job-file> <slot>
 *
 * Holds two locks for its whole life: the mailbox lock (no two jobs on one
 * mailbox) and a concurrency slot (caps how many mailboxes work at once).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(0);
require '/usr/local/mailcleaner/lib/common.php';
require MC_BASE.'/lib/db.php';
require MC_BASE.'/lib/dove.php';
require MC_BASE.'/lib/mime.php';
require MC_BASE.'/lib/ai.php';
require MC_BASE.'/lib/engine.php';

if(posix_getuid() !== 0){ fwrite(STDERR, "worker must run as root\n"); exit(1); }
umask(0077);   // state files are root-only from the moment they exist
$claim = $argv[1] ?? '';
$slot  = (int)($argv[2] ?? 0);
if(strpos($claim, MC_SPOOL.'/') !== 0 || strpos($claim, '..') !== false || !is_file($claim)) exit(2);

$slotfh = fopen(MC_LOCKS.'/slot-'.$slot.'.lock', 'c');
if(!$slotfh || !flock($slotfh, LOCK_EX)) exit(3);

$job = mc_load_json($claim);
@unlink($claim);
if(!is_array($job)) exit(0);
$email = (string)($job['box'] ?? '');
$kind  = (string)($job['kind'] ?? '');

/* ---- admin "Test" buttons: no mailbox involved ---- */
if($email === '_test'){
	$prov = (string)($job['provider'] ?? '');
	if(!in_array($prov, array('anthropic', 'gemini'), true)) exit(0);
	$s = mc_settings();
	$res = mc_ready_or_test($prov, $s);
	$s = mc_settings();   // re-read: the admin may have saved meanwhile
	$s['test'][$prov] = $res;
	mc_save_settings($s);
	exit(0);
}

function mc_ready_or_test($prov, $s){
	if(!mc_ai_ready($prov, $s)) return array('ok' => false, 'ts' => time(), 'error' => 'no API key or model set', 'model' => mc_ai_model($prov, $s));
	try { return mc_ai_test($prov, $s); }
	catch(Throwable $t){ return array('ok' => false, 'ts' => time(), 'error' => mc_one_line($t->getMessage(), 200), 'model' => mc_ai_model($prov, $s)); }
}

$box = mc_load_box($email);
if(!$box) exit(0);

$lock = fopen(MC_LOCKS.'/'.$email.'.lock', 'c');
if(!$lock || !flock($lock, LOCK_EX | LOCK_NB)){
	// someone is already working on this mailbox: put the job back for later
	mc_save_json(MC_SPOOL.'/'.$email.'.json', $job, 0600);
	exit(0);
}

$trig = (string)($job['trigger'] ?? 'manual');
$parent = isset($job['run']) && mc_valid_run($job['run']) ? $job['run'] : null;
$kinds = array('scan' => 'scan', 'apply' => 'apply', 'undo' => 'undo', 'restore' => 'restore', 'recheck' => 'recheck', 'sort' => 'sort');
if(!isset($kinds[$kind])) exit(0);
$run = mc_new_run_id($kind);
$log = new McLog(mc_log_file($email, $run));
mc_db_run_start($run, $email, $kind, $trig, $parent);
mc_write_status($email, array('state' => 'working', 'kind' => $kind, 'run' => $run, 'started' => time(),
	'heartbeat' => time(), 'progress' => array('done' => 0, 'total' => 0, 'phase' => 'starting'), 'error' => ''));

$final = 'done'; $err = ''; $tot = array(); $view_run = $run;
try {
	if($kind === 'scan'){
		$tot = mc_scan($box, $job, $run, $log);
		$needs_look = ($tot['move'] + $tot['review']) > 0;
		if($trig === 'auto' && $box['auto'] === 'move' && $tot['move'] > 0){
			mc_db_run_update($run, array('totals' => $tot));
			$log('Automatic mode: moving now');
			$a = mc_apply($box, $run, $log);
			$tot = array_merge($tot, mc_db_run($run)['totals']);
			$final = 'done';
			if($a['moved'] > 0){
				mc_notify($box, 'AI Mail Cleaner moved '.$a['moved'].' message(s) for '.$email,
					$a['moved'].' message(s) were moved to the folder "'.$a['review_folder'].'" in '.$email.".\n"
					.($tot['review'] ? $tot['review']." more are waiting for you to look at in the panel.\n" : '')
					."\nNothing was deleted. Open AI Mail Cleaner in your hosting panel to review or undo.\n");
			}
		}else{
			$final = $needs_look ? 'review' : 'done';
			if($trig === 'auto' && $needs_look){
				mc_notify($box, 'AI Mail Cleaner: '.($tot['move'] + $tot['review']).' message(s) to review in '.$email,
					$tot['move'].' look like spam and '.$tot['review'].' need your decision in '.$email.".\n"
					."Nothing has been moved yet. Open AI Mail Cleaner in your hosting panel to review them.\n");
			}
		}
	}elseif($kind === 'apply'){
		// One "Apply": spam to the review folder, then everything else to its chosen folder.
		if(!$parent) throw new RuntimeException('no scan given');
		$c = mc_db()->prepare('SELECT COUNT(*) FROM msgs WHERE run = ? AND pick = 1 AND action IS NULL');
		$c->execute(array($parent));
		$tot = ((int)$c->fetchColumn() > 0) ? mc_apply($box, $parent, $log) : array('moved' => 0, 'failed' => 0, 'gone' => 0);
		$f = mc_file($box, $parent, $log);
		$tot['filed'] = $f['filed'];
		$tot['failed'] = (int)($tot['failed'] ?? 0) + $f['failed'];
		$tot['created'] = $f['created'];
		mc_db_run_update($parent, array('state' => 'done'));
		$view_run = $parent;
	}elseif($kind === 'sort'){
		if(!$parent) throw new RuntimeException('no check given');
		$tot = mc_sort($box, $parent, $log);
		$view_run = $parent;
	}elseif($kind === 'recheck'){
		if(!$parent) throw new RuntimeException('no check given');
		$tot = mc_recheck($box, $parent, (string)($job['scope'] ?? 'unsure') === 'all' ? 'all' : 'unsure', $log);
		$view_run = $parent;
	}elseif($kind === 'undo'){
		if(!$parent) throw new RuntimeException('no scan given');
		$tot = mc_undo($box, $parent, $log);
		$view_run = $parent;
	}elseif($kind === 'restore'){
		$tot = mc_restore_one($box, (int)($job['msg'] ?? 0), $log);
		$view_run = $parent ?: $run;
	}
} catch(Throwable $t){
	$final = 'failed';
	$err = mc_one_line($t->getMessage(), 300);
	$log('FAILED: '.$err);
	if(mc_db()->inTransaction()) mc_db()->rollBack();
}

mc_db_run_update($run, array('state' => $final, 'finished' => time(), 'totals' => $tot, 'error' => $err !== '' ? $err : null));
$patch = array('state' => $final === 'failed' ? 'failed' : 'idle', 'finished' => time(), 'error' => $err,
	'last' => array('run' => $run, 'kind' => $kind, 'view' => $view_run, 'state' => $final, 'finished' => time()));
if($kind === 'scan') $patch['last_scan'] = $run;
if($kind === 'scan' && $trig === 'auto') $patch['last_auto'] = time();
mc_write_status($email, $patch);

// keep the 40 newest logs per mailbox
$logs = glob(MC_LOGS.'/'.$email.'/*.log') ?: array();
rsort($logs);
foreach(array_slice($logs, 40) as $old) @unlink($old);
exit(0);
