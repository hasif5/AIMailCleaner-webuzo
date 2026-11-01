<?php
/**
 * AI Mail Cleaner — Dovecot access, always through doveadm (root, -u <mailbox>).
 *
 * Mail files are never opened directly: doveadm handles compressed mail, keeps
 * Dovecot's indexes consistent (so webmail sees moves at once) and runs every
 * operation as the mailbox's own system user.
 *
 * Every function takes an already-validated email (mc_valid_email) and folder
 * (mc_valid_folder); arguments go to doveadm as separate argv entries, never
 * through a shell.
 */

// Folder list, e.g. ["INBOX", "INBOX.Junk", ...]
function mc_dove_folders($email){
	$rc = mc_exec(array(MC_DOVEADM, 'mailbox', 'list', '-u', $email), $out, $err, null, 60);
	if($rc !== 0) return array();
	$list = array();
	foreach(preg_split('~\r?\n~', trim($out)) as $f){
		$f = trim($f);
		if(mc_valid_folder($f)) $list[] = $f;
	}
	return $list;
}

/**
 * Hierarchy separator + personal namespace prefix as this mailbox shows them.
 * Webuzo's Dovecot uses "INBOX." (Maildir++); fall back to that.
 */
function mc_dove_prefix($folders){
	foreach($folders as $f){
		if(strpos($f, 'INBOX.') === 0) return 'INBOX.';
		if(strpos($f, 'INBOX/') === 0) return 'INBOX/';
	}
	foreach($folders as $f){
		if(strpos($f, '/') !== false) return '';
	}
	return 'INBOX.';
}

// ['uidvalidity' => int, 'uidnext' => int, 'messages' => int] or null
function mc_dove_status($email, $folder){
	$rc = mc_exec(array(MC_DOVEADM, '-f', 'json', 'mailbox', 'status', '-u', $email, 'uidvalidity uidnext messages', $folder), $out, $err, null, 60);
	if($rc !== 0) return null;
	$j = json_decode($out, true);
	if(!is_array($j) || !isset($j[0])) return null;
	return array('uidvalidity' => (int)$j[0]['uidvalidity'], 'uidnext' => (int)$j[0]['uidnext'], 'messages' => (int)$j[0]['messages']);
}

/**
 * Header summary of every message with uid >= $from_uid in a folder.
 * One doveadm call: ~12s for 18,000 messages on this server.
 */
function mc_dove_headers($email, $folder, $from_uid = 1){
	$fields = 'uid guid size.physical date.received hdr.message-id hdr.date hdr.from hdr.subject hdr.x-spam-score hdr.x-spam-status flags';
	$q = array('mailbox', $folder, 'uid', max(1, (int)$from_uid).':*');
	$rc = mc_exec(array_merge(array(MC_DOVEADM, '-f', 'json', 'fetch', '-u', $email, $fields), $q), $out, $err, null, 900);
	if($rc !== 0) return null;
	$j = json_decode($out, true);
	if(!is_array($j)) {
		// doveadm JSON can carry raw 8-bit header bytes; repair and retry once.
		$j = json_decode(mb_convert_encoding($out, 'UTF-8', 'UTF-8'), true);
		if(!is_array($j)) return null;
	}
	$rows = array();
	foreach($j as $m){
		$uid = (int)($m['uid'] ?? 0);
		// uid N:* always returns the highest message even when N is past it
		if($uid < $from_uid || $uid <= 0 || empty($m['guid'])) continue;
		if(strpos((string)($m['flags'] ?? ''), '\\Deleted') !== false) continue;
		$rows[] = array(
			'uid'     => $uid,
			'guid'    => (string)$m['guid'],
			'size'    => (int)($m['size.physical'] ?? 0),
			'msgid'   => mc_one_line($m['hdr.message-id'] ?? '', 250),
			'mdate'   => mc_one_line(($m['hdr.date'] ?? '') !== '' ? $m['hdr.date'] : ($m['date.received'] ?? ''), 64),
			'sender'  => mc_one_line(mc_decode_header($m['hdr.from'] ?? ''), 250),
			'subject' => mc_one_line(mc_decode_header($m['hdr.subject'] ?? ''), 300),
			'sa'      => mc_sa_from_headers($m['hdr.x-spam-score'] ?? '', $m['hdr.x-spam-status'] ?? ''),
		);
	}
	return $rows;
}

