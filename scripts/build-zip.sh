#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG=elementor-json-bridge
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"
ZIP="$DIST/$SLUG.zip"
EPOCH="${SOURCE_DATE_EPOCH:-315532800}"

rm -rf "$DIST"
mkdir -p "$STAGE"
for item in elementor-json-bridge.php uninstall.php readme.txt LICENSE includes assets; do
  cp -a "$ROOT/$item" "$STAGE/"
done
find "$STAGE" -type d -exec chmod 0755 {} +
find "$STAGE" -type f -exec chmod 0644 {} +
TZ=UTC find "$STAGE" -exec touch -h -d "@$EPOCH" {} +
find "$STAGE" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
(cd "$DIST"; LC_ALL=C find "$SLUG" -type f -print | LC_ALL=C sort | zip -X -q "$ZIP" -@)
(cd "$DIST"; sha256sum "$SLUG.zip" > "$SLUG.zip.sha256")
SOURCE_DATE_EPOCH="$EPOCH" php "$ROOT/scripts/generate-release-metadata.php" "$STAGE" "$ZIP" "$DIST"
echo "$ZIP"
