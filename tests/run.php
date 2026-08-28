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

$valid_payload = static fn (): array => [
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

$expect_runtime_exception = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
};

$tests['canonical-json-is-deterministic'] = static function (): void {
    $a = ['z' => 1, 'a' => ['y' => 2, 'x' => 3]];
    $b = ['a' => ['x' => 3, 'y' => 2], 'z' => 1];
    if (CanonicalJson::hash($a) !== CanonicalJson::hash($b)) {
        throw new RuntimeException('Canonical hashes differ for equivalent data.');
    }
};

$tests['canonical-json-preserves-list-order'] = static function (): void {
    $a = ['items' => [['id' => 'one'], ['id' => 'two']]];
    $b = ['items' => [['id' => 'two'], ['id' => 'one']]];
    if (CanonicalJson::hash($a) === CanonicalJson::hash($b)) {
        throw new RuntimeException('Canonical hashing ignored list order.');
    }
};

$tests['payload-validates'] = static function () use ($valid_payload): void {
    $decoded = (new PayloadValidator())->validate_array($valid_payload(), 'wp-page');
    if ($decoded['type'] !== 'wp-page') {
        throw new RuntimeException('Validated payload changed type.');
    }
};

$tests['payload-accepts-atomic-extra-fields'] = static function () use ($valid_payload): void {
    $payload = $valid_payload();
    $payload['content'][0] = [
        'id' => 'atomic_1',
        'version' => '1.0',
        'elType' => 'container',
        'isInner' => false,
        'interactions' => [],
        'settings' => [],
        'editor_settings' => [],
        'styles' => [],
        'elements' => [],
    ];
    (new PayloadValidator())->validate_array($payload, 'wp-page');
};

$tests['payload-rejects-type-mismatch'] = static function () use ($valid_payload, $expect_runtime_exception): void {
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->validate_array($valid_payload(), 'wp-post'),
        'Type mismatch was accepted.'
    );
};

$tests['payload-rejects-unsupported-format-version'] = static function () use ($valid_payload, $expect_runtime_exception): void {
    $payload = $valid_payload();
    $payload['version'] = '0.5';
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Unsupported format version was accepted.'
    );
};

$tests['payload-rejects-duplicate-element-ids'] = static function () use ($valid_payload, $expect_runtime_exception): void {
    $payload = $valid_payload();
    $payload['content'][] = $payload['content'][0];
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Duplicate element IDs were accepted.'
    );
};

$tests['payload-rejects-invalid-element-id'] = static function () use ($valid_payload, $expect_runtime_exception): void {
    $payload = $valid_payload();
    $payload['content'][0]['id'] = '../bad id';
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Invalid element ID was accepted.'
    );
};

$tests['payload-rejects-unknown-root-field'] = static function () use ($valid_payload, $expect_runtime_exception): void {
    $payload = $valid_payload();
    $payload['unexpected'] = true;
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Unknown root field was accepted.'
    );
};

$tests['payload-rejects-widget-without-widget-type'] = static function () use ($valid_payload, $expect_runtime_exception): void {
    $payload = $valid_payload();
    $payload['content'][0]['elType'] = 'widget';
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Widget without widgetType was accepted.'
    );
};

$tests['payload-rejects-missing-settings'] = static function () use ($valid_payload, $expect_runtime_exception): void {
    $payload = $valid_payload();
    unset($payload['content'][0]['settings']);
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Element without settings was accepted.'
    );
};

$tests['payload-rejects-missing-elements'] = static function () use ($valid_payload, $expect_runtime_exception): void {
    $payload = $valid_payload();
    unset($payload['content'][0]['elements']);
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Element without elements list was accepted.'
    );
};

$tests['payload-rejects-too-deep-nesting'] = static function () use ($valid_payload, $expect_runtime_exception): void {
    $payload = $valid_payload();
    $node = ['id' => 'node_0', 'elType' => 'container', 'settings' => [], 'elements' => []];
    for ($depth = 1; $depth <= PayloadValidator::MAX_DEPTH + 1; ++$depth) {
        $node = [
            'id' => 'node_' . $depth,
            'elType' => 'container',
            'settings' => [],
            'elements' => [$node],
        ];
    }
    $payload['content'] = [$node];
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->validate_array($payload, 'wp-page'),
        'Over-deep element nesting was accepted.'
    );
};

$tests['payload-rejects-malformed-json-without-wordpress-runtime'] = static function () use ($expect_runtime_exception): void {
    $expect_runtime_exception(
        static fn () => (new PayloadValidator())->decode('{broken'),
        'Malformed JSON was accepted.'
    );
};

$tests['repo-path-sanitization-removes-traversal'] = static function (): void {
    $actual = Settings::sanitize_repo_path('../elementor/../../pages/../safe');
    if ($actual !== 'elementor/pages/safe') {
        throw new RuntimeException('Unexpected sanitized path: ' . $actual);
    }
};

$tests['repo-path-sanitization-normalizes-backslashes'] = static function (): void {
    $actual = Settings::sanitize_repo_path('..\\elementor\\pages\\..\\safe folder');
    if ($actual !== 'elementor/pages/safe-folder') {
        throw new RuntimeException('Unexpected normalized repo path: ' . $actual);
    }
};

$tests['release-metadata-is-aligned'] = static function (): void {
    $plugin = file_get_contents(dirname(__DIR__) . '/elementor-json-bridge.php');
    $readme = file_get_contents(dirname(__DIR__) . '/readme.txt');
    if (!is_string($plugin) || !is_string($readme)) {
        throw new RuntimeException('Unable to read release metadata files.');
    }
    if (!preg_match('/\\* Version: ([0-9]+\\.[0-9]+\\.[0-9]+)/', $plugin, $header_match)) {
        throw new RuntimeException('Plugin header version was not found.');
    }
    if (!preg_match("/define\\([[:space:]]*'EJB_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'/", $plugin, $constant_match)) {
        throw new RuntimeException('EJB_VERSION was not found.');
    }
    if (!preg_match("/^Stable tag: ([0-9]+\\.[0-9]+\\.[0-9]+)$/m", $readme, $stable_match)) {
        throw new RuntimeException('Stable tag was not found.');
    }
    if ($header_match[1] !== $constant_match[1] || $header_match[1] !== $stable_match[1]) {
        throw new RuntimeException('Release versions are not aligned.');
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
