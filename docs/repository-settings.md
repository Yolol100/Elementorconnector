# Required GitHub repository settings

These settings are part of the release gate for `main`.

## Main branch ruleset

Target: `main`

- require a pull request before merging;
- require all `Plugin quality` status checks to pass;
- require branches to be up to date before merge when practical;
- block force pushes;
- block branch deletion;
- require conversation resolution;
- do not permit bypass except an explicit emergency owner path;
- require full-length commit SHA pinning for Actions where GitHub exposes that organization/repository rule.

For a single-owner repository, do not require an impossible second-person approval. Add reviewer approval only when another maintainer is actually available.

## Security

For this public source repository enable:

- Dependabot alerts;
- Dependabot security updates;
- secret scanning;
- push protection for secrets.

## Merge/repository hygiene

Recommended:

- squash merge enabled as the normal merge method;
- delete head branches after merge;
- disable unused Wiki/Projects surfaces unless they are deliberately used;
- add a concise repository description and topics such as `wordpress`, `elementor`, `github`, `json`, `backup`, `version-control`.

Real website JSON belongs in a separate **private** repository. Never reuse this public source repository for production Elementor exports.
