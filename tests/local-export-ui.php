<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/js/local-export.js');
$css = file_get_contents($root . '/assets/css/local-export.css');

$assert(is_string($js) && '' !== $js, 'Local export JavaScript could not be read.');
$assert(is_string($css) && '' !== $css, 'Local export CSS could not be read.');

$assert(str_contains($js, 'Snackbar'), 'Clean export feedback no longer uses the WordPress Snackbar component.');
$assert(str_contains($js, 'setToast(config.strings.downloaded);'), 'Clean export success no longer creates compact snackbar feedback.');
$assert(str_contains($js, 'setPostId(null);'), 'Clean export success no longer closes the export modal.');
$assert(str_contains($js, "status: 'warning'"), 'Warning feedback no longer remains in the modal.');
$assert(str_contains($js, "status: 'error'"), 'Error feedback no longer remains in the modal.');
$assert(!str_contains($js, "status: warnings.length ? 'warning' : 'success'"), 'Clean success regressed to the large inline Notice path.');

$assert(str_contains($css, '.ejb-export-toast.components-snackbar'), 'Snackbar styling is missing its scoped plugin selector.');
$assert(str_contains($css, 'max-width: min(380px, calc(100vw - 32px));'), 'Snackbar lost its compact desktop width cap.');
$assert(str_contains($css, 'right: 24px;') && str_contains($css, 'bottom: 24px;'), 'Snackbar lost its compact desktop corner placement.');
$assert(str_contains($css, '@media (max-width: 600px)'), 'Snackbar no longer has a narrow-screen layout rule.');

fwrite(STDOUT, "PASS local-export-ui\n");
