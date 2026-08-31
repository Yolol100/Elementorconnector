# Architecture

## Purpose

Elementor JSON Bridge is a narrow synchronization, export and re-import boundary around WordPress/Elementor. It deliberately does not contain an AI client. ChatGPT/Codex can work on synchronized JSON through GitHub while WordPress retains final validation/write authority. Administrators can also download selected Elementor Pages and Posts locally and re-import controlled Elementor JSON without changing GitHub sync state directly.

## Canonical responsibilities

- **WordPress/Elementor**: live document state and final save authority.
- **Plugin sync layer**: GitHub transport, auth, validation, canonicalization, conflict detection, snapshots, rollback and verification.
- **Plugin local export layer**: read-only page/post download, product exclusion and optional Theme Builder site-part resolution.
- **Plugin smart import layer**: JSON upload validation, conservative target recognition, explicit replace/create intent, Page/Post draft creation, native Template creation, replacement snapshots, readback and rollback verification.
- **Private GitHub content repo or private site-data branch**: reviewable/versioned JSON transport and history.
- **ChatGPT/Codex**: optional reviewed editor of repository JSON; never trusted as runtime proof.

## State model

```text
clean
local_dirty
remote_pending
conflict
applying
verified
error
```

`attempted`, `applied` and `verified` are not interchangeable. A write reaches `verified` only after Elementor readback matches the expected canonical fingerprint. Local browser downloads do not enter or mutate this state model. Smart local import is a WordPress-side content action; any normal sync hooks that observe the resulting local save continue to own subsequent sync-state changes.

## Local page/post export

1. WordPress adds the row action only to `page` and `post` list rows that are Elementor documents and editable by the current bridge administrator.
2. The row action opens a WordPress Components modal in the admin screen.
3. The user chooses whether to include the active Elementor Theme Builder header/footer.
4. The protected REST route rechecks bridge capability, `edit_post`, post type and Elementor editability; UI visibility is never treated as authorization.
5. Without site parts, the existing `Documents::payload()` wrapper is returned directly for local download.
6. With site parts, the plugin asks Elementor Pro's Theme Builder condition manager for header/footer documents in a temporary singular query context. Pages use `page_id` so WordPress exposes page conditionals; Posts use `p` so WordPress exposes single-post conditionals.
7. Matching site parts are exported through the same `Documents` adapter and packaged with the source document in `elementor-json-bridge/site-parts-bundle` version 1.
8. Missing Pro, unmatched header/footer or an unexpected Theme Builder condition-resolution failure is non-destructive: the source document remains downloadable and the response carries a stable warning.
9. Products and all other post types fail closed server-side.
10. Expected bridge validation errors may return their stable administrator-facing message; unexpected REST-layer throwables are normalized to a generic server error rather than exposing raw exception details.
11. No local-export operation reads GitHub credentials, changes sync metadata, writes Elementor content or creates rollback snapshots.

The site-parts bundle is a bridge transport artifact, not a claim of native single-template Elementor Library compatibility.

## Smart template JSON re-import

The smart-import surface is layered on Elementor's existing Saved Templates import trigger. The plugin intercepts the native click in capture phase and opens its own WordPress Components modal. A one-shot bypass lets the user return to Elementor's original importer, so native ZIP behavior is not replaced.

### Input boundary

1. The custom REST path accepts exactly one uploaded `.json` file and caps it at the same 5 MB limit as the document validator.
2. Uploaded bytes are read only as data; the file is never executed.
3. The root must be an object. Standard document JSON may contain the five bridge/Elementor core fields plus Elementor's current `global_classes` and `global_variables` snapshots. Bridge site-parts bundles may provide their main `document` wrapper.
4. The core wrapper is validated and canonicalized through `PayloadValidator` before any action is offered.
5. ZIP remains on Elementor's standard importer.

### Recognition boundary

Recognition is a suggestion, never authorization and never an automatic destructive write. Create-new Template remains the default action.

A proposed target is returned only when one of these deterministic signals agrees with the JSON title/type and produces a compatible editable Elementor document:

1. bridge bundle `source.post_id` + `post_type` + exact title;
2. native `elementor-{id}-YYYY-MM-DD.json` filename + existing Template ID + exact title;
3. bridge `{post-slug}-elementor.json` filename + exact Page/Post slug + exact title;
4. one and only one compatible exact-title match.

No fuzzy title matching, nearest-neighbor guessing, product matching or silent ambiguity resolution is allowed. Manual target search is still server-filtered to editable `page`, `post` and `elementor_library` documents.

### Replacement path

1. User selects **Replace existing item** and a target, then separately confirms the destructive action.
2. Server rechecks bridge capability, target type, `edit_post`, Elementor editability and source/target type compatibility.
3. Existing WordPress title and live target document type are retained. Imported Elementor `content` and `page_settings` are adapted to that target type.
4. Current payload is validated and fingerprinted.
5. A private snapshot is created with reason `before_json_import`.
6. Imported payload is saved through `Documents::save_payload()` / Elementor's document API.
7. Readback is validated and must match the expected canonical SHA-256 fingerprint.
8. On any apply/readback failure, the integrity-checked snapshot is restored and the restored fingerprint must match the original current fingerprint.
9. If rollback cannot itself be verified, the operation returns a distinct rollback-failure error and never claims success.

