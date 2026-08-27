=== Elementor JSON Bridge ===
Contributors: webactueel
Tags: elementor, json, github, backup, version control
Requires at least: 6.8
Requires PHP: 8.3
Stable tag: 0.1.0
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
* Incoming JSON is structurally validated before save.
* The saved document is read back and fingerprinted after save.
* A failed roundtrip triggers automatic rollback to the local snapshot.
* Remote changes are never auto-applied in version 0.1.0; an administrator must click Apply GitHub.
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
4. The plugin checks enabled documents for remote changes every ten minutes through WP-Cron. WP-Cron is request-driven, so this is not an exact clock.
5. When the status is remote_pending, review and click Apply GitHub.
6. The plugin creates a snapshot, validates the JSON, saves it through Elementor, reads it back, and verifies the SHA-256 fingerprint.
7. If verification fails, the snapshot is restored.

Normal WordPress or Elementor saves can be exported automatically when Automatic export is enabled. An automatic export refuses to overwrite a GitHub file whose SHA no longer matches the known base.

== Repository layout ==

The JSON root defaults to `elementor`:

`elementor/pages/42.json`
`elementor/posts/91.json`
`elementor/templates/120.json`
`elementor/custom/{post-type}/123.json`

Each file uses Elementor's documented document wrapper:

`title`, `type`, `version`, `page_settings`, `content`.

The plugin currently accepts Elementor JSON structure version `0.4` and preserves the live document type. Current Elementor pages and posts therefore remain `wp-page` and `wp-post`, and their stable element IDs are kept for exact same-document synchronization. These bridge files are not promised as drop-in Template Library imports for live pages/posts; saved Elementor Library templates keep their own template document type.

It does not convert V3 to V4, V4 to V3, invent site IDs, or rewrite dynamic references.

== Security ==

Only users with the custom `manage_elementor_json_bridge` capability can use the bridge. Activation grants that capability to Administrators only. Every document action additionally requires `edit_post` for that document.

Protected admin actions use authenticated WordPress REST requests with a REST nonce and server-side capability checks. Nonces are not treated as authorization.

GitHub credentials are encrypted with libsodium Secretbox where available or AES-256-GCM via OpenSSL as a fallback. If neither encryption primitive exists, GitHub credentials are not stored.

The plugin makes outbound requests only to fixed GitHub HTTPS endpoints. It does not expose an inbound public webhook.

== External service ==

Elementor JSON Bridge uses GitHub as an external storage and version-control service only after an administrator explicitly configures and connects it.

Data sent to GitHub can include the selected repository owner/name/branch, Elementor document JSON, document IDs in file paths, commit messages, and the GitHub authorization requests needed for Device Flow and repository access. Elementor JSON can contain website content and references to site-specific data, so use a private repository and review what you synchronize.

The plugin connects only to GitHub's documented HTTPS endpoints at `github.com` and `api.github.com`. GitHub's own terms and privacy policy apply to data sent there:

* GitHub Terms of Service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* GitHub Privacy Statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

No Elementor JSON is sent to OpenAI by this plugin. ChatGPT or Codex access is a separate GitHub connection controlled outside WordPress.

== Backups and rollback ==

Before applying GitHub JSON or manually restoring an older snapshot, the plugin stores a private local snapshot. The ten newest snapshots per Elementor document are retained.

GitHub commit history is useful version history but is not treated as the only backup.

== Limitations ==

Version 0.1.0 is intentionally conservative:

* Remote JSON is never applied automatically by cron.
* The plugin performs structural validation and exact post-save roundtrip verification, but it cannot prove every widget, add-on, dynamic tag, Theme Builder condition, form action, or site-specific dependency is valid.
* Production use should be verified on staging first, especially for Elementor Pro, Theme Builder, Loops, Forms, WooCommerce, Dynamic Tags, Components, and Atomic/V4 content.
* The GitHub App must already exist and be installed on the selected repository.

== Uninstall ==

GitHub authentication is always deleted on uninstall. Settings and snapshots are retained by default so an accidental uninstall does not destroy recovery data. Enable Uninstall cleanup before uninstalling if you want all plugin-owned settings, snapshots, locks, and sync metadata removed.

== Changelog ==

= 0.1.0 =
* Initial release.
* GitHub Device Flow without a client secret or personal access token.
* Private GitHub Contents synchronization.
* Conflict detection with GitHub blob SHA and local SHA-256 fingerprints.
* Local snapshots, structural validation, Elementor document save, readback verification, and rollback.
* Safe automatic export and request-driven remote polling.
* Explicit administrator approval for every remote apply.
