# Elementor JSON Bridge

A conservative WordPress plugin that synchronizes selected Elementor documents with a private GitHub repository so reviewed tools such as ChatGPT or Codex can edit the JSON without direct WordPress access.

Runtime baseline: WordPress 6.8+ and PHP 8.3+. The automated current-runtime smoke scenario pins WordPress 7.1 and Elementor 4.2.3.

## Core flow

`Elementor -> WordPress plugin -> private GitHub JSON repo -> reviewed edit -> plugin -> backup -> validate -> Elementor save -> readback -> rollback on failure`

## Safety contract

- No OpenAI API and no MCP server are required by this plugin.
- GitHub authentication uses Device Flow. Never commit access tokens, refresh tokens, client secrets or site credentials.
- Real Elementor JSON belongs in a separate **private** content repository. This source repository must stay free of client/site content.
- Existing remote files with unknown history are never overwritten.
- Every remote apply requires an administrator action in v0.1.0.
- Every apply creates a local snapshot first, and snapshot fingerprints are verified before restore.
- Incoming JSON is structurally validated before save.
- Synchronization uses local SHA-256 fingerprints plus GitHub blob SHAs to detect stale/conflicting state.
- Elementor writes go through Elementor's document API, not direct `_elementor_data` mutation.
- A successful apply is not trusted until the document is read back and fingerprinted.
- Failed verification triggers rollback.
- Production use remains staging-first, especially with Pro, Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components and Atomic/V4 content.

## Repository layout

```text
.github/workflows/ci.yml      PHP matrix, package, Plugin Check and runtime smoke gates
.github/pull_request_template.md
.editorconfig                 Stable editor/line-ending defaults
.wp-env.smoke.json            Pinned WordPress/Elementor runtime test environment
assets/                       Admin CSS/JS
includes/                     Plugin application code
scripts/build-zip.sh          Byte-reproducible runtime package builder + SHA-256
tests/run.php                 Zero-dependency regression/security tests
tests/integration/smoke.php   Real Elementor document save/readback smoke test
docs/architecture.md          Runtime boundaries and state model
docs/release-checklist.md     Evidence-gated staging/release checklist
AGENTS.md                     Rules for AI/code agents working in this repo
CONTRIBUTING.md               Change and safety rules
SECURITY.md                   Security reporting and secret/data policy
composer.json                 Development-only quality tooling
composer.lock                 Exact development quality dependency versions
phpcs.xml.dist                WordPress Coding Standards + PHP compatibility
.distignore                   Release package exclusions
```

## Local checks

Minimum zero-dependency checks:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php tests/run.php
bash scripts/build-zip.sh
```

With Composer dev tools installed:

```bash
composer validate --strict
composer install
composer phpcs
composer test
composer audit --locked
```

The GitHub workflow additionally checks PHP 8.3, 8.4 and 8.5, builds the same release ZIP twice and compares the bytes, runs Plugin Check on the exact release package, and executes a pinned WordPress 7.1 + Elementor 4.2.3 save/readback smoke test.

## GitHub repository used for site JSON

Use a different private repository per site or controlled environment. A typical JSON layout is:

```text
elementor/pages/42.json
elementor/posts/91.json
elementor/templates/120.json
elementor/custom/{post-type}/123.json
```

Never put those real files in this public source repo.

## Status

Version `0.1.0` is intentionally conservative. Automated checks are strong evidence for the plugin code and pinned runtime scenario, but they do not prove a specific production site or private GitHub content repository. Complete the staging roundtrip in `docs/release-checklist.md` before a production-readiness claim.