/**
 * Raw RFC 822 message (or its first $max bytes). doveadm's pager format prints
 * "text:\n" followed by the message.
 */
function mc_dove_raw($email, $folder, $uid, $max = 2097152){
	$rc = mc_exec(array(MC_DOVEADM, '-f', 'pager', 'fetch', '-u', $email, 'text', 'mailbox', $folder, 'uid', (string)(int)$uid), $out, $err, null, 120);
	if($rc !== 0 || strncmp($out, "text:\n", 6) !== 0) return null;
	$raw = substr($out, 6);
	if(strlen($raw) > $max) $raw = substr($raw, 0, $max);
	return $raw;
}

// Create (and subscribe) a folder. Already-exists is fine.
function mc_dove_ensure_folder($email, $folder){
	foreach(mc_dove_folders($email) as $f){ if($f === $folder) return true; }
	mc_exec(array(MC_DOVEADM, 'mailbox', 'create', '-u', $email, '-s', $folder), $out, $err, null, 60);
	foreach(mc_dove_folders($email) as $f){ if($f === $folder) return true; }
	return false;
}

/**
 * Move a set of uids from one folder to another. Returns the uids that are
 * verifiably gone from the source afterwards (doveadm move exits 0 even when a
 * uid did not match, so we check rather than trust the exit code).
 */
function mc_dove_move_uids($email, $from, $to, array $uids, &$err){
	$err = '';
	$uids = array_values(array_unique(array_map('intval', $uids)));
	if(!$uids) return array();
	$set = implode(',', $uids);
	$rc = mc_exec(array(MC_DOVEADM, 'move', '-u', $email, $to, 'mailbox', $from, 'uid', $set), $out, $e, null, 600);
	if($rc !== 0) $err = trim($e) !== '' ? trim($e) : 'doveadm move exit '.$rc;
	// Which of them are still in the source folder?
	$still = array();
	$rc2 = mc_exec(array(MC_DOVEADM, '-f', 'json', 'fetch', '-u', $email, 'uid', 'mailbox', $from, 'uid', $set), $o2, $e2, null, 300);
	if($rc2 === 0){
		foreach((json_decode($o2, true) ?: array()) as $m) $still[(int)$m['uid']] = true;
	}else{
		return array();   // cannot verify: report nothing moved, caller marks errors
	}
	$moved = array();
	foreach($uids as $u){ if(!isset($still[$u])) $moved[] = $u; }
	return $moved;
}

/**
 * Where is the message with this GUID now? Returns array(folder, uid) or null.
 * Dovecot keeps a maildir message's GUID across moves (verified on this server),
 * which is what makes "undo" exact.
 */
function mc_dove_find_guid($email, $guid, $folder = null){
	$q = $folder !== null ? array('mailbox', $folder, 'guid', $guid) : array('all', 'guid', $guid);
	$rc = mc_exec(array_merge(array(MC_DOVEADM, '-f', 'json', 'fetch', '-u', $email, 'mailbox uid'), $q), $out, $err, null, 120);
	if($rc !== 0) return null;
	$j = json_decode($out, true);
	if(!is_array($j) || !$j) return null;
	return array((string)$j[0]['mailbox'], (int)$j[0]['uid']);
}

// Move one message, found by GUID, into $to. Returns true when it is there afterwards.
function mc_dove_move_guid($email, $guid, $from, $to, &$err){
	$err = '';
	$rc = mc_exec(array(MC_DOVEADM, 'move', '-u', $email, $to, 'mailbox', $from, 'guid', $guid), $out, $e, null, 120);
	if($rc !== 0){ $err = trim($e) !== '' ? trim($e) : 'doveadm move exit '.$rc; }
	$where = mc_dove_find_guid($email, $guid, $to);
	return $where !== null;
}

