#!/usr/bin/env bash
set -euo pipefail
R="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
SOURCE_DATE_EPOCH=315532800 bash "$R/scripts/build-zip.sh" >/dev/null; cp "$R/dist/elementor-json-bridge.zip" "$T/a.zip"; SOURCE_DATE_EPOCH=315532800 bash "$R/scripts/build-zip.sh" >/dev/null; cmp "$T/a.zip" "$R/dist/elementor-json-bridge.zip"; echo 'PASS reproducible-release-build'
