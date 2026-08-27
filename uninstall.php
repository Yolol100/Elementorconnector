<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ejb_settings = get_option( 'ejb_settings', [] );
$ejb_settings = is_array( $ejb_settings ) ? $ejb_settings : [];

wp_unschedule_hook( 'ejb_poll_remote' );
wp_unschedule_hook( 'ejb_export_document' );
delete_option( 'ejb_github_auth' );
delete_option( 'ejb_poll_page' );
delete_option( 'ejb_github_rate_limit_until' );
delete_metadata( 'user', 0, '_ejb_device_flow', '', true );

$ejb_role = get_role( 'administrator' );
if ( $ejb_role ) {
	$ejb_role->remove_cap( 'manage_elementor_json_bridge' );
}

if ( empty( $ejb_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must find plugin-owned private snapshot rows before this plugin's CPT is registered.
$ejb_snapshots = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'ejb_snapshot' )
);
foreach ( $ejb_snapshots as $ejb_snapshot_id ) {
	wp_delete_post( (int) $ejb_snapshot_id, true );
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
	] as $ejb_meta_key
) {
	delete_post_meta_by_key( $ejb_meta_key );
}

delete_option( 'ejb_settings' );