/* ----------------------------------------------------------- header helpers */

// mc_one_line() lives in lib/common.php (the panel needs it without doveadm).


// RFC 2047 decode ("=?UTF-8?B?...?=") to UTF-8, best effort.
function mc_decode_header($v){
	$v = (string)$v;
	if(strpos($v, '=?') === false) return mb_convert_encoding($v, 'UTF-8', 'UTF-8');
	$d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
	if($d === false || $d === '') $d = @mb_decode_mimeheader($v);
	return mb_convert_encoding((string)$d, 'UTF-8', 'UTF-8');
}

// SpamAssassin score from X-Spam-Score or "score=N" inside X-Spam-Status. null if absent.
function mc_sa_from_headers($score, $status){
	$score = trim((string)$score);
	if($score !== '' && preg_match('~^[-+]?[0-9]+(\.[0-9]+)?~', $score, $m)) return (float)$m[0];
	if(preg_match('~score=([-+]?[0-9]+(?:\.[0-9]+)?)~', (string)$status, $m)) return (float)$m[1];
	return null;
}

/* ----------------------------------------------------------- folder targets */

// Hierarchy separator for a personal prefix ("INBOX." -> ".", "INBOX/" or "" -> "/").
function mc_dove_sep($prefix){ return $prefix === '' ? '/' : substr($prefix, -1); }

// "INBOX.Amazon Web Services.AWS Support" -> "Amazon Web Services/AWS Support"
function mc_folder_display($full, $prefix){
	if($full === 'INBOX') return 'Inbox';
	$rest = ($prefix !== '' && strpos($full, $prefix) === 0) ? substr($full, strlen($prefix)) : $full;
	$sep = mc_dove_sep($prefix);
	return $sep === '/' ? $rest : str_replace($sep, '/', $rest);
}

/**
 * Folders a message may be filed into: the owner's own folders under the personal
 * prefix, minus the Inbox itself, the spam review folder, and Sent / Drafts /
 * Trash / Junk / Spam (and anything inside them).
 * Returns display name => full name, in mailbox order.
 */
function mc_sort_targets(array $all, $review){
	$prefix = mc_dove_prefix($all);
	$sep = mc_dove_sep($prefix);
	$out = array();
	foreach($all as $f){
		if($f === 'INBOX' || $f === $review) continue;
		if($prefix !== '' && strpos($f, $prefix) !== 0) continue;            // other namespaces (virtual labels)
		$disp = mc_folder_display($f, $prefix);
		$top = strtolower(explode('/', $disp)[0]);
		if(preg_match('~^(sent|sent items|sent messages|drafts?|trash|deleted|deleted items|deleted messages|junk|junk e-?mail|spam|bulk mail|outbox)$~', $top)) continue;
		if($review !== '' && strpos($f, $review.$sep) === 0) continue;
		$out[$disp] = $f;
	}
	return $out;
}

/**
 * Turn an AI-proposed folder name ("Amazon Web Services/Billing") into a safe full
 * folder name, or '' if it cannot be made safe. At most 2 levels, 40 characters a
 * level; letters (any language), digits, space, & - _ ' ( ) only.
 */
function mc_folder_from_display($disp, array $all){
	$prefix = mc_dove_prefix($all);
	$sep = mc_dove_sep($prefix);
	$parts = array();
	foreach(explode('/', (string)$disp) as $p){
		$p = preg_replace('~[^\p{L}\p{N} &\-_\'()]+~u', ' ', $p);
		$p = trim(preg_replace('~\s+~u', ' ', (string)$p));
		if($p === '') continue;
		if(function_exists('mb_substr')) $p = mb_substr($p, 0, 40, 'UTF-8'); else $p = substr($p, 0, 40);
		$parts[] = $p;
	}
	if(!$parts || count($parts) > 2) return '';
	if(preg_match('~^(inbox|sent|drafts?|trash|junk|spam)$~i', $parts[0])) return '';
	$full = $prefix.implode($sep, $parts);
	return mc_valid_folder($full) ? $full : '';
}
