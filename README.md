# Elementor JSON Bridge

A conservative WordPress plugin that synchronizes selected Elementor documents with a private GitHub repository and can locally download Elementor-built Pages and Posts as JSON without direct WordPress access for external tools.

## Core flows

GitHub synchronization:

`Elementor -> WordPress plugin -> private GitHub repo/branch -> reviewed edit -> fresh conflict check -> optional automatic apply -> backup -> validate -> Elementor save -> readback -> rollback on failure`

Local admin export:

`Pages/Posts list -> Export Elementor JSON -> optional header/footer -> local JSON download`

## Safety contract

- No OpenAI API and no MCP server are required by this plugin.
- GitHub authentication uses Device Flow. Never commit access tokens, refresh tokens, client secrets or site credentials.
- Encrypted credential packages fail closed when their Sodium nonce or AES-GCM IV/tag/cipher structure is malformed.
- Real Elementor JSON must stay in private GitHub storage when synchronized. A separate private content repo is simplest; single-repository mode is supported only when this repository is private and site JSON uses the dedicated `site-sync` branch.
- Existing remote files with unknown history are never overwritten.
- Automatic remote apply is opt-in and disabled by default.
- Both manual and automatic apply recheck the remote SHA and local base before any Elementor write.
- Every apply creates a local snapshot first, and that snapshot's stored SHA-256 integrity fingerprint is verified before rollback use.
- Incoming JSON is structurally validated and canonicalized to Elementor raw-data defaults before save.
- Elementor writes go through Elementor's document API, not direct `_elementor_data` mutation.
- A successful apply is not trusted until the document is read back and fingerprinted.
- Local download is read-only with respect to GitHub sync state.
- Local download is allowed only for Elementor-built WordPress `page` and `post` documents; Products are rejected in both UI and server-side service.
- Production use remains staging-first, especially with Pro, Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components and Atomic/V4 content.

## Page and post JSON export

Version `0.3.0` adds `Export Elementor JSON` to the normal WordPress **Pages** and **Posts** lists for documents built with Elementor.

The action opens a modal built with `@wordpress/components` and WordPress React. Its interaction model stays native to WordPress admin while the scoped surface, radius and button treatment borrow from Material Design 3.

The modal offers **Include header and footer**:

- Off: download the source page/post using the bridge's normal Elementor document wrapper.
- On: if Elementor Pro Theme Builder is available, resolve the matching header and footer for that document and download one JSON bundle containing `document`, `header`, `footer`, and site-part metadata.
- If Pro or a matching site part is unavailable, the source document still downloads and the modal reports the missing parts.

The multi-document file uses the explicit bridge format `elementor-json-bridge/site-parts-bundle`. It is not claimed to be a native single-template Elementor Library import. The embedded document/header/footer values remain individual Elementor document wrappers.

Products are deliberately excluded. The REST export route also enforces the bridge management capability plus WordPress `edit_post`, so manually crafting a request does not bypass that restriction.

## Repository layout

```text
.github/workflows/ci.yml      PHP/static/package/Plugin Check + real runtime CI
.wp-env.json                  Current WordPress/PHP runtime acceptance config
.wp-env.6.8.json              Minimum WordPress 6.8.3/PHP 8.1 runtime config
assets/                       Admin CSS/JS, including the local export modal
includes/Admin/               Admin page, REST endpoints and post/page row action
includes/Elementor/           Elementor document, validation and local export services
includes/                     Remaining plugin application code
scripts/build-zip.sh          Reproducible runtime package builder
tests/local-export.php        Controlled page/post/product export regression
tests/runtime/                Real WordPress + MySQL + Elementor acceptance
docs/architecture.md          Runtime boundaries and state model
AGENTS.md                     Rules for AI/code agents working in this repo
SECURITY.md                   Security reporting and secret/data policy
composer.json                 Development-only quality tooling
phpcs.xml.dist                WordPress Coding Standards + PHP compatibility
.distignore                   Release package exclusions
```

## Local checks

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
node --check assets/js/admin.js
node --check assets/js/local-export.js
composer validate --strict
composer phpcs
composer test
composer audit
bash scripts/build-zip.sh
```

CI additionally runs real `wp-env` acceptance against WordPress 6.8.3/PHP 8.1 and the current WordPress/PHP 8.3 environment with Elementor 4.2.3.

## GitHub repository used for site JSON

The simplest setup is a different private repository per site or controlled environment:

```text
elementor/pages/42.json
elementor/posts/91.json
elementor/templates/120.json
elementor/custom/{post-type}/123.json
```

The plugin accepts Elementor JSON structure version `0.4` and preserves the live document type. Pages and posts therefore remain `wp-page` and `wp-post` in the bridge. These same-document bridge files are not promised as drop-in Template Library imports.

### Optional single-repository mode

If plugin source and website JSON share one GitHub repository, make the repository private first. Keep plugin source on `main`, use `site-sync` for website data, and use a root such as `site-data/elementor`.

Do not synchronize real site JSON while the repository is public. The plugin rejects public repositories before reading or writing synchronized content.

## Automatic apply

Automatic apply is off by default. When enabled, the plugin records the administrator who enabled it. Before every background write it rechecks the actor's bridge capability and `edit_post`, restores that user context temporarily, refreshes GitHub state, then uses the same conflict, snapshot, validation, readback and rollback gates as a manual apply.

WP-Cron is request-driven, so one minute is a target cadence rather than a hard real-time guarantee.

## Runtime evidence and remaining boundary

Version `0.3.0` keeps PHP 8.1-8.5 regression coverage, PHPCS/PHP compatibility, Composer audit, reproducible packaging and Plugin Check. Real `wp-env` acceptance covers:

- Elementor document save/readback;
- snapshot tamper rejection;
- permission denial;
- local Page and Post JSON export;
- Product exclusion;
- graceful header/footer fallback when Elementor Pro is absent.

Actual Elementor Pro Theme Builder condition matching cannot be proven by the public Core-only CI environment. The final modal appearance and keyboard/screen-reader behavior also require a browser check on a WordPress admin screen. Those are staging/browser gates rather than unresolved repository logic claims.
