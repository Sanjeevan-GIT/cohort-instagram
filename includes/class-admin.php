<?php
/**
 * Settings → Instagram Feed admin UI.
 *
 * @package Cohort_Instagram
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and form handlers.
 */
class Cohort_Instagram_Admin {

	const PAGE_SLUG = 'cohort-instagram';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
		add_action( 'admin_init', array( 'Cohort_Instagram_OAuth', 'maybe_handle_callback' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'cohort-instagram-admin',
			plugins_url( 'assets/admin.js', COHORT_INSTAGRAM_FILE ),
			array( 'jquery' ),
			COHORT_INSTAGRAM_VERSION,
			true
		);
	}

	public static function register_menu() {
		add_options_page(
			__( 'Instagram Feed', 'cohort-instagram' ),
			__( 'Instagram Feed', 'cohort-instagram' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Process settings / connect / disconnect / refresh / fallbacks.
	 */
	public static function handle_post() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_POST['cohort_instagram_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( 'cohort_instagram_admin' );

		$action = sanitize_key( wp_unslash( (string) $_POST['cohort_instagram_action'] ) );

		switch ( $action ) {
			case 'save_credentials':
				self::save_credentials();
				break;
			case 'save_fallbacks':
				self::save_fallbacks();
				break;
			case 'disconnect':
				Cohort_Instagram_Settings::disconnect();
				self::flash( 'success', __( 'Instagram disconnected. Cached media cleared.', 'cohort-instagram' ) );
				break;
			case 'refresh_media':
				$result = Cohort_Instagram_API::refresh_media_cache();
				if ( is_wp_error( $result ) ) {
					self::flash( 'error', $result->get_error_message() );
				} else {
					self::flash( 'success', __( 'Media cache refreshed.', 'cohort-instagram' ) );
				}
				break;
			case 'refresh_token':
				$result = Cohort_Instagram_API::refresh_token();
				if ( is_wp_error( $result ) ) {
					self::flash( 'error', $result->get_error_message() );
				} else {
					self::flash( 'success', __( 'Access token refreshed.', 'cohort-instagram' ) );
				}
				break;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	private static function save_credentials() {
		$app_id = isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['app_id'] ) ) : '';
		$limit  = isset( $_POST['media_limit'] ) ? (int) $_POST['media_limit'] : 6;

		if ( ! defined( 'COHORT_INSTAGRAM_APP_ID' ) || ! COHORT_INSTAGRAM_APP_ID ) {
			Cohort_Instagram_Settings::update_settings( array( 'app_id' => $app_id ) );
		}

		Cohort_Instagram_Settings::update_settings( array( 'media_limit' => $limit ) );

		if ( ! defined( 'COHORT_INSTAGRAM_APP_SECRET' ) || ! COHORT_INSTAGRAM_APP_SECRET ) {
			$secret = isset( $_POST['app_secret'] ) ? (string) wp_unslash( $_POST['app_secret'] ) : '';
			$secret = trim( $secret );
			if ( '' !== $secret ) {
				Cohort_Instagram_Settings::update_secrets( array( 'app_secret' => $secret ) );
			}
		}

		self::flash( 'success', __( 'Settings saved.', 'cohort-instagram' ) );
	}

	private static function save_fallbacks() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below.
		$raw = isset( $_POST['fallback'] ) && is_array( $_POST['fallback'] ) ? wp_unslash( $_POST['fallback'] ) : array();
		$rows = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'attachment_id' => isset( $row['attachment_id'] ) ? absint( $row['attachment_id'] ) : 0,
				'overlay_text'  => isset( $row['overlay_text'] ) ? sanitize_text_field( (string) $row['overlay_text'] ) : '',
				'link'          => isset( $row['link'] ) ? esc_url_raw( (string) $row['link'] ) : '',
			);
		}

		Cohort_Instagram_Fallbacks::save( $rows );
		self::flash( 'success', __( 'Fallback images saved.', 'cohort-instagram' ) );
	}

