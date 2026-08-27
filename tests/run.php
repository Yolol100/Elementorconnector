<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false {
        return json_encode($value, $flags, $depth);
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string {
        return 'elementor-json-bridge-test-salt-' . $scheme;
    }
}
if (!function_exists('get_post')) {
    function get_post(int $post_id): ?object {
        return $GLOBALS['ejb_test_posts'][$post_id] ?? null;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed {
        unset($single);
        return $GLOBALS['ejb_test_meta'][$post_id][$key] ?? '';
    }
}

require_once dirname(__DIR__) . '/includes/Support/CanonicalJson.php';
require_once dirname(__DIR__) . '/includes/Elementor/PayloadValidator.php';
require_once dirname(__DIR__) . '/includes/Security/SecretBox.php';
require_once dirname(__DIR__) . '/includes/Settings.php';
require_once dirname(__DIR__) . '/includes/Backup/Snapshots.php';

use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Security\SecretBox;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

$tests = [];

$validPayload = static fn (): array => [
    'title' => 'Home',
    'type' => 'wp-page',
    'version' => '0.4',
    'page_settings' => [],
    'content' => [[
        'id' => 'abc123',
        'elType' => 'container',
        'settings' => [],
        'elements' => [],
    ]],
];

$expectRuntimeException = static function (callable $callback, string $failure): void {
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($failure);
};

$tests['canonical-json-is-deterministic'] = static function (): void {
    $a = ['z' => 1, 'a' => ['y' => 2, 'x' => 3]];
    $b = ['a' => ['x' => 3, 'y' => 2], 'z' => 1];
    if (CanonicalJson::hash($a) !== CanonicalJson::hash($b)) {
        throw new RuntimeException('Canonical hashes differ for equivalent data.');
    }
};

$tests['canonical-json-preserves-list-order'] = static function (): void {
    if (CanonicalJson::hash(['items' => [1, 2]]) === CanonicalJson::hash(['items' => [2, 1]])) {
        throw new RuntimeException('Canonical hashing ignored list order.');
    }
};

$tests['payload-validates'] = static function () use ($validPayload): void {
    $decoded = (new PayloadValidator())->validate_array($validPayload(), 'wp-page');
    if ($decoded['type'] !== 'wp-page') {
        throw new RuntimeException('Validated payload changed type.');
    }
};

$tests['payload-accepts-atomic-element-extensions'] = static function () use ($validPayload): void {
    $payload = $validPayload();
    $payload['content'][0] = [
        'id' => '6edaa5b1',
        'version' => '0.0',
        'elType' => 'e-div-block',
        'isInner' => false,
        'settings' => [],
        'editor_settings' => [],
        'interactions' => [],
        'styles' => [],
        'elements' => [],
    ];
    (new PayloadValidator())->validate_array($payload, 'wp-page');
};

$tests['payload-rejects-type-mismatch'] = static function () use ($validPayload, $expectRuntimeException): void {
    $expectRuntimeException(
        static fn () => (new PayloadValidator())->validate_array($validPayload(), 'wp-post'),
        'Type mismatch was accepted.'
    );
};

$tests['payload-rejects-duplicate-element-ids'] = static function () use ($validPayload, $expectRuntimeException): void {
    $payload = $validPayload();
    $payload['content'][] = $payload['content'][0];
    $expectRuntimeException(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Duplicate element IDs were accepted.'
    );
};

$tests['payload-rejects-unknown-envelope-fields'] = static function () use ($validPayload, $expectRuntimeException): void {
    $payload = $validPayload();
    $payload['unexpected'] = 'value';
    $expectRuntimeException(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Unknown top-level fields were accepted.'
    );
};

$tests['payload-rejects-missing-envelope-fields'] = static function () use ($validPayload, $expectRuntimeException): void {
    $payload = $validPayload();
    unset($payload['content']);
    $expectRuntimeException(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Missing top-level fields were accepted.'
    );
};

$tests['payload-rejects-malformed-json-without-wordpress-output-helpers'] = static function () use ($expectRuntimeException): void {
    $expectRuntimeException(
        static fn () => (new PayloadValidator())->decode('{not-json', 'wp-page'),
        'Malformed JSON was accepted.'
    );
};

$tests['repo-path-sanitization-removes-traversal'] = static function (): void {
    $actual = Settings::sanitize_repo_path('../elementor/../../pages/../safe');
    if ($actual !== 'elementor/pages/safe') {
        throw new RuntimeException('Unexpected sanitized path: ' . $actual);
    }
};

$tests['secretbox-roundtrip-and-tamper-detection'] = static function (): void {
    $box = new SecretBox();
    $encrypted = $box->encrypt(['access_token' => 'test-token', 'refresh_token' => 'refresh']);
    $decrypted = $box->decrypt($encrypted);
    if (($decrypted['access_token'] ?? '') !== 'test-token') {
        throw new RuntimeException('Encrypted token did not roundtrip.');
    }

    $raw = base64_decode($encrypted, true);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Encrypted package was not base64.');
    }
    $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($package) || !isset($package['cipher'])) {
        throw new RuntimeException('Encrypted package is malformed.');
    }
    $cipher = base64_decode((string) $package['cipher'], true);
    if (!is_string($cipher) || $cipher === '') {
        throw new RuntimeException('Cipher payload is malformed.');
    }
    $cipher[0] = chr(ord($cipher[0]) ^ 1);
    $package['cipher'] = base64_encode($cipher);
    $tampered = base64_encode(json_encode($package, JSON_THROW_ON_ERROR));

    try {
        $box->decrypt($tampered);
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Tampered credential package was accepted.');
};

$tests['snapshot-integrity-fingerprint-is-enforced'] = static function () use ($validPayload, $expectRuntimeException): void {
    $snapshotId = 7001;
    $sourceId = 42;
    $payload = $validPayload();
    $json = CanonicalJson::encode($payload, true);

    $GLOBALS['ejb_test_posts'][$snapshotId] = (object) [
        'post_type' => Snapshots::POST_TYPE,
        'post_parent' => $sourceId,
        'post_content' => $json,
    ];
    $GLOBALS['ejb_test_meta'][$snapshotId]['_ejb_snapshot_hash'] = CanonicalJson::hash($payload);

    $loaded = (new Snapshots())->payload($snapshotId, $sourceId);
    if (CanonicalJson::hash($loaded) !== CanonicalJson::hash($payload)) {
        throw new RuntimeException('Valid snapshot did not roundtrip.');
    }

    $tampered = $payload;
    $tampered['title'] = 'Tampered';
    $GLOBALS['ejb_test_posts'][$snapshotId]->post_content = CanonicalJson::encode($tampered, true);

    $expectRuntimeException(
        static fn () => (new Snapshots())->payload($snapshotId, $sourceId),
        'Tampered snapshot passed its integrity fingerprint.'
    );
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $throwable) {
        ++$failures;
        fwrite(STDERR, "FAIL {$name}: {$throwable->getMessage()}\n");
    }
}

if ($failures > 0) {
    exit(1);
}

echo 'PASS total=' . count($tests) . "\n";
