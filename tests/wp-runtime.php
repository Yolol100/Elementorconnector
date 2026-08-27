<?php

declare(strict_types=1);

use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\GitHub\Client;
use Webactueel\ElementorJsonBridge\GitHub\DeviceAuth;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;
use Webactueel\ElementorJsonBridge\Plugin as BridgePlugin;
use Webactueel\ElementorJsonBridge\Security\SecretBox;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;
use Webactueel\ElementorJsonBridge\Sync\Lock;
use Webactueel\ElementorJsonBridge\Sync\Manager;
use Webactueel\ElementorJsonBridge\Sync\State;

if (!defined('ABSPATH')) {
    exit(1);
}

$admin_ids = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
if (!$admin_ids) {
    throw new RuntimeException('No administrator user is available.');
}
wp_set_current_user((int) $admin_ids[0]);

if (!current_user_can(Hooks::CAPABILITY)) {
    throw new RuntimeException('Activation capability was not granted.');
}
if (!did_action('elementor/loaded') || !defined('ELEMENTOR_VERSION') || version_compare((string) ELEMENTOR_VERSION, BridgePlugin::MIN_ELEMENTOR_VERSION, '<')) {
    throw new RuntimeException('Elementor runtime baseline failed.');
}
if (!wp_next_scheduled('ejb_poll_remote')) {
    throw new RuntimeException('Expected polling schedule is missing.');
}

$post_id = wp_insert_post(
    [
        'post_type' => 'page',
        'post_status' => 'draft',
        'post_title' => 'Original',
    ],
    true
);
if (is_wp_error($post_id)) {
    throw new RuntimeException('Unable to create the runtime test page.');
}
$post_id = (int) $post_id;
update_post_meta($post_id, '_elementor_edit_mode', 'builder');

$documents = new Documents();
$validator = new PayloadValidator();
$snapshots = new Snapshots();
$lock = new Lock();
$secret_box = new SecretBox();
$auth = new DeviceAuth($secret_box);
$client = new Client($auth);
$manager = new Manager($documents, $validator, $client, $snapshots, $lock);
$type = $documents->document_type($post_id);

$payload = [
    'title' => 'Remote rename blocked',
    'type' => $type,
    'version' => '0.4',
    'page_settings' => [],
    'content' => [
        [
            'id' => 'ejbtest01',
            'elType' => 'container',
            'settings' => [],
            'elements' => [],
        ],
    ],
];
$documents->save_payload($post_id, $validator->validate_array($payload, $type));
$initial = $documents->payload($post_id);
if (($initial['title'] ?? '') !== 'Original') {
    throw new RuntimeException('Remote payload changed the WordPress title.');
}

update_option(
    Settings::OPTION,
    Settings::sanitize(
        [
        'github_client_id' => 'Iv1.runtime-test',
        'repo_owner' => 'runtime-owner',
        'repo_name' => 'runtime-private-json',
        'repo_branch' => 'main',
        'repo_root' => 'elementor',
        'auto_export' => 0,
            'delete_data_on_uninstall' => 0,
        ]
    ),
    false
);
update_option(
    Settings::AUTH_OPTION,
    $secret_box->encrypt(
        [
            'access_token' => 'runtime-token',
            'expires_at' => 0,
            'refresh_token' => '',
            'refresh_expires_at' => 0,
            'token_type' => 'bearer',
        ]
    ),
    false
);

$GLOBALS['ejb_runtime_remote'] = [
    'path' => '',
    'content' => '',
    'sha' => '',
];

$http_filter = static function ($preempt, array $args, string $url) {
    unset($preempt);
    $parts = wp_parse_url($url);
    $path = (string) ($parts['path'] ?? '');
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    $json_response = static function (int $code, array $body): array {
        return [
            'headers' => [],
            'body' => wp_json_encode($body),
            'response' => ['code' => $code, 'message' => 'runtime-test'],
            'cookies' => [],
            'filename' => null,
        ];
    };

    if ('/repos/runtime-owner/runtime-private-json' === $path && 'GET' === $method) {
        return $json_response(200, ['private' => true, 'full_name' => 'runtime-owner/runtime-private-json', 'default_branch' => 'main']);
    }

    $prefix = '/repos/runtime-owner/runtime-private-json/contents/';
    if (str_starts_with($path, $prefix)) {
        $remote_path = rawurldecode(substr($path, strlen($prefix)));
        $remote = $GLOBALS['ejb_runtime_remote'];

        if ('GET' === $method) {
            if ('' === (string) $remote['sha']) {
                return $json_response(404, ['message' => 'Not Found']);
            }
            return $json_response(
                200,
                [
                    'type' => 'file',
                    'sha' => (string) $remote['sha'],
                    'encoding' => 'base64',
                    'content' => base64_encode((string) $remote['content']),
                    'path' => (string) $remote['path'],
                ]
            );
        }

        if ('PUT' === $method) {
            $request = json_decode((string) ($args['body'] ?? ''), true);
            if (!is_array($request)) {
                return $json_response(422, ['message' => 'Invalid request']);
            }
            $known_sha = (string) ($request['sha'] ?? '');
            if ('' !== (string) $remote['sha'] && !hash_equals((string) $remote['sha'], $known_sha)) {
                return $json_response(409, ['message' => 'SHA mismatch']);
            }
            $content = base64_decode((string) ($request['content'] ?? ''), true);
            if (!is_string($content)) {
                return $json_response(422, ['message' => 'Invalid base64']);
            }
            $sha = hash('sha1', 'blob ' . strlen($content) . "\0" . $content);
            $GLOBALS['ejb_runtime_remote'] = [
                'path' => $remote_path,
                'content' => $content,
                'sha' => $sha,
            ];
            return $json_response(200, ['content' => ['sha' => $sha]]);
        }
    }

    return new WP_Error('ejb_runtime_unexpected_http', 'Unexpected HTTP request in the controlled runtime test: ' . $method . ' ' . $url);
};
add_filter('pre_http_request', $http_filter, 10, 3);

