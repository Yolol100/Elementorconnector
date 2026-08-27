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

require_once dirname(__DIR__) . '/includes/Support/CanonicalJson.php';
require_once dirname(__DIR__) . '/includes/Elementor/PayloadValidator.php';
require_once dirname(__DIR__) . '/includes/Security/SecretBox.php';
require_once dirname(__DIR__) . '/includes/Settings.php';

use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Security\SecretBox;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

$tests = [];

$tests['canonical-json-is-deterministic'] = static function (): void {
    $a = ['z' => 1, 'a' => ['y' => 2, 'x' => 3]];
    $b = ['a' => ['x' => 3, 'y' => 2], 'z' => 1];
    if (CanonicalJson::hash($a) !== CanonicalJson::hash($b)) {
        throw new RuntimeException('Canonical hashes differ for equivalent data.');
    }
};

$tests['payload-validates'] = static function (): void {
    $payload = [
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
    $decoded = (new PayloadValidator())->validate_array($payload, 'wp-page');
    if ($decoded['type'] !== 'wp-page') {
        throw new RuntimeException('Validated payload changed type.');
    }
};

$tests['payload-rejects-type-mismatch'] = static function (): void {
    $payload = [
        'title' => 'Home',
        'type' => 'wp-page',
        'version' => '0.4',
        'page_settings' => [],
        'content' => [],
    ];
    try {
        (new PayloadValidator())->validate_array($payload, 'wp-post');
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Type mismatch was accepted.');
};

$tests['payload-rejects-duplicate-element-ids'] = static function (): void {
    $element = [
        'id' => 'same',
        'elType' => 'container',
        'settings' => [],
        'elements' => [],
    ];
    $payload = [
        'title' => 'Home',
        'type' => 'wp-page',
        'version' => '0.4',
        'page_settings' => [],
        'content' => [$element, $element],
    ];
    try {
        (new PayloadValidator())->validate_array($payload, 'wp-page');
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Duplicate element IDs were accepted.');
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
