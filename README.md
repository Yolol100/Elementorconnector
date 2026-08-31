# Elementor JSON Bridge

A conservative WordPress plugin that synchronizes selected Elementor documents with a private GitHub repository, locally downloads Elementor-built Pages and Posts as JSON, and safely re-imports Elementor JSON into a chosen Page, Post, or Template.

## Core flows

GitHub synchronization:

`Elementor -> WordPress plugin -> private GitHub repo/branch -> reviewed edit -> fresh conflict check -> optional automatic apply -> backup -> validate -> Elementor save -> readback -> rollback on failure`

Local admin export:

`Pages/Posts list -> Export Elementor JSON -> optional header/footer -> local JSON download`

Smart re-import:

`Elementor Saved Templates -> Import Templates -> inspect JSON -> recognize possible target -> choose replace/new Page/new Post/new Template -> snapshot if replacing -> save -> readback -> rollback on failure`

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
- Smart re-import accepts one JSON file up to 5 MB. Replacement targets are limited to Pages, Posts, and Elementor Templates; Products are excluded server-side.
- Smart replacement is never the default action, requires explicit confirmation, creates a snapshot first, verifies exact Elementor readback, and verifies rollback if apply fails.
- Unexpected local-export/import failures are normalized at their REST boundaries instead of exposing raw internal exception details.
- Production use remains staging-first, especially with Pro, Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components and Atomic/V4 content.

## Page and post JSON export

Version `0.4.0` keeps `Export Elementor JSON` on the normal WordPress **Pages** and **Posts** lists for documents built with Elementor.

The action opens a modal built with `@wordpress/components` and WordPress React. Its interaction model stays native to WordPress admin while the scoped surface, radius and button treatment borrow from Material Design 3.

The modal offers **Include header and footer**:

- Off: download the source page/post using the bridge's normal Elementor document wrapper.
- On: if Elementor Pro Theme Builder is available, resolve the matching header and footer for that document and download one JSON bundle containing `document`, `header`, `footer`, and site-part metadata.
- If Pro is unavailable, no site part matches, or Theme Builder cannot safely resolve the current condition context, the source document still downloads and the modal reports the missing site parts.

After a clean export, the modal closes and WordPress's compact `Snackbar` component confirms the download instead of leaving a large success Notice in the modal. Warnings and errors remain visible in the modal because they may require attention.

For Theme Builder condition evaluation, Pages use WordPress `page_id` query semantics and Posts use `p`. That preserves the `is_page`/`is_single` distinction used by WordPress and condition systems while the plugin restores the prior global query state after the lookup.

The multi-document file uses the explicit bridge format `elementor-json-bridge/site-parts-bundle`. It is not claimed to be a native single-template Elementor Library import. The embedded document/header/footer values remain individual Elementor document wrappers.

Products are deliberately excluded. The REST export route also enforces the bridge management capability plus WordPress `edit_post`, so manually crafting a request does not bypass that restriction.

## Smart Elementor JSON re-import

Version `0.4.0` adds a guarded smart-import layer to Elementor's existing **Import Templates** action on the Saved Templates screen.

The custom flow is intentionally JSON-only. Choosing a ZIP or wanting Elementor's original behavior remains possible through **Use standard Elementor import**.

Before any write, the JSON is analyzed and the modal offers four actions:

- replace an existing compatible Elementor Page, Post, or Template;
- create a new WordPress Page as a **draft**;
- create a new WordPress Post as a **draft**;
- create a new Elementor Template through Elementor's own local Template Library importer.

Recognition is fail-closed rather than fuzzy. The bridge proposes an existing target only when a strong deterministic signal agrees with the JSON title/type, or when exactly one compatible exact-title item exists. Strong signals include bridge bundle source metadata, a native `elementor-{id}-YYYY-MM-DD.json` template filename whose ID/title still agree, and an exact Page/Post bridge export slug plus title. Ambiguous files are not assigned automatically.

