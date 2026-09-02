# Architecture

## Purpose

Elementor JSON Bridge is a narrow synchronization, export and re-import boundary around WordPress/Elementor. It deliberately does not contain an AI client. ChatGPT/Codex can work on synchronized JSON through GitHub while WordPress retains final validation/write authority. Administrators can also download selected Elementor Pages and Posts locally and re-import controlled Page-style Elementor JSON from the normal Pages and Posts overviews without changing GitHub sync state directly.

## Canonical responsibilities

- **WordPress/Elementor**: live document state and final save authority.
- **Plugin sync layer**: GitHub transport, auth, validation, canonicalization, conflict detection, snapshots, rollback and verification.
- **Plugin local export layer**: read-only page/post download, product exclusion and optional Theme Builder site-part resolution.
- **Plugin Page/Post import layer**: JSON upload validation, destination-scoped target recognition, checkbox-controlled replace/create intent, draft creation, shared document locking, replacement snapshots, readback and rollback verification.
- **Elementor target capability layer**: read-only discovery of the exact registered Core/Pro/add-on widgets, elements, document types, Dynamic Tags and exposed controls for the connected target.
- **Elementor native Templates import**: remains the owner of normal Template Library and ZIP import behavior; the plugin does not intercept that trigger.
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

`attempted`, `applied` and `verified` are not interchangeable. A write reaches `verified` only after Elementor readback matches the expected canonical fingerprint. Local browser downloads do not enter or mutate this state model. Local Page/Post import is a WordPress-side content action; normal sync hooks that observe the resulting local save continue to own subsequent sync-state changes.

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

## Page/Post template JSON import

The bridge no longer layers custom behavior onto Elementor's Saved Templates import trigger. The normal **Pages** and **Posts** list screens each expose a dedicated `Import Elementor template` button; Elementor's own Templates importer remains native and unchanged.

### Input boundary

1. The custom REST path accepts exactly one uploaded `.json` file and caps it at the same 5 MB limit as the document validator.
2. Uploaded bytes are read only as data; the file is never executed.
3. The root must be an object. Standard document JSON may contain the five bridge/Elementor core fields plus Elementor's current `global_classes` and `global_variables` snapshots. Bridge site-parts bundles may provide their main `document` wrapper.
4. The core wrapper is validated and canonicalized through `PayloadValidator` before any action is offered.
5. Only Page-style source types (`page`, `wp-page`, `wp-post`) can use the Page/Post overview importer. Specialized document types and ZIP archives remain with Elementor's native Templates workflow.
6. The destination is explicit and server-limited to `page` or `post`; browser UI state cannot expand that scope.

### Recognition boundary

Recognition is a suggestion, never authorization and never an automatic destructive write. The destination screen is part of the identity contract.

A proposed existing target is returned only when it is an editable Elementor document of the current destination type and one of these deterministic conditions holds:

1. bridge bundle `source.post_id` + matching `post_type` + exact title;
2. exact bridge `{post-slug}-elementor.json` filename + same-type slug + exact title;
3. one and only one same-type exact-title WordPress item, which must itself be an editable Elementor document.

No fuzzy title matching, cross-type matching, product matching, Template Library matching or silent ambiguity resolution is allowed. If no unique match exists, the UI has no replacement checkbox and import can only create a new draft.

### Checkbox execution contract

If a target is recognized, the modal exposes exactly one destructive choice: **Replace this existing Page/Post**.

- Checkbox off is the default. The recognized item is left untouched and a new destination item is created as `draft`.
- Checkbox on sends the analyzed target ID as `expected_target_id`.
- Execution reparses the uploaded JSON and repeats destination-scoped recognition immediately before any replacement.
- The fresh recognized ID must equal `expected_target_id`; otherwise the write stops fail-closed and the user must analyze again.

This keeps the UI simple without turning stale analysis into write authorization.

### Replacement path

1. Server rechecks bridge capability, destination type, `edit_post`, Elementor editability, Page-style source compatibility and the fresh recognized target identity.
2. Replacement acquires the same per-document `Sync\Lock` used by GitHub export/apply. If a sync operation already owns the document, local replacement fails before snapshot/write; if local replacement owns it first, sync waits/fails through the same lock boundary.
3. After lock acquisition, the target is re-read and revalidated.
4. Existing WordPress title and live target document type are retained. Imported Elementor `content` and `page_settings` are adapted to that target type.
5. Current payload is validated and fingerprinted.
6. A private snapshot is created with reason `before_json_import`.
7. Imported payload is saved through `Documents::save_payload()` / Elementor's document API.
8. Readback is validated and must match the expected canonical SHA-256 fingerprint.
9. On any apply/readback failure, the integrity-checked snapshot is restored and the restored fingerprint must match the original current fingerprint.
10. If rollback cannot itself be verified, the operation returns a distinct rollback-failure error and never claims success.
11. The document lock is released in a `finally` boundary on every completed attempt.

