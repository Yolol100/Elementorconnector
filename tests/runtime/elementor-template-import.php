<?php

use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Elementor\TemplateImporter;

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress was not bootstrapped.');
}
if (!class_exists('Elementor\\Plugin') || !defined('ELEMENTOR_VERSION')) {
    throw new RuntimeException('Elementor is not active in the runtime environment.');
}
if (!defined('EJB_VERSION') || !class_exists(TemplateImporter::class)) {
    throw new RuntimeException('Elementor JSON Bridge smart template import is not active in the runtime environment.');
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

$created_ids = [];

try {
    $page_id = wp_insert_post(
        [
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'EJB Smart Import Target',
            'post_name' => 'ejb-smart-import-target',
            'post_content' => '',
        ],
        true
    );
    if (is_wp_error($page_id)) {
        throw new RuntimeException('Unable to create the smart import target page.');
    }
    $page_id = (int) $page_id;
    $created_ids[] = $page_id;
    update_post_meta($page_id, '_elementor_edit_mode', 'builder');

    $documents = new Documents();
    $validator = new PayloadValidator();
    $snapshots = new Snapshots();
    $importer = new TemplateImporter($documents, $validator, $snapshots);

    $document_type = $documents->document_type($page_id);
    $base = $validator->validate_array($documents->payload($page_id), $document_type);
    $base['content'] = [
        [
            'id' => 'ejbbase1',
            'elType' => 'container',
            'settings' => [],
            'elements' => [],
        ],
    ];
    $base = $validator->validate_array($base, $document_type);
    $documents->save_payload($page_id, $base);

    $incoming = $base;
    $incoming['content'] = [
        [
            'id' => 'ejbimport1',
            'elType' => 'container',
            'settings' => ['content_width' => 'boxed'],
            'elements' => [],
        ],
    ];
    $incoming = $validator->validate_array($incoming, $document_type);
    $json = wp_json_encode($incoming, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode the smart import runtime fixture.');
    }

    $analysis = $importer->analyze($json, 'ejb-smart-import-target-elementor.json');
    if ((int) ($analysis['recognized_target']['id'] ?? 0) !== $page_id) {
        throw new RuntimeException('Smart import did not recognize the exact Page export filename/title target.');
    }
    if (($analysis['recognition']['confidence'] ?? '') !== 'high') {
        throw new RuntimeException('Exact Page export recognition was not classified as a strong match.');
    }

    $replace = $importer->execute($json, 'ejb-smart-import-target-elementor.json', 'replace', $page_id);
    if (($replace['action'] ?? '') !== 'replaced' || (int) ($replace['snapshot_id'] ?? 0) < 1) {
        throw new RuntimeException('Smart replacement did not create a rollback snapshot.');
    }
    $saved = $validator->validate_array($documents->payload($page_id), $document_type);
    if (($saved['content'][0]['id'] ?? '') !== 'ejbimport1') {
        throw new RuntimeException('Smart replacement did not persist the imported Elementor content.');
    }
    if ((string) get_post($page_id)->post_title !== 'EJB Smart Import Target') {
        throw new RuntimeException('Smart replacement unexpectedly changed the existing WordPress title.');
    }

    $new_page = $importer->execute($json, 'ejb-smart-import-target-elementor.json', 'new_page');
    $new_page_id = (int) ($new_page['id'] ?? 0);
    $created_ids[] = $new_page_id;
    $new_page_post = get_post($new_page_id);
    if (!$new_page_post instanceof WP_Post || 'page' !== $new_page_post->post_type || 'draft' !== $new_page_post->post_status) {
        throw new RuntimeException('Smart import did not create the new Page as a draft.');
    }

    $new_post = $importer->execute($json, 'ejb-smart-import-target-elementor.json', 'new_post');
    $new_post_id = (int) ($new_post['id'] ?? 0);
    $created_ids[] = $new_post_id;
    $new_post_post = get_post($new_post_id);
    if (!$new_post_post instanceof WP_Post || 'post' !== $new_post_post->post_type || 'draft' !== $new_post_post->post_status) {
        throw new RuntimeException('Smart import did not create the new Post as a draft.');
    }

    $new_template = $importer->execute($json, 'ejb-smart-import-target-elementor.json', 'new_template');
    $template_id = (int) ($new_template['id'] ?? 0);
    $created_ids[] = $template_id;
    $template_post = get_post($template_id);
    if (!$template_post instanceof WP_Post || 'elementor_library' !== $template_post->post_type) {
        throw new RuntimeException('Smart import did not create a native Elementor Template.');
    }

    $product_id = wp_insert_post(
        [
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_title' => 'EJB Smart Import Product',
        ],
        true
    );
    if (is_wp_error($product_id)) {
        throw new RuntimeException('Unable to create the smart import product exclusion fixture.');
    }
    $product_id = (int) $product_id;
    $created_ids[] = $product_id;
    update_post_meta($product_id, '_elementor_edit_mode', 'builder');

    $product_rejected = false;
    try {
        $importer->execute($json, 'ejb-smart-import-target-elementor.json', 'replace', $product_id);
    } catch (RuntimeException) {
        $product_rejected = true;
    }
    if (!$product_rejected) {
        throw new RuntimeException('Smart import allowed a Product to become a replacement target.');
    }

    $header_json = json_decode($json, true);
    $header_json['type'] = 'header';
    $header_json = wp_json_encode($header_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $incompatible_rejected = false;
    try {
        $importer->execute($header_json, 'header.json', 'replace', $page_id);
    } catch (RuntimeException) {
        $incompatible_rejected = true;
    }
    if (!$incompatible_rejected) {
        throw new RuntimeException('Smart import allowed a header-type JSON to replace a normal Page.');
    }

    echo wp_json_encode(
        [
            'status' => 'PASS',
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'elementor' => ELEMENTOR_VERSION,
            'bridge' => EJB_VERSION,
            'recognized_existing_page' => true,
            'replacement_snapshot' => true,
            'replacement_readback' => true,
            'new_page_draft' => true,
            'new_post_draft' => true,
            'new_native_template' => true,
            'product_rejected' => true,
            'incompatible_type_rejected' => true,
        ],
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} finally {
    foreach ($created_ids as $id) {
        if ($id > 0) {
            wp_delete_post((int) $id, true);
        }
    }
    $snapshot_ids = get_posts(
        [
            'post_type' => 'ejb_snapshot',
            'post_status' => 'private',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]
    );
    foreach ($snapshot_ids as $snapshot_id) {
        wp_delete_post((int) $snapshot_id, true);
    }
    unregister_post_type('product');
    wp_set_current_user($previous_user);
}
