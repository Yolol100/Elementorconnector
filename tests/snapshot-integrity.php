<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['ejb_snapshot_post'] = null;
$GLOBALS['ejb_snapshot_hash'] = '';

if (!function_exists('get_post')) {
    function get_post(int $post_id): object|false {
        return $post_id === 77 ? $GLOBALS['ejb_snapshot_post'] : false;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key, bool $single = false): mixed {
        unset($single);
        if ($post_id === 77 && $key === '_ejb_snapshot_hash') {
            return $GLOBALS['ejb_snapshot_hash'];
        }
        return '';
    }
}

require_once dirname(__DIR__) . '/includes/Support/CanonicalJson.php';
require_once dirname(__DIR__) . '/includes/Backup/Snapshots.php';

use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

$payload = [
    'title' => 'Original',
    'type' => 'wp-page',
    'version' => '0.4',
    'page_settings' => [],
    'content' => [],
];

$GLOBALS['ejb_snapshot_post'] = (object) [
    'ID' => 77,
    'post_type' => Snapshots::POST_TYPE,
    'post_parent' => 123,
    'post_content' => CanonicalJson::encode($payload, true),
];
$GLOBALS['ejb_snapshot_hash'] = CanonicalJson::hash($payload);

$snapshots = new Snapshots();
$readback = $snapshots->payload(77, 123);
if (!hash_equals(CanonicalJson::hash($payload), CanonicalJson::hash($readback))) {
    throw new RuntimeException('A valid rollback snapshot did not roundtrip.');
}

$tampered = $payload;
$tampered['title'] = 'Tampered but valid JSON';
$GLOBALS['ejb_snapshot_post']->post_content = CanonicalJson::encode($tampered, true);

try {
    $snapshots->payload(77, 123);
} catch (RuntimeException) {
    fwrite(STDOUT, "PASS snapshot-integrity\n");
    exit(0);
}

throw new RuntimeException('A valid-JSON rollback snapshot with a mismatched stored hash was accepted.');
