<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/js/template-import.js');
$ui = file_get_contents($root . '/includes/Admin/TemplateImportUi.php');
$service = file_get_contents($root . '/includes/Elementor/TemplateImporter.php');
$controller = file_get_contents($root . '/includes/Admin/TemplateImportController.php');
$plugin = file_get_contents($root . '/includes/Plugin.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(is_string($js) && is_string($ui) && is_string($service) && is_string($controller) && is_string($plugin), 'Page/Post import source files could not be read.');

$assert(str_contains($js, "closest('.ejb-template-import-trigger')"), 'Page/Post import no longer opens from the dedicated overview button.');
$assert(!str_contains($js, '#elementor-import-template-trigger'), 'The bridge still intercepts Elementor\'s native Templates import trigger.');
$assert(!str_contains($js, 'bypassNextNativeImport'), 'Obsolete native Elementor import bypass code is still active.');
$assert(str_contains($js, 'const [replaceExisting, setReplaceExisting] = useState(false);'), 'Replacement is no longer unchecked by default.');
$assert(str_contains($js, 'CheckboxControl'), 'The detected-target replacement checkbox is missing.');
$assert(str_contains($js, "form.append('replace_existing'"), 'The replacement checkbox choice is not sent to the server.');
$assert(str_contains($js, "form.append('expected_target_id'"), 'The analyzed target identity is not bound to execution.');
$assert(str_contains($js, "form.append('destination', config.destination)"), 'The Page/Post overview destination is not bound to analysis and execution.');
$assert(str_contains($js, 'Snackbar'), 'Import success feedback no longer uses the compact WordPress Snackbar component.');

$assert(str_contains($ui, "add_action( 'restrict_manage_posts'"), 'The dedicated Page/Post overview import button hook is missing.');
$assert(str_contains($ui, "private const POST_TYPES = [ 'page', 'post' ];"), 'The import UI scope is no longer exactly Pages and Posts.');
$assert(!str_contains($ui, "'elementor_library'"), 'The bridge import UI is still loaded on Elementor Saved Templates.');
$assert(str_contains($ui, 'ejb-template-import-trigger'), 'The Page/Post overview import button marker is missing.');

$assert(str_contains($service, "private const DESTINATIONS        = [ 'page', 'post' ];"), 'The smart import service destination scope changed unexpectedly.');
$assert(!str_contains($service, 'create_template('), 'The bridge still contains a second Elementor Template creation/import path.');
$assert(!str_contains($service, "'elementor_library'"), 'Elementor Template Library documents are still bridge replacement targets.');
$assert(str_contains($service, '$this->lock->acquire( $target_id )'), 'Smart replacement is not protected by the shared document lock.');
$assert(str_contains($service, '$this->lock->release( $target_id, $token )'), 'Smart replacement does not release the shared document lock.');
$assert(str_contains($service, '$expected_target_id < 1 || (int) $target[\'id\'] !== $expected_target_id'), 'Replacement is not bound to the target reviewed during analysis.');
$assert(str_contains($service, "'post_status'  => 'draft'"), 'Unchecked import no longer creates a new Page/Post as a draft.');

$assert(!str_contains($controller, '/template-import/targets'), 'The obsolete manual target-search REST route still exists.');
$assert(str_contains($controller, 'in_array( $value, [ \'page\', \'post\' ], true )'), 'The REST destination gate is not limited to Pages and Posts.');
$assert(str_contains($controller, "'replace_existing'"), 'The REST execute route does not expose the replacement checkbox state.');
$assert(str_contains($controller, "'expected_target_id'"), 'The REST execute route does not bind the analyzed target ID.');
$assert(str_contains($controller, "current_user_can( Hooks::CAPABILITY )"), 'Page/Post import REST routes lost the bridge capability gate.');
$assert(str_contains($controller, 'PayloadValidator::MAX_BYTES'), 'Page/Post import upload size is no longer bounded by the JSON validator limit.');

$assert(str_contains($plugin, '$lock              = new Lock();'), 'Plugin bootstrap no longer creates one shared document lock.');
$assert(str_contains($plugin, 'new Manager( $documents, $validator, $github, $snapshots, $lock )'), 'GitHub sync does not use the shared document lock.');
$assert(str_contains($plugin, 'new TemplateImporter( $documents, $validator, $snapshots, $lock )'), 'Page/Post import does not use the shared document lock.');

fwrite(STDOUT, "PASS template-import-ui\n");
