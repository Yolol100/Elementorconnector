<?php

use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Elementor\TemplateImporter;
use Webactueel\ElementorJsonBridge\Sync\Lock;

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress was not bootstrapped.');
}
if (!class_exists('Elementor\\Plugin') || !defined('ELEMENTOR_VERSION')) {
    throw new RuntimeException('Elementor is not active in the runtime environment.');
}
if (!defined('EJB_VERSION') || !class_exists(TemplateImporter::class)) {
    throw new RuntimeException('Elementor JSON Bridge Page/Post import is not active in the runtime environment.');
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
            'post_title' => 'EJB Overview Import Target',
            'post_name' => 'ejb-overview-import-target',
            'post_content' => '',
        ],
        true
    );
    if (is_wp_error($page_id)) {
        throw new RuntimeException('Unable to create the Page import target.');
    }
    $page_id = (int) $page_id;
    $created_ids[] = $page_id;
    update_post_meta($page_id, '_elementor_edit_mode', 'builder');

    $post_id = wp_insert_post(
        [
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'EJB Overview Import Target',
            'post_name' => 'ejb-overview-import-post-target',
            'post_content' => '',
        ],
        true
    );
    if (is_wp_error($post_id)) {
        throw new RuntimeException('Unable to create the Post import target.');
    }
    $post_id = (int) $post_id;
    $created_ids[] = $post_id;
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');

    $documents = new Documents();
    $validator = new PayloadValidator();
    $snapshots = new Snapshots();
    $lock = new Lock();
    $importer = new TemplateImporter($documents, $validator, $snapshots, $lock);

    $page_type = $documents->document_type($page_id);
    $page_base = $validator->validate_array($documents->payload($page_id), $page_type);
    $page_base['content'] = [
        [
            'id' => 'ejbbase1',
            'elType' => 'container',
            'settings' => [],
            'elements' => [],
        ],
    ];
    $page_base = $validator->validate_array($page_base, $page_type);
    $documents->save_payload($page_id, $page_base);

    $post_type = $documents->document_type($post_id);
    $post_base = $validator->validate_array($documents->payload($post_id), $post_type);
    $post_base['content'] = [
        [
            'id' => 'ejbpost1',
            'elType' => 'container',
            'settings' => [],
            'elements' => [],
        ],
    ];
    $post_base = $validator->validate_array($post_base, $post_type);
    $documents->save_payload($post_id, $post_base);

    $incoming = $page_base;
    $incoming['content'] = [
        [
            'id' => 'ejbimport1',
            'elType' => 'container',
            'settings' => ['content_width' => 'boxed'],
            'elements' => [],
        ],
    ];
    $incoming = $validator->validate_array($incoming, $page_type);
    $json = wp_json_encode($incoming, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode the Page/Post import runtime fixture.');
    }

    $filename = 'ejb-overview-import-target-elementor.json';
    $page_analysis = $importer->analyze($json, $filename, 'page');
    if ((int) ($page_analysis['recognized_target']['id'] ?? 0) !== $page_id) {
        throw new RuntimeException('Page overview analysis did not recognize the exact Page target.');
    }
    if (($page_analysis['recognition']['confidence'] ?? '') !== 'high') {
        throw new RuntimeException('Exact Page slug/title recognition was not classified as a strong match.');
    }

    $post_analysis = $importer->analyze($json, $filename, 'post');
    if ((int) ($post_analysis['recognized_target']['id'] ?? 0) !== $post_id) {
        throw new RuntimeException('Post overview analysis did not stay inside the Post destination.');
    }
    if (($post_analysis['recognition']['confidence'] ?? '') !== 'medium') {
        throw new RuntimeException('Unique exact-title Post recognition was not classified correctly.');
    }

    $held_token = $lock->acquire($page_id);
    $concurrent_rejected = false;
    try {
        $importer->execute($json, $filename, 'page', true, $page_id);
    } catch (RuntimeException $exception) {
        $concurrent_rejected = str_contains($exception->getMessage(), 'already being synchronized');
    } finally {
        $lock->release($page_id, $held_token);
    }
    if (!$concurrent_rejected) {
        throw new RuntimeException('Page replacement ignored the shared synchronization lock.');
    }

    $stale_target_rejected = false;
    try {
        $importer->execute($json, $filename, 'page', true, $post_id);
    } catch (RuntimeException) {
        $stale_target_rejected = true;
    }
    if (!$stale_target_rejected) {
        throw new RuntimeException('Page replacement accepted a target ID that did not match fresh recognition.');
    }

    $replace = $importer->execute($json, $filename, 'page', true, $page_id);
    if (($replace['action'] ?? '') !== 'replaced' || (int) ($replace['snapshot_id'] ?? 0) < 1) {
        throw new RuntimeException('Checked replacement did not create a rollback snapshot.');
    }
    $saved = $validator->validate_array($documents->payload($page_id), $page_type);
    if (($saved['content'][0]['id'] ?? '') !== 'ejbimport1') {
        throw new RuntimeException('Checked replacement did not persist the imported Elementor content.');
    }
    if ('' !== (string) get_post_meta($page_id, '_ejb_lock', true)) {
        throw new RuntimeException('Page replacement left the shared document lock behind.');
    }

    $new_page = $importer->execute($json, $filename, 'page', false, 0);
    $new_page_id = (int) ($new_page['id'] ?? 0);
    $created_ids[] = $new_page_id;
    $new_page_post = get_post($new_page_id);
    if (!$new_page_post instanceof WP_Post || 'page' !== $new_page_post->post_type || 'draft' !== $new_page_post->post_status) {
        throw new RuntimeException('Unchecked Page import did not create a new Page draft.');
    }

    $new_post = $importer->execute($json, $filename, 'post', false, 0);
    $new_post_id = (int) ($new_post['id'] ?? 0);
    $created_ids[] = $new_post_id;
    $new_post_post = get_post($new_post_id);
    if (!$new_post_post instanceof WP_Post || 'post' !== $new_post_post->post_type || 'draft' !== $new_post_post->post_status) {
        throw new RuntimeException('Unchecked Post import did not create a new Post draft.');
    }

    $invalid_destination_rejected = false;
    try {
        $importer->analyze($json, $filename, 'product');
    } catch (RuntimeException) {
        $invalid_destination_rejected = true;
    }
    if (!$invalid_destination_rejected) {
        throw new RuntimeException('Page/Post import accepted Product as a destination.');
    }

    $header_data = json_decode($json, true);
    $header_data['type'] = 'header';
    $header_json = wp_json_encode($header_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $specialized_type_rejected = false;
    try {
        $importer->analyze($header_json, 'header.json', 'page');
    } catch (RuntimeException) {
        $specialized_type_rejected = true;
    }
    if (!$specialized_type_rejected) {
        throw new RuntimeException('Page overview accepted a specialized non-Page Elementor template type.');
    }

    $duplicate_page_id = wp_insert_post(
        [
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => 'EJB Overview Import Target',
            'post_name' => 'ejb-overview-import-duplicate',
            'post_content' => '',
        ],
        true
    );
    if (is_wp_error($duplicate_page_id)) {
        throw new RuntimeException('Unable to create the ambiguous Page fixture.');
    }
    $duplicate_page_id = (int) $duplicate_page_id;
    $created_ids[] = $duplicate_page_id;
    update_post_meta($duplicate_page_id, '_elementor_edit_mode', 'builder');

    $ambiguous = $importer->analyze($json, 'generic-template.json', 'page');
    if (null !== ($ambiguous['recognized_target'] ?? null)) {
        throw new RuntimeException('Ambiguous exact-title Pages were not handled fail-closed.');
    }

    echo wp_json_encode(
        [
            'status' => 'PASS',
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'elementor' => ELEMENTOR_VERSION,
            'bridge' => EJB_VERSION,
            'page_destination_recognition' => true,
            'post_destination_recognition' => true,
            'shared_lock_rejection' => true,
            'stale_target_rejection' => true,
            'replacement_snapshot' => true,
            'replacement_readback' => true,
            'unchecked_new_page_draft' => true,
            'unchecked_new_post_draft' => true,
            'product_destination_rejected' => true,
            'specialized_type_rejected' => true,
            'ambiguous_match_rejected' => true,
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
