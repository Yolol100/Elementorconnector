=== Elementor JSON Bridge ===
Contributors: webactueel
Tags: wordpress, github, elementor, acf, yoast
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to a private GitHub repository so reviewed tools can safely edit normal WordPress content, Elementor data, ACF values and Yoast fields.

== Description ==

Elementor JSON Bridge 0.5.0 turns the existing GitHub bridge into a zero-configuration WordPress content bridge. After an administrator configures one private GitHub repository and completes GitHub Device Flow, editable WordPress content is discovered automatically. There is no per-page enable step and no first manual export requirement.

The managed GitHub envelope can contain:

* normal WordPress title, slug, status, editor/block content, excerpt, parent, menu order, comment/ping status, page template and featured-image ID;
* visible taxonomies by existing term slug;
* Advanced Custom Fields values when ACF is active, bound to the exported ACF field key and type;
* selected user-editable Yoast SEO fields when Yoast SEO is active, including focus keyphrase, SEO title, meta description, robots/canonical/schema and social metadata;
* safe registered non-protected post metadata that is registered with `show_in_rest`;
* Elementor document data when the item is actually built with Elementor.

The existing local Elementor Page/Post export and guarded Elementor Page/Post JSON import remain available. Elementor's own Templates import button remains untouched.

Development builds also maintain a read-only `elementor-capabilities.json` snapshot in the same private site repository. It lets connected tools verify which Elementor/Core/Pro/add-on widgets, elements, document types, Dynamic Tags and controls are actually registered on the target before planning site-bound Elementor JSON.

The plugin does not contain an OpenAI API integration and does not require an MCP server. ChatGPT, Codex or another reviewed tool edits the private GitHub copy. WordPress performs the actual site write after its own validation, conflict, permission, snapshot and readback checks.

== One-time setup ==

1. Create a private GitHub repository for the website content.
2. Create/install a GitHub App with Device Flow enabled and Repository permissions -> Contents: Read and write.
3. In Tools -> Elementor JSON Bridge enter the public GitHub App Client ID, repository owner, repository name, branch and content folder.
4. Click Connect GitHub and authorize the device code.

Completing the GitHub connection records that administrator as the background actor and enables automatic export/apply. Existing and future editable WordPress content is then discovered automatically through the polling cycle. Individual items can still be excluded as an emergency opt-out.

Real website content must not be synchronized to a public repository. The plugin rejects public repositories before reading or writing managed content.

== Repository protocol ==

The default root is `site-data`.

The plugin maintains:

`site-data/bridge.json`
`site-data/site-index.json`
`site-data/elementor-capabilities.json`
`site-data/content/pages/42.json`
`site-data/content/posts/91.json`
`site-data/content/templates/120.json`
`site-data/content/custom/{post-type}/123.json`
`site-data/requests/{request-id}.json`

`bridge.json` is the machine-readable contract. `site-index.json` tells ChatGPT or another tool which canonical file belongs to each current WordPress item. Existing content must be edited through the exact path listed in that index. The stable `source.id` and `source.post_type` inside an existing content file must not be changed.

`elementor-capabilities.json` uses format `elementor-json-bridge/elementor-capabilities`, version `1`. It is read-only target evidence and is never applied back to WordPress. It records active runtime/plugin versions plus registered Elementor widgets, layout/Atomic elements, document/template types, Dynamic Tags and exposed controls. Add-on capabilities are usable candidates only when the exact runtime registers them; the file does not authorize installing or activating a plugin.

The managed content format is `elementor-json-bridge/wordpress-content`, version `1`.

== Creating new content ==

A connected tool can request a new WordPress item by creating a unique JSON file below `site-data/requests/` with:

`format`: `elementor-json-bridge/create-content`
`version`: `1`
`request_id`: a unique stable ID
`post_type`: a managed WordPress post type
`post.title`: required
`post.slug`, `post.content`, `post.excerpt`: optional

Optional `taxonomies`, `acf`, `yoast` and `registered_meta` sections can also be supplied when their fields already exist and pass the same validation rules.

New content is always created as a WordPress draft. The plugin writes a `result` object back into the request file with the new WordPress ID and canonical content path. Request IDs are remembered so a retry cannot silently create a duplicate item.

