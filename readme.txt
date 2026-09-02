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

WooCommerce catalog fields use `manage-product` and `manage-product-variation` request files so product writes use WooCommerce CRUD and verified readback.

`abilities.json` records the registered external ability surface plus WordPress/ACF/Elementor/WooCommerce/Yoast version context. An ability appearing in source code is not treated as available unless the live target actually registers it.

== Request operations ==

`elementor-json-bridge/manage-post` supports create, update and delete for managed WordPress content. New content is always a draft. Delete requires `confirm_destructive=true`.

When an explicit Elementor payload is supplied for a new item, the bridge creates the document through Elementor's document manager and saves it through Elementor's document API. It never directly writes `_elementor_data`.

`elementor-json-bridge/manage-product` supports WooCommerce product create, update and delete. Product core/catalog fields are applied through `WC_Product` setters and `save()`. Supported current product-model fields include global product identifiers, low-stock amounts and product brand IDs when the installed WooCommerce version provides those methods. COGS remains feature-gated upstream and is not exposed as an unconditional generic write field.

Product deletion follows WooCommerce semantics: confirmed deletion moves to Trash by default; permanent deletion additionally requires `force=true`.

`elementor-json-bridge/manage-product-variation` supports variable-product variation create, update and confirmed permanent delete. Current stable identifier and low-stock fields are supported when the installed WooCommerce version exposes them.

`elementor-json-bridge/manage-term` supports create/update/delete for WordPress/WooCommerce taxonomies with exact term IDs for update/delete. ACF and Yoast term data can be included where the installed plugin APIs support it.

`elementor-json-bridge/run-ability` executes only a constrained live-catalog ability. Core abilities are executable through this GitHub route only when read-only. Destructive abilities require explicit confirmation. ACF abilities require ACF AI support to be enabled on the target.

== Safety ==

* The content repository must be private.
* GitHub authentication uses Device Flow; access/refresh tokens are encrypted at rest.
* The recorded background actor must still hold the bridge capability.
* Request JSON is bounded and rejects unknown fields.
* `request_id` values are fingerprinted; an ID cannot be reused for changed input.
* A process lock prevents two request polls from executing the same unrecorded operation concurrently.
* Direct update handlers validate desired state before writing and perform exact readback; failures attempt verified rollback.
* Existing canonical content uses fresh conflict checks, integrity-checked snapshots, supported APIs, exact fingerprint readback and verified rollback.
* ACF fields remain bound to their live field name/key/type identity.
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
* Create new Elementor documents through Elementor's official document manager when an Elementor payload is explicitly requested.
* Add live WordPress Abilities discovery for safe Core, ACF, Yoast SEO and WooCommerce product capabilities.
* Add ACF AI capability context and current integration version context to `abilities.json`.
* Route WooCommerce product core/catalog writes through WooCommerce CRUD and align delete behavior with WooCommerce Trash/force semantics.
* Add current stable WooCommerce global identifier, low-stock and product-brand fields; keep feature-gated COGS fail-closed.
* Add prevalidation, exact readback and verified rollback to direct update operations.
* Add a process lock around request polling to prevent duplicate concurrent execution windows.
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
