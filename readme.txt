=== Elementor JSON Bridge ===
Contributors: webactueel
Tags: wordpress, github, elementor, acf, yoast, woocommerce
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely connect editable WordPress, Elementor, ACF, Yoast SEO and WooCommerce catalog operations to a private GitHub repository.

== Description ==

Elementor JSON Bridge keeps WordPress as the execution boundary while allowing reviewed tools to prepare versioned JSON in a private GitHub repository. WordPress rechecks permissions, validates the complete desired state, writes through supported WordPress/plugin APIs and reads the result back before reporting success.

Version 0.6.0 adds guarded request operations for WordPress content, new/existing Elementor documents, WooCommerce products, product variations and taxonomy terms. It also maintains a live WordPress Abilities catalog so connected tools can plan against what the target actually exposes instead of guessing from a plugin name or version.

The plugin itself does not call OpenAI and does not require an MCP server.

== Repository protocol ==

The default root is `site-data` and can contain:

`site-data/bridge.json`
`site-data/site-index.json`
`site-data/abilities.json`
`site-data/elementor-capabilities.json`
`site-data/media.json`
`site-data/content/...`
`site-data/requests/{request-id}.json`

`bridge.json` is the machine-readable contract. Existing common WordPress/ACF/Yoast/Elementor content is edited at the exact canonical path listed in `site-index.json`.

WooCommerce catalog fields use `manage-product` and `manage-product-variation` request files so product writes use WooCommerce CRUD and verified readback. Product `brand_ids` are aligned with the canonical `product_brand` taxonomy envelope so the WooCommerce product model and WordPress taxonomy API cannot overwrite each other with different desired states.

`abilities.json` records the registered external ability surface plus WordPress/ACF/Elementor/WooCommerce/Yoast version context. An ability appearing in source code is not treated as available unless the live target actually registers it.

== Request operations ==

`elementor-json-bridge/manage-post` version 2 supports create, update and delete for managed WordPress content. Version 2 is a safety migration: pending version-1 `manage-post` request files must be regenerated before execution. New content is always a draft. Update and delete require a `base_hash` calculated from the exact canonical content JSON used to author the request; stale hashes fail closed. Delete additionally requires `confirm_destructive=true`.

The version-2 `post` object accepts only fields present in the canonical conflict/snapshot envelope: title, slug, status, content, excerpt, parent, menu order, comment status, ping status, page template and featured image. Author, date, password, post format and sticky state are deliberately not request-mutable because those fields are outside that canonical envelope and therefore cannot be given the same conflict and durable-snapshot guarantees without expanding the protocol.

When an explicit Elementor payload is supplied for a new item, the bridge creates the document through Elementor's document manager and saves it through Elementor's document API. It never directly writes `_elementor_data`.

`elementor-json-bridge/manage-product` version 2 supports WooCommerce product create, read, update and delete. Read returns the current `base_hash`; update/delete require that exact hash and create a durable typed snapshot before validation. Pending version-1 requests must be regenerated. Product core/catalog fields are applied through `WC_Product` setters and `save()`. Supported current product-model fields include global product identifiers, low-stock amounts and product brand IDs when the installed WooCommerce version provides those methods. COGS remains feature-gated upstream and is not exposed as an unconditional generic write field.

Product deletion follows WooCommerce semantics: confirmed deletion moves to Trash by default; permanent deletion additionally requires `force=true`.

`elementor-json-bridge/manage-product-variation` version 2 supports variable-product variation create, read, update and confirmed permanent delete. Update/delete require the `base_hash` from a fresh read result and create a durable typed snapshot before validation. Pending version-1 requests must be regenerated. Current stable identifier and low-stock fields are supported when the installed WooCommerce version exposes them.

`elementor-json-bridge/manage-term` version 2 supports create/read/update/delete for WordPress/WooCommerce taxonomies with exact term IDs outside create. Update/delete require the `base_hash` from a fresh read result and create a durable typed snapshot before validation. Pending version-1 requests must be regenerated. ACF and Yoast term data can be included where the installed plugin APIs support it.

`elementor-json-bridge/run-ability` version 2 executes only a constrained live-catalog ability explicitly annotated read-only. Mutable abilities remain discoverable context but cannot execute through this generic GitHub route; mutations use guarded versioned CRUD requests instead. Pending version-1 ability requests must be regenerated. Core abilities are executable through this GitHub route only when read-only. Destructive abilities require explicit confirmation. ACF abilities require ACF AI support to be enabled on the target.

== Safety ==

