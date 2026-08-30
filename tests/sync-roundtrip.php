<?php

declare(strict_types=1);

$root = dirname(__DIR__);
define('ABSPATH', __DIR__ . '/');

$GLOBALS['ejb_meta'] = [];
$GLOBALS['ejb_post'] = (object) ['ID' => 123, 'post_type' => 'page'];
$GLOBALS['ejb_saved_payload'] = null;
$GLOBALS['ejb_readback_mismatches_left'] = 0;

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key, bool $single = false): mixed {
        unset($post_id, $single);
        return $GLOBALS['ejb_meta'][$key] ?? '';
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $key, mixed $value): bool {
        unset($post_id);
        $GLOBALS['ejb_meta'][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $key): bool {
        unset($post_id);
        unset($GLOBALS['ejb_meta'][$key]);
        return true;
    }
}
if (!function_exists('get_post')) {
    function get_post(int $post_id): object|false {
        return $post_id === 123 ? $GLOBALS['ejb_post'] : false;
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $value));
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string {
        return trim(strip_tags($value));
    }
}
if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed {
        if ($key === 'ejb_settings') {
            return [
                'repo_root' => 'site-data/elementor',
                'repo_owner' => 'owner',
                'repo_name' => 'repo',
                'repo_branch' => 'site-sync',
                'auto_export' => 1,
                'auto_apply' => 0,
            ];
        }
        return $default;
    }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args(mixed $args, array $defaults = []): array {
        return array_merge($defaults, is_array($args) ? $args : []);
    }
}

require_once $root . '/includes/Support/CanonicalJson.php';
require_once $root . '/includes/Elementor/PayloadValidator.php';
require_once $root . '/includes/Settings.php';
require_once $root . '/includes/Sync/State.php';

use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;
use Webactueel\ElementorJsonBridge\Sync\State;

final class EJB_Test_Documents {
    public array $payload;

    public function __construct(array $payload) {
        $this->payload = $payload;
    }

    public function payload(int $post_id): array {
        unset($post_id);
        $payload = $GLOBALS['ejb_saved_payload'] ?? $this->payload;
        if ($GLOBALS['ejb_readback_mismatches_left'] > 0 && $GLOBALS['ejb_saved_payload'] !== null) {
            --$GLOBALS['ejb_readback_mismatches_left'];
            $payload['title'] = 'Mutated by runtime';
        }
        return $payload;
    }

    public function document_type(int $post_id): string {
        unset($post_id);
        return 'wp-page';
    }

    public function save_payload(int $post_id, array $payload): void {
        unset($post_id);
        $GLOBALS['ejb_saved_payload'] = $payload;
    }
}

final class EJB_Test_GitHub_Client {
    public array $remote;

    public function __construct(array $remote) {
        $this->remote = $remote;
    }

    public function assert_private_repository(): void {}

    public function get_file(string $path): array {
        unset($path);
        return $this->remote;
    }
}

final class EJB_Test_Snapshots {
    public array $items = [];
    private int $next_id = 1;

    public function create(int $post_id, array $payload, string $reason, string $sha): int {
        unset($post_id, $reason, $sha);
        $id = $this->next_id++;
        $this->items[$id] = $payload;
        return $id;
    }

    public function payload(int $snapshot_id, int $post_id): array {
        unset($post_id);
        return $this->items[$snapshot_id];
    }
}

final class EJB_Test_Lock {
    public function acquire(int $post_id): string {
        unset($post_id);
        return 'lock';
    }

    public function release(int $post_id, string $token): void {
        unset($post_id, $token);
    }
}

class_alias(EJB_Test_Documents::class, 'Webactueel\\ElementorJsonBridge\\Elementor\\Documents');
class_alias(EJB_Test_GitHub_Client::class, 'Webactueel\\ElementorJsonBridge\\GitHub\\Client');
class_alias(EJB_Test_Snapshots::class, 'Webactueel\\ElementorJsonBridge\\Backup\\Snapshots');
class_alias(EJB_Test_Lock::class, 'Webactueel\\ElementorJsonBridge\\Sync\\Lock');

require_once $root . '/includes/Sync/Manager.php';

use Webactueel\ElementorJsonBridge\Sync\Manager;

