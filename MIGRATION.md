# Elementorconnector -> WordPress Connector

## Status

Source consolidation is complete in `Yolol100/wordpressconnector` 1.9.0. This repository is legacy-only and code-frozen except for security/migration maintenance.

## Successor

Canonical route:

`ChatGPT / WP Agent -> authenticated HTTPS REST -> WordPress Connector -> WordPress/Elementor -> exact readback + rollback`

WordPress Connector now owns the live WordPress/Elementor bridge. `Yolol100/elementorjson` remains separate as the controlled Elementor JSON QA/runtime lab.

## Parity implemented in 1.9.0

- Elementor document inspect/create/replace/patch through Elementor APIs;
- capability inventory and Elementor V3/V4 forms;
- ACF, WooCommerce, Yoast and media adapters;
- mutation lock, idempotency and rollback snapshots;
- deterministic `expected_fingerprint` plus site-scoped HMAC `expected_state_token`;
- Page/Post Elementor JSON export;
- Saved Template native export fallback;
- Page/Post/Saved Template import with explicit replace-existing or create-new-draft behavior;
- optional Page/Post bundle with matching Elementor Pro Theme Builder header/footer.

## Runtime exit gate

Before removing this repository/plugin, verify on staging:

1. `connector.discover`, `system.doctor` and `elementor.capabilities` pass;
2. Elementor inspect/create/replace returns exact readback;
3. stale fingerprint/token requests are rejected before write;
4. repeated request IDs remain idempotent;
5. rollback succeeds after a representative mutation/failure path;
6. Page/Post JSON create + replace succeeds;
7. Saved Template import/export succeeds;
8. Theme Builder site-parts export succeeds where Elementor Pro is used;
9. representative ACF/WooCommerce/Yoast/media operations needed by the workflow pass;
10. no active site still runs Elementor JSON Bridge.

If any gate is missing, keep this repository as rollback/migration evidence. Do not add new product capability here.
