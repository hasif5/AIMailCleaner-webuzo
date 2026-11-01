<?php
/**
 * AI Mail Cleaner — job runner. Root, started by /etc/cron.d/mailcleaner every
 * minute and resident for ~50 seconds, polling the spool every 3 seconds.
 *
 * It never does mail work itself: each job is handed to a detached
 * bin/worker.php, so a scan of a big mailbox (which can take an hour) never
 * blocks other mailboxes or the next cron tick. It also queues the automatic
 * scans of mailboxes that have automatic mode switched on.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require '/usr/local/mailcleaner/lib/common.php';
require MC_BASE.'/lib/db.php';

const MC_SLOTS = 3;   // mailboxes worked on at the same time (protects a shared server)

if(posix_getuid() !== 0){ fwrite(STDERR, "runner must run as root\n"); exit(1); }
umask(0077);   // state files are root-only from the moment they exist

$g = fopen(MC_LOCKS.'/runner.lock', 'ce');
if(!$g || !flock($g, LOCK_EX | LOCK_NB)) exit(0);
@touch(MC_STATE.'/.heartbeat');

foreach(mc_enforce_state_perms() as $fix) fwrite(STDOUT, date('c').' permission drift corrected: '.$fix."\n");
mc_reconcile_stale();

$deadline = time() + 50;
$launched = array();   // slot => ts (a just-started worker may not hold its slot lock yet)
$paused_logged = false;
do {
	if(mc_is_paused()){
		if(!$paused_logged){ fwrite(STDOUT, date('c')." paused by the server administrator; jobs stay queued\n"); $paused_logged = true; }
		sleep(3);
		continue;
	}
	$paused_logged = false;
	mc_schedule_auto();

	foreach(glob(MC_SPOOL.'/*.json') ?: array() as $jobfile){
		$box = basename($jobfile, '.json');
		if($box !== '_test' && !mc_valid_email($box)){ @unlink($jobfile); continue; }
		if($box !== '_test' && mc_box_locked($box)) continue;   // a worker is on it; job waits
		$slot = mc_free_slot($launched);
		if($slot === null) break;
		$claim = MC_SPOOL.'/.claim-'.bin2hex(random_bytes(6)).'.job';
		if(!@rename($jobfile, $claim)) continue;
		$argv = array('/usr/bin/setsid', '-f', PHP_BINARY, MC_BASE.'/bin/worker.php', $claim, (string)$slot);
		$log = '/var/webuzo/logs/mailcleaner';
		$p = @proc_open($argv, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $log, 'a'), 2 => array('file', $log, 'a')), $pipes, '/');
		if(is_resource($p)){
			proc_close($p);
			$launched[$slot] = time();
			fwrite(STDOUT, date('c').' started '.$box.' job in slot '.$slot."\n");
		}else{
			@rename($claim, $jobfile);   // could not start: put it back
		}
	}
	sleep(3);
} while(time() < $deadline);
exit(0);

function mc_box_locked($email){
	$fh = @fopen(MC_LOCKS.'/'.$email.'.lock', 'ce');
	if(!$fh) return true;
	$free = flock($fh, LOCK_EX | LOCK_NB);
	if($free) flock($fh, LOCK_UN);
	fclose($fh);
	return !$free;
}

function mc_free_slot($launched){
	for($i = 0; $i < MC_SLOTS; $i++){
		if(isset($launched[$i]) && time() - $launched[$i] < 15) continue;
		$fh = @fopen(MC_LOCKS.'/slot-'.$i.'.lock', 'ce');
		if(!$fh) continue;
		$free = flock($fh, LOCK_EX | LOCK_NB);
		if($free) flock($fh, LOCK_UN);
		fclose($fh);
		if($free) return $i;
	}
	return null;
}

/**
 * A worker killed by a reboot or OOM leaves "working" behind. If nobody holds
 * the mailbox lock, nobody is working on it: mark the status and the run failed.
 */
function mc_reconcile_stale(){
	foreach(glob(MC_STATUS.'/*.json') ?: array() as $f){
		$email = basename($f, '.json');
		if(!mc_valid_email($email)) continue;
		$st = mc_load_json($f);
		if(!is_array($st) || ($st['state'] ?? '') !== 'working') continue;
		if(mc_box_locked($email)) continue;
		mc_write_status($email, array('state' => 'failed', 'error' => 'interrupted (server restart or the job was killed)', 'finished' => time()));
		if(!empty($st['run']) && mc_valid_run($st['run'])){
			mc_db_run_update($st['run'], array('state' => 'failed', 'finished' => time(), 'error' => 'interrupted'));
		}
	}
}

// Queue the incremental scan of every automatic mailbox that is due.
function mc_schedule_auto(){
	static $last = 0;
	if(time() - $last < 20) return;
	$last = time();
	foreach(mc_all_boxes() as $email => $b){
		if($b['auto'] === 'off') continue;
		$st = mc_status($email);
		if(mc_queued($email) || ($st['state'] ?? '') === 'working') continue;
		$every = max(5, min(1440, (int)$b['auto_every'])) * 60;
		if(time() - (int)($st['last_auto'] ?? 0) < $every) continue;
		mc_queue_job($email, 'scan', array('trigger' => 'auto', 'incremental' => true));
		// stamp now so a failing mailbox is not re-queued every 20 seconds
		mc_write_status($email, array('last_auto' => time()));
	}
}
