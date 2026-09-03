<?php

declare(strict_types=1);

$root = dirname(__DIR__);
define('ABSPATH', __DIR__ . '/');

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID;
        public string $post_type;
        public string $post_status = 'publish';

        public function __construct(int $id, string $postType) {
            $this->ID = $id;
            $this->post_type = $postType;
        }
    }
}

$GLOBALS['ejb_meta'] = [];
$GLOBALS['ejb_post'] = new WP_Post(123, 'page');
$GLOBALS['ejb_saved_payload'] = null;
$GLOBALS['ejb_readback_mismatches_left'] = 0;
$GLOBALS['ejb_extended_state'] = [
    'author' => 7,
    'date' => '2026-09-03 08:00:00',
    'password' => 'base-password',
    'format' => '',
];

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
    function get_post(int $post_id): WP_Post|false {
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
                'repo_root' => 'site-data',
                'repo_owner' => 'owner',
                'repo_name' => 'repo',
                'repo_branch' => 'site-sync',
                'auto_export' => 1,
                'auto_apply' => 1,
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
require_once $root . '/includes/Settings.php';
require_once $root . '/includes/Sync/State.php';

use Webactueel\ElementorJsonBridge\Support\CanonicalJson;
use Webactueel\ElementorJsonBridge\Sync\State;

final class EJB_Test_Content {
    public array $payload;

    public function __construct(array $payload) {
        $this->payload = $payload;
    }

    public function supports(int $post_id): bool {
        return $post_id === 123;
    }

    public function post_types(): array {
        return ['page'];
    }

    public function payload(int $post_id): array {
        unset($post_id);
        $payload = $GLOBALS['ejb_saved_payload'] ?? $this->payload;
        if ($GLOBALS['ejb_readback_mismatches_left'] > 0 && $GLOBALS['ejb_saved_payload'] !== null) {
            --$GLOBALS['ejb_readback_mismatches_left'];
            $payload['post']['title'] = 'Mutated by runtime';
        }
        return $payload;
    }

    public function decode(string $json, int $post_id): array {
        unset($post_id);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid test payload.');
        }
        return $decoded;
    }

    public function validate_array(array $payload, int $post_id): array {
        unset($post_id);
        return $payload;
    }

    public function apply(int $post_id, array $payload): void {
        unset($post_id);
        $GLOBALS['ejb_saved_payload'] = $payload;
    }

    public function index_descriptor(int $post_id, string $path): array {
        return ['id' => $post_id, 'post_type' => 'page', 'path' => $path];
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
    public array $extra = [];
    private int $next_id = 1;

    public function create(int $post_id, array $payload, string $reason, string $sha = '', array $extra_state = []): int {
        unset($post_id, $reason, $sha);
        $id = $this->next_id++;
        $this->items[$id] = $payload;
        $this->extra[$id] = $extra_state;
        return $id;
    }

    public function payload(int $snapshot_id, int $post_id): array {
        unset($post_id);
        return $this->items[$snapshot_id];
    }

    public function extra_state(int $snapshot_id, int $post_id): array {
        unset($post_id);
        return $this->extra[$snapshot_id] ?? [];
    }
}

final class EJB_Test_Post_State {
    public static function read(int $post_id): array {
        if ($post_id !== 123) {
            throw new RuntimeException('Unexpected post state target.');
        }
        return $GLOBALS['ejb_extended_state'];
    }

    public static function validate(int $post_id, array $state): void {
        if ($post_id !== 123 || array_is_list($state)) {
            throw new RuntimeException('Invalid extended state.');
        }
    }

    public static function apply(int $post_id, array $state): void {
        self::validate($post_id, $state);
        $GLOBALS['ejb_extended_state'] = $state;
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

class_alias(EJB_Test_Content::class, 'Webactueel\\ElementorJsonBridge\\Content\\WordPressDocument');
class_alias(EJB_Test_GitHub_Client::class, 'Webactueel\\ElementorJsonBridge\\GitHub\\Client');
class_alias(EJB_Test_Snapshots::class, 'Webactueel\\ElementorJsonBridge\\Backup\\Snapshots');
class_alias(EJB_Test_Post_State::class, 'Webactueel\\ElementorJsonBridge\\Content\\PostState');
class_alias(EJB_Test_Lock::class, 'Webactueel\\ElementorJsonBridge\\Sync\\Lock');

require_once $root . '/includes/Sync/Manager.php';

use Webactueel\ElementorJsonBridge\Sync\Manager;

$base = [
    'format' => 'elementor-json-bridge/wordpress-content',
    'version' => 1,
    'source' => ['id' => 123, 'post_type' => 'page'],
    'post' => ['title' => 'Base', 'content' => '<p>Base</p>'],
    'acf' => [],
    'yoast' => [],
    'registered_meta' => [],
    'taxonomies' => [],
    'elementor' => null,
];
$incoming = $base;
$incoming['post']['title'] = 'Remote';
$incoming['post']['content'] = '<p>Remote</p>';

$base_hash = CanonicalJson::hash($base);
$incoming_hash = CanonicalJson::hash($incoming);
$remote_sha = 'remote-sha-2';
$base_extended = $GLOBALS['ejb_extended_state'];

$GLOBALS['ejb_meta'] = [
    State::META_STATUS => State::REMOTE_PENDING,
    State::META_BASE_HASH => $base_hash,
    State::META_REMOTE_SHA => 'remote-sha-1',
    State::META_REMOTE_PATH => 'site-data/content/pages/123.json',
    State::META_PENDING_SHA => $remote_sha,
    State::META_PENDING_HASH => $incoming_hash,
];
$GLOBALS['ejb_saved_payload'] = null;
$GLOBALS['ejb_readback_mismatches_left'] = 0;
$GLOBALS['ejb_extended_state'] = $base_extended;

$content = new EJB_Test_Content($base);
$github = new EJB_Test_GitHub_Client([
    'sha' => $remote_sha,
    'content' => CanonicalJson::encode($incoming, true),
]);
$snapshots = new EJB_Test_Snapshots();
$manager = new Manager($content, $github, $snapshots, new EJB_Test_Lock());

$result = $manager->apply_remote(123);
if (($result['status'] ?? '') !== State::VERIFIED) {
    throw new RuntimeException('Successful remote apply was not verified.');
}
if (CanonicalJson::hash($content->payload(123)) !== $incoming_hash) {
    throw new RuntimeException('Successful remote apply did not persist the incoming WordPress payload.');
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
if (($snapshots->extra[(int) ($result['snapshot_id'] ?? 0)] ?? null) !== $base_extended) {
    throw new RuntimeException('Remote apply did not snapshot the extended WordPress state.');
}

$GLOBALS['ejb_meta'] = [
    State::META_STATUS => State::REMOTE_PENDING,
    State::META_BASE_HASH => $base_hash,
    State::META_REMOTE_SHA => 'remote-sha-1',
    State::META_REMOTE_PATH => 'site-data/content/pages/123.json',
    State::META_PENDING_SHA => $remote_sha,
    State::META_PENDING_HASH => $incoming_hash,
];
$GLOBALS['ejb_saved_payload'] = null;
$GLOBALS['ejb_readback_mismatches_left'] = 1;
$GLOBALS['ejb_extended_state'] = $base_extended;

$content = new EJB_Test_Content($base);
$github = new EJB_Test_GitHub_Client([
    'sha' => $remote_sha,
    'content' => CanonicalJson::encode($incoming, true),
]);
$snapshots = new EJB_Test_Snapshots();
$manager = new Manager($content, $github, $snapshots, new EJB_Test_Lock());

$failed = false;
try {
    $manager->apply_remote(123);
} catch (RuntimeException $exception) {
    $failed = str_contains($exception->getMessage(), 'previous WordPress content was restored');
}
if (!$failed) {
    throw new RuntimeException('Readback mismatch did not fail closed with a verified rollback.');
}
if (CanonicalJson::hash($content->payload(123)) !== $base_hash) {
    throw new RuntimeException('Rollback did not restore the previous WordPress payload.');
}
if ($GLOBALS['ejb_extended_state'] !== $base_extended) {
    throw new RuntimeException('Rollback did not restore the extended WordPress state.');
}
if (($GLOBALS['ejb_meta'][State::META_STATUS] ?? '') !== State::ERROR) {
    throw new RuntimeException('Rollback path did not leave an explicit error state.');
}

fwrite(STDOUT, "PASS sync-roundtrip\n");
