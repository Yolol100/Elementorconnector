# Elementor JSON Bridge

Version 0.5.0 expands the project from an Elementor-only synchronization bridge into a conservative WordPress content bridge. After one private GitHub connection, editable WordPress content is discovered automatically and can be reviewed/edited through GitHub without a per-page enable or first-export step.

## Core flow

`WordPress -> private GitHub content repo -> reviewed edit -> fresh conflict check -> snapshot -> WordPress/ACF/Yoast/Elementor APIs -> readback -> verified rollback on failure`

The plugin itself does not call OpenAI and does not require an MCP server. ChatGPT, Codex or another tool can work on the GitHub copy; WordPress remains the only component that writes to the live WordPress data model.

## Managed content envelope

Existing managed items use `elementor-json-bridge/wordpress-content`, version `1`.

The envelope contains:

- core WordPress title, slug, status, block/classic content, excerpt, parent, menu order, comment/ping status, page template and featured-image ID;
- visible taxonomies, represented by existing term slugs;
- ACF values when ACF is active, bound to the exported field name/key/type identity;
- a conservative whitelist of user-editable Yoast SEO metadata when Yoast is active;
- non-protected registered post meta whose registration exposes it through `show_in_rest`;
- Elementor payload only when `_elementor_edit_mode=builder` and the item is a real editable Elementor document.

The bridge deliberately does not bulk expose arbitrary private post meta, users, passwords, plugin settings, options, orders, payment data or database tables.

## Zero-config site repository

Default root: `site-data`.

```text
site-data/
  bridge.json
  site-index.json
  content/
    pages/42.json
    posts/91.json
    templates/120.json
    custom/{post-type}/123.json
  requests/
    {request-id}.json
```

`bridge.json` is the machine-readable protocol contract. `site-index.json` maps every discovered managed item to its canonical GitHub path, WordPress ID, post type, title, slug and feature flags.

Existing content must be edited at the exact path listed in `site-index.json`. The `source.id` and `source.post_type` inside an existing content file are stable identity and cannot be reassigned through GitHub.

A known 0.4.x Elementor-only sync path is migrated safely by clearing the local remembered base and reseeding the new `content/` path. The old remote JSON is not deleted or silently overwritten.

## Creating new WordPress content

Create a unique request file under `site-data/requests/`:

```json
{
  "format": "elementor-json-bridge/create-content",
  "version": 1,
  "request_id": "unique-id",
  "post_type": "page",
  "post": {
    "title": "New page",
    "slug": "new-page",
    "content": "<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->",
    "excerpt": ""
  }
}
```

The bridge creates the item as a **draft**, records the `request_id` to make retries idempotent, and writes a `result` object back into the request file with the new WordPress ID and canonical content path.

Optional `taxonomies`, `acf`, `yoast` and `registered_meta` sections can be supplied when those fields already exist and pass the same validation rules. The request protocol creates ordinary WordPress content; it does not invent a new Elementor layout for a brand-new item.

## Safety contract

- The selected content repository must be private. Public repositories are rejected before managed reads/writes.
- GitHub authentication uses Device Flow. Only the public Client ID is configured; access/refresh tokens are encrypted at rest.
- The completed GitHub connection records the administrator who authorized background changes. Automatic apply is activated at that point; it remains off before connection.
- Every background write rechecks the recorded actor's bridge capability and `edit_post`; publishing additionally requires the post type's publish capability.
- Taxonomy assignments require the taxonomy's assign capability and can reference existing terms only.
- Registered metadata requires `edit_post_meta` and is limited to non-protected `show_in_rest` registrations.
- ACF updates fail closed when the field key/type identity differs from the exported field.
- Yoast metadata fails closed if Yoast is unavailable; calculated score/indexable data is not synchronized.
- Elementor data can only be applied to an existing Elementor-builder document. Normal WordPress content is never silently converted into Elementor.
- Both the live WordPress fingerprint and the GitHub SHA/fingerprint are rechecked before apply. Both sides changing from the same base becomes an explicit conflict.
- Every apply creates an integrity-checked local snapshot, performs API-based writes, reads the complete envelope back, and compares the canonical SHA-256 fingerprint.
- Apply/readback failure triggers snapshot restore and a second readback verification.
- The same per-document lock protects GitHub apply and local Elementor replacement from concurrent writes.

## Existing Elementor admin flows

The previous local Elementor functionality remains:

- Elementor-built Pages/Posts expose **Export Elementor JSON**; optional Theme Builder header/footer export remains available when Elementor Pro can resolve matching site parts.
- Pages/Posts expose **Import Elementor template**; unchecked creates a draft, checked replaces only a safely re-recognized same-type Elementor target.
- Elementor's native **Templates -> Import Templates** behavior remains untouched, including normal ZIP/template import.

## Runtime matrix

CI covers:

- PHP 8.1-8.5 syntax, WPCS/PHP compatibility and dependency audit;
- reproducible release packaging;
- WordPress Plugin Check;
- real WordPress 6.8.3 / PHP 8.1 + Elementor + ACF;
- current WordPress / PHP 8.3 + Elementor + ACF + current Yoast;
- normal non-Elementor Page/Post content roundtrip;
- registered metadata and taxonomy export/apply;
- ACF identity/value roundtrip;
- Yoast field roundtrip when Yoast is present;
- non-Elementor isolation;
- create-content draft creation;
- existing Elementor save/readback and local import/export regressions;
- snapshot-integrity and rollback regressions.

Production GitHub credentials, Elementor Pro Theme Builder condition resolution, and final authenticated wp-admin browser/accessibility behavior remain staging/browser evidence gates.

## Repository layout

```text
.github/workflows/ci.yml
.wp-env.json
.wp-env.6.8.json
assets/
includes/
  Admin/
  Backup/
  Content/WordPressDocument.php
  Elementor/
  GitHub/
  Sync/
scripts/build-zip.sh
tests/
  runtime/
docs/architecture.md
readme.txt
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

## GitHub App

For the content bridge the GitHub App needs Repository permissions -> **Contents: Read and write** on the selected private content repository. Do not grant Administration, Actions, Issues or unrelated repository permissions just for synchronization.

A separate private repository per site/environment is the simplest setup. If source and site content intentionally share a repository, make it private and keep site content on a dedicated branch such as `site-sync`.
