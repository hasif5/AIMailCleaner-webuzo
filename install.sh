#!/usr/bin/env bash
#
# AI Mail Cleaner — installer for Webuzo v4. Idempotent; a re-run is a repair run.
#
# What it touches:
#   /usr/local/mailcleaner                                       (payload, perms)
#   /var/lib/mailcleaner                                         (state tree, root-only)
#   /usr/local/webuzo/plugins/mailcleaner                        (symlink)
#   /usr/local/webuzo/web/enduser/mailcleaner                    (symlink)
#   /usr/local/webuzo/web/admin/mailcleaner                      (symlink)
#   /usr/local/webuzo/conf/webuzo/emps/{enduser,admin}_locations_mailcleaner.conf (symlinks)
#   /var/webuzo/plugins.json                                     (one key added)
#   /var/webuzo/plans/*                                          (features.mailcleaner=1, backed up)
#   /etc/cron.d/mailcleaner, /etc/logrotate.d/mailcleaner        (created)
#   /var/webuzo/logs/mailcleaner                                 (runner log)
#
# What it NEVER touches: any mailbox, any Webuzo core file, Dovecot/Exim/SpamAssassin
# configuration, any other plugin's registration, firewall, systemd.

set -euo pipefail

ROOT=/usr/local/mailcleaner
STATE=/var/lib/mailcleaner
WEBUZO_ROOT=/usr/local/webuzo
WEBUZO_VAR=/var/webuzo
PLUGINS_JSON=$WEBUZO_VAR/plugins.json
PLANS_DIR=$WEBUZO_VAR/plans
PLUGIN_KEY='mailcleaner/mailcleaner.php'
FEATURE_KEY='mailcleaner'

PHP=/usr/local/emps/bin/php
NGINX=/usr/local/emps/sbin/nginx
NGINX_CONF=/usr/local/emps/etc/nginx/nginx.conf

LINK_PLUGIN=$WEBUZO_ROOT/plugins/mailcleaner
LINK_WEB=$WEBUZO_ROOT/web/enduser/mailcleaner
LINK_WEB_ADMIN=$WEBUZO_ROOT/web/admin/mailcleaner
LINK_NGINX=$WEBUZO_ROOT/conf/webuzo/emps/enduser_locations_mailcleaner.conf
LINK_NGINX_ADMIN=$WEBUZO_ROOT/conf/webuzo/emps/admin_locations_mailcleaner.conf

CRON_FILE=/etc/cron.d/mailcleaner
LOGROTATE_FILE=/etc/logrotate.d/mailcleaner
RUNNER_LOG=$WEBUZO_VAR/logs/mailcleaner
CSRF_KEY=$STATE/.csrf_key

SELF_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '    \033[1;32mok\033[0m   %s\n' "$*"; }
warn() { printf '    \033[1;33mwarn\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mFATAL\033[0m %s\n' "$*" >&2; exit 1; }

MODE=install
for arg in "$@"; do
	case "$arg" in
		--repair) MODE=repair ;;
		--check) exec "$ROOT/bin/check.sh" ;;
		-h|--help) echo "Usage: install.sh [--repair|--check]"; exit 0 ;;
		*) die "unknown option: $arg (try --help)" ;;
	esac
done

