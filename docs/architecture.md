# Architecture

## Trust boundaries

1. WordPress/Elementor is the live document authority.
2. GitHub is a versioned review/edit transport, not an automatic production authority.
3. The browser never receives GitHub access or refresh tokens.
4. ChatGPT/Codex can work through GitHub without direct WordPress credentials.

## Synchronization identity

A trusted base consists of:

- live canonical SHA-256;
- GitHub blob SHA;
- stable GitHub file path;
- SHA-256 identity of repository owner + repository + branch + JSON root.

Changing repository settings invalidates that base. The operator must explicitly reset it before establishing a new one.

### Legacy trusted bases

Older 0.1.0 synchronization state can contain a trusted base without the repository-identity field. That state is never bound to the current repository on configuration alone. Before the identity is added, the migration requires the saved GitHub path to still be the current path, the saved GitHub blob SHA to still match, and the remote canonical JSON fingerprint to still equal the saved base fingerprint. A mismatch remains fail-closed and requires operator review/reset. Transport failures remain retryable rather than being treated as proof of a mismatch.

## States

- `clean`: local and trusted remote base match;
- `local_dirty`: live Elementor differs or no trusted remote exists;
- `remote_pending`: checked remote differs while local still equals the base;
- `conflict`: local/remote/repository identity violates the trusted base;
- `applying`: a guarded remote apply is executing;
- `verified`: apply completed and exact readback verification passed;
- `error`: the operation failed outside a safe conflict state.

## Apply transaction

1. acquire per-document lock;
2. require enabled sync and private repository;
3. verify repository identity;
4. fetch remote and re-check pending GitHub SHA;
5. re-read live Elementor and verify base hash;
6. validate remote JSON and replace informational remote title with current live title;
7. verify pending canonical hash;
8. create integrity-fingerprinted local snapshot;
9. save through Elementor `Document::save()`;
10. re-read and compare canonical SHA-256;
11. on failure restore snapshot and verify rollback;
12. on success update trusted base and mark `verified`;
13. release lock.

## Concurrency

Locks use WordPress options because `option_name` is unique. Acquisition uses `add_option()`; stale recovery uses a conditional compare-and-delete against the exact old option value so one request cannot delete a lock newly acquired by another request.

## External service

Only fixed `github.com` Device Flow/token endpoints and `api.github.com` repository endpoints are used. HTTP redirects are disabled for authenticated provider calls. GitHub files remain untrusted until validated.
