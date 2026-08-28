# Elementor JSON Bridge

A conservative WordPress plugin that synchronizes selected Elementor documents with a private GitHub repository so reviewed tools such as ChatGPT or Codex can edit the JSON without direct WordPress access.

## Core flow

`Elementor -> WordPress plugin -> private GitHub repo/branch -> reviewed edit -> fresh conflict check -> optional automatic apply -> backup -> validate -> Elementor save -> readback -> rollback on failure`

## Safety contract

- No OpenAI API and no MCP server are required by this plugin.
- GitHub authentication uses Device Flow. Never commit access tokens, refresh tokens, client secrets or site credentials.
- Real Elementor JSON must stay in private GitHub storage. A separate private content repo is simplest; single-repository mode is supported only when this repository is private and site JSON uses the dedicated `site-sync` branch.
- Existing remote files with unknown history are never overwritten.
- Automatic remote apply is opt-in in v0.2.x and disabled by default.
- Automatic apply records the administrator who enabled it and rechecks that actor's bridge capability plus `edit_post` before each background write.
- Both manual and automatic apply recheck the remote SHA and local base before any Elementor write.
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
tests/background-authorization.php  Cron/background authorization regression test
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
php tests/background-authorization.php
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

The simplest setup is a different private repository per site or controlled environment:

```text
elementor/pages/42.json
elementor/posts/91.json
elementor/templates/120.json
elementor/custom/{post-type}/123.json
```

### Optional single-repository mode

If you want plugin source and website JSON in one GitHub repository, make this repository private first. Keep plugin source on `main`, use a dedicated `site-sync` branch for website data, and configure the plugin like this:

```text
Repository: Elementorconnector
Branch: site-sync
JSON folder: site-data/elementor
```

Do not synchronize real site JSON while the repository is public. The plugin rejects public repositories before reading or writing synchronized content. Using `site-sync` also keeps normal website-content commits away from the source `main` branch and its CI workflow.

## Automatic apply

Version 0.2.1 can apply fresh remote changes without an administrator click. The setting is off by default. When an administrator enables it, that actor is recorded. Before each background write, the plugin verifies that the actor still has the bridge capability and `edit_post` for the target, temporarily activates that WordPress user context, checks GitHub again, and then uses the same conflict, snapshot, validation, readback and rollback sequence as a manual apply. The previous WordPress user context is restored immediately afterwards.

WP-Cron is request-driven, so one minute is a target cadence rather than a hard real-time guarantee.

## Status

Version `0.2.1` fixes and permanently tests background authorization for automatic apply. Source/static/controlled checks are useful evidence, but they do not prove a specific production site. A real staging roundtrip remains required before a production-ready claim.