This request protocol creates normal WordPress content. It does not invent a new Elementor layout for a brand-new item. Existing Elementor-built items remain fully editable through their `elementor` section, and the existing Elementor JSON import flow remains available when an Elementor layout must be created from template JSON.

== Synchronization and safety ==

Normal WordPress saves are marked dirty and exported automatically. Elementor after-save also marks the same WordPress item dirty. GitHub is checked through request-driven WP-Cron on an approximately one-minute target cadence.

The read-only Elementor capability inventory uses the same private-repository gate. Full capability scans are throttled to once per hour during ordinary polling; plugin activation, deactivation or updater completion invalidates that throttle so the next poll can refresh sooner. An unchanged deterministic inventory does not create a new GitHub commit.

Before an existing GitHub file can be applied, the bridge requires a trusted local base, a fresh GitHub SHA, a matching pending content fingerprint and an unchanged live WordPress fingerprint. Both sides changing from the same base is a conflict and does not auto-merge.

Every remote apply:

* acquires the same per-item document lock used by other bridge writes;
* rechecks the recorded administrator's bridge capability and `edit_post` permission;
* creates an integrity-checked private snapshot containing the full managed WordPress envelope;
* validates core fields, taxonomy identities, ACF field identities, Yoast field names, registered metadata and optional Elementor data;
* writes through WordPress, ACF, Yoast and Elementor APIs rather than directly mutating Elementor JSON storage;
* reads the complete managed envelope back and verifies its canonical SHA-256 fingerprint;
* restores the snapshot and verifies rollback when apply/readback fails.

Publishing or scheduling content additionally requires the relevant post type's publish capability. Taxonomy changes require the taxonomy assign capability. Registered metadata writes require `edit_post_meta` for the field.

ACF data fails closed if the field name/key/type no longer matches the exported field. Yoast data fails closed if Yoast is unavailable. Elementor data can only be applied to an item whose `_elementor_edit_mode` is actually `builder`; ordinary WordPress pages and posts are not silently converted into Elementor documents.

Version 0.5.0 writes generic content to the new `content/` subtree. When upgrading from the old Elementor-only synchronization path, a known old bridge path is treated as a legacy base: local sync state is reseeded to the new path and the old remote JSON is left untouched rather than overwritten or deleted.

== GitHub App setup ==

The GitHub App needs only Repository permissions -> Contents: Read and write for this plugin. Do not grant Administration, Issues, Actions or unrelated permissions merely for the bridge.

Device Flow uses the public Client ID. Do not paste a client secret or personal access token into plugin settings. Access and refresh tokens returned by GitHub are encrypted at rest using libsodium Secretbox when available or AES-256-GCM as the fallback. Malformed encrypted packages fail closed.

== Local Elementor export and import ==

Elementor-built Pages and Posts keep the `Export Elementor JSON` row action. The optional header/footer bundle remains a bridge-owned transport format and requires Elementor Pro Theme Builder for real matching site parts.

The normal Pages and Posts overviews keep the `Import Elementor template` button. Its replacement checkbox remains opt-in: unchecked creates a new draft Page/Post, checked replaces the safely recognized same-type Elementor item after revalidation, snapshot and readback verification.

Elementor -> Templates keeps Elementor's native import behavior and normal ZIP/template path.

== External service and privacy ==

Elementor JSON Bridge sends managed website content only to the GitHub repository selected by the administrator. That content can include page/post text, ACF values, SEO metadata, taxonomy references, registered metadata and Elementor JSON. The read-only capability artifact also includes WordPress/PHP/theme/Elementor/plugin version metadata and registered Elementor capability names/controls. Use a private repository and grant access only to people/tools that should see that technical inventory and content.

The plugin connects only to GitHub's documented HTTPS endpoints at `github.com` and `api.github.com`.

* GitHub Terms of Service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* GitHub Privacy Statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

The plugin does not send WordPress content to OpenAI. ChatGPT/Codex access to the repository is a separate connection controlled outside WordPress.

== Runtime verification ==

Repository CI covers PHP 8.1 through 8.5, WordPress Coding Standards/PHP compatibility, dependency audit, reproducible package building and WordPress Plugin Check. Real `wp-env` acceptance runs against WordPress 6.8.3/PHP 8.1/Elementor 4.2.4/ACF 6.8.9 and WordPress 7.1/PHP 8.3/Elementor 4.2.4/ACF 6.8.9/Yoast 28.3.

