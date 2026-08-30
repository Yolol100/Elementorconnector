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

require_once dirname(__DIR__) . '/includes/Security/SecretBox.php';

use Webactueel\ElementorJsonBridge\Security\SecretBox;

$expect_runtime_exception = static function (callable $callback, string $label): void {
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    } catch (Throwable $throwable) {
        throw new RuntimeException($label . ' leaked ' . get_class($throwable) . ' instead of failing closed.');
    }

    throw new RuntimeException($label . ' was accepted.');
};

$box = new SecretBox();
$encrypted = $box->encrypt(['access_token' => 'test-token']);
$raw = base64_decode($encrypted, true);
if (!is_string($raw)) {
    throw new RuntimeException('Encrypted SecretBox package was not base64.');
}
$package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($package)) {
    throw new RuntimeException('Encrypted SecretBox package was malformed.');
}

if (($package['alg'] ?? '') === 'sodium-secretbox') {
    $bad_nonce = $package;
    $bad_nonce['nonce'] = base64_encode('short');
    $encoded = base64_encode(json_encode($bad_nonce, JSON_THROW_ON_ERROR));
    $expect_runtime_exception(
        static fn () => $box->decrypt($encoded),
        'A sodium package with an invalid nonce length'
    );
}

if (function_exists('openssl_encrypt') && function_exists('openssl_decrypt')) {
    $context = 'elementor-json-bridge/github-auth/v1';
    $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth') . $context, true);
    $plaintext = json_encode(['access_token' => 'openssl-token'], JSON_THROW_ON_ERROR);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        $context,
        16
    );
    if (!is_string($cipher) || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to construct the AES-GCM regression fixture.');
    }

    $valid = base64_encode(json_encode([
        'v' => 1,
        'alg' => 'aes-256-gcm',
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'cipher' => base64_encode($cipher),
    ], JSON_THROW_ON_ERROR));
    $roundtrip = $box->decrypt($valid);
    if (($roundtrip['access_token'] ?? '') !== 'openssl-token') {
        throw new RuntimeException('Valid AES-GCM credentials did not roundtrip.');
    }

    $short_tag = base64_encode(json_encode([
        'v' => 1,
        'alg' => 'aes-256-gcm',
        'iv' => base64_encode($iv),
        'tag' => base64_encode(substr($tag, 0, 8)),
        'cipher' => base64_encode($cipher),
    ], JSON_THROW_ON_ERROR));
    $expect_runtime_exception(
        static fn () => $box->decrypt($short_tag),
        'An AES-GCM package with a truncated authentication tag'
    );
}

fwrite(STDOUT, "PASS secretbox-invalid-packages\n");
