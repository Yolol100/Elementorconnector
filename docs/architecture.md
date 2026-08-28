# Architecture

## Purpose

Elementor JSON Bridge is a narrow synchronization boundary between WordPress/Elementor and GitHub. It deliberately does not contain an AI client. ChatGPT/Codex can work on the JSON through GitHub while WordPress retains the final validation/write authority.

## Canonical responsibilities

- **WordPress/Elementor**: live document state and final save authority.
- **Plugin**: export/import boundary, auth, validation, conflict detection, snapshots, rollback and verification.
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
5. Plugin creates a private local snapshot.
6. JSON passes structural validation and live document-type validation.
7. Plugin saves through Elementor `Document::save()`.
8. Plugin reads the document back and canonicalizes it.
9. Expected and actual fingerprints must match.
10. On mismatch/error, restore the snapshot and mark the operation failed.

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

Snapshots are private WordPress records. Git history is additional version history, not the sole backup. The plugin retains a bounded number of snapshots per document.

## Non-goals for v0.2.x

- V3/V4 migration;
- creating or remapping site IDs;
- proving third-party widget/add-on availability;
- production deployment automation;
- OpenAI/MCP integration inside WordPress;
- generic Git hosting providers;
- inbound public webhooks.
