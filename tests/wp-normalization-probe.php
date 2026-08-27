<?php

declare(strict_types=1);

use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

if (!defined('ABSPATH')) {
    exit(1);
}

$adminIds = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
if (!$adminIds) {
    throw new RuntimeException('No administrator user is available.');
}
wp_set_current_user((int) $adminIds[0]);

$postId = wp_insert_post(
    [
        'post_type' => 'page',
        'post_status' => 'draft',
        'post_title' => 'Normalization probe',
    ],
    true
);
if (is_wp_error($postId)) {
    throw new RuntimeException('Unable to create normalization probe page.');
}
$postId = (int) $postId;
update_post_meta($postId, '_elementor_edit_mode', 'builder');

$documents = new Documents();
$validator = new PayloadValidator();
$type = $documents->document_type($postId);

$diffValues = static function (mixed $expected, mixed $actual, string $path = '') use (&$diffValues): array {
    if (is_array($expected) && is_array($actual)) {
        $diffs = [];
        $keys = array_values(array_unique(array_merge(array_keys($expected), array_keys($actual))));
        foreach ($keys as $key) {
            $child = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            if (!array_key_exists($key, $expected)) {
                $diffs[] = ['path' => $child, 'expected' => '<missing>', 'actual' => $actual[$key]];
                continue;
            }
            if (!array_key_exists($key, $actual)) {
                $diffs[] = ['path' => $child, 'expected' => $expected[$key], 'actual' => '<missing>'];
                continue;
            }
            $diffs = array_merge($diffs, $diffValues($expected[$key], $actual[$key], $child));
        }
        return $diffs;
    }
    if ($expected !== $actual) {
        return [['path' => '' === $path ? '/' : $path, 'expected' => $expected, 'actual' => $actual]];
    }
    return [];
};

try {
    $seed = [
        'title' => 'Normalization probe',
        'type' => $type,
        'version' => '0.4',
        'page_settings' => [],
        'content' => [
            [
                'id' => 'ejbprobe01',
                'elType' => 'container',
                'isInner' => false,
                'settings' => [],
                'elements' => [],
            ],
        ],
    ];
    $documents->save_payload($postId, $validator->validate_array($seed, $type));
    $base = $documents->payload($postId);

    $candidate = $base;
    $candidate['content'][] = [
        'id' => 'ejbprobe02',
        'elType' => 'container',
        'isInner' => false,
        'settings' => [],
        'elements' => [],
    ];
    $candidate = $validator->validate_array($candidate, $type);

    $documents->save_payload($postId, $candidate);
    $readback = $documents->payload($postId);
    $diffs = $diffValues($candidate, $readback);

    $documents->save_payload($postId, $base);
    $restored = $documents->payload($postId);
    if (!hash_equals(CanonicalJson::hash($base), CanonicalJson::hash($restored))) {
        throw new RuntimeException('Normalization probe could not restore its baseline exactly.');
    }

    if ($diffs) {
        $limited = array_slice($diffs, 0, 40);
        throw new RuntimeException('ELEMENTOR_NORMALIZATION_DIFF ' . wp_json_encode($limited, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
} finally {
    wp_delete_post($postId, true);
}

echo 'PASS elementor-normalization-probe exact-roundtrip Elementor=' . (string) ELEMENTOR_VERSION . ' WP=' . get_bloginfo('version') . ' PHP=' . PHP_VERSION . "\n";
