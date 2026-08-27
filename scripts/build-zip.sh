#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="elementor-json-bridge"
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"
ZIP="$DIST/$SLUG.zip"
HASH_FILE="$ZIP.sha256"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$ROOT" log -1 --format=%ct 2>/dev/null || printf '0')}"

if [[ ! "$SOURCE_DATE_EPOCH" =~ ^[0-9]+$ ]]; then
    echo 'SOURCE_DATE_EPOCH must be an integer Unix timestamp.' >&2
    exit 1
fi

rm -rf "$DIST"
mkdir -p "$STAGE"

for item in elementor-json-bridge.php uninstall.php readme.txt LICENSE includes assets; do
    cp -a "$ROOT/$item" "$STAGE/"
done

find "$STAGE" -type d -exec chmod 0755 {} +
find "$STAGE" -type f -exec chmod 0644 {} +

# ZIP stores file modification timestamps. Normalize them to the source commit
# so the same commit and build inputs produce the same archive bytes.
php -r '
$epoch = (int) $argv[1];
$root = $argv[2];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $item) {
    if (!touch($item->getPathname(), $epoch, $epoch)) {
        fwrite(STDERR, "Unable to normalize release timestamps.\n");
        exit(1);
    }
}
' "$SOURCE_DATE_EPOCH" "$STAGE"

find "$STAGE" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

(
    cd "$DIST"
    LC_ALL=C find "$SLUG" -type f -print | LC_ALL=C sort | zip -X -q "$ZIP" -@
)

if unzip -Z1 "$ZIP" | grep -Eq '(^|/)(tests|vendor|\.git|\.github|AGENTS\.md|composer\.json|composer\.lock|phpcs\.xml\.dist)($|/)'; then
    echo 'Release ZIP contains development-only files.' >&2
    exit 1
fi

HASH="$(sha256sum "$ZIP" | awk '{print $1}')"
printf '%s  %s\n' "$HASH" "$(basename "$ZIP")" > "$HASH_FILE"
printf '%s\n' "$ZIP"
cat "$HASH_FILE"
