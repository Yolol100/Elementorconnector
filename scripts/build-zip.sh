#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="elementor-json-bridge"
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"
ZIP="$DIST/$SLUG.zip"

rm -rf "$DIST"
mkdir -p "$STAGE"

for item in elementor-json-bridge.php uninstall.php readme.txt LICENSE includes assets; do
    cp -a "$ROOT/$item" "$STAGE/"
done

find "$STAGE" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

# Normalize runtime file timestamps so identical source produces identical ZIP bytes.
find "$STAGE" -type f -exec touch -t 202001010000.00 {} +

(
    cd "$DIST"
    find "$SLUG" -type f -print | LC_ALL=C sort | zip -X -q "$ZIP" -@
)

if unzip -Z1 "$ZIP" | grep -Eq '(^|/)(tests|vendor|\.git|\.github|AGENTS\.md|composer\.json|phpcs\.xml\.dist)($|/)'; then
    echo 'Release ZIP contains development-only files.' >&2
    exit 1
fi

echo "$ZIP"
sha256sum "$ZIP"