$set_remote_payload = static function (array $remote_payload): void {
    $content = CanonicalJson::encode($remote_payload, true);
    $GLOBALS['ejb_runtime_remote']['content'] = $content;
    $GLOBALS['ejb_runtime_remote']['sha'] = hash('sha1', 'blob ' . strlen($content) . "\0" . $content);
};

try {
    if (!$manager->toggle($post_id)) {
        throw new RuntimeException('Unable to enable synchronization.');
    }

    $export = $manager->export($post_id);
    if (($export['status'] ?? '') !== State::CLEAN || '' === (string) $GLOBALS['ejb_runtime_remote']['sha']) {
        throw new RuntimeException('Initial controlled GitHub export failed.');
    }

    $remote_v2 = $documents->payload($post_id);
    $remote_v2['title'] = 'Do not rename WordPress';
    $remote_v2['content'][] = [
        'id' => 'ejbtest02',
        'elType' => 'container',
        'settings' => [],
        'elements' => [],
    ];
    $set_remote_payload($remote_v2);

    $check = $manager->check_remote($post_id);
    if (($check['status'] ?? '') !== State::REMOTE_PENDING) {
        throw new RuntimeException('Remote change was not detected as pending.');
    }
    $apply = $manager->apply_remote($post_id);
    if (($apply['status'] ?? '') !== State::VERIFIED) {
        throw new RuntimeException('Remote apply was not verified.');
    }
    $after_apply = $documents->payload($post_id);
    if (($after_apply['title'] ?? '') !== 'Original' || count((array) ($after_apply['content'] ?? [])) !== 2) {
        throw new RuntimeException('Verified apply did not preserve the title and remote structure.');
    }

    $pre_failure_hash = CanonicalJson::hash($after_apply);
    $remote_v3 = $after_apply;
    $remote_v3['content'][] = [
        'id' => 'ejbtest03',
        'elType' => 'container',
        'settings' => [],
        'elements' => [],
    ];
    $set_remote_payload($remote_v3);
    if (($manager->check_remote($post_id)['status'] ?? '') !== State::REMOTE_PENDING) {
        throw new RuntimeException('Rollback scenario was not staged as pending.');
    }

    $force_mismatch = true;
    $save_filter = static function (array $data) use (&$force_mismatch): array {
        if ($force_mismatch && isset($data['elements']) && is_array($data['elements'])) {
            $force_mismatch = false;
            array_pop($data['elements']);
        }
        return $data;
    };
    add_filter('elementor/document/save/data', $save_filter, 999, 1);
    try {
        $manager->apply_remote($post_id);
        throw new RuntimeException('Intentional roundtrip mismatch did not fail.');
    } catch (RuntimeException $exception) {
        if ('Intentional roundtrip mismatch did not fail.' === $exception->getMessage()) {
            throw $exception;
        }
    } finally {
        remove_filter('elementor/document/save/data', $save_filter, 999);
    }

    $after_rollback = $documents->payload($post_id);
    if (!hash_equals($pre_failure_hash, CanonicalJson::hash($after_rollback)) || State::ERROR !== $manager->status($post_id)) {
        throw new RuntimeException('Automatic rollback was not verified after the forced mismatch.');
    }

    $snapshot_id = $snapshots->latest_id($post_id);
    if ($snapshot_id < 1) {
        throw new RuntimeException('Expected rollback snapshot is missing.');
    }
    $snapshot_post = get_post($snapshot_id);
    if (!$snapshot_post) {
        throw new RuntimeException('Unable to read rollback snapshot.');
    }
    $tampered = json_decode((string) $snapshot_post->post_content, true);
    if (!is_array($tampered)) {
        throw new RuntimeException('Unable to decode rollback snapshot.');
    }
    $tampered['title'] = 'tampered';
    wp_update_post(['ID' => $snapshot_id, 'post_content' => wp_slash(wp_json_encode($tampered))]);
    try {
        $snapshots->payload($snapshot_id, $post_id);
        throw new RuntimeException('Tampered snapshot was accepted.');
    } catch (RuntimeException $exception) {
        if ('Tampered snapshot was accepted.' === $exception->getMessage()) {
            throw $exception;
        }
    }

    $lock_token = $lock->acquire($post_id);
    try {
        try {
            $lock->acquire($post_id);
            throw new RuntimeException('Concurrent document lock was accepted.');
        } catch (RuntimeException $exception) {
            if ('Concurrent document lock was accepted.' === $exception->getMessage()) {
                throw $exception;
            }
        }
    } finally {
        $lock->release($post_id, $lock_token);
    }
} finally {
    remove_filter('pre_http_request', $http_filter, 10);
    delete_option(Lock::option_name($post_id));
    delete_option(Settings::AUTH_OPTION);
    delete_option(Settings::OPTION);
    delete_option('ejb_github_rate_limit_until');
    foreach (get_posts(['post_type' => Snapshots::POST_TYPE, 'post_status' => 'any', 'post_parent' => $post_id, 'posts_per_page' => -1, 'fields' => 'ids']) as $snapshot_id) {
        wp_delete_post((int) $snapshot_id, true);
    }
    wp_delete_post($post_id, true);
}

echo 'PASS wp-runtime controlled-github-roundtrip rollback title-lock snapshot-integrity Elementor=' . (string) ELEMENTOR_VERSION . ' WP=' . get_bloginfo('version') . ' PHP=' . PHP_VERSION . "\n";
