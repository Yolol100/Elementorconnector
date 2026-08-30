<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require_once dirname(__DIR__) . '/includes/Elementor/PayloadValidator.php';

use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;

$validator = new PayloadValidator();
$payload = [
    'title' => 'Canonicalization',
    'type' => 'wp-page',
    'version' => '0.4',
    'page_settings' => [],
    'content' => [
        [
            'id' => 'container1',
            'elType' => 'container',
            'settings' => [],
            'elements' => [
                [
                    'id' => 'widget1',
                    'elType' => 'widget',
                    'widgetType' => 'heading',
                    'settings' => ['title' => 'Hello'],
                    'elements' => [],
                    'isInner' => false,
                    'isLocked' => false,
                ],
            ],
        ],
    ],
];

$normalized = $validator->validate_array($payload, 'wp-page');
$container = $normalized['content'][0] ?? [];
$widget = $container['elements'][0] ?? [];

if (($container['isInner'] ?? null) !== false) {
    throw new RuntimeException('A non-widget element without isInner was not canonicalized to false.');
}
if (array_key_exists('isInner', $widget)) {
    throw new RuntimeException('Widget isInner was not removed to match Elementor raw data.');
}
if (array_key_exists('isLocked', $widget)) {
    throw new RuntimeException('False isLocked was not removed to match Elementor raw data.');
}

$expect_runtime_exception = static function (array $candidate, string $message) use ($validator): void {
    try {
        $validator->validate_array($candidate, 'wp-page');
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
};

$invalid_inner = $payload;
$invalid_inner['content'][0]['isInner'] = 'false';
$expect_runtime_exception($invalid_inner, 'Invalid non-boolean isInner data was accepted.');

$invalid_lock = $payload;
$invalid_lock['content'][0]['isLocked'] = 0;
$expect_runtime_exception($invalid_lock, 'Invalid non-boolean isLocked data was accepted.');

fwrite(STDOUT, "PASS elementor-canonicalization\n");
