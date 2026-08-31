# Elementor JSON Bridge

A conservative WordPress plugin that synchronizes selected Elementor documents with a private GitHub repository, locally downloads Elementor-built Pages and Posts as JSON, and safely re-imports Page-style Elementor JSON from the normal Pages and Posts overviews.

## Core flows

GitHub synchronization:

`Elementor -> WordPress plugin -> private GitHub repo/branch -> reviewed edit -> fresh conflict check -> optional automatic apply -> backup -> validate -> Elementor save -> readback -> rollback on failure`

Local admin export:

`Pages/Posts list -> Export Elementor JSON -> optional header/footer -> local JSON download`

Page/Post re-import:

`Pages/Posts list -> Import Elementor template -> inspect JSON -> detect same-type existing target -> checkbox replace or create new draft -> snapshot if replacing -> save -> readback -> rollback on failure`

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
- Page/Post re-import accepts one Page-style JSON file up to 5 MB. Its destination is fixed by the current Pages or Posts overview; Products and Elementor Library templates are not replacement targets.
- Replacement is opt-in through one checkbox. With the checkbox off, import creates a new draft. With it on, the server re-recognizes the exact reviewed target before any write.
- Smart replacement uses the same document lock as GitHub synchronization, creates a snapshot first, verifies exact Elementor readback, and verifies rollback if apply fails.
- Elementor's existing Saved Templates import button remains native and untouched, including its normal ZIP/template behavior.
- Unexpected local-export/import failures are normalized at their REST boundaries instead of exposing raw internal exception details.
- Production use remains staging-first, especially with Pro, Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components and Atomic/V4 content.

## Page and post JSON export

Version `0.4.1` keeps `Export Elementor JSON` on the normal WordPress **Pages** and **Posts** lists for documents built with Elementor.

The action opens a modal built with `@wordpress/components` and WordPress React. Its interaction model stays native to WordPress admin while the scoped surface, radius and button treatment borrow from Material Design 3.

The modal offers **Include header and footer**:

- Off: download the source page/post using the bridge's normal Elementor document wrapper.
- On: if Elementor Pro Theme Builder is available, resolve the matching header and footer for that document and download one JSON bundle containing `document`, `header`, `footer`, and site-part metadata.
- If Pro is unavailable, no site part matches, or Theme Builder cannot safely resolve the current condition context, the source document still downloads and the modal reports the missing site parts.

After a clean export, the modal closes and WordPress's compact `Snackbar` component confirms the download instead of leaving a large success Notice in the modal. Warnings and errors remain visible in the modal because they may require attention.

For Theme Builder condition evaluation, Pages use WordPress `page_id` query semantics and Posts use `p`. That preserves the `is_page`/`is_single` distinction used by WordPress and condition systems while the plugin restores the prior global query state after the lookup.

The multi-document file uses the explicit bridge format `elementor-json-bridge/site-parts-bundle`. It is not claimed to be a native single-template Elementor Library import. The embedded document/header/footer values remain individual Elementor document wrappers.

Products are deliberately excluded. The REST export route also enforces the bridge management capability plus WordPress `edit_post`, so manually crafting a request does not bypass that restriction.

## Page and post Elementor JSON re-import

Version `0.4.1` moves the bridge's custom JSON import away from Elementor's Saved Templates screen. Elementor's own **Import Templates** button is no longer intercepted or replaced.

The normal WordPress **Pages** and **Posts** overviews each receive an **Import Elementor template** button. The button opens the same WordPress Components + Material-inspired modal language used by the local export flow.

The custom Page/Post flow accepts one JSON file up to 5 MB and only accepts Page-style source types (`page`, `wp-page`, `wp-post`). Specialized templates such as headers and footers stay with Elementor's native Templates workflow.

Before any write, the server analyzes the JSON inside the current destination only:

- on **Pages**, only editable Elementor Pages can be recognized;
- on **Posts**, only editable Elementor Posts can be recognized;
- a bridge source ID/type/title match or exact bridge slug/title match is strong evidence;
- otherwise exactly one same-type exact-title item may be proposed;
- multiple matches, missing matches and incompatible items fail closed and are not assigned automatically.

