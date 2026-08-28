# Elementor JSON Bridge

A conservative WordPress plugin that synchronizes selected Elementor documents with a private GitHub repository so reviewed tools such as ChatGPT or Codex can edit the JSON without direct WordPress access.

## Core flow

`Elementor -> WordPress plugin -> private GitHub JSON repo -> reviewed edit -> plugin -> backup -> validate -> Elementor save -> readback -> rollback on failure`

## Safety contract

- No OpenAI API and no MCP server are required by this plugin.
- GitHub authentication uses Device Flow. Never commit access tokens, refresh tokens, client secrets or site credentials.
- Real Elementor JSON belongs in a separate **private** content repository. This source repository must stay free of client/site content.
- Existing remote files with unknown history are never overwritten.
- Every remote apply requires an administrator action in v0.1.x.
- Every apply creates a local snapshot first.
- Incoming JSON is structurally validated before save.
- Synchronization uses local SHA-256 fingerprints plus GitHub blob SHAs to detect stale/conflicting state.
- Elementor writes go through Elementor's document API, not direct `_elementor_data` mutation.
- A successful apply is not trusted until the document is read back and fingerprinted.
- Failed verification triggers rollback.
- Production use remains staging-first, especially with Pro, Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components and Atomic/V4 content.

## Repository layout

```text
.github/workflows/ci.yml      Static checks + Plugin Check canary
assets/                       Admin CSS/JS
includes/                     Plugin application code
scripts/build-zip.sh          Reproducible runtime package builder
tests/run.php                 Zero-dependency regression/security tests
docs/architecture.md          Runtime boundaries and state model
AGENTS.md                     Rules for AI/code agents working in this repo
SECURITY.md                   Security reporting and secret/data policy
composer.json                 Development-only quality tooling
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
composer phpcs
composer test
composer audit
```

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

Version `0.1.2` is intentionally conservative. Source/static checks are useful evidence, but they do not prove a specific production site. A real staging roundtrip remains required before production use.
