<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$posts = file_get_contents($root . '/includes/Content/PostRequest.php');
$products = file_get_contents($root . '/includes/Content/ProductRequest.php');
$variations = file_get_contents($root . '/includes/Content/ProductVariation.php');
$productExtras = file_get_contents($root . '/includes/Content/WooCommerceProductExtras.php');
$plugin = file_get_contents($root . '/includes/Plugin.php');
$terms = file_get_contents($root . '/includes/Content/TaxonomyTerm.php');
$requests = file_get_contents($root . '/includes/Sync/ContentRequests.php');
$abilities = file_get_contents($root . '/includes/Sync/WordPressAbilities.php');
$acfFields = file_get_contents($root . '/includes/Content/AcfFields.php');
$stateToken = file_get_contents($root . '/includes/Support/StateToken.php');
$snapshots = file_get_contents($root . '/includes/Backup/Snapshots.php');
$manager = file_get_contents($root . '/includes/Sync/Manager.php');
$uninstall = file_get_contents($root . '/uninstall.php');
$wpEnv = file_get_contents($root . '/.wp-env.json');
$ci = file_get_contents($root . '/.github/workflows/ci.yml');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([$posts, $products, $variations, $productExtras, $plugin, $terms, $requests, $abilities, $acfFields, $stateToken, $snapshots, $manager, $uninstall, $wpEnv, $ci] as $source) {
    $assert(is_string($source), 'A request-safety source file could not be read.');
}

$assert(str_contains($posts, 'rollback could not be verified') && str_contains($posts, 'CanonicalJson::hash'), 'Post request rollback/readback verification is missing.');
$assert(str_contains($posts, "'expected_state_token'") && str_contains($posts, 'assert_state_token('), 'Post updates can still apply against stale local state.');
$assert(str_contains($posts, "'before_request_update'") && str_contains($posts, '$this->snapshots->create('), 'Post updates do not persist a durable pre-mutation snapshot.');
$assert(str_contains($plugin, 'new PostRequest( $content, $documents, $validator, $snapshots )'), 'Post requests are not wired to durable snapshots.');
$assert(str_contains($snapshots, '_ejb_snapshot_extra_state') && str_contains($snapshots, '_ejb_snapshot_extra_hash') && str_contains($manager, 'extra_state('), 'Extended snapshot state is not persisted and recovered with integrity checks.');

$assert(str_contains($products, "'force'") && str_contains($products, '$product->delete( $force )') && str_contains($products, "'trash' !== get_post_status"), 'WooCommerce soft/force delete semantics are missing.');
$assert(str_contains($products, 'rollback could not be verified') && str_contains($products, 'exact readback verification'), 'WooCommerce product rollback/readback verification is missing.');
$assert(str_contains($products, "'expected_state_token'") && str_contains($products, 'assert_state_token('), 'WooCommerce product updates can still apply against stale local state.');
$assert(str_contains($productExtras, 'global_unique_id') && str_contains($productExtras, 'low_stock_amount') && str_contains($productExtras, 'brand_ids') && str_contains($productExtras, 'set_global_unique_id') && str_contains($productExtras, 'set_brand_ids'), 'Current WooCommerce product-model extras are not bridged.');
$assert(str_contains($products, 'WooCommerceProductExtras') && str_contains($products, 'woo_payload(') && str_contains($products, 'apply_woo('), 'Product requests do not merge current WooCommerce product-model extras.');
$assert(str_contains($plugin, 'new WooCommerceProductExtras()') && str_contains($plugin, 'new ProductRequest( $woocommerce, $woocommerce_extra, $content )'), 'Plugin bootstrap does not inject current WooCommerce product-model extras.');

$assert(str_contains($terms, 'rollback could not be verified') && str_contains($terms, 'assert_requested_state'), 'Taxonomy request rollback/readback verification is missing.');
$assert(str_contains($terms, "'expected_state_token'") && str_contains($terms, 'StateToken::assert_matches('), 'Taxonomy updates can still apply against stale local state.');
$assert(str_contains($terms, 'AcfFields::validate_taxonomy(') && str_contains($acfFields, 'acf_get_field_groups(') && str_contains($acfFields, 'delete_field('), 'ACF first-write identity and rollback deletion are not protected.');
$assert(str_contains($variations, 'rollback could not be verified') && str_contains($variations, 'assert_requested_state'), 'Variation request rollback/readback verification is missing.');
$assert(str_contains($variations, "'expected_state_token'") && str_contains($variations, 'StateToken::assert_matches('), 'Variation updates can still apply against stale local state.');
$assert(str_contains($variations, 'global_unique_id') && str_contains($variations, 'low_stock_amount'), 'Variation requests do not cover current stable identifier/low-stock fields.');

$assert(str_contains($stateToken, 'hash_hmac(') && str_contains($stateToken, "wp_salt( 'auth' )") && str_contains($stateToken, 'hash_equals('), 'State tokens are not site-scoped and timing-safe.');
$assert(str_contains($requests, "should_process( Settings::get( 'auto_apply', 0 ) )"), 'Repository request processing does not honor auto_apply.');
$assert(str_contains($requests, 'ejb_content_requests_lock') && str_contains($requests, 'add_option( self::PROCESS_LOCK_OPTION') && str_contains($requests, 'PROCESS_LOCK_TTL'), 'Request-processing lock is missing.');
$assert(str_contains($requests, 'compare_and_swap_process_lock(') && str_contains($requests, 'delete_process_lock_if_value('), 'Stale lock takeover/release is not compare-and-swap protected.');
$assert(!str_contains($requests, 'delete_option( self::PROCESS_LOCK_OPTION )'), 'A stale process can still unconditionally delete a newer lock.');

$assert(str_contains($abilities, "'integrations'") && str_contains($abilities, 'WC_VERSION') && str_contains($abilities, 'WPSEO_VERSION') && str_contains($abilities, 'ACF_VERSION') && str_contains($abilities, 'ELEMENTOR_VERSION'), 'Ability catalog runtime version context is incomplete.');
$assert(str_contains($uninstall, "delete_option( 'ejb_content_requests_lock' )"), 'Request-processing lock is not cleaned during uninstall.');
$assert(str_contains($wpEnv, 'woocommerce.11.0.1.zip') && str_contains($wpEnv, 'wordpress-seo.28.4.zip') && str_contains($wpEnv, 'ejb-enable-acf-ai.php'), 'Current integration runtime versions or ACF AI test bootstrap are missing.');
$assert(str_contains($ci, 'content-operations.php') && str_contains($ci, 'wp plugin is-active woocommerce') && str_contains($ci, 'WPSEO_VERSION'), 'CI does not execute the expanded current-runtime scenarios.');

fwrite(STDOUT, "PASS content-request-safety\n");
