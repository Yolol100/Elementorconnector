<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

final class WP_Post {
    public function __construct(
        public int $ID,
        public string $post_type,
        public string $post_title,
        public string $post_name = ''
    ) {}
}

$GLOBALS['ejb_site_parts_posts'] = [
    10 => new WP_Post(10, 'page', 'Landing Page', 'landing-page'),
    11 => new WP_Post(11, 'post', 'News Post', 'news-post'),
    91 => new WP_Post(91, 'elementor_library', 'Header'),
    92 => new WP_Post(92, 'elementor_library', 'Footer'),
    99 => new WP_Post(99, 'post', 'Previous Global'),
];
$GLOBALS['ejb_site_parts_last_query'] = [];

if (!function_exists('get_post')) {
    function get_post(int $post_id): WP_Post|false {
        return $GLOBALS['ejb_site_parts_posts'][$post_id] ?? false;
    }
}

if (!function_exists('setup_postdata')) {
    function setup_postdata(WP_Post $post): bool {
        $GLOBALS['post'] = $post;
        return true;
    }
}

final class WP_Query {
    private WP_Post|false $selected_post;

    public function __construct(public array $query) {
        $GLOBALS['ejb_site_parts_last_query'] = $query;
        $post_id = (int) ($query['page_id'] ?? $query['p'] ?? 0);
        $this->selected_post = get_post($post_id);
    }

    public function have_posts(): bool {
        return $this->selected_post instanceof WP_Post;
    }

    public function the_post(): void {
        if ($this->selected_post instanceof WP_Post) {
            $GLOBALS['post'] = $this->selected_post;
        }
    }

    public function reset_postdata(): void {}
}

final class EJB_Test_Site_Parts_Documents {
    public function payload(int $post_id): array {
        return match ($post_id) {
            91 => [
                'title' => 'Header',
                'type' => 'header',
                'version' => '0.4',
                'page_settings' => [],
                'content' => [],
            ],
            92 => [
                'title' => 'Footer',
                'type' => 'footer',
                'version' => '0.4',
                'page_settings' => [],
                'content' => [],
            ],
            default => throw new RuntimeException('Unexpected document payload request.'),
        };
    }
}

final class EJB_Test_Theme_Document {
    public function __construct(private int $post_id) {}

    public function get_post(): WP_Post|false {
        return get_post($this->post_id);
    }
}

final class EJB_Test_Conditions_Manager {
    public bool $throw = false;

    public function get_documents_for_location(string $location): array {
        if ($this->throw) {
            throw new RuntimeException('Simulated Elementor Pro condition failure.');
        }

        return match ($location) {
            'header' => [91 => new EJB_Test_Theme_Document(91)],
            'footer' => [92 => new EJB_Test_Theme_Document(92)],
            default => [],
        };
    }
}

final class EJB_Test_ThemeBuilder_Module {
    public static function instance(): self {
        static $instance;
        return $instance ??= new self();
    }

    public function get_conditions_manager(): object {
        return $GLOBALS['ejb_site_parts_conditions'];
    }
}

class_alias(EJB_Test_Site_Parts_Documents::class, 'Webactueel\\ElementorJsonBridge\\Elementor\\Documents');
class_alias(EJB_Test_ThemeBuilder_Module::class, 'ElementorPro\\Modules\\ThemeBuilder\\Module');

require_once dirname(__DIR__) . '/includes/Elementor/SiteParts.php';

use Webactueel\ElementorJsonBridge\Elementor\SiteParts;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$GLOBALS['ejb_site_parts_conditions'] = new EJB_Test_Conditions_Manager();
$documents = new EJB_Test_Site_Parts_Documents();
$resolver = new SiteParts($documents);

$previous_post = $GLOBALS['ejb_site_parts_posts'][99];
$previous_query = (object) ['name' => 'previous-query'];
$previous_the_query = (object) ['name' => 'previous-main-query'];
$GLOBALS['post'] = $previous_post;
$GLOBALS['wp_query'] = $previous_query;
$GLOBALS['wp_the_query'] = $previous_the_query;

$page_result = $resolver->for_post(10);
$page_query = $GLOBALS['ejb_site_parts_last_query'];
$assert(($page_query['page_id'] ?? 0) === 10, 'Page context did not use page_id.');
$assert(!array_key_exists('p', $page_query), 'Page context incorrectly used the single-post p query variable.');
$assert(($page_result['header']['id'] ?? 0) === 91, 'Page context did not resolve the matching header.');
$assert(($page_result['footer']['id'] ?? 0) === 92, 'Page context did not resolve the matching footer.');
$assert($GLOBALS['post'] === $previous_post, 'Page export did not restore the previous global post.');
$assert($GLOBALS['wp_query'] === $previous_query, 'Page export did not restore the previous wp_query.');
$assert($GLOBALS['wp_the_query'] === $previous_the_query, 'Page export did not restore the previous wp_the_query.');

$post_result = $resolver->for_post(11);
$post_query = $GLOBALS['ejb_site_parts_last_query'];
$assert(($post_query['p'] ?? 0) === 11, 'Post context did not use p.');
$assert(!array_key_exists('page_id', $post_query), 'Post context incorrectly used page_id.');
$assert(($post_result['header']['id'] ?? 0) === 91, 'Post context did not resolve the matching header.');
$assert(($post_result['footer']['id'] ?? 0) === 92, 'Post context did not resolve the matching footer.');

$GLOBALS['ejb_site_parts_conditions']->throw = true;
$fallback_result = $resolver->for_post(10);
$assert(($fallback_result['supported'] ?? true) === false, 'Theme Builder failure did not enter the safe fallback state.');
$assert(($fallback_result['header'] ?? 'unexpected') === null, 'Theme Builder failure unexpectedly returned a header.');
$assert(($fallback_result['footer'] ?? 'unexpected') === null, 'Theme Builder failure unexpectedly returned a footer.');
$warnings = $fallback_result['warnings'] ?? [];
$assert(is_array($warnings) && count($warnings) === 1, 'Theme Builder failure did not return one stable warning.');
$assert(str_contains((string) $warnings[0], 'only the source document was exported'), 'Theme Builder failure warning did not explain the safe fallback.');
$assert($GLOBALS['post'] === $previous_post, 'Failure fallback did not restore the previous global post.');
$assert($GLOBALS['wp_query'] === $previous_query, 'Failure fallback did not restore the previous wp_query.');
$assert($GLOBALS['wp_the_query'] === $previous_the_query, 'Failure fallback did not restore the previous wp_the_query.');

fwrite(STDOUT, "PASS site-parts\n");
