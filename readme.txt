=== Elementor JSON Bridge ===
Contributors: webactueel
Tags: elementor, json, github, backup, version control
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely synchronize Elementor document JSON with a private GitHub repository.

== Description ==

Elementor JSON Bridge keeps Elementor page, post, and template JSON in GitHub so it can be reviewed and edited with tools such as ChatGPT or Codex without giving those tools direct WordPress access.

The plugin deliberately contains no OpenAI API integration and requires no MCP server. GitHub authentication uses GitHub Device Flow: the site stores only the public GitHub App Client ID in normal settings and encrypts the resulting user access and refresh tokens at rest.

Core safety rules:

* Elementor is read and written through Elementor's document API.
* Existing GitHub files with unknown history are never overwritten.
* A SHA-256 base fingerprint detects simultaneous local and remote changes.
* Every remote apply creates a local WordPress snapshot first.
* Rollback snapshots are integrity-checked against their stored SHA-256 fingerprint before use.
* Incoming JSON is structurally validated and canonicalized to Elementor's raw-data defaults before save.
* The saved document is read back and fingerprinted after save.
* A failed roundtrip triggers automatic rollback to the local snapshot.
* Automatic remote apply is opt-in and disabled by default. When enabled, the plugin rechecks fresh GitHub/local state before applying.
* Manual Apply GitHub remains available when automatic apply is disabled or a pending change needs review.
* GitHub tokens are never exposed to browser JavaScript or stored in logs.

== GitHub App setup ==

1. Create a GitHub App in GitHub Developer settings.
2. Disable webhooks for this plugin.
3. Enable Device Flow.
4. Give the app Repository permissions -> Contents: Read and write. Do not grant Administration, Issues, Actions, or other permissions unless you separately need them.
5. Install the app only on the private repository that will contain the Elementor JSON.
6. Copy the app's public Client ID into Tools -> Elementor JSON Bridge. Do not enter a Client Secret or personal access token.
7. Enter the repository owner, repository name, branch, and JSON folder.
8. Click Connect GitHub and authorize the displayed device code.

The private repository itself is not created by this plugin. Create it first in GitHub and keep it private when it contains real website content.

== Workflow ==

1. Enable a document in Tools -> Elementor JSON Bridge.
2. Click Export once to establish the trusted GitHub base.
3. Edit the JSON in GitHub with ChatGPT, Codex, or another reviewed workflow.
4. The plugin checks enabled documents about once per minute through WP-Cron. WP-Cron is request-driven, so this is not an exact clock.
5. If Automatic apply is disabled, review a remote_pending change and click Apply GitHub.
6. If Automatic apply is enabled, a pending change is checked again and then applied automatically only when the live document still matches the trusted base, the GitHub SHA/fingerprint are fresh, and the administrator who enabled Automatic apply still has the bridge capability plus `edit_post` for that document.
7. Every apply creates a snapshot, validates and canonicalizes the JSON, saves it through Elementor, reads it back, and verifies the SHA-256 fingerprint.
8. If verification fails, the integrity-checked snapshot is restored.

Normal WordPress or Elementor saves can be exported automatically when Automatic export is enabled. An automatic export refuses to overwrite a GitHub file whose SHA no longer matches the known base.

== Repository layout ==

The JSON root defaults to `elementor`:

`elementor/pages/42.json`
`elementor/posts/91.json`
`elementor/templates/120.json`
`elementor/custom/{post-type}/123.json`

Each file uses Elementor's documented document wrapper:

`title`, `type`, `version`, `page_settings`, `content`.

The plugin currently accepts Elementor JSON structure version `0.4` and preserves the live document type. Current Elementor pages and posts therefore remain `wp-page` and `wp-post`, and their stable element IDs are kept for exact same-document synchronization. Before hashing or saving, the bridge normalizes Elementor raw-data defaults such as `isInner` for non-widget elements and omitted false `isLocked` values; malformed types are rejected rather than silently coerced. These bridge files are not promised as drop-in Template Library imports for live pages/posts; saved Elementor Library templates keep their own template document type.

It does not convert V3 to V4, V4 to V3, invent site IDs, or rewrite dynamic references.

== Single-repository mode ==

A separate private content repository remains the simplest setup. If you prefer one GitHub repository for both plugin source and website JSON, the repository must be private first.

Recommended single-repository layout:

* Plugin source branch: `main`
* Website JSON branch: `site-sync`
* JSON folder: `site-data/elementor`

The plugin's private-repository guard rejects a public repository. Do not synchronize real site JSON while the repository is public. Keeping live JSON on `site-sync` also separates normal website-content commits from source-code CI on `main`.

== Automatic apply ==

Automatic apply is disabled by default and must be enabled in Tools -> Elementor JSON Bridge.

When enabled, the plugin records the WordPress administrator who enabled the setting. Only enabled documents for which that actor still has the bridge capability and `edit_post` are eligible. The cron task temporarily restores that authorized WordPress user context for the fresh remote check and Elementor write, and restores the previous user immediately afterwards. Before every automatic apply, the plugin performs a fresh GitHub check. The existing remote SHA, local base fingerprint, pending content fingerprint, structural validation, snapshot, Elementor save, readback verification and rollback gates remain in force. A conflict, malformed payload, changed SHA, changed local document, revoked permission or failed readback prevents a successful automatic apply.

The plugin does not use inbound webhooks. The target poll cadence is about one minute, but WP-Cron runs when WordPress receives requests unless the host provides a real cron trigger.

== Security ==

Only users with the custom `manage_elementor_json_bridge` capability can configure the bridge or trigger protected manual actions. Activation grants that capability to Administrators only. Manual document actions additionally require `edit_post` for that document. Automatic apply revalidates the recorded administrator's bridge capability and `edit_post` permission for each target before activating that user's context for the background write.

