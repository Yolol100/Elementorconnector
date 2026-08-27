#!/usr/bin/env bash
set -euo pipefail
R="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="$R/dist/elementor-json-bridge.zip"
STAGE="$R/dist/elementor-json-bridge"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

SOURCE_DATE_EPOCH=315532800 SOURCE_REVISION=package-roundtrip bash "$R/scripts/build-zip.sh" >/dev/null

mapfile -t entries < <(unzip -Z1 "$ZIP")
((${#entries[@]} > 0)) || { echo 'empty release ZIP' >&2; exit 1; }
if printf '%s\n' "${entries[@]}" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
  echo 'unsafe ZIP path' >&2
  exit 1
fi
if [[ $(printf '%s\n' "${entries[@]}" | sort | uniq -d | wc -l) -ne 0 ]]; then
  echo 'duplicate ZIP entry' >&2
  exit 1
fi

unzip -q "$ZIP" -d "$T"
[[ -d "$T/elementor-json-bridge" ]] || { echo 'missing canonical plugin root' >&2; exit 1; }
[[ $(find "$T" -mindepth 1 -maxdepth 1 -type d | wc -l) -eq 1 ]] || { echo 'multiple ZIP roots' >&2; exit 1; }
[[ $(find "$T" -mindepth 1 -maxdepth 1 -type f | wc -l) -eq 0 ]] || { echo 'loose root files' >&2; exit 1; }

diff -qr "$STAGE" "$T/elementor-json-bridge"

echo 'PASS exact-package-roundtrip'
