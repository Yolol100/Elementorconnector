<?php

use Webactueel\ElementorJsonBridge\Content\WordPressDocument;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress was not bootstrapped.');
}
if (!defined('EJB_VERSION') || !class_exists(WordPressDocument::class)) {
    throw new RuntimeException('Elementor JSON Bridge is not active in the runtime environment.');
}

$admin = get_user_by('login', 'admin');
if (!$admin instanceof WP_User) {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $admin = $admins[0] ?? null;
}
if (!$admin instanceof WP_User) {
    throw new RuntimeException('No administrator user is available for the WordPress content runtime test.');
}

$previous_user = get_current_user_id();
wp_set_current_user((int) $admin->ID);

register_post_meta(
    'page',
    'ejb_runtime_public_meta',
    [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => true,
        'auth_callback' => static fn (): bool => true,
    ]
);

if (!function_exists('acf_add_local_field_group') || !function_exists('update_field')) {
    throw new RuntimeException('Advanced Custom Fields is not active in the runtime environment.');
}
acf_add_local_field_group(
    [
        'key' => 'group_ejb_runtime',
        'title' => 'EJB Runtime Fields',
        'fields' => [
            [
                'key' => 'field_ejb_runtime_text',
                'label' => 'Runtime text',
                'name' => 'ejb_runtime_text',
                'type' => 'text',
            ],
        ],
        'location' => [
            [
                [
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'page',
                ],
            ],
        ],
    ]
);

$page_id = wp_insert_post(
    [
        'post_type' => 'page',
        'post_status' => 'draft',
        'post_title' => 'EJB Generic Page',
        'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
        'post_excerpt' => 'Before excerpt',
    ],
    true
);
if (is_wp_error($page_id)) {
    throw new RuntimeException('Unable to create the generic runtime page: ' . $page_id->get_error_message());
}
$page_id = (int) $page_id;

$post_id = wp_insert_post(
    [
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_title' => 'EJB Generic Post',
        'post_content' => '<p>Post before</p>',
    ],
    true
);
if (is_wp_error($post_id)) {
    wp_delete_post($page_id, true);
    throw new RuntimeException('Unable to create the generic runtime post: ' . $post_id->get_error_message());
}
$post_id = (int) $post_id;

$category = wp_insert_term('EJB Runtime Category', 'category', ['slug' => 'ejb-runtime-category']);
if (is_wp_error($category)) {
    wp_delete_post($page_id, true);
    wp_delete_post($post_id, true);
    throw new RuntimeException('Unable to create the runtime taxonomy term: ' . $category->get_error_message());
}
$category_id = (int) $category['term_id'];

$created_id = 0;

