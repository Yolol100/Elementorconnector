<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['ejb_snapshot_post'] = null;
$GLOBALS['ejb_snapshot_hash'] = '';
$GLOBALS['ejb_snapshot_extra_state'] = '';
$GLOBALS['ejb_snapshot_extra_hash'] = '';

if (!function_exists('get_post')) {
    function get_post(int $post_id): object|false {
        return $post_id === 77 ? $GLOBALS['ejb_snapshot_post'] : false;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key, bool $single = false): mixed {
        unset($single);
        if ($post_id !== 77) {
            return '';
        }
        return match ($key) {
            '_ejb_snapshot_hash' => $GLOBALS['ejb_snapshot_hash'],
            '_ejb_snapshot_extra_state' => $GLOBALS['ejb_snapshot_extra_state'],
            '_ejb_snapshot_extra_hash' => $GLOBALS['ejb_snapshot_extra_hash'],
            default => '',
        };
    }
}

require_once dirname(__DIR__) . '/includes/Support/CanonicalJson.php';
require_once dirname(__DIR__) . '/includes/Backup/Snapshots.php';

use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

$expectRuntimeException = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
};

$payload = [
    'title' => 'Original',
    'type' => 'wp-page',
    'version' => '0.4',
    'page_settings' => [],
    'content' => [],
];
$extra = [
    'author' => 4,
    'date' => '2026-09-03 09:30:00',
    'password' => 'secret-in-snapshot',
    'format' => '',
];

$GLOBALS['ejb_snapshot_post'] = (object) [
    'ID' => 77,
    'post_type' => Snapshots::POST_TYPE,
    'post_parent' => 123,
    'post_content' => CanonicalJson::encode($payload, true),
];
$GLOBALS['ejb_snapshot_hash'] = CanonicalJson::hash($payload);
$GLOBALS['ejb_snapshot_extra_state'] = CanonicalJson::encode($extra, true);
$GLOBALS['ejb_snapshot_extra_hash'] = CanonicalJson::hash($extra);

$snapshots = new Snapshots();
$readback = $snapshots->payload(77, 123);
if (!hash_equals(CanonicalJson::hash($payload), CanonicalJson::hash($readback))) {
    throw new RuntimeException('A valid rollback snapshot did not roundtrip.');
}
$extraReadback = $snapshots->extra_state(77, 123);
if (!hash_equals(CanonicalJson::hash($extra), CanonicalJson::hash($extraReadback))) {
    throw new RuntimeException('A valid extended rollback snapshot did not roundtrip.');
}

$tamperedPayload = $payload;
$tamperedPayload['title'] = 'Tampered but valid JSON';
$GLOBALS['ejb_snapshot_post']->post_content = CanonicalJson::encode($tamperedPayload, true);
$expectRuntimeException(
    static fn (): array => $snapshots->payload(77, 123),
    'A valid-JSON rollback snapshot with a mismatched stored hash was accepted.'
);
$GLOBALS['ejb_snapshot_post']->post_content = CanonicalJson::encode($payload, true);

$tamperedExtra = $extra;
$tamperedExtra['password'] = 'tampered';
$GLOBALS['ejb_snapshot_extra_state'] = CanonicalJson::encode($tamperedExtra, true);
$expectRuntimeException(
    static fn (): array => $snapshots->extra_state(77, 123),
    'A valid-JSON extended rollback snapshot with a mismatched stored hash was accepted.'
);

fwrite(STDOUT, "PASS snapshot-integrity\n");