Protected admin actions use authenticated WordPress REST requests with a REST nonce and server-side capability checks. Nonces are not treated as authorization.

GitHub credentials are encrypted with libsodium Secretbox where available or AES-256-GCM via OpenSSL as a fallback. Stored packages validate their algorithm-specific nonce/IV, authentication-tag, and ciphertext structure before decryption and fail closed on malformed input. If neither encryption primitive exists, GitHub credentials are not stored.

The plugin makes outbound requests only to fixed GitHub HTTPS endpoints. It does not expose an inbound public webhook.

== External service ==

Elementor JSON Bridge uses GitHub as an external storage and version-control service only after an administrator explicitly configures and connects it.

Data sent to GitHub can include the selected repository owner/name/branch, Elementor document JSON, document IDs in file paths, commit messages, and the GitHub authorization requests needed for Device Flow and repository access. Elementor JSON can contain website content and references to site-specific data, so use a private repository and review what you synchronize.

The plugin connects only to GitHub's documented HTTPS endpoints at `github.com` and `api.github.com`. GitHub's own terms and privacy policy apply to data sent there:

* GitHub Terms of Service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* GitHub Privacy Statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

No Elementor JSON is sent to OpenAI by this plugin. ChatGPT or Codex access is a separate GitHub connection controlled outside WordPress.

== Backups and rollback ==

Before applying GitHub JSON or manually restoring an older snapshot, the plugin stores a private local snapshot. The ten newest snapshots per Elementor document are retained. Before a snapshot is used, its JSON is hashed again and compared with the SHA-256 fingerprint stored when that snapshot was created; damaged or tampered snapshot content is rejected.

GitHub commit history is useful version history but is not treated as the only backup.

== Runtime verification ==

The repository CI includes real WordPress + MySQL + Elementor runtime acceptance through `wp-env`, in addition to controlled regression tests. Version 0.2.2 is exercised against the minimum supported WordPress 6.8.3 / PHP 8.1 combination and the current WordPress / PHP 8.3 combination with Elementor 4.2.3. The runtime probe verifies Elementor document save/readback, snapshot integrity rejection, and a real `edit_post` denial path.

This CI evidence validates those exact configurations. It does not replace a site-specific staging roundtrip for production websites with additional Elementor Pro features, widgets, add-ons, dynamic data, or hosting-specific behavior.

== Limitations ==

Version 0.2.x remains intentionally conservative:

* Automatic apply is optional and disabled by default.
* The plugin performs structural validation and exact post-save roundtrip verification, but it cannot prove every widget, add-on, dynamic tag, Theme Builder condition, form action, or site-specific dependency is valid.
* WP-Cron is request-driven and the one-minute polling cadence is not a real-time guarantee.
* Production use should be verified on staging first, especially for Elementor Pro, Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components, and Atomic/V4 content.
* The GitHub App must already exist and be installed on the selected repository.
* Single-repository mode is safe only when the repository is private; `site-sync` is the recommended data branch.

== Uninstall ==

GitHub authentication is always deleted on uninstall. Settings and snapshots are retained by default so an accidental uninstall does not destroy recovery data. Enable Uninstall cleanup before uninstalling if you want all plugin-owned settings, snapshots, locks, and sync metadata removed.

== Changelog ==

= 0.2.2 =
* Fail closed on malformed encrypted credential packages by validating Sodium nonce and AES-GCM IV/tag structure before decryption.
* Verify each rollback snapshot against the SHA-256 fingerprint stored at creation time before the snapshot can be restored.
* Canonicalize Elementor raw-data defaults before hashing and save so normal `isInner`/`isLocked` normalization cannot cause a false readback failure, while malformed field types remain rejected.
* Add real WordPress + MySQL + Elementor runtime acceptance for WordPress 6.8.3/PHP 8.1 and the current WordPress/PHP 8.3 environment, alongside permanent security and rollback regressions.

= 0.2.1 =
* Fix automatic cron apply by running it under the administrator who explicitly enabled Automatic apply, while rechecking that user's bridge capability and `edit_post` permission for each document.
* Restore the previous WordPress user immediately after every background attempt so cron does not leak authorization context to later hooks.

= 0.2.0 =
* Add opt-in automatic application of fresh, conflict-free GitHub changes using the existing snapshot, validation, readback and rollback gates.
* Check GitHub through request-driven WP-Cron on a one-minute target cadence and migrate the old ten-minute schedule automatically.
* Document private single-repository mode using a dedicated `site-sync` branch and `site-data/elementor` root.
* Keep automatic apply disabled by default so existing installations do not start writing Elementor content without an explicit administrator choice.
* Clarify that `edit_post` protects manual document actions while automatic apply is restricted to documents previously enabled by an authorized administrator.

= 0.1.2 =
* Correct the admin copy to describe the v0.1.x manual-apply safety rule accurately.
* Expand CI coverage to PHP 8.1 through 8.5 and strengthen permanent regression coverage.

= 0.1.1 =
* Keep structural validation independent from WordPress HTML escaping so regression checks can execute outside a loaded WordPress request.
* Expand negative validator coverage for malformed JSON, unknown fields, duplicate IDs, and invalid widget payloads.
* Make CI verify two byte-identical release builds and publish the exact tested ZIP artifact.
* Run Plugin Check against the private-distribution quality categories instead of WordPress.org-only repository policy.

= 0.1.0 =
* Initial release.
* GitHub Device Flow without a client secret or personal access token.
* Private GitHub Contents synchronization.
* Conflict detection with GitHub blob SHA and local SHA-256 fingerprints.
* Local snapshots, structural validation, Elementor document save, readback verification, and rollback.
* Safe automatic export and request-driven remote polling.
* Explicit administrator approval for every remote apply.
