#!/usr/bin/env bash
#
# AI Mail Cleaner — uninstaller. Removes the panel wiring, cron and log rotation.
# Never touches any mailbox: messages already moved stay in their review folders.
#
#   uninstall.sh           keep /var/lib/mailcleaner (settings, API keys, history)
#   uninstall.sh --purge   also delete /var/lib/mailcleaner
#
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "run as root" >&2; exit 1; }
PURGE=0
[[ "${1:-}" == "--purge" ]] && PURGE=1

PHP=/usr/local/emps/bin/php
NGINX=/usr/local/emps/sbin/nginx
PLUGINS_JSON=/var/webuzo/plugins.json

rm -f /etc/cron.d/mailcleaner /etc/logrotate.d/mailcleaner
for l in /usr/local/webuzo/plugins/mailcleaner /usr/local/webuzo/web/enduser/mailcleaner /usr/local/webuzo/web/admin/mailcleaner \
         /usr/local/webuzo/conf/webuzo/emps/enduser_locations_mailcleaner.conf /usr/local/webuzo/conf/webuzo/emps/admin_locations_mailcleaner.conf; do
	[[ -L "$l" ]] && rm -f "$l"
done
"$NGINX" -t >/dev/null 2>&1 && "$NGINX" -s reload || true

if [[ -f "$PLUGINS_JSON" ]]; then
	O=$(stat -c '%U:%G' "$PLUGINS_JSON"); M=$(stat -c '%a' "$PLUGINS_JSON")
	"$PHP" -r '$p=$argv[1]; $d=json_decode((string)file_get_contents($p), true); if(!is_array($d)) exit(1);
		unset($d["mailcleaner/mailcleaner.php"]); $o=json_encode($d, JSON_PRETTY_PRINT); $t=$p.".mc.tmp";
		if(file_put_contents($t,$o)===strlen($o)) rename($t,$p);' "$PLUGINS_JSON" || echo "warn: could not edit $PLUGINS_JSON" >&2
	chown "$O" "$PLUGINS_JSON"; chmod "$M" "$PLUGINS_JSON"
fi
shopt -s nullglob
for PLAN in /var/webuzo/plans/*; do
	[[ -f "$PLAN" ]] || continue
	case "$PLAN" in *.bak|*.tmp) continue ;; esac
	O=$(stat -c '%U:%G' "$PLAN"); M=$(stat -c '%a' "$PLAN")
	"$PHP" -r '$p=$argv[1]; $d=json_decode((string)file_get_contents($p), true); if(!is_array($d)||!isset($d["features"]["mailcleaner"])) exit(0);
		unset($d["features"]["mailcleaner"]); $o=json_encode($d, JSON_PRETTY_PRINT); $t=$p.".mc.tmp";
		if(file_put_contents($t,$o)===strlen($o)) rename($t,$p);' "$PLAN" || true
	chown "$O" "$PLAN"; chmod "$M" "$PLAN"
done

if (( PURGE )); then
	rm -rf /var/lib/mailcleaner
	echo "Removed panel wiring, cron and /var/lib/mailcleaner. Mail in review folders was left where it is."
else
	echo "Removed panel wiring and cron. Settings and history kept in /var/lib/mailcleaner (use --purge to delete)."
fi
echo "The code in /usr/local/mailcleaner was left in place; delete it yourself if you no longer need it."
