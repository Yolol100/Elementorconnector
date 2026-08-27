# Contributing

Keep this repository focused on one job: safely bridge Elementor document JSON and a private GitHub content repository.

## Safety rules

- Never commit real customer/site Elementor JSON, access tokens, refresh tokens, GitHub App secrets, passwords, `.env` files, exports, or logs containing credentials.
- Keep the source repository separate from the private repository that stores synchronized website content.
- Use Elementor document APIs for Elementor data writes. Do not replace them with direct `_elementor_data` post-meta writes.
- Remote changes remain review-before-apply. Do not introduce automatic remote apply without a new threat model, rollback design, and staging evidence.
- Preserve optimistic concurrency: local canonical hash plus GitHub blob SHA must continue to guard writes.
- Preserve snapshot-before-apply and readback verification.

## Development checks

Run before opening a pull request:

```bash
composer install
composer test
composer phpcs
composer audit --locked
bash scripts/build-zip.sh
```

GitHub Actions additionally tests the supported PHP matrix, byte-reproducible packaging, Plugin Check, and the pinned WordPress/Elementor runtime smoke scenario.

## Change discipline

Prefer the smallest change that fixes the demonstrated problem. Keep unrelated refactors separate. A code-quality tool warning is not by itself a reason to add a new framework, database table, service, or dependency.

Runtime or data-path changes require staging validation before a production-readiness claim. Use `docs/release-checklist.md` for the final evidence gate.
