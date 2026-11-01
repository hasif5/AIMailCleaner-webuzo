#!/usr/bin/env bash
# AI Mail Cleaner — read-only health check. Exit 0 = healthy, 1 = problems found.
set -uo pipefail
ROOT=/usr/local/mailcleaner
STATE=/var/lib/mailcleaner
PHP=/usr/local/emps/bin/php
BAD=0
ok()   { printf '  \033[32mok\033[0m    %s\n' "$*"; }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; BAD=1; }
note() { printf '  \033[33mnote\033[0m  %s\n' "$*"; }

echo "AI Mail Cleaner $(cat $ROOT/VERSION 2>/dev/null) — health check"
for l in /usr/local/webuzo/plugins/mailcleaner /usr/local/webuzo/web/enduser/mailcleaner /usr/local/webuzo/web/admin/mailcleaner \
         /usr/local/webuzo/conf/webuzo/emps/enduser_locations_mailcleaner.conf /usr/local/webuzo/conf/webuzo/emps/admin_locations_mailcleaner.conf; do
	[[ -L "$l" && -e "$l" ]] && ok "$l" || bad "$l missing or broken"
done
grep -Eq '"mailcleaner\\?/mailcleaner\.php"' /var/webuzo/plugins.json 2>/dev/null && ok "registered in plugins.json" || bad "not registered in /var/webuzo/plugins.json"
[[ -f /etc/cron.d/mailcleaner ]] && ok "cron file present" || bad "/etc/cron.d/mailcleaner missing"
HB=$(stat -c %Y "$STATE/.heartbeat" 2>/dev/null || echo 0)
(( $(date +%s) - HB < 180 )) && ok "runner ran $(( $(date +%s) - HB ))s ago" || bad "runner has not run in the last 3 minutes (cron?)"
for d in "$STATE" "$STATE/boxes" "$STATE/status" "$STATE/spool" "$STATE/locks" "$STATE/logs"; do
	P=$(stat -c '%a %U:%G' "$d" 2>/dev/null)
	[[ "$P" == "700 root:root" ]] && ok "$d $P" || bad "$d is '$P' (want 700 root:root)"
done
LOOSE=$(find "$STATE" -maxdepth 1 -type f ! -perm 0600 2>/dev/null | wc -l)
(( LOOSE == 0 )) && ok "state files 0600" || bad "$LOOSE state file(s) not 0600"
"$PHP" -r 'require "/usr/local/mailcleaner/lib/common.php"; $s = mc_settings();
	echo "  ".($s["gemini_key"] !== "" ? "\033[32mok\033[0m    Gemini key set (".$s["gemini_model"].", main AI)" : "\033[33mnote\033[0m  no Gemini key: REQUIRED, AI is off until it is saved (SpamAssassin only)")."\n";
	echo "  ".($s["anthropic_key"] !== "" ? "\033[32mok\033[0m    Claude key set (".$s["anthropic_model"].", effort ".$s["anthropic_effort"].", second opinion ".($s["second"] === "none" ? "switched off" : $s["second_when"]).")" : "\033[32mok\033[0m    no Claude key (optional): Gemini decides alone")."\n";
	echo "  \033[32mok\033[0m    mailboxes set up: ".count(mc_all_boxes())."\n";'
command -v spamc >/dev/null && ok "spamc present" || note "spamc missing: messages without a SpamAssassin header cannot be scored"
[[ -f "$STATE/.paused" ]] && note "PAUSED by the administrator"
exit $BAD
