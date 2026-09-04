# Agent rules

Apply these rules to every change in this repository.

## Platform status

This repository is a maintenance-only consolidation source for the canonical `Yolol100/wordpressconnector` live WordPress bridge. Read `MIGRATION.md` before changing behavior. Existing validated routes may be maintained, but do not add a new Webactueel platform capability unless it preserves safety or closes a proven migration parity gap.

## Rules

1. Preserve the plugin slug `elementor-json-bridge`, namespace `Webactueel\ElementorJsonBridge`, option/meta keys and documented JSON contract unless a breaking migration is explicitly approved.
2. Never commit credentials, GitHub tokens, client secrets, license keys, private URLs, database dumps, logs with secrets, or real-site Elementor JSON to a public source branch. Real-site JSON may exist only in an explicitly configured private site-sync route.
3. Keep the plugin independent of OpenAI APIs and MCP. GitHub remains the external synchronization provider for existing routes.
4. Do not write Elementor content directly to `_elementor_data`. Use Elementor document APIs and verify readback.
5. Preserve the safety sequence: fresh state → conflict check → snapshot → validation → save → readback → fingerprint → verified; otherwise rollback.
6. Automatic remote apply remains opt-in and disabled by default. Recheck the authorized actor, bridge capability and per-target permission for every guarded operation, and always restore prior user context after failure or success.
7. Treat GitHub data, repository files, branch names and API responses as untrusted input.
8. Never overwrite a GitHub file with unknown base history. Bind updates to the current blob SHA and fail closed on mismatch.
9. Keep tokens server-side and encrypted at rest. Never expose them to admin JavaScript, REST responses or logs.
10. Keep outbound hosts fixed to documented GitHub HTTPS endpoints. Do not add generic URL fetchers, inbound public webhooks or proxy primitives.
11. Prefer the smallest implementation. Add no framework, table, queue or dependency without a demonstrated requirement.
12. Every behavioral or security change needs a regression test. Run PHP lint, existing test suites, PHPCS/PHPCompatibility where available, package validation and the relevant runtime/staging test.
13. Use minimum GitHub Actions permissions and pin external actions to full commit SHAs.
14. Keep tests, CI, development files, agent instructions, local artifacts and `site-data/` out of the release ZIP.
15. Do not claim production-ready or 10/10 from static checks alone. Require staging/target evidence and rollback proof.
16. During consolidation, allow only security/compatibility fixes, parity tests, documentation and minimal extraction work. Do not broaden capabilities or remove this route until every exit gate in `MIGRATION.md` is proven.
