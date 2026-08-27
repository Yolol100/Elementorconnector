#!/usr/bin/env bash
set -euo pipefail
R="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; W="$R/.github/workflows"
! grep -Rqs 'pull_request_target:' "$W" || { echo forbidden >&2; exit 1; }
grep -Rqs 'permissions: {}' "$W" || { echo permissions >&2; exit 1; }
while IFS= read -r l; do r="${l#*@}"; r="${r%%[[:space:]#]*}"; [[ "$r" =~ ^[0-9a-f]{40}$ ]] || { echo "unpinned $l" >&2; exit 1; }; done < <(grep -RhE '^[[:space:]]*uses:[[:space:]]*[^#[:space:]]+@' "$W")
echo PASS workflow-security-policy
