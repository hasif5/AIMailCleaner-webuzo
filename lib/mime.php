<?php
/**
 * AI Mail Cleaner — minimal MIME reader. No extension needed (mailparse is not
 * in the EMPS PHP build), PHP 7.4 compatible.
 *
 * It only extracts what the classifier sends to the AI: a few headers, a short
 * plain-text body preview, the links and the attachment names. It never writes
 * anything and never executes or renders message content.
 */

/**
 * Split raw message text into [headers array, body string]. Headers are
 * lower-cased names => list of unfolded values.
 */
function mc_mime_split($raw){
	$raw = str_replace("\r\n", "\n", (string)$raw);
	$pos = strpos($raw, "\n\n");
	if($pos === false){ $head = $raw; $body = ''; }
	else { $head = substr($raw, 0, $pos); $body = substr($raw, $pos + 2); }
	$headers = array();
	$head = preg_replace("~\n[ \t]+~", ' ', $head);   // unfold
	foreach(explode("\n", $head) as $line){
		$c = strpos($line, ':');
		if($c === false || $c === 0) continue;
		$name = strtolower(trim(substr($line, 0, $c)));
		if(!preg_match('~^[a-z0-9-]{1,76}$~', $name)) continue;
		$headers[$name][] = trim(substr($line, $c + 1));
	}
	return array($headers, $body);
}

function mc_mime_h($headers, $name){
	return isset($headers[$name][0]) ? $headers[$name][0] : '';
}

// "text/plain; charset=utf-8; name=x" => ['type' => 'text/plain', 'charset' => 'utf-8', ...]
function mc_mime_params($v){
	$parts = explode(';', (string)$v);
	$out = array('type' => strtolower(trim(array_shift($parts))));
	foreach($parts as $p){
		$eq = strpos($p, '=');
		if($eq === false) continue;
		$k = strtolower(trim(substr($p, 0, $eq)));
		$val = trim(substr($p, $eq + 1));
		if(strlen($val) >= 2 && $val[0] === '"' && substr($val, -1) === '"') $val = substr($val, 1, -1);
		$k = rtrim($k, '*');   // RFC 2231 name*= — good enough for display
		if(!isset($out[$k])) $out[$k] = $val;
	}
	return $out;
}

function mc_mime_decode_body($body, $cte){
	$cte = strtolower(trim((string)$cte));
	if($cte === 'base64') return (string)base64_decode(preg_replace('~[^A-Za-z0-9+/=]~', '', $body));
	if($cte === 'quoted-printable') return quoted_printable_decode($body);
	return $body;
}

function mc_mime_to_utf8($s, $charset){
	$charset = strtolower(trim((string)$charset));
	if($charset === '' || $charset === 'utf-8' || $charset === 'us-ascii' || $charset === 'utf8'){
		return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
	}
	$r = @mb_convert_encoding($s, 'UTF-8', $charset);
	if($r === false || $r === '') { $r = @iconv($charset, 'UTF-8//IGNORE', $s); }
	return mb_convert_encoding((string)$r, 'UTF-8', 'UTF-8');
}

