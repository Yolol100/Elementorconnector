<?php

declare(strict_types=1);

$root = dirname(__DIR__);
define('ABSPATH', __DIR__ . '/');

$GLOBALS['ejb_test_user'] = 0;
$GLOBALS['ejb_test_allow_bridge'] = true;
$GLOBALS['ejb_test_allow_edit'] = true;
$GLOBALS['ejb_test_throw_apply'] = false;
$GLOBALS['ejb_test_settings'] = [
    'github_client_id' => 'Iv1.test',
    'repo_owner' => 'owner',
    'repo_name' => 'repo',
    'repo_branch' => 'site-sync',
    'repo_root' => 'site-data/elementor',
    'auto_export' => 1,
    'auto_apply' => 1,
    'auto_apply_actor' => 42,
    'delete_data_on_uninstall' => 0,
];

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed {
        if ($key === 'ejb_settings') {
            return $GLOBALS['ejb_test_settings'];
        }
        if ($key === 'ejb_github_auth') {
            return 'encrypted-token';
        }
        return $default;
    }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args(mixed $args, array $defaults = []): array {
        return array_merge($defaults, is_array($args) ? $args : []);
    }
}
if (!function_exists('get_posts')) {
    function get_posts(array $args): array {
        unset($args);
        return [123];
    }
}
if (!function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array {
        unset($args, $output);
        return ['page' => 'page'];
    }
}
if (!function_exists('user_can')) {
    function user_can(int $user_id, string $capability, mixed ...$args): bool {
        if ($user_id !== 42) {
            return false;
        }
        if ($capability === 'manage_elementor_json_bridge') {
            return (bool) $GLOBALS['ejb_test_allow_bridge'];
        }
        if ($capability === 'edit_post') {
            return (bool) $GLOBALS['ejb_test_allow_edit'] && (($args[0] ?? 0) === 123);
        }
        return false;
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int {
        return (int) $GLOBALS['ejb_test_user'];
    }
}
if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user(int $user_id): object {
        $GLOBALS['ejb_test_user'] = $user_id;
        return (object) ['ID' => $user_id];
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string {
        return $value;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash(string $value): string {
        return $value;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $key): bool {
        unset($key);
        return true;
    }
}

final class EJB_Test_Elementor_Plugin {}
class_alias(EJB_Test_Elementor_Plugin::class, 'Elementor\\Plugin');

final class EJB_Test_Manager {
    public int $checks = 0;
    public int $applies = 0;

    public function check_remote(int $id): array {
        ++$this->checks;
        if ($id !== 123 || get_current_user_id() !== 42) {
            throw new RuntimeException('Background actor was not active during the fresh remote check.');
        }
        return ['status' => 'remote_pending'];
    }

    public function apply_remote(int $id): array {
        ++$this->applies;
        if ($id !== 123 || get_current_user_id() !== 42) {
            throw new RuntimeException('Background actor was not active during apply.');
        }
        if ($GLOBALS['ejb_test_throw_apply']) {
            throw new RuntimeException('Simulated apply failure.');
        }
        return ['status' => 'verified'];
    }
}
class_alias(EJB_Test_Manager::class, 'Webactueel\\ElementorJsonBridge\\Sync\\Manager');

require_once $root . '/includes/Settings.php';
require_once $root . '/includes/Sync/State.php';
require_once $root . '/includes/Lifecycle/Hooks.php';
require_once $root . '/includes/Sync/AutoApply.php';
require_once $root . '/includes/Sync/ContentRequests.php';

$GLOBALS['ejb_test_user'] = 42;
$captured = Webactueel\ElementorJsonBridge\Settings::sanitize([
    'github_client_id' => 'Iv1.test',
    'repo_owner' => 'owner',
    'repo_name' => 'repo',
    'repo_branch' => 'site-sync',
    'repo_root' => 'site-data/elementor',
    'auto_export' => 1,
    'auto_apply' => 1,
]);
if (($captured['auto_apply_actor'] ?? 0) !== 42) {
    throw new RuntimeException('Enabling Automatic apply did not bind the current administrator actor.');
}
$GLOBALS['ejb_test_user'] = 0;

if (Webactueel\ElementorJsonBridge\Sync\ContentRequests::should_process(0)) {
    throw new RuntimeException('Repository-authored requests can run while automatic apply is disabled.');
}
if (!Webactueel\ElementorJsonBridge\Sync\ContentRequests::should_process(1)) {
    throw new RuntimeException('Repository-authored requests are blocked while automatic apply is enabled.');
}

$manager = new EJB_Test_Manager();
$auto = new Webactueel\ElementorJsonBridge\Sync\AutoApply($manager);
$auto->apply_pending();
if ($manager->checks !== 1 || $manager->applies !== 1) {
    throw new RuntimeException(sprintf('Authorized background flow did not complete: checks=%d applies=%d.', $manager->checks, $manager->applies));
}
if (get_current_user_id() !== 0) {
    throw new RuntimeException('Background user context leaked after automatic apply.');
}

$GLOBALS['ejb_test_allow_edit'] = false;
$manager = new EJB_Test_Manager();
$auto = new Webactueel\ElementorJsonBridge\Sync\AutoApply($manager);
$auto->apply_pending();
if ($manager->checks !== 0 || $manager->applies !== 0) {
    throw new RuntimeException('An actor without edit_post reached the synchronization path.');
}
if (get_current_user_id() !== 0) {
    throw new RuntimeException('Background user context leaked after a denied automatic apply.');
}

$GLOBALS['ejb_test_allow_edit'] = true;
$GLOBALS['ejb_test_allow_bridge'] = false;
$manager = new EJB_Test_Manager();
$auto = new Webactueel\ElementorJsonBridge\Sync\AutoApply($manager);
$auto->apply_pending();
if ($manager->checks !== 0 || $manager->applies !== 0) {
    throw new RuntimeException('An actor without the bridge capability reached the synchronization path.');
}

$GLOBALS['ejb_test_allow_bridge'] = true;
$GLOBALS['ejb_test_throw_apply'] = true;
$manager = new EJB_Test_Manager();
$auto = new Webactueel\ElementorJsonBridge\Sync\AutoApply($manager);
$auto->apply_pending();
if ($manager->checks !== 1 || $manager->applies !== 1) {
    throw new RuntimeException('The simulated failing apply did not reach the guarded write path.');
}
if (get_current_user_id() !== 0) {
    throw new RuntimeException('Background user context leaked after a failed automatic apply.');
}

echo "PASS background-authorization\n";
