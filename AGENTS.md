# Agent rules

1. Preserve slug, namespace, option/meta keys and JSON contract unless an explicit migration is approved.
2. Never commit credentials, tokens, secrets, production exports, dumps, private screenshots or secret-bearing logs.
3. Runtime remains independent of OpenAI APIs and MCP; GitHub is the external sync provider in `0.1.x`.
4. Never mutate `_elementor_data` directly; use Elementor document APIs and verify readback.
5. JSON `title` is informational; GitHub must not rename WordPress documents.
6. Preserve trusted base -> repository identity -> conflict check -> snapshot -> validation -> save -> readback -> fingerprint -> verified, otherwise rollback.
7. Remote Apply is never automatic in `0.1.x` and requires capability + `edit_post`.
8. Treat GitHub and JSON as untrusted input; fail closed.
9. Bind sync state to owner/repo/branch/root, GitHub blob SHA and local canonical SHA-256.
10. Tokens stay server-side and encrypted; never expose them to JS, REST, analytics or logs.
11. Keep outbound hosts fixed; no generic fetchers or public webhooks without a separate security design.
12. Every behavior/security change requires regression, WPCS, PHPStan, audit, Plugin Check, deterministic package and relevant runtime evidence.
13. Actions use minimum permissions, full commit-SHA pins and checkout `persist-credentials: false`.
14. Do not claim production 10/10 from static checks; target-specific Elementor behavior needs staging evidence.
