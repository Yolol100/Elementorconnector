# Agent rules

Apply these rules to every change in this repository.

1. Preserve the plugin slug `elementor-json-bridge`, namespace `Webactueel\ElementorJsonBridge`, option/meta keys and documented JSON contract unless a breaking migration is explicitly approved.
2. Never commit credentials, GitHub tokens, client secrets, license keys, private URLs, database dumps, logs with secrets, or real-site Elementor JSON to a public repository or the source `main` branch. In explicitly configured single-repository mode, real-site JSON may exist only in the private `site-sync` branch under `site-data/`.
3. Keep the plugin independent of OpenAI APIs and MCP. GitHub is the only external synchronization provider in v0.2.x.
4. Do not write Elementor content directly to `_elementor_data`. Read/write through Elementor document APIs and verify readback.
5. Preserve the safety sequence: fresh state -> conflict check -> snapshot -> validation -> save -> readback -> fingerprint -> verified, otherwise rollback.
6. Automatic remote apply is opt-in and disabled by default. It must bind to the administrator who explicitly enables the setting, recheck that actor's bridge capability and `edit_post` permission for each target, temporarily activate that WordPress user context only for the guarded operation, and restore the previous user even after failures. Both manual and automatic apply paths must revalidate fresh GitHub/local state, refuse conflicts, create a snapshot, validate, save through Elementor, verify readback and roll back on failure.
7. Treat GitHub data, repository files, branch names and API responses as untrusted input. Validate before use.
8. Existing GitHub files with unknown base history must never be overwritten. Use the current GitHub blob SHA for updates and fail closed on mismatch.
9. Keep tokens server-side and encrypted at rest. Never expose them to admin JavaScript, REST responses or logs.
10. Keep outbound hosts fixed to documented GitHub HTTPS endpoints. Do not add generic URL fetchers or inbound public webhooks without a separate security design.
11. Prefer the smallest implementation. No React, custom database table, background queue framework or new dependency unless a demonstrated requirement justifies it.
12. Every behavioral/security change needs a regression test. Run PHP lint, `php tests/run.php`, `php tests/background-authorization.php`, PHPCS/PHPCompatibility where available, package validation and the relevant runtime/staging test before a release claim.
13. CI actions must use minimum permissions. Pin third-party/official actions to full commit SHAs and update them deliberately.
14. The release ZIP contains runtime files only. Tests, CI, Composer dev files, agent instructions, local artifacts and `site-data/` stay out of the package.
15. Do not claim 10/10 or production-ready from static checks alone. Production behavior requires staging/target evidence and rollback proof.
