#!/usr/bin/env bash
# AI Mail Cleaner — release builder. Writes mailcleaner-<version>.tar.gz (top-level
# directory mailcleaner/) from the committed tree, plus a .sha256 next to it.
#   packaging/make-release.sh [output-directory]     (default /root)
set -euo pipefail
SRC=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
OUT=${1:-/root}
VERSION=$(tr -d ' \t\r\n' < "$SRC/VERSION")
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "bad VERSION '$VERSION'" >&2; exit 1; }
grep -q "\"version\":\"$VERSION\"" "$SRC/info.json" || { echo "info.json version does not match VERSION" >&2; exit 1; }
[[ -z "$(git -C "$SRC" status --porcelain)" ]] || { echo "working tree not clean — commit first" >&2; exit 1; }
for f in "$SRC"/lib/*.php "$SRC"/bin/*.php "$SRC"/web/*/index.php "$SRC"/webuzo/plugin/*.php; do
	/usr/local/emps/bin/php -l "$f" >/dev/null || { echo "PHP 7.4 lint failed: $f" >&2; exit 1; }
done
ART="$OUT/mailcleaner-$VERSION.tar.gz"
git -C "$SRC" archive --format=tar --prefix=mailcleaner/ HEAD -- . ':!packaging' | gzip -9 > "$ART"
( cd "$OUT" && sha256sum "$(basename "$ART")" > "$(basename "$ART").sha256" )
echo "built $ART"
cat "$ART.sha256"
