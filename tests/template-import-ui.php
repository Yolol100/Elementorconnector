<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/js/template-import.js');
$service = file_get_contents($root . '/includes/Elementor/TemplateImporter.php');
$controller = file_get_contents($root . '/includes/Admin/TemplateImportController.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(is_string($js) && is_string($service) && is_string($controller), 'Smart import source files could not be read.');

$assert(str_contains($js, "closest('#elementor-import-template-trigger')"), 'Smart import no longer intercepts Elementor\'s native Template Import trigger.');
$assert(str_contains($js, "document.addEventListener('click', handleNativeImport, true)"), 'Smart import interception is not capture-phase and may lose to Elementor handlers.');
$assert(str_contains($js, "setAction('new_template')"), 'Smart import no longer defaults to the non-destructive create-new Template path.');
$assert(str_contains($js, "action === 'replace' && !confirmed"), 'Smart import replacement no longer requires explicit confirmation.');
$assert(str_contains($js, 'bypassNextNativeImport = true'), 'Smart import no longer exposes a one-shot standard Elementor import fallback.');
$assert(str_contains($js, "accept: '.json,application/json'"), 'Smart import file picker no longer limits the custom flow to JSON.');
$assert(str_contains($js, 'Snackbar'), 'Smart import success feedback no longer uses the WordPress Snackbar component.');

$assert(str_contains($service, "'before_json_import'"), 'Smart replacement no longer creates a dedicated rollback snapshot.');
$assert(str_contains($service, '$this->snapshots->payload'), 'Smart replacement no longer reads its rollback snapshot after failure.');
$assert(str_contains($service, "'page', 'post', 'elementor_library'"), 'Smart import target scope changed unexpectedly.');
$assert(!str_contains($service, "'product' =>"), 'Products were added as an explicit smart import target.');
$assert(str_contains($service, "'post_status'  => 'draft'"), 'New Page/Post imports are no longer forced to draft status.');
$assert(str_contains($service, "templates_manager->get_source( 'local' )"), 'Create-new Template no longer delegates to Elementor\'s local template importer.');

$assert(str_contains($controller, 'get_file_params()'), 'Smart import controller no longer reads WordPress REST file parameters.');
$assert(str_contains($controller, "'json' !== strtolower( pathinfo"), 'Smart import controller no longer rejects non-JSON files server-side.');
$assert(str_contains($controller, 'PayloadValidator::MAX_BYTES'), 'Smart import upload size is no longer bounded by the JSON validator limit.');
$assert(str_contains($controller, "current_user_can( Hooks::CAPABILITY )"), 'Smart import REST routes lost the bridge capability gate.');

fwrite(STDOUT, "PASS template-import-ui\n");
