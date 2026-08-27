#!/usr/bin/env bash
set -euo pipefail
R="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
W="$R/.github/workflows"

! grep -Rqs 'pull_request_target:' "$W" || { echo forbidden >&2; exit 1; }
grep -Rqs 'permissions: {}' "$W" || { echo permissions >&2; exit 1; }
! grep -RqsE 'composer[[:space:]]+update([[:space:]]|$)' "$W" || { echo 'composer update is forbidden in CI' >&2; exit 1; }
grep -RqsE 'composer[[:space:]]+install([[:space:]]|$)' "$W" || { echo 'CI must install the committed lock' >&2; exit 1; }
grep -Rqs 'composer audit --locked' "$W" || { echo 'CI must audit the committed lock' >&2; exit 1; }

while IFS= read -r line; do
  ref="${line#*@}"
  ref="${ref%%[[:space:]#]*}"
  [[ "$ref" =~ ^[0-9a-f]{40}$ ]] || { echo "unpinned $line" >&2; exit 1; }
done < <(grep -RhE '^[[:space:]]*uses:[[:space:]]*[^#[:space:]]+@' "$W")

echo PASS workflow-security-policy
