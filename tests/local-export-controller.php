<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

final class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params) {}

    public function get_param(string $key): mixed {
        return $this->params[$key] ?? null;
    }

    public function offsetExists(mixed $offset): bool {
        return isset($this->params[$offset]);
    }

    public function offsetGet(mixed $offset): mixed {
        return $this->params[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        throw new LogicException('Test request is immutable.');
    }

    public function offsetUnset(mixed $offset): void {
        throw new LogicException('Test request is immutable.');
    }
}

final class WP_REST_Response {
    public function __construct(public mixed $data) {}
}

final class WP_Error {
    public function __construct(
        public string $code,
        public string $message,
        public mixed $data = null
    ) {}

    public function get_error_message(): string {
        return $this->message;
    }

    public function get_error_data(): mixed {
        return $this->data;
    }
}

if (!function_exists('absint')) {
    function absint(mixed $value): int {
        return abs((int) $value);
    }
}

if (!function_exists('get_post')) {
    function get_post(int $post_id): object|false {
        return 10 === $post_id ? (object) ['ID' => 10] : false;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool {
        unset($capability, $args);
        return true;
    }
}

if (!function_exists('rest_sanitize_boolean')) {
    function rest_sanitize_boolean(mixed $value): bool {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

final class EJB_Test_Controller_Local_Export {
    public function export(int $post_id, bool $include_site_parts = false): array {
        unset($post_id, $include_site_parts);

        return match ($GLOBALS['ejb_controller_mode'] ?? 'success') {
            'runtime' => throw new RuntimeException('Known bridge validation error.'),
            'throwable' => throw new TypeError('Sensitive internal type details.'),
            default => [
                'filename' => 'page-elementor.json',
                'format' => 'elementor-document',
                'export' => ['type' => 'wp-page'],
                'included_site_parts' => ['header' => false, 'footer' => false],
                'warnings' => [],
            ],
        };
    }
}

class_alias(EJB_Test_Controller_Local_Export::class, 'Webactueel\\ElementorJsonBridge\\Elementor\\LocalExport');

require_once dirname(__DIR__) . '/includes/Admin/LocalExportController.php';

use Webactueel\ElementorJsonBridge\Admin\LocalExportController;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$controller = new LocalExportController(new EJB_Test_Controller_Local_Export());
$request = new WP_REST_Request(['id' => 10, 'include_site_parts' => false]);

$GLOBALS['ejb_controller_mode'] = 'success';
$success = $controller->export($request);
$assert($success instanceof WP_REST_Response, 'Successful export did not return a REST response.');
$assert(($success->data['ok'] ?? false) === true, 'Successful export response did not include ok=true.');

$GLOBALS['ejb_controller_mode'] = 'runtime';
$known_error = $controller->export($request);
$assert($known_error instanceof WP_Error, 'Known bridge validation failure did not return WP_Error.');
$assert(($known_error->get_error_data()['status'] ?? 0) === 400, 'Known bridge validation failure did not keep HTTP 400.');
$assert($known_error->get_error_message() === 'Known bridge validation error.', 'Known bridge validation failure lost its safe message.');

$GLOBALS['ejb_controller_mode'] = 'throwable';
$unexpected_error = $controller->export($request);
$assert($unexpected_error instanceof WP_Error, 'Unexpected export failure did not return WP_Error.');
$assert(($unexpected_error->get_error_data()['status'] ?? 0) === 500, 'Unexpected export failure did not use HTTP 500.');
$assert($unexpected_error->get_error_message() === 'The Elementor JSON export could not be completed.', 'Unexpected export failure did not use the stable public message.');
$assert(!str_contains($unexpected_error->get_error_message(), 'Sensitive internal'), 'Unexpected export failure leaked internal exception details.');

fwrite(STDOUT, "PASS local-export-controller\n");