try {
    update_post_meta($page_id, 'ejb_runtime_public_meta', 'meta-before');
    update_field('field_ejb_runtime_text', 'acf-before', $page_id);
    wp_set_object_terms($post_id, [$category_id], 'category', false);

    $yoast_expected = version_compare((string) get_bloginfo('version'), '6.9', '>=');
    if ($yoast_expected && !class_exists('WPSEO_Meta')) {
        throw new RuntimeException('Yoast SEO is not active in the current WordPress runtime.');
    }
    if (class_exists('WPSEO_Meta')) {
        WPSEO_Meta::set_value('title', 'Yoast before', $page_id);
        WPSEO_Meta::set_value('metadesc', 'Yoast description before', $page_id);
    }

    $content = new WordPressDocument(new Documents(), new PayloadValidator());

    if (!$content->supports($page_id) || !$content->supports($post_id)) {
        throw new RuntimeException('Normal Page/Post content was not discovered automatically.');
    }

    $before = $content->payload($page_id);
    if (($before['elementor'] ?? 'missing') !== null) {
        throw new RuntimeException('A normal non-Elementor page was incorrectly exposed as Elementor content.');
    }
    if (($before['post']['content'] ?? '') !== '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->') {
        throw new RuntimeException('Normal WordPress editor content was not exported exactly.');
    }
    if (($before['registered_meta']['ejb_runtime_public_meta'] ?? '') !== 'meta-before') {
        throw new RuntimeException('Registered public metadata was not exported.');
    }
    if (($before['acf']['ejb_runtime_text']['key'] ?? '') !== 'field_ejb_runtime_text' || ($before['acf']['ejb_runtime_text']['value'] ?? '') !== 'acf-before') {
        throw new RuntimeException('ACF field identity/value was not exported.');
    }
    if (class_exists('WPSEO_Meta') && (($before['yoast']['title'] ?? '') !== 'Yoast before' || ($before['yoast']['metadesc'] ?? '') !== 'Yoast description before')) {
        throw new RuntimeException('Yoast editable metadata was not exported.');
    }

    $incoming = $before;
    $incoming['post']['title'] = 'EJB Generic Page Updated';
    $incoming['post']['content'] = '<!-- wp:heading --><h2>Updated</h2><!-- /wp:heading -->';
    $incoming['post']['excerpt'] = 'Updated excerpt';
    $incoming['registered_meta']['ejb_runtime_public_meta'] = 'meta-after';
    $incoming['acf']['ejb_runtime_text']['value'] = 'acf-after';
    if (class_exists('WPSEO_Meta')) {
        $incoming['yoast']['title'] = 'Yoast after';
        $incoming['yoast']['metadesc'] = 'Yoast description after';
    }

    $incoming = $content->validate_array($incoming, $page_id);
    $content->apply($page_id, $incoming);
    $readback = $content->payload($page_id);
    if (!hash_equals(CanonicalJson::hash($incoming), CanonicalJson::hash($readback))) {
        throw new RuntimeException(
            'The full WordPress/ACF/Yoast content envelope failed exact readback verification: ' .
            wp_json_encode(['incoming' => $incoming, 'readback' => $readback], JSON_UNESCAPED_SLASHES)
        );
    }

    $post_payload = $content->payload($post_id);
    if (($post_payload['taxonomies']['category'] ?? []) !== ['ejb-runtime-category']) {
        throw new RuntimeException('Existing taxonomy terms were not exported by slug.');
    }

    $request = [
        'format' => WordPressDocument::CREATE_FORMAT,
        'version' => WordPressDocument::VERSION,
        'request_id' => 'runtime-create-draft',
        'post_type' => 'page',
        'post' => [
            'title' => 'EJB Created Draft',
            'slug' => 'ejb-created-draft',
            'content' => '<p>Created through the bridge request protocol.</p>',
            'excerpt' => 'Created draft',
        ],
    ];
    $created_id = $content->create_draft($request);
    $created = get_post($created_id);
    if (!$created instanceof WP_Post || $created->post_status !== 'draft' || $created->post_title !== 'EJB Created Draft') {
        throw new RuntimeException('The create-content protocol did not create a safe WordPress draft.');
    }
    $created_payload = $content->payload($created_id);
    if (($created_payload['elementor'] ?? 'missing') !== null || ($created_payload['post']['content'] ?? '') !== '<p>Created through the bridge request protocol.</p>') {
        throw new RuntimeException('The newly created normal draft did not roundtrip through the generic content adapter.');
    }

    wp_set_current_user(0);
    $permission_denied = false;
    try {
        $content->apply($page_id, $incoming);
    } catch (RuntimeException) {
        $permission_denied = true;
    }
    if (!$permission_denied) {
        throw new RuntimeException('Generic WordPress content accepted a write without edit_post permission.');
    }
    wp_set_current_user((int) $admin->ID);

    echo wp_json_encode(
        [
            'status' => 'PASS',
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'bridge' => EJB_VERSION,
            'acf' => defined('ACF_VERSION') ? ACF_VERSION : 'active',
            'yoast' => defined('WPSEO_VERSION') ? WPSEO_VERSION : (class_exists('WPSEO_Meta') ? 'active' : 'not-installed-on-minimum-runtime'),
            'normal_content_roundtrip' => true,
            'registered_meta_roundtrip' => true,
            'acf_roundtrip' => true,
            'yoast_roundtrip' => class_exists('WPSEO_Meta'),
            'taxonomy_export' => true,
            'non_elementor_isolation' => true,
            'draft_creation' => true,
            'permission_denied_without_user' => true,
            'roundtrip_hash' => CanonicalJson::hash($readback),
        ],
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} finally {
    wp_set_current_user((int) $admin->ID);
    if ($created_id > 0) {
        wp_delete_post($created_id, true);
    }
    wp_delete_post($page_id, true);
    wp_delete_post($post_id, true);
    wp_delete_term($category_id, 'category');
    wp_set_current_user($previous_user);
}
