<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

final class WP_Post {
    public function __construct(
        public int $ID,
        public string $post_type,
        public string $post_title,
        public string $post_name
    ) {}
}

$GLOBALS['ejb_export_posts'] = [];
$GLOBALS['ejb_export_meta'] = [];

if (!function_exists('get_post')) {
    function get_post(int $post_id): WP_Post|false {
        return $GLOBALS['ejb_export_posts'][$post_id] ?? false;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key, bool $single = false): mixed {
        unset($single);
        return $GLOBALS['ejb_export_meta'][$post_id][$key] ?? '';
    }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $filename): string {
        return strtolower((string) preg_replace('/[^a-z0-9._-]+/i', '-', $filename));
    }
}

final class EJB_Test_Export_Documents {
    public function is_elementor_document(int $post_id): bool {
        return isset($GLOBALS['ejb_export_posts'][$post_id]);
    }

    public function payload(int $post_id): array {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            throw new RuntimeException('Missing test post.');
        }
        return [
            'title' => $post->post_title,
            'type' => 'page' === $post->post_type ? 'wp-page' : 'wp-post',
            'version' => '0.4',
            'page_settings' => [],
            'content' => [],
        ];
    }
}

final class EJB_Test_Export_SiteParts {
    public function for_post(int $post_id): array {
        unset($post_id);
        return [
            'supported' => true,
            'header' => [
                'id' => 91,
                'title' => 'Header',
                'payload' => [
                    'title' => 'Header',
                    'type' => 'header',
                    'version' => '0.4',
                    'page_settings' => [],
                    'content' => [],
                ],
            ],
            'footer' => [
                'id' => 92,
                'title' => 'Footer',
                'payload' => [
                    'title' => 'Footer',
                    'type' => 'footer',
                    'version' => '0.4',
                    'page_settings' => [],
                    'content' => [],
                ],
            ],
            'warnings' => [],
        ];
    }
}

class_alias(EJB_Test_Export_Documents::class, 'Webactueel\\ElementorJsonBridge\\Elementor\\Documents');
class_alias(EJB_Test_Export_SiteParts::class, 'Webactueel\\ElementorJsonBridge\\Elementor\\SiteParts');

require_once dirname(__DIR__) . '/includes/Elementor/LocalExport.php';

use Webactueel\ElementorJsonBridge\Elementor\LocalExport;

$GLOBALS['ejb_export_posts'][10] = new WP_Post(10, 'page', 'Landing Page', 'landing-page');
$GLOBALS['ejb_export_posts'][11] = new WP_Post(11, 'post', 'News Post', 'news-post');
$GLOBALS['ejb_export_posts'][12] = new WP_Post(12, 'product', 'Product', 'product');
$GLOBALS['ejb_export_posts'][13] = new WP_Post(13, 'page', 'Classic Page', 'classic-page');
$GLOBALS['ejb_export_meta'][10]['_elementor_edit_mode'] = 'builder';
$GLOBALS['ejb_export_meta'][11]['_elementor_edit_mode'] = 'builder';
$GLOBALS['ejb_export_meta'][12]['_elementor_edit_mode'] = 'builder';
$GLOBALS['ejb_export_meta'][13]['_elementor_edit_mode'] = '';

$exporter = new LocalExport(new EJB_Test_Export_Documents(), new EJB_Test_Export_SiteParts());

$plain = $exporter->export(10, false);
if (($plain['format'] ?? '') !== 'elementor-document') {
    throw new RuntimeException('Plain export did not use the document format.');
}
if (($plain['export']['type'] ?? '') !== 'wp-page' || isset($plain['export']['document'])) {
    throw new RuntimeException('Plain export was wrapped instead of returning the Elementor document JSON.');
}
if (($plain['filename'] ?? '') !== 'landing-page-elementor.json') {
    throw new RuntimeException('Plain export filename is not stable.');
}

$bundle = $exporter->export(11, true);
if (($bundle['format'] ?? '') !== 'elementor-json-bridge/site-parts-bundle') {
    throw new RuntimeException('Site-part export did not use the bundle format.');
}
if (($bundle['export']['document']['type'] ?? '') !== 'wp-post') {
    throw new RuntimeException('Bundle did not include the source post document.');
}
if (($bundle['export']['header']['type'] ?? '') !== 'header' || ($bundle['export']['footer']['type'] ?? '') !== 'footer') {
    throw new RuntimeException('Bundle did not include both Elementor site parts.');
}
if (($bundle['included_site_parts']['header'] ?? false) !== true || ($bundle['included_site_parts']['footer'] ?? false) !== true) {
    throw new RuntimeException('Bundle site-part status is incorrect.');
}

try {
    $exporter->export(12, false);
    throw new RuntimeException('Product export was incorrectly accepted.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'only for pages and posts')) {
        throw $exception;
    }
}

try {
    $exporter->export(13, false);
    throw new RuntimeException('Non-Elementor page export was incorrectly accepted.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'not an editable Elementor document')) {
        throw $exception;
    }
}

if (!LocalExport::supports_post_type('page') || !LocalExport::supports_post_type('post') || LocalExport::supports_post_type('product')) {
    throw new RuntimeException('Supported post-type gate is incorrect.');
}

fwrite(STDOUT, "PASS local-export\n");
