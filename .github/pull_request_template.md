## Scope

- What changed?
- Why is this the smallest safe change?

## Risk

- [ ] No secrets, tokens, credentials, customer data, or real Elementor site JSON are included.
- [ ] Data-loss and rollback impact has been reviewed.
- [ ] Authentication, authorization, nonces, sanitization, escaping, and outbound HTTP were reviewed when relevant.
- [ ] Sync-state, stale-write, lock, conflict, and retry behavior were reviewed when relevant.

## Evidence

- [ ] Zero-dependency regression tests pass.
- [ ] PHP 8.3 / 8.4 / 8.5 quality matrix passes.
- [ ] Composer validation and locked dependency audit pass.
- [ ] WordPress Coding Standards / PHPCompatibility pass.
- [ ] Release ZIP builds byte-identically twice and its SHA-256 is recorded.
- [ ] Plugin Check has no blocking error.
- [ ] WordPress + Elementor runtime smoke test passes when the Elementor data path changed.

## Staging

- [ ] Not required: no runtime/data-path behavior changed.
- [ ] Required and completed: export -> remote edit -> detect -> apply -> verify -> rollback was tested on staging.

Document any exception or remaining manual test below. Do not label a change production-ready without the evidence that supports that claim.
