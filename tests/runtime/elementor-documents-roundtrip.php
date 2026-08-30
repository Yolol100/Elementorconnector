<?php

declare(strict_types=1);

use RuntimeException;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress was not bootstrapped.');
}
if (!class_exists('Elementor\\Plugin') || !defined('ELEMENTOR_VERSION')) {
    throw new RuntimeException('Elementor is not active in the runtime environment.');
}
if (!defined('EJB_VERSION') || !class_exists(Documents::class)) {
    throw new RuntimeException('Elementor JSON Bridge is not active in the runtime environment.');
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

$post_id = wp_insert_post(
    [
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'EJB Runtime Roundtrip',
        'post_content' => '',
    ],
    true
);
if (is_wp_error($post_id)) {
    throw new RuntimeException('Unable to create the runtime test page: ' . $post_id->get_error_message());
}
$post_id = (int) $post_id;

try {
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');

    $documents = new Documents();
    $validator = new PayloadValidator();

    if (!$documents->is_elementor_document($post_id)) {
        throw new RuntimeException('Elementor did not expose the test page as an editable document.');
    }

    $document_type = $documents->document_type($post_id);
    $before = $validator->validate_array($documents->payload($post_id), $document_type);

    $incoming = $before;
    $incoming['title'] = 'EJB Runtime Roundtrip Updated';
    $incoming['content'] = [
        [
            'id' => 'ejbruntime1',
            'elType' => 'container',
            'settings' => [],
            'elements' => [],
        ],
    ];
    $incoming = $validator->validate_array($incoming, $document_type);

    $documents->save_payload($post_id, $incoming);
    clean_post_cache($post_id);

    $readback = $validator->validate_array($documents->payload($post_id), $document_type);
    if (!hash_equals(CanonicalJson::hash($incoming), CanonicalJson::hash($readback))) {
        throw new RuntimeException('Real Elementor save/readback changed the synchronized payload.');
    }

    $saved_post = get_post($post_id);
    if (!$saved_post || 'EJB Runtime Roundtrip Updated' !== (string) $saved_post->post_title) {
        throw new RuntimeException('The real WordPress document title did not roundtrip.');
    }

    wp_set_current_user(0);
    $permission_denied = false;
    try {
        $documents->save_payload($post_id, $incoming);
    } catch (RuntimeException) {
        $permission_denied = true;
    }
    if (!$permission_denied) {
        throw new RuntimeException('The real runtime accepted an Elementor write without edit_post permission.');
    }

    wp_set_current_user((int) $admin->ID);

    echo wp_json_encode(
        [
            'status' => 'PASS',
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'elementor' => ELEMENTOR_VERSION,
            'bridge' => EJB_VERSION,
            'document_type' => $document_type,
            'roundtrip_hash' => CanonicalJson::hash($readback),
            'permission_denied_without_user' => true,
        ],
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} finally {
    wp_set_current_user((int) $admin->ID);
    wp_delete_post($post_id, true);
    wp_set_current_user($previous_user);
}