Products are excluded before the document adapter is reached.

### Create-new paths

- **New Page** and **New Post** accept only Page-style source types (`page`, `wp-page`, `wp-post`). WordPress creates the item as `draft`, enables Elementor builder mode, saves through the document adapter and verifies readback. A failed creation is hard-deleted so no broken draft is left behind.
- **New Template** delegates to Elementor's local Template Library importer. Bridge `wp-page`/`wp-post` wrappers are mapped to Elementor's native `page` template type for this operation. The temporary JSON file is removed immediately after the native importer returns.

Native `global_classes`/`global_variables` may reference site-level definitions. Smart Page/Post replacement keeps references in document content but does not claim to migrate missing global definitions between sites; the UI warns about this and the standard Elementor importer remains the cross-site route.

## Safe remote apply

1. A manual administrator action or the opt-in automatic worker obtains a fresh remote check.
2. Plugin binds the pending change to the exact current GitHub blob SHA.
3. Plugin re-reads current local state and checks the remembered base fingerprint.
4. Any local/remote divergence becomes `conflict`; no overwrite occurs.
5. Plugin creates a private local snapshot and stores its SHA-256 integrity fingerprint.
6. JSON passes structural validation, live document-type validation and Elementor raw-data canonicalization.
7. Non-widget elements receive Elementor's default `isInner: false` when omitted; widgets omit `isInner`; false `isLocked` values are omitted. Malformed field types are rejected rather than coerced.
8. Plugin saves through Elementor `Document::save()`.
9. Plugin reads the document back through Elementor and canonicalizes it again.
10. Expected and actual fingerprints must match.
11. On mismatch/error, the snapshot is rehashed and must match its stored integrity fingerprint before rollback is allowed.
12. A damaged/tampered snapshot is rejected rather than trusted as recovery data.

## Credential boundary

GitHub user tokens are encrypted at rest with libsodium Secretbox when available or AES-256-GCM through OpenSSL as a fallback. The serialized encrypted package is treated as untrusted stored data during readback. Before decryption the plugin validates the algorithm marker and algorithm-specific structure, including exact Sodium nonce length and AES-GCM IV/tag lengths. Malformed packages fail closed through the plugin's normal `RuntimeException` boundary instead of leaking crypto-library exceptions.

## Automatic apply

Automatic apply is disabled by default. When enabled, request-driven WP-Cron checks enabled documents about once per minute. A pending file is checked again immediately before `apply_remote()` runs. The existing SHA/base-fingerprint conflict gates, snapshot, validation, Elementor save, readback verification and rollback remain authoritative. No inbound webhook is required.

## Safe GitHub export

- New remote file: create only when no unknown remote history exists.
- Existing remote file: update only with the exact GitHub blob SHA learned from the trusted base.
- Timeout/uncertain response: reconcile by reading the remote content before retrying.
- Rate limiting: persist a cooldown and avoid request storms.

## Single-repository mode

The source repository and site JSON may share one GitHub repository only when that repository is private. Keep plugin source on `main`, use a dedicated `site-sync` branch for live JSON, and use a root such as `site-data/elementor`. The plugin's private-repository guard rejects a public repository. The release builder copies an explicit runtime allowlist, so `site-data/` is not shipped in the plugin ZIP.

## Backups

Snapshots are private WordPress records. Each snapshot stores a SHA-256 fingerprint of the canonical payload and that fingerprint is verified before payload recovery. Git history is additional version history, not the sole backup. The plugin retains a bounded number of snapshots per document. Both GitHub remote apply and smart local replacement use this same snapshot integrity boundary.

## Runtime evidence

Repository CI includes real WordPress + MySQL + Elementor acceptance via `wp-env`, in addition to controlled regressions and static checks. The runtime matrix covers the minimum supported WordPress 6.8.3/PHP 8.1 combination and the current WordPress/PHP 8.3 combination with Elementor 4.2.3.

The production `Documents` adapter is exercised for real Elementor save/readback, snapshot integrity rejection, a real `edit_post` denial path, local Page/Post export, Product exclusion and the no-Pro site-part fallback. Version 0.4.0 additionally exercises smart import against real WordPress/Elementor: exact existing-Page recognition, replacement snapshot creation, replacement readback, new draft Page/Post creation, native Elementor Template creation, Product rejection and incompatible-type rejection.

Actual Elementor Pro Theme Builder condition matching remains configuration-scoped because the public CI environment contains Elementor Core only. The final export/import modal visual, keyboard and assistive-technology behavior also remains a browser acceptance gate.

## Non-goals for v0.4.x

- V3/V4 migration;
- creating or remapping site IDs;
- fuzzy or AI-based import-target matching;
- overwriting Products through smart import;
- using the site-parts bundle to automatically overwrite its embedded header/footer during main-document import;
- claiming smart Page/Post replacement migrates missing global classes/variables between different sites;
- replacing Elementor's native ZIP importer;
- proving every Elementor Pro Theme Builder condition without a Pro staging runtime;
- proving third-party widget/add-on availability;
- production deployment automation;
- OpenAI/MCP integration inside WordPress;
- generic Git hosting providers;
- inbound public webhooks.
