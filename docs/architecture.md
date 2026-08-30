# Architecture

## Purpose

Elementor JSON Bridge is a narrow synchronization boundary between WordPress/Elementor and GitHub. It deliberately does not contain an AI client. ChatGPT/Codex can work on the JSON through GitHub while WordPress retains the final validation/write authority.

## Canonical responsibilities

- **WordPress/Elementor**: live document state and final save authority.
- **Plugin**: export/import boundary, auth, validation, canonicalization, conflict detection, snapshots, rollback and verification.
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

`attempted`, `applied` and `verified` are not interchangeable. A write reaches `verified` only after Elementor readback matches the expected canonical fingerprint.

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

## Safe export

- New remote file: create only when no unknown remote history exists.
- Existing remote file: update only with the exact GitHub blob SHA learned from the trusted base.
- Timeout/uncertain response: reconcile by reading the remote content before retrying.
- Rate limiting: persist a cooldown and avoid request storms.

## Single-repository mode

The source repository and site JSON may share one GitHub repository only when that repository is private. Keep plugin source on `main`, use a dedicated `site-sync` branch for live JSON, and use a root such as `site-data/elementor`. The plugin's private-repository guard rejects a public repository. The release builder copies an explicit runtime allowlist, so `site-data/` is not shipped in the plugin ZIP.

## Backups

Snapshots are private WordPress records. Each snapshot stores a SHA-256 fingerprint of the canonical payload and that fingerprint is verified before payload recovery. Git history is additional version history, not the sole backup. The plugin retains a bounded number of snapshots per document.

## Runtime evidence

Repository CI includes real WordPress + MySQL + Elementor acceptance via `wp-env`, in addition to controlled regressions and static checks. The runtime matrix covers the minimum supported WordPress 6.8.3/PHP 8.1 combination and the current WordPress/PHP 8.3 combination with Elementor 4.2.3. It exercises the production `Documents` adapter, real Elementor save/readback, snapshot integrity rejection and a real `edit_post` denial path.

This evidence is configuration-scoped. It does not establish parity for every production site's Elementor Pro features, add-ons, dynamic data, cache/CDN layer or hosting behavior; those remain staging/runtime acceptance concerns for that site.

## Non-goals for v0.2.x

- V3/V4 migration;
- creating or remapping site IDs;
- proving third-party widget/add-on availability;
- production deployment automation;
- OpenAI/MCP integration inside WordPress;
- generic Git hosting providers;
- inbound public webhooks.