* The content repository must be private.
* GitHub authentication uses Device Flow; access/refresh tokens are encrypted at rest.
* The recorded background actor must still hold the bridge capability.
* Request JSON is bounded and rejects unknown fields.
* `request_id` values are fingerprinted; an ID cannot be reused for changed input.
* A process lock prevents two request polls from executing the same unrecorded operation concurrently, and stale-lock takeover uses an atomic compare-and-swap.
* Automatic GitHub request dispatch honors the explicit `auto_apply` opt-in.
* `manage-post` version 2 update/delete follows fresh canonical state -> `base_hash` conflict check -> durable snapshot -> validation -> save -> exact readback; stale or legacy requests fail closed.
* Existing canonical content uses fresh conflict checks, integrity-checked snapshots, supported APIs, exact fingerprint readback and verified rollback.
* ACF fields remain bound to their live field name/key/type identity, including guarded first writes for fields registered on the target screen.
* Calculated Yoast analysis/indexable data is not bulk written as editable SEO metadata.
* Elementor storage is never directly mutated.
* WooCommerce catalog data is written through WooCommerce CRUD.
* Product, term, variation and ability deletion/destructive actions require explicit confirmation.

== Current runtime matrix ==

CI covers PHP 8.1 through 8.5, WPCS/PHP compatibility, dependency audit, deterministic package building and WordPress Plugin Check.

Runtime canaries cover:

* WordPress 6.8.3 / PHP 8.1 / Elementor 4.2.4 / ACF 6.8.9.
* WordPress 7.1 / PHP 8.3 / Elementor 4.2.4 / ACF 6.8.9 / Yoast SEO 28.4 / WooCommerce 11.0.1.

The current runtime suite includes positive and negative scenarios for WordPress posts, ACF term fields, Elementor document creation, WooCommerce products, categories/brands, variable-product variations, deletion modes, rollback/readback and live Abilities discovery.

== Production gate ==

CI is repository/runtime evidence, not a production-site guarantee. Test the exact generated ZIP on the target staging environment before production. Real GitHub Device Flow, the site's complete add-on combination, Elementor Pro Theme Builder conditions and authenticated browser/accessibility behavior remain site-specific gates.

== Uninstall ==

Authentication, processed request IDs and request-process lock state are always removed. Settings/snapshots remain by default to protect recovery data; optional uninstall cleanup removes plugin-owned settings, snapshots, locks and sync metadata.

== Changelog ==

= 0.6.0 =
* Add guarded create/update/delete requests for WordPress content, WooCommerce products, variations and taxonomy terms.
* Version `manage-post` as contract version 2 for mandatory stale-request protection; version-1 pending request files must be regenerated.
* Version product, taxonomy-term and variation mutation contracts to version 2 with read-derived base hashes and durable typed snapshots before validation.
* Version the generic ability route to version 2 and execute only abilities explicitly annotated read-only; mutable abilities use guarded CRUD adapters.
* Restrict `manage-post` version 2 to the canonical conflict/snapshot envelope and create durable snapshots before validation for update/delete operations.
* Create new Elementor documents through Elementor's official document manager when an Elementor payload is explicitly requested.
* Add live WordPress Abilities discovery for safe Core, ACF, Yoast SEO and WooCommerce product capabilities.
* Add ACF AI capability context and current integration version context to `abilities.json`.
* Route WooCommerce product core/catalog writes through WooCommerce CRUD and align delete behavior with WooCommerce Trash/force semantics.
* Keep WooCommerce `brand_ids` and the canonical `product_brand` taxonomy envelope on one desired state so supported write paths cannot undo each other.
* Add current stable WooCommerce global identifier, low-stock and product-brand fields; keep feature-gated COGS fail-closed.
* Add prevalidation, exact readback and verified rollback to direct update operations.
* Make GitHub request dispatch honor automatic-apply opt-in and use atomic stale-lock takeover.
* Support guarded ACF first writes while preserving live field name/key/type identity.
* Expand current runtime CI to ACF 6.8.9, Yoast SEO 28.4 and WooCommerce 11.0.1 with positive and negative integration scenarios.

= 0.5.0 =
* Replace per-document opt-in GitHub sync with automatic discovery of editable WordPress content after the one-time GitHub connection.
* Add a versioned WordPress content envelope for normal editor content, taxonomies, ACF, selected Yoast fields, registered metadata and existing Elementor data.
* Add machine-readable `bridge.json` and `site-index.json` files.
* Add idempotent draft content requests and extend snapshot/readback rollback to the managed WordPress envelope.

= 0.4.1 =
* Move custom Elementor JSON import to guarded buttons on the normal Pages and Posts overviews.

= 0.4.0 =
* Add guarded smart Elementor JSON re-import with snapshots, readback and rollback.