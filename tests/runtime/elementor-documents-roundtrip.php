<?php

use Webactueel\ElementorJsonBridge\Backup\Snapshots;
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
$snapshot_id = 0;

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
    $incoming_hash = CanonicalJson::hash($incoming);
    $readback_hash = CanonicalJson::hash($readback);
    if (!hash_equals($incoming_hash, $readback_hash)) {
        throw new RuntimeException(
            'Real Elementor save/readback changed the controlled payload. ' .
            wp_json_encode(
                [
                    'incoming_hash' => $incoming_hash,
                    'readback_hash' => $readback_hash,
                    'incoming' => $incoming,
                    'readback' => $readback,
                ],
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    $saved_post = get_post($post_id);
    if (!$saved_post || 'EJB Runtime Roundtrip Updated' !== (string) $saved_post->post_title) {
        throw new RuntimeException('The real WordPress document title did not roundtrip.');
    }

    $snapshots = new Snapshots();
    $snapshot_id = $snapshots->create($post_id, $readback, 'runtime_integrity');
    $snapshot_readback = $snapshots->payload($snapshot_id, $post_id);
    if (!hash_equals(CanonicalJson::hash($readback), CanonicalJson::hash($snapshot_readback))) {
        throw new RuntimeException('A real database rollback snapshot did not roundtrip.');
    }

    $tampered_snapshot = $readback;
    $tampered_snapshot['title'] = 'Tampered runtime snapshot';
    $tamper_result = wp_update_post(
        [
            'ID' => $snapshot_id,
            'post_content' => wp_slash(CanonicalJson::encode($tampered_snapshot, true)),
        ],
        true
    );
    if (is_wp_error($tamper_result)) {
        throw new RuntimeException('Unable to prepare the real snapshot integrity test.');
    }

    $snapshot_tamper_rejected = false;
    try {
        $snapshots->payload($snapshot_id, $post_id);
    } catch (RuntimeException) {
        $snapshot_tamper_rejected = true;
    }
    if (!$snapshot_tamper_rejected) {
        throw new RuntimeException('The real database accepted a rollback snapshot with a mismatched integrity hash.');
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
            'roundtrip_hash' => $readback_hash,
            'snapshot_integrity_rejected_tamper' => true,
            'permission_denied_without_user' => true,
        ],
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} finally {
    wp_set_current_user((int) $admin->ID);
    if ($snapshot_id > 0) {
        wp_delete_post($snapshot_id, true);
    }
    wp_delete_post($post_id, true);
    wp_set_current_user($previous_user);
}