The runtime suite covers real target Elementor capability collection, normal non-Elementor WordPress content roundtrip, registered metadata, taxonomies, optional ACF/Yoast fields, Elementor isolation, draft creation, existing Elementor document save/readback, local Elementor export/import and snapshot integrity.

Actual production GitHub credentials, Elementor Pro Theme Builder condition matching, third-party add-on combinations, and final wp-admin browser appearance/focus/screen-reader behavior remain staging/browser gates.

== Limitations ==

* The bridge manages editable post-type content; it does not expose arbitrary WordPress options, users, passwords, plugin settings, database tables, WooCommerce orders, payment data or secrets as generic editable GitHub JSON.
* The capability inventory is read-only availability evidence. It does not install plugins, prove Form delivery, prove Theme Builder conditions, validate WooCommerce transactions or prove frontend rendering.
* New request files create drafts, never automatic published content.
* ACF only exposes fields ACF resolves for the specific content item; arbitrary hidden post meta is not exported as ACF.
* Registered metadata is limited to non-protected `show_in_rest` post meta. Private/internal plugin meta is intentionally not bulk exposed.
* Yoast support is limited to a conservative user-editable field whitelist; calculated analysis/indexable data is not synchronized.
* Taxonomy updates use existing terms only. The bridge does not silently create categories/tags from GitHub input.
* WP-Cron is request-driven, so the one-minute poll is a target rather than a hard real-time guarantee.
* Production rollout should be staging-first for plugin combinations and content models not represented by CI.

== Uninstall ==

GitHub authentication and processed request IDs are always deleted on uninstall. Settings and snapshots are retained by default so an accidental uninstall does not destroy recovery data. Enable Uninstall cleanup before uninstalling to remove plugin-owned settings, snapshots, locks and sync metadata as well.

== Changelog ==

= Unreleased =
* Add a deterministic, read-only private target Elementor capability inventory for Core, Pro and installed add-ons.
* Add bounded automatic refresh with immediate invalidation after plugin lifecycle changes.
* Add static and real WordPress/Elementor runtime coverage for the capability surface.
* Update both runtime canaries to Elementor 4.2.4.

= 0.5.0 =
* Replace per-document opt-in GitHub sync with automatic discovery of editable WordPress content after the one-time GitHub connection.
* Add a versioned full WordPress content envelope for normal editor/block content, taxonomies, featured image/template fields, safe registered metadata and optional Elementor data.
* Add ACF field-value synchronization bound to field key/type identity and conservative Yoast SEO field synchronization through Yoast's metadata API.
* Add machine-readable `bridge.json` and `site-index.json` files so connected tools can deterministically find canonical content files.
* Add idempotent GitHub create-content request files that create new WordPress content as drafts and return the new WordPress ID/path.
* Extend snapshots, conflict detection, shared locking, readback verification and rollback to the complete managed WordPress envelope.
* Keep ordinary WordPress content separate from Elementor unless the item is actually marked as an Elementor builder document.
* Move generic managed files to the `content/` subtree and safely reseed known legacy Elementor-only paths without deleting old remote JSON.

= 0.4.1 =
* Move custom Elementor JSON import to dedicated buttons on the normal Pages and Posts overviews.
* Keep Elementor's native Templates import button untouched.
* Use one detected-target checkbox: unchecked creates a draft; checked replaces the safely revalidated same-type Elementor item.
* Share the document lock between local replacement and GitHub synchronization.

= 0.4.0 =
* Add guarded smart Elementor JSON re-import with deterministic recognition, snapshots, readback and rollback.

= 0.3.2 =
* Replace the oversized local-export success notice with a compact WordPress Snackbar.

= 0.3.1 =
* Fix Theme Builder Page/Post query context and normalize unexpected local-export failures.

= 0.3.0 =
* Add direct Page/Post Elementor JSON export with optional Theme Builder header/footer bundle.

= 0.2.2 =
* Harden encrypted credentials, snapshot integrity and Elementor canonicalization; add real WordPress/Elementor runtime acceptance.

= 0.2.1 =
* Run automatic apply under the explicitly authorized administrator context.
