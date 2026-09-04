# Platform migration status

## Status

`Yolol100/Elementorconnector` is a controlled consolidation source. It remains operational for existing validated routes, but receives no new Webactueel platform capability unless that capability is required to preserve safety or close a proven parity gap.

The target platform route is:

```text
webactueel-workflow
→ originating domain owner
→ wordpressqualityarchitect safety/runtime ownership
→ Yolol100/wordpressconnector
→ exact readback and rollback evidence
```

## Why consolidation

`wordpressconnector` already provides the broader canonical live WordPress bridge for WordPress content, Elementor, ACF, WooCommerce, media, users, settings and system actions behind explicit gates. Maintaining two primary live bridges creates duplicate permissions, request protocols, state models, tests and rollback paths.

## What must be migrated

The following Elementorconnector strengths must be retained before this repository can be deprecated:

- live Elementor/Core/Pro/add-on capability inventory;
- WordPress Abilities discovery and schema/permission checks;
- site-scoped fresh-state tokens;
- idempotency and compare-and-swap stale-state guards;
- Elementor document API create/save/readback;
- WooCommerce CRUD and ACF identity boundaries;
- exact post-mutation readback and verified rollback;
- same-site media identity;
- negative permission, conflict and partial-persistence tests.

## Change policy during migration

Allowed:

- security fixes;
- compatibility fixes for existing supported routes;
- tests and documentation needed to prove parity;
- minimal changes needed for safe extraction into `wordpressconnector`.

Not allowed:

- new unrelated platform features;
- broader GitHub or WordPress permissions;
- a second orchestration layer;
- direct database, `_elementor_data`, arbitrary SQL, shell, filesystem or proxy primitives;
- removal before staging parity and rollback proof.

## Exit gates

Archive/deprecate this repository only after:

1. capability-diff is complete;
2. missing capabilities are implemented in `wordpressconnector`;
3. local/CI, disposable-runtime and staging matrices pass;
4. read/write/delete/stale-state/permission/rollback cases have equivalent evidence;
5. active site routes use `wordpressconnector`;
6. one stable regression period has passed;
7. rollback documentation remains available.

Until then, existing validated usage may continue. A missing gate means `NO_CHANGE`, not forced migration.