# ------------------------------------------------------------ 1. preflight -
say "Preflight"
[[ $EUID -eq 0 ]] || die "install.sh must run as root."
for d in "$WEBUZO_ROOT" "$WEBUZO_VAR"; do [[ -d "$d" ]] || die "$d not found — this does not look like a Webuzo server."; done
MISSING=()
for b in /usr/bin/doveadm /usr/bin/setsid "$PHP" "$NGINX"; do [[ -x "$b" ]] || MISSING+=("$b"); done
(( ${#MISSING[@]} )) && die "missing required binaries: ${MISSING[*]}"
for m in curl pdo_sqlite posix json mbstring; do
	"$PHP" -m 2>/dev/null | grep -qix "$m" || die "the EMPS PHP ($PHP) lacks the '$m' extension."
done
if ! command -v spamc >/dev/null 2>&1; then
	warn "spamc not found — messages without a SpamAssassin header cannot be scored; they go to the AI (or to you) instead."
fi
[[ -x /usr/sbin/sendmail ]] || warn "/usr/sbin/sendmail missing — summary emails cannot be sent."
ok "root, Webuzo, doveadm, EMPS php (curl, pdo_sqlite) and nginx present"

# ------------------------------------------------------ 2. stage the payload -
say "Staging payload"
if [[ "$SELF_DIR" != "$ROOT" ]]; then
	[[ -f "$SELF_DIR/lib/common.php" && -f "$SELF_DIR/bin/runner.php" ]] || die "$SELF_DIR does not look like an AI Mail Cleaner payload."
	mkdir -p "$ROOT"
	rsync -a --exclude '.git/' --exclude '.gitignore' --exclude '*.tmp' "$SELF_DIR"/ "$ROOT"/
	ok "payload synced $SELF_DIR -> $ROOT"
else
	ok "already running from $ROOT"
fi
[[ -f "$ROOT/VERSION" ]] && ok "version $(tr -d '\n' < "$ROOT/VERSION")"

# ---------------------------------------------------------- 3. permissions -
say "Applying permissions to $ROOT"
chown -R root:root "$ROOT"
chmod 0755 "$ROOT"
for sub in lib web webuzo bin packaging; do
	[[ -d "$ROOT/$sub" ]] || continue
	find "$ROOT/$sub" -type d -exec chmod 0755 {} +
	find "$ROOT/$sub" -type f -exec chmod 0644 {} +
done
chmod 0700 "$ROOT/bin/runner.php" "$ROOT/bin/worker.php"
chmod 0755 "$ROOT/bin/check.sh" "$ROOT/install.sh" "$ROOT/uninstall.sh"
[[ -f "$ROOT/packaging/make-release.sh" ]] && chmod 0755 "$ROOT/packaging/make-release.sh"
ok "root:root; bin/runner.php + worker.php 0700 (root only), web files 0644"

# ---------------------------------------------------------- 4. state tree --
say "State tree $STATE"
# Everything root-only: the panel pages run on the root FPM pool and there is no
# non-root endpoint that needs to read anything here (API keys live here).
install -d -o root -g root -m 0700 "$STATE"
for d in boxes status spool locks logs; do install -d -o root -g root -m 0700 "$STATE/$d"; done
if [[ ! -s "$CSRF_KEY" ]]; then
	umask 077
	od -An -N32 -tx1 < /dev/urandom | tr -d ' \n' > "$CSRF_KEY.tmp.$$"
	[[ $(wc -c < "$CSRF_KEY.tmp.$$") -eq 64 ]] || { rm -f "$CSRF_KEY.tmp.$$"; die "could not generate the CSRF key"; }
	mv -f "$CSRF_KEY.tmp.$$" "$CSRF_KEY"
	umask 022
	ok "generated $CSRF_KEY"
fi
find "$STATE" -maxdepth 2 -type f -exec chown root:root {} + -exec chmod 0600 {} +
ok "$STATE and everything in it: root:root, dirs 0700, files 0600"

# ------------------------------------------------------------ 5. symlinks --
say "Wiring symlinks"
link_to() {
	local target=$1 link=$2
	[[ -d "$(dirname -- "$link")" ]] || die "$(dirname -- "$link") does not exist"
	if [[ -e "$link" && ! -L "$link" ]]; then die "$link exists and is NOT a symlink. Refusing to clobber it."; fi
	ln -sfn "$target" "$link"
	ok "$link -> $target"
}
link_to "$ROOT/webuzo/plugin"                              "$LINK_PLUGIN"
link_to "$ROOT/web/enduser"                                "$LINK_WEB"
link_to "$ROOT/web/admin"                                  "$LINK_WEB_ADMIN"
link_to "$ROOT/webuzo/enduser_locations_mailcleaner.conf"  "$LINK_NGINX"
link_to "$ROOT/webuzo/admin_locations_mailcleaner.conf"    "$LINK_NGINX_ADMIN"

# --------------------------------------------------- 6. plugins.json entry -
say "Registering plugin in $PLUGINS_JSON"
[[ -f "$PLUGINS_JSON" ]] || die "$PLUGINS_JSON not found — is Webuzo installed?"
PJ_OWNER=$(stat -c '%U:%G' "$PLUGINS_JSON"); PJ_MODE=$(stat -c '%a' "$PLUGINS_JSON")
"$PHP" -- "$PLUGINS_JSON" "$PLUGIN_KEY" <<'PHPEOF' || die "could not register the plugin (nothing was overwritten)."
<?php
$path = $argv[1]; $key = $argv[2];
$data = json_decode((string)@file_get_contents($path), true);
if(!is_array($data)){ fwrite(STDERR, "REFUSING TO WRITE: $path is not valid JSON\n"); exit(1); }
if(array_key_exists($key, $data)){ echo "already registered\n"; exit(0); }
$data[$key] = time();
$out = json_encode($data, JSON_PRETTY_PRINT);
$tmp = $path.'.mailcleaner.tmp.'.getmypid();
if(@file_put_contents($tmp, $out) !== strlen($out) || !@rename($tmp, $path)){ @unlink($tmp); fwrite(STDERR, "write failed\n"); exit(1); }
echo "registered\n";
PHPEOF
chown "$PJ_OWNER" "$PLUGINS_JSON"; chmod "$PJ_MODE" "$PLUGINS_JSON"
ok "$PLUGIN_KEY present"

# ------------------------------------------------------- 7. plan features --
say "Enabling the '$FEATURE_KEY' feature in plan files"
if [[ -d "$PLANS_DIR" ]]; then
	shopt -s nullglob
	for PLAN in "$PLANS_DIR"/*; do
		[[ -f "$PLAN" ]] || continue
		case "$PLAN" in *.bak|*.tmp) continue ;; esac
		PL_OWNER=$(stat -c '%U:%G' "$PLAN"); PL_MODE=$(stat -c '%a' "$PLAN")
		BACKUP="$PLAN.mailcleaner.bak"; MADE=0
		[[ -e "$BACKUP" ]] || { cp -p "$PLAN" "$BACKUP"; MADE=1; }
		set +e
		OUT=$("$PHP" -- "$PLAN" "$FEATURE_KEY" <<'PHPEOF'
<?php
$path = $argv[1]; $feat = $argv[2];
$data = json_decode((string)@file_get_contents($path), true);
if(!is_array($data)) exit(2);
if(!isset($data['features']) || !is_array($data['features'])){ echo "no features map\n"; exit(3); }
if(array_key_exists($feat, $data['features'])){ echo "already enabled\n"; exit(0); }
$data['features'][$feat] = 1;
$out = json_encode($data, JSON_PRETTY_PRINT);
$tmp = $path.'.mailcleaner.tmp.'.getmypid();
if(@file_put_contents($tmp, $out) !== strlen($out) || !@rename($tmp, $path)){ @unlink($tmp); exit(2); }
echo "enabled\n";
PHPEOF
		)
		RC=$?
		set -e
		chown "$PL_OWNER" "$PLAN"; chmod "$PL_MODE" "$PLAN"
		case "$RC" in
			0) ok "$(basename "$PLAN"): $OUT" ;;
			*) warn "$(basename "$PLAN"): left untouched ($OUT)"; (( MADE )) && rm -f "$BACKUP" ;;
		esac
	done
	shopt -u nullglob
else
	warn "$PLANS_DIR missing — the panel icon may stay hidden."
fi

# --------------------------------------------------------------- 8. nginx --
say "Validating and reloading nginx (EMPS)"
NGX_OUT=$(mktemp)
if "$NGINX" -t -c "$NGINX_CONF" >"$NGX_OUT" 2>&1; then
	ok "nginx -t passed"
	"$NGINX" -s reload >>"$NGX_OUT" 2>&1 && ok "nginx reloaded" || warn "reload failed — run: $NGINX -s reload"
	rm -f "$NGX_OUT"
else
	cat "$NGX_OUT" >&2; rm -f "$NGX_OUT" "$LINK_NGINX" "$LINK_NGINX_ADMIN"
	die "nginx rejected the config; our two drop-ins were REMOVED so the panel keeps working."
fi

# ---------------------------------------------------------------- 9. cron -
say "Installing cron job + log rotation"
[[ -f "$RUNNER_LOG" ]] || : > "$RUNNER_LOG"
chown root:root "$RUNNER_LOG"; chmod 0640 "$RUNNER_LOG"
cat > "$CRON_FILE" <<EOF
# AI Mail Cleaner — starts queued and automatic mailbox checks. Managed by install.sh.
* * * * * root $PHP $ROOT/bin/runner.php >> $RUNNER_LOG 2>&1
EOF
chmod 0644 "$CRON_FILE"
cat > "$LOGROTATE_FILE" <<EOF
$RUNNER_LOG {
    weekly
    rotate 4
    compress
    missingok
    notifempty
    copytruncate
}
EOF
chmod 0644 "$LOGROTATE_FILE"
ok "$CRON_FILE, $LOGROTATE_FILE"

VERSION_STR=$( [[ -f "$ROOT/VERSION" ]] && tr -d '\n' < "$ROOT/VERSION" || echo unknown )
cat <<EOF

$(printf '\033[1;32m==> AI Mail Cleaner %s installed (%s run complete).\033[0m' "$VERSION_STR" "$MODE")

  Server settings  admin panel (port 2004/2005) -> AI Mail Cleaner
                   add the Gemini API key there (required; Claude key optional), then press Test
  Users            enduser panel -> Email -> AI Mail Cleaner
  State            $STATE (root-only: settings, API keys, history)
  Re-run any time  $ROOT/install.sh --repair
  Health check     $ROOT/bin/check.sh
  Remove           $ROOT/uninstall.sh   (--purge also deletes settings and history)

EOF
