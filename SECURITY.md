# Security policy

## Supported version

Security fixes currently target the latest `0.2.x` source until a newer release line exists.

## Report a vulnerability

Do not open a public issue for vulnerabilities that could expose credentials, private Elementor JSON or a WordPress site. Report privately to the repository owner through an agreed private channel.

Include the affected version, reproduction steps, impact and any safe proof needed to validate the issue. Do not include real client data or live secrets.

## Security boundaries

- GitHub Device Flow is used instead of personal access tokens entered in WordPress.
- Access/refresh tokens are encrypted at rest and must never be logged or sent to the browser.
- GitHub repository access should be limited to the configured private repository and `Contents: read/write` only. In single-repository mode, the source repository must be private and live Elementor JSON should use the dedicated `site-sync` branch.
- WordPress state-changing admin actions require capability checks and nonces/authenticated REST requests.
- Each manual Elementor document action additionally requires `edit_post` permission.
- Automatic apply is disabled by default and is limited to documents explicitly enabled for synchronization.
- Both manual and automatic apply perform a fresh remote/local conflict check before writing.
- Incoming JSON is size/shape/depth/node/ID validated before it can reach Elementor.
- Remote state is bound to both a GitHub blob SHA and a canonical local SHA-256 fingerprint.
- Every remote apply creates a local snapshot and verifies the saved document by readback; verification failure rolls back.
- No public inbound webhook is exposed.
- Production writes are staging-first.
