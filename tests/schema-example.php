<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($value, $flags, $depth);
}

require dirname(__DIR__) . '/includes/Elementor/PayloadValidator.php';

use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;

$schemaPath = dirname(__DIR__) . '/docs/elementor-document.schema.json';
$examplePath = dirname(__DIR__) . '/docs/examples/wp-page.json';
$readmePath = dirname(__DIR__) . '/README.md';

$schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
$example = json_decode((string) file_get_contents($examplePath), true, 512, JSON_THROW_ON_ERROR);

if (($schema['$schema'] ?? '') !== 'https://json-schema.org/draft/2020-12/schema') {
    throw new RuntimeException('Unexpected JSON Schema dialect.');
}
if (($schema['additionalProperties'] ?? null) !== false) {
    throw new RuntimeException('Bridge wrapper schema must reject unknown top-level fields.');
}
$required = $schema['required'] ?? [];
sort($required);
$expected = ['content', 'page_settings', 'title', 'type', 'version'];
sort($expected);
if ($required !== $expected || (($schema['properties']['version']['const'] ?? '') !== '0.4')) {
    throw new RuntimeException('Bridge wrapper schema contract drifted.');
}
$element = $schema['$defs']['element'] ?? [];
$elementRequired = $element['required'] ?? [];
sort($elementRequired);
$expectedElement = ['elType', 'elements', 'id', 'settings'];
sort($expectedElement);
if ($elementRequired !== $expectedElement || (($element['additionalProperties'] ?? null) !== true)) {
    throw new RuntimeException('Element schema must preserve extension fields while enforcing core fields.');
}

(new PayloadValidator())->validate_array($example, 'wp-page');

$readme = (string) file_get_contents($readmePath);
foreach ([
    'docs/architecture.md',
    'docs/repository-settings.md',
    'SECURITY.md',
    'docs/elementor-document.schema.json',
    'docs/examples/wp-page.json',
] as $reference) {
    if (!str_contains($readme, $reference)) {
        throw new RuntimeException('README does not expose ' . $reference);
    }
}

echo "PASS schema-example-and-reference-discoverability\n";