	/**
	 * @param string $type success|error.
	 * @param string $message Message.
	 */
	private static function flash( $type, $message ) {
		set_transient(
			'cohort_instagram_admin_notice_' . get_current_user_id(),
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => sanitize_text_field( $message ),
			),
			120
		);
	}

	public static function render_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'settings_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		$notice = get_transient( 'cohort_instagram_admin_notice_' . get_current_user_id() );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( 'cohort_instagram_admin_notice_' . get_current_user_id() );

		$class = ( isset( $notice['type'] ) && 'success' === $notice['type'] ) ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( (string) $notice['message'] )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings         = Cohort_Instagram_Settings::get_settings();
		$connected        = Cohort_Instagram_Settings::is_connected();
		$cache            = Cohort_Instagram_Cache::get();
		$redirect_uri     = Cohort_Instagram_Settings::get_redirect_uri();
		$app_id_locked    = defined( 'COHORT_INSTAGRAM_APP_ID' ) && COHORT_INSTAGRAM_APP_ID;
		$secret_locked    = defined( 'COHORT_INSTAGRAM_APP_SECRET' ) && COHORT_INSTAGRAM_APP_SECRET;
		$has_secret       = '' !== Cohort_Instagram_Settings::get_app_secret();
		$authorize        = Cohort_Instagram_OAuth::get_authorize_url();
		$fallbacks        = Cohort_Instagram_Fallbacks::get_raw();
		$front_source     = ( $connected && ! empty( Cohort_Instagram_Cache::get_media( 1 ) ) ) ? 'feed' : 'fallback';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Instagram Feed', 'cohort-instagram' ); ?></h1>
			<p>
				<?php
				echo esc_html(
					'feed' === $front_source
						? __( 'Front end is currently using the live Instagram cache.', 'cohort-instagram' )
						: __( 'Front end is currently using fallback images (no usable live feed).', 'cohort-instagram' )
				);
				?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'cohort_instagram_admin' ); ?>
				<input type="hidden" name="cohort_instagram_action" value="save_credentials" />

				<h2><?php esc_html_e( 'API credentials', 'cohort-instagram' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cohort-ig-app-id"><?php esc_html_e( 'App ID', 'cohort-instagram' ); ?></label></th>
						<td>
							<?php if ( $app_id_locked ) : ?>
								<code><?php echo esc_html( Cohort_Instagram_Settings::get_app_id() ); ?></code>
								<p class="description"><?php esc_html_e( 'Loaded from COHORT_INSTAGRAM_APP_ID in wp-config.php.', 'cohort-instagram' ); ?></p>
							<?php else : ?>
								<input type="text" class="regular-text" id="cohort-ig-app-id" name="app_id" value="<?php echo esc_attr( $settings['app_id'] ); ?>" autocomplete="off" />
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cohort-ig-app-secret"><?php esc_html_e( 'App Secret', 'cohort-instagram' ); ?></label></th>
						<td>
							<?php if ( $secret_locked ) : ?>
								<p class="description"><?php esc_html_e( 'Loaded from COHORT_INSTAGRAM_APP_SECRET in wp-config.php (not shown).', 'cohort-instagram' ); ?></p>
							<?php else : ?>
								<input type="password" class="regular-text" id="cohort-ig-app-secret" name="app_secret" value="" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'cohort-instagram' ) : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Stored encrypted. Prefer defining COHORT_INSTAGRAM_APP_SECRET in wp-config.php on production.', 'cohort-instagram' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cohort-ig-limit"><?php esc_html_e( 'Posts to fetch', 'cohort-instagram' ); ?></label></th>
						<td>
							<input type="number" id="cohort-ig-limit" name="media_limit" min="1" max="12" value="<?php echo esc_attr( (string) $settings['media_limit'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'OAuth redirect URI', 'cohort-instagram' ); ?></th>
						<td>
							<code style="user-select:all;"><?php echo esc_html( $redirect_uri ); ?></code>
							<p class="description"><?php esc_html_e( 'Paste this exact URI into your Meta app → Instagram → Business login → OAuth redirect URIs. Remove any old options-general.php redirect URI first. Press Enter after pasting so it saves as a chip, then Save.', 'cohort-instagram' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'cohort-instagram' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Connection', 'cohort-instagram' ); ?></h2>
			<?php if ( $connected ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: Instagram username */
						esc_html__( 'Connected as @%s', 'cohort-instagram' ),
						esc_html( $settings['username'] ? $settings['username'] : 'instagram' )
					);
					?>
				</p>
				<?php if ( ! empty( $settings['token_expires_at'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: localized date */
							esc_html__( 'Token expires: %s', 'cohort-instagram' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $settings['token_expires_at'] ) )
						);
						?>
					</p>
				<?php endif; ?>

				<form method="post" action="" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( 'cohort_instagram_admin' ); ?>
					<input type="hidden" name="cohort_instagram_action" value="refresh_media" />
					<?php submit_button( __( 'Refresh media now', 'cohort-instagram' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( 'cohort_instagram_admin' ); ?>
					<input type="hidden" name="cohort_instagram_action" value="refresh_token" />
					<?php submit_button( __( 'Refresh token', 'cohort-instagram' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect Instagram and clear the media cache?', 'cohort-instagram' ) ); ?>');">
					<?php wp_nonce_field( 'cohort_instagram_admin' ); ?>
					<input type="hidden" name="cohort_instagram_action" value="disconnect" />
					<?php submit_button( __( 'Disconnect', 'cohort-instagram' ), 'delete', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Not connected. Save credentials, then connect with a Business/Creator account.', 'cohort-instagram' ); ?></p>
				<?php if ( is_wp_error( $authorize ) ) : ?>
					<p class="description"><?php echo esc_html( $authorize->get_error_message() ); ?></p>
				<?php else : ?>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $authorize ); ?>">
							<?php esc_html_e( 'Connect Instagram', 'cohort-instagram' ); ?>
						</a>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Cache status', 'cohort-instagram' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Media refreshes automatically about once an hour (WP-Cron). Use the button below only if you need an immediate update.', 'cohort-instagram' ); ?></p>
			<p>
				<?php
				printf(
					/* translators: 1: item count, 2: date or em dash */
					esc_html__( '%1$d cached posts. Last updated: %2$s', 'cohort-instagram' ),
					(int) count( $cache['items'] ),
					$cache['updated_at']
						? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cache['updated_at'] ) )
						: '—'
				);
				?>
			</p>
			<?php if ( ! empty( $cache['error'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $cache['error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $cache['items'] ) ) : ?>
				<ul style="display:flex;flex-wrap:wrap;gap:8px;list-style:none;margin:12px 0;padding:0;">
					<?php foreach ( array_slice( $cache['items'], 0, 6 ) as $item ) : ?>
						<li style="width:80px;height:80px;overflow:hidden;background:#111;">
							<?php if ( ! empty( $item['url'] ) ) : ?>
								<img src="<?php echo esc_url( $item['url'] ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;" loading="lazy" width="80" height="80" />
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Fallback images', 'cohort-instagram' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Used by the theme when Instagram is disconnected or the live cache is empty. Upload square images from the Media Library. Drag order is top → first slide.', 'cohort-instagram' ); ?>
			</p>

			<form method="post" action="" id="cohort-ig-fallbacks-form">
				<?php wp_nonce_field( 'cohort_instagram_admin' ); ?>
				<input type="hidden" name="cohort_instagram_action" value="save_fallbacks" />

				<table class="widefat striped" id="cohort-ig-fallbacks-table" style="max-width:960px;">
					<thead>
						<tr>
							<th style="width:120px;"><?php esc_html_e( 'Image', 'cohort-instagram' ); ?></th>
							<th><?php esc_html_e( 'Overlay text', 'cohort-instagram' ); ?></th>
							<th><?php esc_html_e( 'Link', 'cohort-instagram' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Remove', 'cohort-instagram' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						if ( empty( $fallbacks ) ) {
							$fallbacks = array(
								array(
									'attachment_id' => 0,
									'overlay_text'  => '',
									'link'          => '',
								),
							);
						}
						foreach ( $fallbacks as $i => $row ) :
							$thumb = $row['attachment_id'] ? wp_get_attachment_image_url( (int) $row['attachment_id'], 'thumbnail' ) : '';
							?>
						<tr class="cohort-ig-fallback-row">
							<td>
								<input type="hidden" class="cohort-ig-attachment-id" name="fallback[<?php echo (int) $i; ?>][attachment_id]" value="<?php echo esc_attr( (string) (int) $row['attachment_id'] ); ?>" />
								<div class="cohort-ig-preview" style="width:72px;height:72px;background:#f0f0f1;margin-bottom:6px;overflow:hidden;">
									<?php if ( $thumb ) : ?>
										<img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;" />
									<?php endif; ?>
								</div>
								<button type="button" class="button cohort-ig-select-image"><?php esc_html_e( 'Select', 'cohort-instagram' ); ?></button>
							</td>
							<td>
								<input type="text" class="regular-text" name="fallback[<?php echo (int) $i; ?>][overlay_text]" value="<?php echo esc_attr( $row['overlay_text'] ); ?>" />
							</td>
							<td>
								<input type="url" class="regular-text" name="fallback[<?php echo (int) $i; ?>][link]" value="<?php echo esc_attr( $row['link'] ); ?>" placeholder="https://" />
							</td>
							<td>
								<button type="button" class="button-link-delete cohort-ig-remove-row"><?php esc_html_e( 'Remove', 'cohort-instagram' ); ?></button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin-top:12px;">
					<button type="button" class="button" id="cohort-ig-add-row"><?php esc_html_e( 'Add image', 'cohort-instagram' ); ?></button>
					<?php submit_button( __( 'Save fallback images', 'cohort-instagram' ), 'primary', 'submit', false ); ?>
				</p>
			</form>

			<template id="cohort-ig-fallback-row-tpl">
				<tr class="cohort-ig-fallback-row">
					<td>
						<input type="hidden" class="cohort-ig-attachment-id" name="fallback[__i__][attachment_id]" value="0" />
						<div class="cohort-ig-preview" style="width:72px;height:72px;background:#f0f0f1;margin-bottom:6px;overflow:hidden;"></div>
						<button type="button" class="button cohort-ig-select-image"><?php esc_html_e( 'Select', 'cohort-instagram' ); ?></button>
					</td>
					<td>
						<input type="text" class="regular-text" name="fallback[__i__][overlay_text]" value="" />
					</td>
					<td>
						<input type="url" class="regular-text" name="fallback[__i__][link]" value="" placeholder="https://" />
					</td>
					<td>
						<button type="button" class="button-link-delete cohort-ig-remove-row"><?php esc_html_e( 'Remove', 'cohort-instagram' ); ?></button>
					</td>
				</tr>
			</template>

			<hr />

			<h2><?php esc_html_e( 'Production tips', 'cohort-instagram' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Instagram account must be Business or Creator (not Personal).', 'cohort-instagram' ); ?></li>
				<li><?php esc_html_e( 'Create a Meta app with Instagram API product; add the redirect URI above.', 'cohort-instagram' ); ?></li>
				<li>
					<code>define( 'COHORT_INSTAGRAM_APP_ID', '…' );</code><br />
					<code>define( 'COHORT_INSTAGRAM_APP_SECRET', '…' );</code><br />
					<code>define( 'COHORT_INSTAGRAM_ENCRYPTION_KEY', 'long-random-string' );</code>
				</li>
				<li><?php esc_html_e( 'OAuth redirect URI must be exactly admin-post.php (no query string). Update Meta if you previously saved an options-general.php URL.', 'cohort-instagram' ); ?></li>
				<li><?php esc_html_e( 'OAuth requires a publicly reachable HTTPS URL on production. Local WAMP may need a tunnel (ngrok) for the Connect step.', 'cohort-instagram' ); ?></li>
			</ol>
		</div>
		<?php
	}
}
