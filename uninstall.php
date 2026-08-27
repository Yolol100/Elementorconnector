<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'ejb_settings', [] );
$settings = is_array( $settings ) ? $settings : [];

wp_unschedule_hook( 'ejb_poll_remote' );
wp_unschedule_hook( 'ejb_export_document' );
delete_option( 'ejb_github_auth' );
delete_option( 'ejb_poll_page' );
delete_option( 'ejb_github_rate_limit_until' );
delete_metadata( 'user', 0, '_ejb_device_flow', '', true );

$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_elementor_json_bridge' );
}

if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$snapshots = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'ejb_snapshot' )
);
foreach ( $snapshots as $snapshot_id ) {
	wp_delete_post( (int) $snapshot_id, true );
}

foreach (
	[
		'_ejb_enabled',
		'_ejb_status',
		'_ejb_base_hash',
		'_ejb_remote_sha',
		'_ejb_remote_path',
		'_ejb_pending_sha',
		'_ejb_pending_hash',
		'_ejb_last_error',
		'_ejb_last_sync_at',
		'_ejb_lock',
	] as $meta_key
) {
	delete_post_meta_by_key( $meta_key );
}

delete_option( 'ejb_settings' );