Create-new Template is the initial selection. Replacing requires a selected target plus a second explicit confirmation. The existing WordPress title stays intact while Elementor content/page settings are replaced. A private snapshot is created first, followed by save/readback fingerprint verification. Any failed apply is rolled back from the integrity-checked snapshot and the restored fingerprint is verified.

Normal Page/Post creation and replacement are limited to Page-style source types (`page`, `wp-page`, `wp-post`). Specialized source types such as headers or footers can only target a compatible Elementor Template or be imported as a new Template. Products never enter the target query.

Current Elementor template JSON can also contain `global_classes` and `global_variables`. Smart Page/Post replacement preserves references in the document but does not claim to migrate missing global definitions between sites; the modal warns about this and the standard Elementor import remains the cross-site fallback.

## Repository layout

```text
.github/workflows/ci.yml      PHP/static/package/Plugin Check + real runtime CI
.wp-env.json                  Current WordPress/PHP runtime acceptance config
.wp-env.6.8.json              Minimum WordPress 6.8.3/PHP 8.1 runtime config
assets/                       Admin CSS/JS for export and smart import modals
includes/Admin/               Admin pages, REST endpoints and UI bootstraps
includes/Elementor/           Elementor document, validation, export and import services
includes/                     Remaining plugin application code
scripts/build-zip.sh          Reproducible runtime package builder
tests/local-export.php        Controlled page/post/product export regression
tests/template-import-ui.php  Smart import UI/security contract regression
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
node --check assets/js/template-import.js
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

The plugin accepts Elementor JSON structure version `0.4` and preserves the live document type. Pages and posts therefore remain `wp-page` and `wp-post` in the bridge. These same-document bridge files are not promised as drop-in Template Library imports. The smart create-new Template action deliberately maps Page/Post wrapper types to Elementor's native `page` template type before delegating to the local importer.

### Optional single-repository mode

If plugin source and website JSON share one GitHub repository, make the repository private first. Keep plugin source on `main`, use `site-sync` for website data, and use a root such as `site-data/elementor`.

Do not synchronize real site JSON while the repository is public. The plugin rejects public repositories before reading or writing synchronized content.

## Automatic apply

Automatic apply is off by default. When enabled, the plugin records the administrator who enabled it. Before every background write it rechecks the actor's bridge capability and `edit_post`, restores that user context temporarily, refreshes GitHub state, then uses the same conflict, snapshot, validation, readback and rollback gates as a manual apply.

WP-Cron is request-driven, so one minute is a target cadence rather than a hard real-time guarantee.

## Runtime evidence and remaining boundary

Version `0.4.0` keeps PHP 8.1-8.5 regression coverage, PHPCS/PHP compatibility, Composer audit, reproducible packaging and Plugin Check. Real `wp-env` acceptance covers:

- Elementor document save/readback;
- snapshot tamper rejection;
- permission denial;
- local Page and Post JSON export;
- Product exclusion;
- graceful header/footer fallback when Elementor Pro is absent;
- deterministic recognition of an existing Page export;
- smart replacement plus rollback snapshot creation and exact readback;
- new draft Page and Post creation;
- native Elementor Template creation;
- Product target rejection;
- incompatible document-type rejection.

Controlled Theme Builder coverage additionally proves that Page lookups use `page_id`, Post lookups use `p`, prior WordPress query globals are restored, and an Elementor Pro condition-resolution exception degrades to a source-only export warning. Controlled REST/export coverage verifies that unexpected exceptions do not leak their raw message to the client. The smart import source contract keeps the capture-phase native trigger interception, JSON-only custom route, explicit replacement confirmation, non-destructive default and standard Elementor fallback in place.

Actual Elementor Pro Theme Builder condition matching cannot be proven by the public Core-only CI environment. The final export/import modal appearance and full keyboard/screen-reader behavior also require a browser check on a real WordPress admin screen. Those are staging/browser gates rather than unresolved repository logic claims.
