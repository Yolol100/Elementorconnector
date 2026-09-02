<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$content = file_get_contents($root . '/includes/Content/WordPressDocument.php');
$manager = file_get_contents($root . '/includes/Sync/Manager.php');
$requests = file_get_contents($root . '/includes/Sync/ContentRequests.php');
$settings = file_get_contents($root . '/includes/Settings.php');
$plugin = file_get_contents($root . '/includes/Plugin.php');
$main = file_get_contents($root . '/elementor-json-bridge.php');
$uninstall = file_get_contents($root . '/uninstall.php');
$posts = file_get_contents($root . '/includes/Content/PostRequest.php');
$products = file_get_contents($root . '/includes/Content/ProductRequest.php');
$woo = file_get_contents($root . '/includes/Content/WooCommerceProduct.php');
$variations = file_get_contents($root . '/includes/Content/ProductVariation.php');
$terms = file_get_contents($root . '/includes/Content/TaxonomyTerm.php');
$abilities = file_get_contents($root . '/includes/Content/AbilityBridge.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(is_string($content) && is_string($manager) && is_string($requests) && is_string($settings) && is_string($plugin) && is_string($main) && is_string($uninstall), 'WordPress content sync source files could not be read.');
$assert(is_string($posts) && is_string($products) && is_string($woo) && is_string($variations) && is_string($terms) && is_string($abilities), 'Expanded content-operation source files could not be read.');

$assert(str_contains($content, "'elementor-json-bridge/wordpress-content'"), 'The versioned WordPress content format is missing.');
$assert(str_contains($content, "'elementor-json-bridge/create-content'"), 'The create-content request format is missing.');
$assert(str_contains($content, "'content'        => (string) \$post->post_content"), 'Normal WordPress editor content is not part of the managed envelope.');
$assert(str_contains($content, 'get_field_objects(') && str_contains($content, 'update_field('), 'ACF read/write integration is missing.');
$assert(str_contains($content, '\\WPSEO_Meta::set_value('), 'Yoast metadata writes do not use the Yoast metadata API.');
$assert(str_contains($content, 'get_registered_meta_keys(') && str_contains($content, "current_user_can( 'edit_post_meta'"), 'Safe registered metadata is not permission-gated.');
$assert(str_contains($content, "current_user_can( \$object->cap->assign_terms )"), 'Taxonomy writes are not permission-gated.');
$assert(str_contains($content, "'post_status'  => 'draft'"), 'Create-content no longer forces new WordPress content to draft.');
$assert(str_contains($content, "'elementor'       => null"), 'The generic envelope no longer distinguishes optional Elementor data.');
$assert(str_contains($content, '! empty( $object->public )') && str_contains($content, '! empty( $object->publicly_queryable )'), 'Automatic discovery is not restricted to website-facing content types.');
$assert(str_contains($content, 'EXPLICIT_CONTENT_POST_TYPES') && str_contains($content, "'elementor_library'"), 'Safe explicit editor/template content types are no longer preserved.');

$assert(str_contains($manager, "'1' !== (string) get_post_meta( \$post_id, State::META_EXCLUDED, true )"), 'Managed content is no longer enabled automatically by default.');
$assert(!str_contains($manager, "'meta_key'       => State::META_ENABLED"), 'Polling still depends on the old per-document enable metadata.');
$assert(str_contains($manager, "'/content/'"), 'Canonical WordPress content files are not routed through the content subtree.');
$assert(str_contains($manager, "'/site-index.json'"), 'The automatic site index is missing.');
$assert(str_contains($manager, '$this->snapshots->create( $post_id, $current, \'before_remote_apply\''), 'Remote WordPress writes no longer snapshot the full managed envelope.');
$assert(str_contains($manager, '$this->content->apply( $post_id, $rollback )'), 'Generic WordPress rollback is missing.');

$assert(str_contains($requests, "'/bridge.json'"), 'The machine-readable repository manifest is missing.');
$assert(str_contains($requests, "'/requests'"), 'GitHub request discovery is missing.');
$assert(str_contains($requests, 'WordPressDocument::CREATE_FORMAT'), 'Create-content requests are not bound to the versioned request format.');
$assert(str_contains($requests, 'PostRequest::FORMAT') && str_contains($requests, 'ProductRequest::FORMAT') && str_contains($requests, 'TaxonomyTerm::FORMAT') && str_contains($requests, 'ProductVariation::FORMAT') && str_contains($requests, 'AbilityBridge::FORMAT'), 'Expanded request dispatch is incomplete.');
$assert(str_contains($requests, 'ejb_processed_content_requests') && str_contains($requests, "'fingerprint'"), 'Request idempotency or fingerprinting is missing.');
$assert(str_contains($requests, "'post_id' => \$post_id"), 'Create-content result files do not return the new WordPress ID.');
$assert(str_contains($requests, 'MAX_REQUEST_BYTES'), 'GitHub operation requests are not size bounded.');

$assert(str_contains($posts, "'elementor-json-bridge/manage-post'") && str_contains($posts, "confirm_destructive=true"), 'Page/post CRUD request support is incomplete.');
$assert(str_contains($products, "'elementor-json-bridge/manage-product'") && str_contains($products, 'publish_products'), 'WooCommerce product CRUD or publish capability checks are missing.');
$assert(str_contains($products, "[ 'acf', 'yoast', 'registered_meta', 'elementor' ]"), 'Product ACF/Yoast/registered-meta/Elementor extensions are not routed through the managed content layer.');
$assert(str_contains($woo, 'wc_get_product(') && str_contains($woo, '$product->save()') && !str_contains($woo, 'update_post_meta('), 'WooCommerce product data is not using WooCommerce CRUD exclusively.');
$assert(str_contains($variations, "'elementor-json-bridge/manage-product-variation'") && str_contains($variations, '$variation->delete( true )'), 'WooCommerce variation CRUD is incomplete.');
$assert(str_contains($terms, "'elementor-json-bridge/manage-term'") && str_contains($terms, 'wp_insert_term(') && str_contains($terms, 'wp_update_term(') && str_contains($terms, 'wp_delete_term('), 'Taxonomy-term CRUD is incomplete.');
$assert(str_contains($terms, '\\WPSEO_Taxonomy_Meta::set_values(') && str_contains($terms, 'update_field('), 'Taxonomy ACF/Yoast integration is incomplete.');
$assert(str_contains($abilities, "[ 'acf/', 'yoast-seo/' ]") && str_contains($abilities, 'wp_get_ability(') && str_contains($abilities, 'confirm_destructive=true'), 'ACF/Yoast WordPress Ability routing is not properly constrained.');

$assert(str_contains($settings, "'auto_apply'               => 0"), 'Automatic apply must remain off until the GitHub connection completes.');
$assert(str_contains($settings, 'public static function mark_connected_actor'), 'GitHub connection no longer activates the zero-config background actor.');
$assert(str_contains($settings, "\$settings['auto_apply']       = 1;"), 'Completing the GitHub connection no longer activates automatic apply.');
$assert(str_contains($plugin, 'new WordPressDocument( $documents, $validator )'), 'Plugin bootstrap does not create the generic WordPress content adapter.');
$assert(str_contains($plugin, 'new ProductRequest( $woocommerce, $content )'), 'Plugin bootstrap does not create the WooCommerce product request adapter.');
$assert(str_contains($plugin, 'new ContentRequests( $content, $posts, $products, $terms, $variations, $abilities, $github, $sync )'), 'Plugin bootstrap does not register expanded GitHub-driven operations.');
$assert(!str_contains($main, 'Requires Plugins: elementor'), 'Elementor is still a hard plugin dependency for generic WordPress content sync.');
$assert(str_contains($uninstall, "'_ejb_excluded'"), 'Zero-config exclusion metadata is not cleaned during full uninstall cleanup.');
$assert(str_contains($uninstall, "'ejb_processed_content_requests'"), 'Request idempotency state is not removed on uninstall.');

fwrite(STDOUT, "PASS wordpress-content-sync\n");