Products and Elementor Library templates are excluded before the document adapter is reached.

### Create-new path

Unchecked import creates one new WordPress item of the current overview type (`page` or `post`) as `draft`, enables Elementor builder mode, adapts the Page-style wrapper to the new live document type, saves through the production document adapter and verifies exact readback. A failed creation is hard-deleted so no broken draft is left behind.

Native `global_classes`/`global_variables` may reference site-level definitions. Page/Post import keeps references in document content but does not claim to migrate missing global definitions between sites; the UI warns about this and Elementor's native Templates importer remains the cross-site/template route.

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

## Target Elementor capability inventory

The connected private site repository also receives `site-data/elementor-capabilities.json`. This artifact is separate from editable WordPress content and is never applied back to WordPress.

The collector records the exact target environment plus registered widgets, layout/Atomic elements, Elementor document/template types and Dynamic Tags. Widget/element/tag records identify whether the runtime owner is Elementor Core, Elementor Pro or a third-party plugin and expose the controls needed for target-bound planning. Classic Form `submit_actions` choice keys are bounded and treated as availability evidence only.

The collector is fail-soft around optional or version-sensitive Elementor managers and controls. Missing surfaces produce stable warning codes rather than invented capabilities. The synchronizer preserves the existing private-repository gate and adds no public endpoint.

A full inventory check is throttled to once per hour during normal polling. Plugin activation, deactivation or updater completion invalidates that throttle so the next poll can refresh sooner. Because the serialized payload is deterministic, unchanged capability state does not create an extra GitHub commit.

This inventory can prove that an add-on widget/element/tag is registered on the target. It does not prove that an add-on should be installed, that a chosen composition renders correctly, or that Forms, Theme Builder, WooCommerce or other site-bound behavior works end-to-end.

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

Snapshots are private WordPress records. Each snapshot stores a SHA-256 fingerprint of the canonical payload and that fingerprint is verified before payload recovery. Git history is additional version history, not the sole backup. The plugin retains a bounded number of snapshots per document. Both GitHub remote apply and checked local Page/Post replacement use this same snapshot integrity boundary.

## Runtime evidence

Repository CI includes real WordPress + MySQL + Elementor acceptance via `wp-env`, in addition to controlled regressions and static checks. The runtime matrix covers the minimum supported WordPress 6.8.3/PHP 8.1 combination and the current WordPress 7.1/PHP 8.3 combination with Elementor 4.2.4.

The production `Documents` adapter is exercised for real Elementor save/readback, snapshot integrity rejection, a real `edit_post` denial path, local Page/Post export, Product exclusion and the no-Pro site-part fallback. Target capability coverage additionally requires a real registered widget inventory, element inventory, document-type inventory and Dynamic Tags surface from the same Elementor runtime. Version 0.4.1 additionally exercises destination-scoped Page/Post recognition, fail-closed ambiguous matching, shared-lock rejection, stale analyzed-target rejection, checked replacement snapshot/readback, unchecked draft Page/Post creation, Product destination rejection and specialized non-Page source rejection.

Source-contract coverage also asserts that Elementor's native Templates trigger is no longer intercepted and that both GitHub sync and local replacement receive the same lock instance from the plugin bootstrap.

Actual Elementor Pro Theme Builder condition matching remains configuration-scoped because the public CI environment contains Elementor Core only. The final export/import modal visual, keyboard and assistive-technology behavior also remains a browser acceptance gate.

## Remaining non-goals

- V3/V4 migration;
- creating or remapping site IDs;
- fuzzy or AI-based import-target matching;
- overwriting Products or Elementor Library templates through the Page/Post importer;
- using the site-parts bundle to automatically overwrite its embedded header/footer during main-document import;
- claiming Page/Post import migrates missing global classes/variables between different sites;
- replacing or intercepting Elementor's native Templates/ZIP importer;
- proving every Elementor Pro Theme Builder condition without a Pro staging runtime;
- auto-installing, activating or choosing third-party add-ons merely because they are popular;
- production deployment automation;
- OpenAI/MCP integration inside WordPress;
- generic Git hosting providers;
- inbound public webhooks.