function mc_html_to_text($html){
	$html = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', ' ', (string)$html);
	$html = preg_replace('~<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>~i', "\n", $html);
	$text = strip_tags($html);
	return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Walk the MIME tree. Collects text parts (plain preferred over html),
 * attachment names and hrefs. Depth- and count-limited so a hostile message
 * cannot make us loop or eat memory.
 */
function mc_mime_walk($headers, $body, &$acc, $depth = 0){
	if($depth > 8 || $acc['parts'] > 200) return;
	$acc['parts']++;
	$ct = mc_mime_params(mc_mime_h($headers, 'content-type') ?: 'text/plain');
	$cd = mc_mime_params(mc_mime_h($headers, 'content-disposition'));
	$type = $ct['type'];

	if(strpos($type, 'multipart/') === 0 && !empty($ct['boundary'])){
		$b = '--'.$ct['boundary'];
		$chunks = explode("\n".$b, "\n".$body);
		array_shift($chunks);   // preamble
		foreach($chunks as $chunk){
			if(strncmp($chunk, '--', 2) === 0) break;   // closing boundary
			$chunk = ltrim(preg_replace('~^[ \t]*\n~', '', $chunk, 1), "\r");
			list($h, $bd) = mc_mime_split($chunk);
			mc_mime_walk($h, $bd, $acc, $depth + 1);
		}
		return;
	}
	if($type === 'message/rfc822'){
		list($h, $bd) = mc_mime_split($body);
		mc_mime_walk($h, $bd, $acc, $depth + 1);
		return;
	}

	$fname = '';
	if(!empty($cd['filename'])) $fname = $cd['filename'];
	elseif(!empty($ct['name'])) $fname = $ct['name'];
	$is_att = ($cd['type'] === 'attachment') || ($fname !== '' && strpos($type, 'text/') !== 0);
	if($is_att){
		if(count($acc['attachments']) < 10){
			$acc['attachments'][] = mc_one_line(mc_decode_header($fname !== '' ? $fname : '(unnamed)'), 120).' ('.$type.')';
		}
		return;
	}
	if($type === 'text/plain' || $type === 'text/html'){
		$dec = mc_mime_decode_body($body, mc_mime_h($headers, 'content-transfer-encoding'));
		$txt = mc_mime_to_utf8($dec, $ct['charset'] ?? '');
		if($type === 'text/html'){
			if(preg_match_all('~href\s*=\s*["\']?(https?://[^"\'\s>]+)~i', $txt, $m)){
				foreach($m[1] as $u) $acc['urls'][] = $u;
			}
			if($acc['html'] === '') $acc['html'] = mc_html_to_text($txt);
		}else{
			if($acc['plain'] === '') $acc['plain'] = $txt;
		}
	}
}

/**
 * Everything the classifier needs from one raw message.
 * Returns array(headers..., 'body' => preview, 'urls' => [...], 'attachments' => [...]).
 */
function mc_mime_digest($raw, $preview_chars = 1500){
	list($headers, $body) = mc_mime_split($raw);
	$acc = array('plain' => '', 'html' => '', 'urls' => array(), 'attachments' => array(), 'parts' => 0);
	mc_mime_walk($headers, $body, $acc);

	$text = $acc['plain'] !== '' ? $acc['plain'] : $acc['html'];
	if(preg_match_all('~https?://[^\s<>"\')\]]+~i', $text, $m)){
		foreach($m[0] as $u) $acc['urls'][] = $u;
	}
	$urls = array();
	foreach($acc['urls'] as $u){
		$u = mc_one_line($u, 200);
		if(!isset($urls[$u])) $urls[$u] = true;
		if(count($urls) >= 15) break;
	}
	$text = preg_replace('~[ \t\x{00a0}]+~u', ' ', (string)$text);
	$text = trim(preg_replace("~\n\s*\n+~", "\n", (string)$text));

	$hv = function($n) use ($headers){ return mc_one_line(mc_decode_header(mc_mime_h($headers, $n)), 300); };
	$received = isset($headers['received']) ? count($headers['received']) : 0;

	return array(
		'from'          => $hv('from'),
		'reply_to'      => $hv('reply-to'),
		'return_path'   => $hv('return-path'),
		'to'            => $hv('to'),
		'subject'       => $hv('subject'),
		'date'          => $hv('date'),
		'list_unsubscribe' => mc_mime_h($headers, 'list-unsubscribe') !== '' ? 'present' : 'absent',
		'authentication_results' => mc_one_line(implode(' | ', array_slice(
			array_merge($headers['authentication-results'] ?? array(), $headers['arc-authentication-results'] ?? array()), 0, 2)), 600),
		'received_spf'  => mc_one_line(mc_mime_h($headers, 'received-spf'), 200),
		'x_mailer'      => mc_one_line(mc_mime_h($headers, 'x-mailer'), 100),
		'received_hops' => $received,
		'body'          => mc_one_line_keep_newlines($text, $preview_chars),
		'urls'          => array_keys($urls),
		'attachments'   => $acc['attachments'],
	);
}

function mc_one_line_keep_newlines($s, $max){
	$s = preg_replace('~[\x00-\x09\x0b-\x1f\x7f]+~', ' ', (string)$s);
	return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8').'…' : $s;
}
