<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

( static function (): void {
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

	global $wpdb;
	$lock_pattern = $wpdb->esc_like( 'ejb_lock_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-document lock options are transient synchronization state and must never survive uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $lock_pattern ) );

	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The private snapshot CPT is not registered during uninstall, so plugin-owned rows are selected explicitly before deletion through WordPress APIs.
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
			'_ejb_repo_identity',
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
} )();
