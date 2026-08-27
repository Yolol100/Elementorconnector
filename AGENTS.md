# Agent rules

Apply these rules to every change in this repository.

1. Preserve the plugin slug `elementor-json-bridge`, namespace `Webactueel\ElementorJsonBridge`, option/meta keys and documented JSON contract unless a breaking migration is explicitly approved.
2. Never commit credentials, GitHub tokens, client secrets, license keys, private URLs, client data, production exports, Elementor JSON from real sites, screenshots containing private content, database dumps or logs with secrets.
3. Keep the plugin independent of OpenAI APIs and MCP. GitHub is the only external synchronization provider in v0.1.x.
4. Do not write Elementor content directly to `_elementor_data`. Read/write through Elementor document APIs and verify readback.
5. Preserve the safety sequence: fresh state -> conflict check -> snapshot -> validation -> save -> readback -> fingerprint -> verified, otherwise rollback.
6. Remote changes must not be auto-applied in v0.1.x. Applying requires an authorized administrator action plus `edit_post` permission for the target document.
7. Treat GitHub data, repository files, branch names and API responses as untrusted input. Validate before use.
8. Existing GitHub files with unknown base history must never be overwritten. Use the current GitHub blob SHA for updates and fail closed on mismatch.
9. Keep tokens server-side and encrypted at rest. Never expose them to admin JavaScript, REST responses or logs.
10. Keep outbound hosts fixed to documented GitHub HTTPS endpoints. Do not add generic URL fetchers or inbound public webhooks without a separate security design.
11. Prefer the smallest implementation. No React, custom database table, background queue framework or new dependency unless a demonstrated requirement justifies it.
12. Every behavioral/security change needs a regression test. Run PHP lint, `php tests/run.php`, PHPCS/PHPCompatibility where available, package validation and the relevant runtime/staging test before a release claim.
13. CI actions must use minimum permissions. Pin third-party/official actions to full commit SHAs and update them deliberately.
14. The release ZIP contains runtime files only. Tests, CI, Composer dev files, agent instructions and local artifacts stay out of the package.
15. Do not claim 10/10 or production-ready from static checks alone. Production behavior requires staging/target evidence and rollback proof.
