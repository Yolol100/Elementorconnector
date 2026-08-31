<?php

namespace Webactueel\ElementorJsonBridge\Admin;

use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Content\WordPressDocument;
use Webactueel\ElementorJsonBridge\GitHub\DeviceAuth;
use Webactueel\ElementorJsonBridge\Lifecycle\Hooks;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Sync\Manager;
use Webactueel\ElementorJsonBridge\Sync\State;

defined( 'ABSPATH' ) || exit;

final class AdminPage {
	private const SLUG = 'elementor-json-bridge';

	public function __construct(
		private readonly DeviceAuth $auth,
		private readonly WordPressDocument $content,
		private readonly Manager $sync,
		private readonly Snapshots $snapshots
	) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function menu(): void {
		add_management_page(
			'Elementor JSON Bridge',
			'Elementor JSON Bridge',
			Hooks::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function settings(): void {
		register_setting(
			'ejb_settings_group',
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
			]
		);
	}

	public function assets( string $hook_suffix ): void {
		if ( 'tools_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'ejb-admin', plugins_url( 'assets/css/admin.css', EJB_FILE ), [], EJB_VERSION );
		wp_enqueue_script( 'ejb-admin', plugins_url( 'assets/js/admin.js', EJB_FILE ), [], EJB_VERSION, true );
		wp_localize_script(
			'ejb-admin',
			'EJB_ADMIN',
			[
				'restUrl' => esc_url_raw( rest_url( 'ejb/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	public function render(): void {
		if ( ! current_user_can( Hooks::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Elementor JSON Bridge.', 'elementor-json-bridge' ) );
		}
		$settings = Settings::all();
		?>
		<div class="wrap ejb-wrap">
			<h1><?php echo esc_html__( 'Elementor JSON Bridge', 'elementor-json-bridge' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Connect one private GitHub repository. The bridge then manages editable WordPress content automatically, including normal post content, Elementor data, ACF values, Yoast fields, taxonomies and safe registered metadata.', 'elementor-json-bridge' ); ?></p>

			<div id="ejb-message" class="notice inline" role="status" aria-live="polite" hidden><p></p></div>

			<section class="ejb-card">
				<h2><?php echo esc_html__( '1. GitHub connection', 'elementor-json-bridge' ); ?></h2>
				<p><strong><?php echo esc_html__( 'Status:', 'elementor-json-bridge' ); ?></strong> <?php echo $this->auth->is_connected() ? esc_html__( 'Connected', 'elementor-json-bridge' ) : esc_html__( 'Not connected', 'elementor-json-bridge' ); ?></p>
				<p><?php echo esc_html__( 'After connection, existing and future editable content is discovered automatically. No per-page enable step is required.', 'elementor-json-bridge' ); ?></p>
				<div class="ejb-actions">
					<button type="button" class="button button-primary" id="ejb-connect"><?php echo esc_html__( 'Connect GitHub', 'elementor-json-bridge' ); ?></button>
					<button type="button" class="button" id="ejb-disconnect"><?php echo esc_html__( 'Disconnect', 'elementor-json-bridge' ); ?></button>
				</div>
				<div id="ejb-device" hidden>
					<p><?php echo esc_html__( 'Open GitHub and enter this code:', 'elementor-json-bridge' ); ?></p>
					<p><code id="ejb-device-code"></code></p>
					<p><a id="ejb-device-link" class="button" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open GitHub verification', 'elementor-json-bridge' ); ?></a></p>
				</div>
			</section>

			<section class="ejb-card">
				<h2><?php echo esc_html__( '2. Repository', 'elementor-json-bridge' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( 'ejb_settings_group' ); ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="ejb-client-id"><?php echo esc_html__( 'GitHub App Client ID', 'elementor-json-bridge' ); ?></label></th><td><input class="regular-text code" id="ejb-client-id" name="<?php echo esc_attr( Settings::OPTION ); ?>[github_client_id]" value="<?php echo esc_attr( (string) $settings['github_client_id'] ); ?>"><p class="description"><?php echo esc_html__( 'Public Client ID only. Do not paste a client secret or personal access token.', 'elementor-json-bridge' ); ?></p></td></tr>
						<tr><th scope="row"><label for="ejb-owner"><?php echo esc_html__( 'Repository owner', 'elementor-json-bridge' ); ?></label></th><td><input class="regular-text" id="ejb-owner" name="<?php echo esc_attr( Settings::OPTION ); ?>[repo_owner]" value="<?php echo esc_attr( (string) $settings['repo_owner'] ); ?>"></td></tr>
						<tr><th scope="row"><label for="ejb-repo"><?php echo esc_html__( 'Repository name', 'elementor-json-bridge' ); ?></label></th><td><input class="regular-text" id="ejb-repo" name="<?php echo esc_attr( Settings::OPTION ); ?>[repo_name]" value="<?php echo esc_attr( (string) $settings['repo_name'] ); ?>"></td></tr>
						<tr><th scope="row"><label for="ejb-branch"><?php echo esc_html__( 'Branch', 'elementor-json-bridge' ); ?></label></th><td><input class="regular-text code" id="ejb-branch" name="<?php echo esc_attr( Settings::OPTION ); ?>[repo_branch]" value="<?php echo esc_attr( (string) $settings['repo_branch'] ); ?>"></td></tr>
						<tr><th scope="row"><label for="ejb-root"><?php echo esc_html__( 'Content folder', 'elementor-json-bridge' ); ?></label></th><td><input class="regular-text code" id="ejb-root" name="<?php echo esc_attr( Settings::OPTION ); ?>[repo_root]" value="<?php echo esc_attr( (string) $settings['repo_root'] ); ?>"><p class="description"><?php echo esc_html__( 'The bridge creates content/ and site-index.json below this folder.', 'elementor-json-bridge' ); ?></p></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Automatic synchronization', 'elementor-json-bridge' ); ?></th><td><strong><?php echo esc_html__( 'On', 'elementor-json-bridge' ); ?></strong><p class="description"><?php echo esc_html__( 'Local saves export automatically. Fresh conflict-free GitHub changes apply automatically with snapshots, readback verification and rollback.', 'elementor-json-bridge' ); ?></p></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Uninstall cleanup', 'elementor-json-bridge' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[delete_data_on_uninstall]" value="1" <?php checked( 1, (int) $settings['delete_data_on_uninstall'] ); ?>> <?php echo esc_html__( 'Delete snapshots and sync metadata when the plugin is uninstalled.', 'elementor-json-bridge' ); ?></label></td></tr>
					</table>
					<?php submit_button(); ?>
					<button type="button" class="button" id="ejb-test-repo"><?php echo esc_html__( 'Test repository access', 'elementor-json-bridge' ); ?></button>
				</form>
			</section>

			<section class="ejb-card">
				<h2><?php echo esc_html__( '3. Managed WordPress content', 'elementor-json-bridge' ); ?></h2>
				<p><?php echo esc_html__( 'The table is informational. Content is managed automatically. Exclude is only an emergency opt-out for an individual item.', 'elementor-json-bridge' ); ?></p>
				<?php $this->document_table(); ?>
			</section>
		</div>
		<?php
	}

	private function document_table(): void {
		$ids = get_posts(
			[
				'post_type'      => $this->content->post_types(),
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'No managed WordPress content was found.', 'elementor-json-bridge' ) . '</p>';
			return;
		}
		?>
		<div class="ejb-table-wrap">
		<table class="widefat striped ejb-table">
			<thead><tr><th scope="col"><?php echo esc_html__( 'Content', 'elementor-json-bridge' ); ?></th><th scope="col"><?php echo esc_html__( 'Type', 'elementor-json-bridge' ); ?></th><th scope="col"><?php echo esc_html__( 'Sync', 'elementor-json-bridge' ); ?></th><th scope="col"><?php echo esc_html__( 'GitHub path', 'elementor-json-bridge' ); ?></th><th scope="col"><?php echo esc_html__( 'Actions', 'elementor-json-bridge' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $ids as $id ) : ?>
				<?php
				$id = (int) $id;
				if ( ! $this->content->supports( $id ) || ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				$post       = get_post( $id );
				$enabled    = $this->sync->is_enabled( $id );
				$status     = $this->sync->status( $id );
				$last_error = (string) get_post_meta( $id, State::META_LAST_ERROR, true );
				$snapshot   = $this->snapshots->latest_id( $id );
				?>
				<tr>
					<td><strong><?php echo esc_html( $post ? $post->post_title : '#' . $id ); ?></strong><br><code>#<?php echo esc_html( (string) $id ); ?></code></td>
					<td><?php echo esc_html( $post ? $post->post_type : '' ); ?></td>
					<td><code><?php echo esc_html( $enabled ? $status : 'excluded' ); ?></code><?php if ( $last_error ) : ?><br><span class="ejb-error"><?php echo esc_html( $last_error ); ?></span><?php endif; ?></td>
					<td><code><?php echo esc_html( $this->sync->path_for( $id ) ); ?></code></td>
					<td class="ejb-actions">
						<button type="button" class="button ejb-doc-action" data-action="toggle" data-id="<?php echo esc_attr( (string) $id ); ?>"><?php echo $enabled ? esc_html__( 'Exclude', 'elementor-json-bridge' ) : esc_html__( 'Include', 'elementor-json-bridge' ); ?></button>
						<?php if ( $enabled ) : ?>
							<button type="button" class="button ejb-doc-action" data-action="export" data-id="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html__( 'Export now', 'elementor-json-bridge' ); ?></button>
							<button type="button" class="button ejb-doc-action" data-action="check" data-id="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html__( 'Check GitHub', 'elementor-json-bridge' ); ?></button>
							<?php if ( State::REMOTE_PENDING === $status ) : ?><button type="button" class="button button-primary ejb-doc-action" data-action="apply" data-id="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html__( 'Apply GitHub', 'elementor-json-bridge' ); ?></button><?php endif; ?>
						<?php endif; ?>
						<button type="button" class="button ejb-doc-action" data-action="reset" data-id="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html__( 'Reset base', 'elementor-json-bridge' ); ?></button>
						<?php if ( $snapshot > 0 ) : ?><button type="button" class="button ejb-restore" data-id="<?php echo esc_attr( (string) $id ); ?>" data-snapshot="<?php echo esc_attr( (string) $snapshot ); ?>"><?php echo esc_html__( 'Restore latest', 'elementor-json-bridge' ); ?></button><?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}
}
