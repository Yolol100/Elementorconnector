# Elementor JSON Bridge

A conservative WordPress plugin that synchronizes selected Elementor documents with a private GitHub repository so reviewed tools such as ChatGPT or Codex can edit the JSON without direct WordPress access.

## Safety model

`Elementor -> WordPress bridge -> private GitHub JSON repo -> reviewed edit -> check -> snapshot -> validate -> Elementor save -> readback -> verify -> rollback on failure`

The bridge is deliberately fail-closed: private content repo only; repository identity + GitHub SHA + local canonical SHA-256; no direct `_elementor_data` writes; no remote title changes; snapshot before apply; readback verification; rollback on failure; no automatic remote apply in `0.1.x`.

## Runtime baseline

- WordPress `6.8+`
- PHP `8.3+`
- Elementor `4.2.3+`
- Elementor JSON wrapper `0.4`

A green source/CI run is not a production-site claim. Pro, Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components, Atomic/V4, custom widgets, and add-ons still require target-specific staging evidence.

## Quality gates

```bash
composer install --no-interaction --prefer-dist
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php tests/run.php
php tests/repository-identity-migration.php
bash tests/workflow-policy.sh
bash tests/reproducible-build.sh
composer qa
composer audit --locked
```

## Release evidence

`bash scripts/build-zip.sh` creates four deterministic evidence files in `dist/`:

- `elementor-json-bridge.zip` — installable runtime package;
- `elementor-json-bridge.zip.sha256` — portable SHA-256 manifest using the relative ZIP filename;
- `elementor-json-bridge.spdx.json` — SPDX 2.3 file-level SBOM for the exact runtime package;
- `elementor-json-bridge.provenance.json` — in-toto Statement with the SLSA provenance v1 predicate and the exact ZIP digest.

The provenance file is unsigned build metadata. It does not by itself claim a SLSA build level or cryptographic attestation.

Real site JSON belongs in a separate private repository, never in this public source repo.

## Release status

`0.1.0` remains **staging-first** until the exact target passes export -> GitHub edit -> check -> apply -> editor reopen -> re-export -> frontend verification plus an intentional rollback scenario.