If a unique existing item is found, the modal shows it with one checkbox: **Replace this existing Page/Post**.

- Checkbox **off**: the existing item is left untouched and a new Page/Post is created as a **draft**.
- Checkbox **on**: the analyzed target ID is sent back as an expected identity, the server repeats recognition, and replacement proceeds only if the same target is still the unique safe match.

Replacement shares the sync layer's document lock, so a GitHub apply/export and a local replacement cannot write the same Elementor document concurrently. The existing WordPress title stays intact, an integrity-checked private snapshot is created, Elementor content/page settings are saved through the document API, and exact readback is required. Any failed apply/readback attempts verified rollback before an error is returned.

Current Elementor template JSON can also contain `global_classes` and `global_variables`. Page/Post import keeps references in the document but does not claim to migrate missing global definitions between sites; the modal warns about this and Elementor's untouched native Templates import remains the cross-site/template route.

## Repository layout

```text
.github/workflows/ci.yml      PHP/static/package/Plugin Check + real runtime CI
.wp-env.json                  Current WordPress/PHP runtime acceptance config
.wp-env.6.8.json              Minimum WordPress 6.8.3/PHP 8.1 runtime config
assets/                       Admin CSS/JS for export and Page/Post import modals
includes/Admin/               Admin pages, REST endpoints and UI bootstraps
includes/Elementor/           Elementor document, validation, export and import services
includes/                     Remaining plugin application code
scripts/build-zip.sh          Reproducible runtime package builder
tests/local-export.php        Controlled page/post/product export regression
tests/template-import-ui.php  Page/Post import UI/security contract regression
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

The plugin accepts Elementor JSON structure version `0.4` and preserves the live document type. Pages and posts therefore remain `wp-page` and `wp-post` in the bridge. These same-document bridge files are not promised as universal Template Library imports; the Page/Post overview import path accepts them only as Page-style content and adapts the wrapper to the actual destination document type before saving.

### Optional single-repository mode

If plugin source and website JSON share one GitHub repository, make the repository private first. Keep plugin source on `main`, use `site-sync` for website data, and use a root such as `site-data/elementor`.

Do not synchronize real site JSON while the repository is public. The plugin rejects public repositories before reading or writing synchronized content.

## Automatic apply

Automatic apply is off by default. When enabled, the plugin records the administrator who enabled it. Before every background write it rechecks the actor's bridge capability and `edit_post`, restores that user context temporarily, refreshes GitHub state, then uses the same conflict, snapshot, validation, readback and rollback gates as a manual apply.

WP-Cron is request-driven, so one minute is a target cadence rather than a hard real-time guarantee.

## Runtime evidence and remaining boundary

Version `0.4.1` keeps PHP 8.1-8.5 regression coverage, PHPCS/PHP compatibility, Composer audit, reproducible packaging and Plugin Check. Real `wp-env` acceptance covers:

- Elementor document save/readback;
- snapshot tamper rejection;
- permission denial;
- local Page and Post JSON export;
- Product exclusion;
- graceful header/footer fallback when Elementor Pro is absent;
- destination-scoped recognition for an existing Page and Post;
- fail-closed ambiguous-target recognition;
- shared-lock rejection during a concurrent local/sync write window;
- stale analyzed-target rejection;
- checked replacement plus rollback snapshot creation and exact readback;
- unchecked new draft Page and Post creation;
- Product destination rejection;
- specialized non-Page template rejection.

Controlled Theme Builder coverage additionally proves that Page lookups use `page_id`, Post lookups use `p`, prior WordPress query globals are restored, and an Elementor Pro condition-resolution exception degrades to a source-only export warning. Controlled REST/export coverage verifies that unexpected exceptions do not leak their raw message to the client. The Page/Post import source contract verifies that Elementor's native Templates trigger is not intercepted, replacement is unchecked by default, the target identity is rebound at execution, and the shared document lock is wired into both sync and replacement.

Actual Elementor Pro Theme Builder condition matching cannot be proven by the public Core-only CI environment. The final export/import modal appearance and full keyboard/screen-reader behavior also require a browser check on a real WordPress admin screen. Those are staging/browser gates rather than unresolved repository logic claims.
