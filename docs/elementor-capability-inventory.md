# Elementor target capability inventory

The bridge publishes a read-only capability manifest for the connected WordPress target at:

`site-data/elementor-capabilities.json`

Large capability details are stored as versioned JSON shards below:

`site-data/elementor-capabilities/{inventory-hash-prefix}/{surface}-{index}.json`

The repository must already pass the bridge's private-repository gate. Capability files never replace the managed WordPress content envelope and are not instructions to install, activate, deactivate or configure plugins.

## Purpose

Use the manifest to determine what the exact target currently registers before planning or validating Elementor content. It is target evidence for availability, not proof that a selected composition renders correctly.

The version 1 inventory is additive and contains:

- WordPress, PHP, theme, Elementor and Elementor Pro versions when available;
- active plugin names, basenames and versions;
- active Elementor devices and breakpoints;
- registered widgets with owner/plugin and categories;
- registered layout/Atomic element types with owner/plugin and Atomic classification when supported by the runtime;
- registered Elementor document/template types;
- registered Classic Dynamic Tags with owner/plugin, group and categories;
- exact Classic controls in referenced detail shards, including responsive/dynamic capability flags;
- for registered Atomic widgets/elements: the runtime-exposed Atomic controls, props schema, style/pseudo states, dependency mapping, base styles/settings, default children, allowed child types, initial attributes, HTML-tag capability, metadata and element version;
- the active runtime experiment/feature registry so V3/V4/Atomic/Components/Variables/Interactions availability is evidence-bound instead of inferred from the Core version;
- the current Atomic style schema and Atomic Dynamic Tag editor registry;
- current target Global Classes and Variables from the active Elementor Kit;
- current Component metadata/UIDs and component style references when Components are enabled;
- current Interactions configuration when that module is active;
- bounded choice keys for the classic Form `submit_actions` control when Elementor exposes them;
- Atomic Form action choices indirectly through the exact Atomic control/props configuration registered by that runtime, including license-dependent choices when Elementor exposes them;
- stable warning codes when an optional inventory surface or one third-party component cannot be read safely.

Do not treat the inventory as permission to use every listed capability. Core/Pro should remain the default when they satisfy the requirement with lower dependency/risk. Third-party add-on widgets/elements/tags are candidates only when their exact plugin and registered capability are present and the site context allows that dependency.

## V3 and V4 evidence boundary

Classic/V3 and Atomic/V4 use different configuration systems. An empty Classic `controls` array on an Atomic element is not evidence that the element has no settings. Consumers must use the element's `atomic_config` shard data for Atomic props/controls/styles/dependencies and use `atomic_style_schema`, `global_classes`, `variables`, `components`, `interactions` and `atomic_dynamic_tags` only when those surfaces are present in the same inventory fingerprint.

The inventory records availability and current target design-system references. It does not authorize changing a Global Class, Variable, Component, interaction, form action, Loop query or site-bound object. Those remain change-gated by the Elementor Skill and target/staging evidence.

## Manifest and shard contract

The manifest is intentionally compact. Each capability summary points to the JSON shard that contains its complete runtime record. Consumers should read the manifest first and fetch only the shard needed for the selected candidate instead of loading every Classic control, Atomic props schema, design-system value or installed add-on capability at once.

Every inventory receives a canonical SHA-256 fingerprint. Shards are written below a directory derived from that fingerprint and each shard is bounded to at most 500 KB of canonical JSON. The manifest includes the full inventory fingerprint plus shard paths, shard hashes and record counts. Heavy fields such as Classic controls, Atomic config and class/component styles remain in detail shards rather than being duplicated into the manifest.

Synchronization writes all new shards first and switches `elementor-capabilities.json` last. This makes the manifest the atomic pointer: a partial failed refresh cannot make consumers follow a half-written inventory. Older hash directories can remain as rollback/history evidence but are not active unless the current manifest references them.

## Refresh behavior

The normal bridge polling hook checks the capability state no more than once per hour. Plugin activation, deactivation or updater completion invalidates that throttle so the next poll can refresh it sooner. The payload is deterministic, so an unchanged inventory does not create new GitHub commits.

Element Manager, Kit, Class, Variable, Component or other runtime configuration changes that do not trigger a plugin lifecycle hook are picked up by the bounded hourly check. A failed refresh receives a short retry cooldown instead of either hammering GitHub every minute or suppressing retries for a full hour.

## Safety boundary

- The capability collector is read-only against WordPress/Elementor.
- Synchronization writes only the capability manifest and its immutable detail shards to the already configured private site repository.
- No credentials, access tokens, private URLs, user records, WooCommerce orders or payment data are included.
- Capability collection fails soft per optional runtime surface and per broken third-party registration; it never guesses missing widgets, controls, Atomic props, document types, Dynamic Tags, Classes, Variables or Components.
- Global Classes, Variables and Component metadata are target-bound design-system evidence and must remain in the configured private site repository.
- Exact Theme Builder conditions, query objects, Forms delivery, WooCommerce transactions and frontend behavior still require their own target/staging evidence.

## Consumer contract

Before generating or repairing Elementor JSON for a site-bound target:

1. read `elementor-capabilities.json`;
2. verify the target environment and inventory fingerprint;
3. classify the document/object context and editor family;
4. shortlist only capabilities present in the manifest;
5. fetch the referenced shard before relying on exact Classic controls or Atomic props/controls/styles/dependencies;
6. for V4, resolve Classes, Variables, Components, Atomic Dynamic Tags and Interactions only from shards bound to the same fingerprint;
7. preserve the current V3/V4 family unless migration is explicit;
8. record Pro/add-on dependencies and license-bound capability choices;
9. block or hand off when a required capability is absent, ambiguous or the shard cannot be verified against the manifest hash;
10. validate the resulting document and use target/staging/browser evidence for claims beyond availability.