$validator = new PayloadValidator();
$base = $validator->validate_array([
    'title' => 'Base',
    'type' => 'wp-page',
    'version' => '0.4',
    'page_settings' => [],
    'content' => [[
        'id' => 'abc123',
        'elType' => 'container',
        'settings' => [],
        'elements' => [],
    ]],
], 'wp-page');
$incoming = $base;
$incoming['title'] = 'Remote';
$incoming = $validator->validate_array($incoming, 'wp-page');

$base_hash = CanonicalJson::hash($base);
$incoming_hash = CanonicalJson::hash($incoming);
$remote_sha = 'remote-sha-2';

$GLOBALS['ejb_meta'] = [
    State::META_ENABLED => '1',
    State::META_STATUS => State::REMOTE_PENDING,
    State::META_BASE_HASH => $base_hash,
    State::META_REMOTE_SHA => 'remote-sha-1',
    State::META_REMOTE_PATH => 'site-data/elementor/pages/123.json',
    State::META_PENDING_SHA => $remote_sha,
    State::META_PENDING_HASH => $incoming_hash,
];
$GLOBALS['ejb_saved_payload'] = null;
$GLOBALS['ejb_readback_mismatches_left'] = 0;

$documents = new EJB_Test_Documents($base);
$github = new EJB_Test_GitHub_Client([
    'sha' => $remote_sha,
    'content' => CanonicalJson::encode($incoming, true),
]);
$snapshots = new EJB_Test_Snapshots();
$manager = new Manager($documents, $validator, $github, $snapshots, new EJB_Test_Lock());

$result = $manager->apply_remote(123);
if (($result['status'] ?? '') !== State::VERIFIED) {
    throw new RuntimeException('Successful remote apply was not verified.');
}
if (CanonicalJson::hash($documents->payload(123)) !== $incoming_hash) {
    throw new RuntimeException('Successful remote apply did not persist the incoming payload.');
}
if (($GLOBALS['ejb_meta'][State::META_REMOTE_SHA] ?? '') !== $remote_sha) {
    throw new RuntimeException('Successful remote apply did not update the remote SHA.');
}
if (($GLOBALS['ejb_meta'][State::META_BASE_HASH] ?? '') !== $incoming_hash) {
    throw new RuntimeException('Successful remote apply did not update the base hash.');
}
if (isset($GLOBALS['ejb_meta'][State::META_PENDING_SHA]) || isset($GLOBALS['ejb_meta'][State::META_PENDING_HASH])) {
    throw new RuntimeException('Successful remote apply left pending metadata behind.');
}

$GLOBALS['ejb_meta'] = [
    State::META_ENABLED => '1',
    State::META_STATUS => State::REMOTE_PENDING,
    State::META_BASE_HASH => $base_hash,
    State::META_REMOTE_SHA => 'remote-sha-1',
    State::META_REMOTE_PATH => 'site-data/elementor/pages/123.json',
    State::META_PENDING_SHA => $remote_sha,
    State::META_PENDING_HASH => $incoming_hash,
];
$GLOBALS['ejb_saved_payload'] = null;
$GLOBALS['ejb_readback_mismatches_left'] = 1;

$documents = new EJB_Test_Documents($base);
$github = new EJB_Test_GitHub_Client([
    'sha' => $remote_sha,
    'content' => CanonicalJson::encode($incoming, true),
]);
$snapshots = new EJB_Test_Snapshots();
$manager = new Manager($documents, $validator, $github, $snapshots, new EJB_Test_Lock());

$failed = false;
try {
    $manager->apply_remote(123);
} catch (RuntimeException $exception) {
    $failed = str_contains($exception->getMessage(), 'previous Elementor version was restored');
}
if (!$failed) {
    throw new RuntimeException('Readback mismatch did not fail closed with a verified rollback.');
}
if (CanonicalJson::hash($documents->payload(123)) !== $base_hash) {
    throw new RuntimeException('Rollback did not restore the previous Elementor payload.');
}
if (($GLOBALS['ejb_meta'][State::META_STATUS] ?? '') !== State::ERROR) {
    throw new RuntimeException('Rollback path did not leave an explicit error state.');
}

fwrite(STDOUT, "PASS sync-roundtrip\n");
