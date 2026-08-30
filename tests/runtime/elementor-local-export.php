<?php

use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\LocalExport;
use Webactueel\ElementorJsonBridge\Elementor\SiteParts;

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress was not bootstrapped.');
}
if (!class_exists('Elementor\\Plugin') || !defined('ELEMENTOR_VERSION')) {
    throw new RuntimeException('Elementor is not active in the runtime environment.');
}
if (!defined('EJB_VERSION') || !class_exists(LocalExport::class)) {
    throw new RuntimeException('Elementor JSON Bridge local export is not active in the runtime environment.');
}

$admin = get_user_by('login', 'admin');
if (!$admin instanceof WP_User) {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $admin = $admins[0] ?? null;
}
if (!$admin instanceof WP_User) {
    throw new RuntimeException('No administrator user is available for the runtime test.');
}

$previous_user = get_current_user_id();
wp_set_current_user((int) $admin->ID);
register_post_type('product', ['public' => false, 'show_ui' => true]);

$ids = [];

try {
    foreach (
        [
            'page' => 'EJB Export Page',
            'post' => 'EJB Export Post',
            'product' => 'EJB Export Product',
        ] as $post_type => $title
    ) {
        $id = wp_insert_post(
            [
                'post_type' => $post_type,
                'post_status' => 'publish',
                'post_title' => $title,
                'post_content' => '',
            ],
            true
        );
        if (is_wp_error($id)) {
            throw new RuntimeException('Unable to create runtime export document: ' . $id->get_error_message());
        }
        $ids[$post_type] = (int) $id;
        update_post_meta((int) $id, '_elementor_edit_mode', 'builder');
    }

    $documents = new Documents();
    $exporter = new LocalExport($documents, new SiteParts($documents));

    if (!$documents->is_elementor_document($ids['page']) || !$documents->is_elementor_document($ids['post'])) {
        throw new RuntimeException('Elementor did not expose both page and post runtime documents.');
    }

    $page_export = $exporter->export($ids['page'], false);
    if (($page_export['format'] ?? '') !== 'elementor-document' || ($page_export['export']['type'] ?? '') !== 'wp-page') {
        throw new RuntimeException('Real page export did not produce the expected Elementor document JSON.');
    }

    $post_export = $exporter->export($ids['post'], true);
    if (($post_export['format'] ?? '') !== 'elementor-json-bridge/site-parts-bundle') {
        throw new RuntimeException('Real post export did not produce the requested site-parts bundle.');
    }
    if (($post_export['export']['document']['type'] ?? '') !== 'wp-post') {
        throw new RuntimeException('Real post bundle did not contain the source Elementor document.');
    }
    if (($post_export['included_site_parts']['header'] ?? true) !== false || ($post_export['included_site_parts']['footer'] ?? true) !== false) {
        throw new RuntimeException('Elementor Free runtime unexpectedly reported Pro header/footer site parts.');
    }
    if (empty($post_export['warnings'])) {
        throw new RuntimeException('Elementor Free runtime did not explain why header/footer were unavailable.');
    }

    $page_actions = apply_filters('page_row_actions', [], get_post($ids['page']));
    $post_actions = apply_filters('post_row_actions', [], get_post($ids['post']));
    $product_actions = apply_filters('post_row_actions', [], get_post($ids['product']));
    if (!isset($page_actions['ejb_export_json']) || !isset($post_actions['ejb_export_json'])) {
        throw new RuntimeException('The page/post list did not receive the Elementor JSON export row action.');
    }
    if (isset($product_actions['ejb_export_json'])) {
        throw new RuntimeException('The product list incorrectly received the Elementor JSON export row action.');
    }

    $product_rejected = false;
    try {
        $exporter->export($ids['product'], false);
    } catch (RuntimeException $exception) {
        $product_rejected = str_contains($exception->getMessage(), 'only for pages and posts');
    }
    if (!$product_rejected) {
        throw new RuntimeException('The server-side product export gate did not fail closed.');
    }

    echo wp_json_encode(
        [
            'status' => 'PASS',
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'elementor' => ELEMENTOR_VERSION,
            'bridge' => EJB_VERSION,
            'page_action' => true,
            'post_action' => true,
            'product_action' => false,
            'plain_page_json' => true,
            'site_parts_fallback_without_pro' => true,
        ],
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} finally {
    foreach ($ids as $id) {
        wp_delete_post((int) $id, true);
    }
    unregister_post_type('product');
    wp_set_current_user($previous_user);
}
