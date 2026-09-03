# Elementor JSON Bridge — Controlled WordPress Content Automation

> **Portfolio flagship · WordPress/PHP · Elementor · ACF · Yoast SEO · WooCommerce · GitHub Actions**

Elementor JSON Bridge is a WordPress plugin that lets reviewed changes move between GitHub and WordPress without bypassing WordPress, Elementor or WooCommerce APIs. It is designed for teams that want automation without turning content updates into uncontrolled direct database writes.

**Built by:** [Andrew Baeten](https://github.com/Yolol100) · [Portfolio](https://andrewbaeten.nl)  
**Status:** active development · staging-first for production rollout

## What problem it solves

WordPress automation becomes risky when external tools write directly to post meta, Elementor data or WooCommerce records. This project keeps WordPress as the execution boundary: tools can prepare reviewed JSON in GitHub, while WordPress rechecks permissions, validates the payload, writes through supported APIs and verifies the resulting state.

## Technical snapshot

| Area | Evidence |
| --- | --- |
| WordPress | Pages, posts, custom post types and taxonomy terms |
| Elementor | Existing documents plus explicitly requested new documents through Elementor APIs |
| ACF | Field values tied to live field identity and supported abilities |
| Yoast SEO | Conservative user-editable SEO metadata and live registered abilities |
| WooCommerce | Products, variations, categories, tags and brands through WooCommerce CRUD |
| Safety | Validation, conflict checks, idempotency, readback and verified rollback |
| Delivery | Composer, wp-env, GitHub Actions, integration matrices and deterministic release builds |

Version **0.6.0** expands the bridge with guarded CRUD request flows for WordPress content, Elementor documents, WooCommerce catalog data, taxonomy terms and product variations, plus a live WordPress Abilities catalog for ACF, Yoast SEO, WooCommerce and safe Core discovery.

## What it manages

- WordPress Pages, Posts and other website-facing editable post types.
- Existing Elementor documents and explicitly requested new Elementor documents through Elementor's document manager.
- ACF values bound to live field identity; ACF schema abilities when ACF AI access is enabled on the site.
- A conservative set of user-editable Yoast SEO metadata; live Yoast abilities when actually registered.
- WooCommerce products through WooCommerce CRUD, including simple, variable, grouped and external products.
- WooCommerce product variations.
- WordPress categories/tags and WooCommerce categories/tags/brands through taxonomy term operations.
- Current WooCommerce stable catalog fields including global product identifiers, low-stock thresholds and product brands when the installed WooCommerce version supports them.

COGS is intentionally **not** exposed as an unconditional generic field. WooCommerce feature-gates that value; the bridge reports this as feature-gated context instead of pretending every site can safely mutate it.

## Repository protocol

Default root:

```text
site-data/
  bridge.json
  site-index.json
  abilities.json
  elementor-capabilities.json
  media.json
  content/
    pages/{id}.json
    posts/{id}.json
    templates/{id}.json
    custom/{post-type}/{id}.json
  requests/
    {request-id}.json
```

`bridge.json` is the machine-readable protocol contract. `site-index.json` maps managed WordPress items to canonical content files. `abilities.json` records the abilities and integration versions actually available on the target. `elementor-capabilities.json` records the Elementor/Core/Pro/add-on surface actually registered on the site.

WooCommerce catalog fields are intentionally request-driven: use `manage-product` / `manage-product-variation` so writes go through WooCommerce CRUD and exact readback instead of generic post-meta mutation.

## Supported request formats

### WordPress content

`elementor-json-bridge/manage-post`, version `1`

Actions: `create`, `update`, `delete`. New items are drafts. Delete requires `confirm_destructive=true`.

When a create request contains an `elementor` document payload, creation is routed through `Elementor\Plugin::$instance->documents->create()` and saved through the Elementor document API. A normal WordPress item is never silently converted into Elementor content.

### WooCommerce products

`elementor-json-bridge/manage-product`, version `1`

Actions: `create`, `update`, `delete`.

Product updates use `WC_Product` setters and `save()`. Product deletion follows WooCommerce semantics:

- `confirm_destructive=true`, no `force`: move to Trash when WooCommerce allows trashing.
- `confirm_destructive=true` + `force=true`: permanent deletion.

The bridge validates the full desired WooCommerce state before applying the request and performs exact readback. Failed updates attempt a verified rollback.

### WooCommerce variations

`elementor-json-bridge/manage-product-variation`, version `1`

Create, update and permanent delete are supported against an exact variable-product parent. Destructive deletion requires confirmation. Variation writes use `WC_Product_Variation` and verified readback/rollback.

### Taxonomy terms

`elementor-json-bridge/manage-term`, version `1`

Create, update and delete terms using WordPress taxonomy APIs. Term ACF and Yoast data are supported where the relevant plugin APIs are active. A late extension failure cannot silently leave a partially renamed/updated term.

### WordPress Abilities

`elementor-json-bridge/run-ability`, version `1`

The bridge does not hardcode a promise that every plugin ability exists. `abilities.json` is generated from the live target and currently considers these namespaces/capabilities:

- safe Core discovery (`core/*`; Core execution through this GitHub route is read-only only),
- `acf/*`,
- `yoast-seo/*`,
- WooCommerce product abilities (`woocommerce/product-*` and `woocommerce/products-*`).

Each ability remains subject to its own WordPress permission callback and input/output schema. Abilities marked destructive require `confirm_destructive=true`.

## Safety model

1. Private GitHub repository required.
2. Background actor must be the administrator who authorized the connection and still hold the bridge capability.
3. Request payloads are bounded to 1 MB and use strict known-field validation.
4. Request IDs are fingerprinted for idempotency; reusing an ID with changed input fails closed.
5. Only one request-processing poll may execute at a time; stale process locks expire.
6. Existing content uses fresh conflict checks, local integrity-checked snapshots, validation, supported APIs, full readback and verified rollback.
7. Direct request updates validate the desired state before mutation and verify/restore the previous state on failure.
8. Elementor data is written through Elementor document APIs, never by directly writing `_elementor_data`.
9. WooCommerce product data is written through WooCommerce CRUD.
10. Destructive product/term/variation/ability operations require explicit confirmation.

## Runtime matrix

CI is designed to cover:

- PHP 8.1–8.5 syntax and compatibility.
- WordPress Coding Standards.
- dependency audit.
- deterministic release ZIP build.
- WordPress Plugin Check: general, security, performance and accessibility categories.
- WordPress 6.8.3 / PHP 8.1 compatibility runtime with Elementor 4.2.4 + ACF 6.8.9.
- WordPress 7.1 / PHP 8.3 current-integration runtime with Elementor 4.2.4 + ACF 6.8.9 + Yoast SEO 28.4 + WooCommerce 11.0.1.
- ordinary WordPress/ACF/Yoast content roundtrips.
- real Elementor document create/save/readback.
- WooCommerce product create/update/trash/force-delete.
- product categories and brands.
- variable-product variation CRUD and rollback scenarios.
- ACF and WooCommerce WordPress Abilities discovery/execution boundaries.
- negative cases that must not partially persist changes.

## What CI does not prove

Repository CI is not a production-site oracle. Before a production rollout, test the exact generated ZIP on the intended staging site, including:

- the real private GitHub Device Flow connection and repository permissions;
- the site's exact third-party plugin/add-on combination;
- Elementor Pro Theme Builder conditions if used;
- authenticated wp-admin/browser/accessibility behavior;
- provider-specific mail/payment/checkout behavior if relevant to the site.

## Local checks

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
node --check assets/js/admin.js
node --check assets/js/local-export.js
node --check assets/js/template-import.js
composer validate --strict
composer install --no-interaction --prefer-dist
composer test
composer phpcs
composer audit
bash scripts/build-zip.sh
```

## GitHub App permissions

For the site content repository the app needs Repository permissions → **Contents: Read and write**. Do not grant Administration, Actions, Issues or unrelated permissions merely for content synchronization.

## Release discipline

`main` is protected by required CI checks. Tagged versions rerun the quality workflow, verify tag/source/readme version parity, build the ZIP twice and create a draft GitHub Release. Publishing remains a separate staging/browser gate.

## About the developer

I am **Andrew Baeten**, a Senior WordPress Developer & Web Designer with 10+ years of experience across **90+ WordPress projects**. I currently manage and regularly update **120+ websites and webshops**, covering maintenance, UX, performance, technical SEO, QA and ongoing WordPress/WooCommerce improvements.

[Portfolio](https://andrewbaeten.nl) · [LinkedIn](https://www.linkedin.com/in/andrew-baeten-305a1478/) · [Email](mailto:info@andrewbaeten.nl)

## License

GPL-2.0-or-later. See `LICENSE`.
