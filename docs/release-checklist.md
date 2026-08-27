# Release checklist

A green static build is not the same as production proof. Complete the applicable gates before creating a tag or release.

## Source and dependencies

- [ ] Version headers and changelog match the intended release.
- [ ] `composer.lock` matches `composer.json` and `composer validate --strict` passes.
- [ ] `composer audit --locked` reports no unresolved vulnerable dependency.
- [ ] No secret, token, customer content, real Elementor JSON, environment file, or generated archive is committed.
- [ ] License and security documentation are current.

## Automated evidence

- [ ] PHP 8.3, 8.4, and 8.5 jobs pass.
- [ ] Zero-dependency tests pass.
- [ ] WordPress Coding Standards and PHPCompatibility pass.
- [ ] Plugin Check has no blocking error.
- [ ] Pinned WordPress 7.1 + Elementor 4.2.3 runtime smoke test passes.
- [ ] Two release builds from the same commit are byte-identical.
- [ ] The release ZIP SHA-256 is recorded with the artifact.

## Staging data-path evidence

Use a dedicated staging site and a dedicated private GitHub content repository.

- [ ] Connect GitHub through Device Flow with only the required repository permission.
- [ ] Export a representative Elementor page and establish the trusted base.
- [ ] Change its JSON in GitHub and confirm the plugin detects `remote_pending` without applying it automatically.
- [ ] Review and apply the remote change.
- [ ] Confirm Elementor renders and the saved JSON readback is VERIFIED.
- [ ] Create a deliberate second change, restore the previous snapshot, and confirm the original content is restored.
- [ ] Test a stale/conflicting GitHub SHA and confirm the plugin refuses to overwrite it.
- [ ] Test at least one representative advanced feature actually used by the target site, such as Elementor Pro Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components, or Atomic/V4 content.

## Repository gate

- [ ] `main` requires pull requests and required status checks.
- [ ] Force pushes and branch deletion are blocked for `main`.
- [ ] Required checks match the current CI jobs.
- [ ] The release commit is the reviewed commit that passed the gates.

Only after these checks should the release be described as production-ready for the tested scope.
