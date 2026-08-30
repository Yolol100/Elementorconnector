# Elementor JSON Bridge

A conservative WordPress plugin that synchronizes selected Elementor documents with a private GitHub repository so reviewed tools such as ChatGPT or Codex can edit the JSON without direct WordPress access.

## Core flow

`Elementor -> WordPress plugin -> private GitHub repo/branch -> reviewed edit -> fresh conflict check -> optional automatic apply -> backup -> validate -> Elementor save -> readback -> rollback on failure`

## Safety contract

- No OpenAI API and no MCP server are required by this plugin.
- GitHub authentication uses Device Flow. Never commit access tokens, refresh tokens, client secrets or site credentials.
- Encrypted credential packages fail closed when their Sodium nonce or AES-GCM IV/tag/cipher structure is malformed.
- Real Elementor JSON must stay in private GitHub storage. A separate private content repo is simplest; single-repository mode is supported only when this repository is private and site JSON uses the dedicated `site-sync` branch.
- Existing remote files with unknown history are never overwritten.
- Automatic remote apply is opt-in in v0.2.x and disabled by default.
- Automatic apply records the administrator who enabled it and rechecks that actor's bridge capability plus `edit_post` before each background write.
- Both manual and automatic apply recheck the remote SHA and local base before any Elementor write.
- Every apply creates a local snapshot first, and that snapshot's stored SHA-256 integrity fingerprint is verified before rollback use.
- Incoming JSON is structurally validated and canonicalized to Elementor raw-data defaults before save.
- Synchronization uses local SHA-256 fingerprints plus GitHub blob SHAs to detect stale/conflicting state.
- Elementor writes go through Elementor's document API, not direct `_elementor_data` mutation.
- A successful apply is not trusted until the document is read back and fingerprinted.
- Failed verification triggers rollback.
- Production use remains staging-first, especially with Pro, Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components and Atomic/V4 content.

## Repository layout

```text
.github/workflows/ci.yml      PHP/static/package/Plugin Check + real runtime CI
.wp-env.json                  Current WordPress/PHP runtime acceptance config
.wp-env.6.8.json              Minimum WordPress 6.8.3/PHP 8.1 runtime config
assets/                       Admin CSS/JS
includes/                     Plugin application code
scripts/build-zip.sh          Reproducible runtime package builder
tests/run.php                 Zero-dependency regression/security tests
tests/background-authorization.php  Cron/background authorization regression
tests/sync-roundtrip.php      Controlled apply/readback/rollback regression
tests/secretbox-invalid-packages.php Credential fail-closed regression
tests/snapshot-integrity.php  Rollback snapshot tamper regression
tests/elementor-canonicalization.php Elementor raw-data canonicalization regression
tests/runtime/                Real WordPress + MySQL + Elementor acceptance
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
composer test
bash scripts/build-zip.sh
```

With Composer dev tools installed:

```bash
composer validate --strict
composer phpcs
composer test
composer audit
```

The CI additionally runs real `wp-env` acceptance against WordPress 6.8.3/PHP 8.1 and the current WordPress/PHP 8.3 environment with Elementor 4.2.3.

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

Version 0.2.2 can apply fresh remote changes without an administrator click. The setting is off by default. When an administrator enables it, that actor is recorded. Before each background write, the plugin verifies that the actor still has the bridge capability and `edit_post` for the target, temporarily activates that WordPress user context, checks GitHub again, and then uses the same conflict, snapshot, validation, readback and rollback sequence as a manual apply. The previous WordPress user context is restored immediately afterwards.

WP-Cron is request-driven, so one minute is a target cadence rather than a hard real-time guarantee.

## 0.2.2 hardening

Version 0.2.2 closes three failures found through adversarial and real-runtime testing:

- malformed encrypted credential packages are structurally rejected before decryption;
- rollback snapshots must match the SHA-256 integrity fingerprint stored when they were created;
- Elementor raw-data defaults such as non-widget `isInner: false` and omitted false `isLocked` are canonicalized before hashing/save, preventing normal Elementor normalization from causing a false roundtrip failure while malformed types are still rejected.

The permanent CI now includes the controlled failure paths plus real WordPress/MySQL/Elementor save/readback, snapshot-tamper rejection and `edit_post` denial tests.

## Status

Version `0.2.2` has source/static/controlled checks, PHP 8.1-8.5 regression coverage, reproducible packaging, Plugin Check, and real WordPress + Elementor runtime acceptance for the configurations named above. That evidence does not prove a specific production site. A site-specific staging roundtrip remains required before a production-ready claim, especially with Elementor Pro and site-specific integrations.
