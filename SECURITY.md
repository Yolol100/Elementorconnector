# Security policy

## Supported version

Security fixes currently target the latest `0.1.x` source until a newer release line exists.

## Report a vulnerability

Do not open a public issue for vulnerabilities that could expose credentials, private Elementor JSON or a WordPress site. Report privately to the repository owner through an agreed private channel.

Include the affected version, reproduction steps, impact and any safe proof needed to validate the issue. Do not include real client data or live secrets.

## Security boundaries

- GitHub Device Flow is used instead of personal access tokens entered in WordPress.
- Access/refresh tokens are encrypted at rest and must never be logged or sent to the browser.
- GitHub repository access should be limited to the single private JSON repository and `Contents: read/write` only.
- WordPress state-changing actions require capability checks and nonces/authenticated REST requests.
- Each Elementor document action also requires `edit_post` permission.
- Incoming JSON is size/shape/depth/node/ID validated before it can reach Elementor.
- Remote state is bound to both a GitHub blob SHA and a canonical local SHA-256 fingerprint.
- A remote apply creates a local snapshot and verifies the saved document by readback; verification failure rolls back.
- No public inbound webhook is exposed.
- Production writes are staging-first.
