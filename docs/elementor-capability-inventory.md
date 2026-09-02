# Elementor target capability inventory

The bridge publishes a read-only capability manifest for the connected WordPress target at:

`site-data/elementor-capabilities.json`

Large capability details are stored as versioned JSON shards below:

`site-data/elementor-capabilities/{inventory-hash-prefix}/{surface}-{index}.json`

The repository must already pass the bridge's private-repository gate. Capability files never replace the managed WordPress content envelope and are not instructions to install, activate, deactivate or configure plugins.

## Purpose

Use the manifest to determine what the exact target currently registers before planning or validating Elementor content. It is target evidence for availability, not proof that a selected composition renders correctly.

The version 1 inventory contains:

- WordPress, PHP, theme, Elementor and Elementor Pro versions when available;
- active plugin names, basenames and versions;
- active Elementor devices and breakpoints;
- registered widgets with owner/plugin and categories;
- registered layout/Atomic element types with owner/plugin and Atomic classification when supported by the runtime;
- registered Elementor document/template types;
- registered Dynamic Tags with owner/plugin, group and categories;
- full exposed controls in the referenced detail shards;
- bounded choice keys for the classic Form `submit_actions` control when Elementor exposes them;
- stable warning codes when an optional inventory surface cannot be read safely.

Do not treat the inventory as permission to use every listed capability. Core/Pro should remain the default when they satisfy the requirement with lower dependency/risk. Third-party add-on widgets/elements/tags are candidates only when their exact plugin and registered capability are present and the site context allows that dependency.

## Manifest and shard contract

The manifest is intentionally compact. Each widget, element, document type and Dynamic Tag summary points to the JSON shard that contains its complete runtime record. Consumers should read the manifest first and fetch only the shard needed for the selected candidate instead of loading every control for every installed add-on.

Every inventory receives a canonical SHA-256 fingerprint. Shards are written below a directory derived from that fingerprint and each shard is bounded to at most 500 KB of canonical JSON. The manifest includes the full inventory fingerprint plus shard paths, shard hashes and record counts.

Synchronization writes all new shards first and switches `elementor-capabilities.json` last. This makes the manifest the atomic pointer: a partial failed refresh cannot make consumers follow a half-written inventory. Older hash directories can remain as rollback/history evidence but are not active unless the current manifest references them.

## Refresh behavior

The normal bridge polling hook checks the capability state no more than once per hour. Plugin activation, deactivation or updater completion invalidates that throttle so the next poll can refresh it sooner. The payload is deterministic, so an unchanged inventory does not create new GitHub commits.

Element Manager or other runtime configuration changes that do not trigger a plugin lifecycle hook are picked up by the bounded hourly check. A failed refresh receives a short retry cooldown instead of either hammering GitHub every minute or suppressing retries for a full hour.

## Safety boundary

- The capability collector is read-only against WordPress/Elementor.
- Synchronization writes only the capability manifest and its immutable detail shards to the already configured private site repository.
- No credentials, access tokens, private URLs, WordPress options, user records, WooCommerce orders or payment data are included.
- Capability collection fails soft per optional runtime surface and per broken third-party registration; it never guesses missing widgets, controls, document types or Dynamic Tags.
- Exact site-object IDs, Theme Builder conditions, query objects, Forms delivery, WooCommerce transactions and frontend behavior still require their own target/staging evidence.

## Consumer contract

Before generating or repairing Elementor JSON for a site-bound target:

1. read `elementor-capabilities.json`;
2. verify the target environment and inventory fingerprint;
3. classify the document/object context;
4. shortlist only capabilities present in the manifest;
5. fetch the referenced shard before relying on exact controls, responsive flags, Dynamic Tag support or Form action choices;
6. preserve the current V3/V4 family unless migration is explicit;
7. record Pro/add-on dependencies;
8. block or hand off when a required capability is absent, ambiguous or the shard cannot be verified against the manifest hash;
9. validate the resulting document and use target/staging/browser evidence for claims beyond availability.
