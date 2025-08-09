<?php
/**
 * Plugin Name: AI Alt Text Pro
 * Plugin URI:  https://example.com/ai-alt-text-pro
 * Description: Automatically generates image alt text using AI. Free plan has a monthly limit; add a license for unlimited & multi-language support.
 * Version:     1.1.0
 * Author:      ChatGPT
 * License:     GPL-2.0-or-later
 * Text Domain: ai-alt-text-pro
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AI_ATP_VERSION' ) ) {
	define( 'AI_ATP_VERSION', '1.1.0' );
}

final class AI_Alt_Text_Pro {

	const OPTION_API_KEY    = 'ai_atp_api_key';
	const OPTION_LICENSE    = 'ai_atp_license_key';
	const OPTION_FREE_COUNT = 'ai_atp_free_count';

	const FREE_LIMIT        = 10;       // Free version monthly quota
	const MAX_FILESIZE_MB   = 15;       // Safety guard on huge uploads
	const ALT_MAX_LEN       = 160;      // Sensible alt text ceiling

	private static $instance = null;

	public static function instance() : self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// i18n
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );

		// Settings
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );

		// Assets
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Core features
		add_action( 'add_attachment', [ $this, 'maybe_generate_alt_text' ] );

		// Notices & scheduling
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		add_filter( 'cron_schedules', [ $this, 'add_monthly_schedule' ] );
		add_action( 'ai_atp_reset_count', [ $this, 'reset_free_count' ] );

		// Includes
		$this->include_files();

		// Lifecycle
		register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
		register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );
	}

	private function include_files() : void {
		$base = plugin_dir_path( __FILE__ ) . 'includes/';

		// Bulk action in Media Library
		require_once $base . 'class-ai-atp-bulk.php';
		AI_ATP_Bulk::instance( $this );

		// REST endpoint for on-demand regeneration
		require_once $base . 'class-ai-atp-rest.php';
		AI_ATP_REST::instance( $this );
	}

	/** ======================================================================
	 * i18n
	 * ====================================================================== */
	public function load_textdomain() : void {
		load_plugin_textdomain( 'ai-alt-text-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/** ======================================================================
	 * Settings (Settings API)
	 * ====================================================================== */
	public function register_settings() : void {
		register_setting(
			'ai_atp_settings',
			self::OPTION_API_KEY,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_token' ],
				'default'           => '',
			]
		);

		register_setting(
			'ai_atp_settings',
			self::OPTION_LICENSE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_token' ],
				'default'           => '',
			]
		);

		// Usage counter option
		if ( false === get_option( self::OPTION_FREE_COUNT, false ) ) {
			add_option( self::OPTION_FREE_COUNT, 0, '', false );
		}
	}

	public function sanitize_token( $value ) : string {
		$value = trim( wp_strip_all_tags( (string) $value ) );
		return preg_replace( '/[^A-Za-z0-9_\-\.\:\|~]/', '', $value );
	}

	public function register_settings_page() : void {
		add_options_page(
			__( 'AI Alt Text', 'ai-alt-text-pro' ),
			__( 'AI Alt Text', 'ai-alt-text-pro' ),
			'manage_options',
			'ai-alt-text-pro',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api_key = get_option( self::OPTION_API_KEY, '' );
		$license = get_option( self::OPTION_LICENSE, '' );
		$count   = (int) get_option( self::OPTION_FREE_COUNT, 0 );

		$limit   = self::FREE_LIMIT;
		$used    = max( 0, $count );
		$pct     = $limit > 0 ? min( 100, max( 0, (int) round( ( $used / $limit ) * 100 ) ) ) : 0;
		$state   = $pct >= 100 ? 'is-danger' : ( $pct >= 70 ? 'is-warning' : '' );
		?>
		<div class="wrap ai-atp-wrap">
			<h1>
				<span class="dashicons dashicons-format-image ai-atp-logo" aria-hidden="true"></span>
				<?php esc_html_e( 'AI Alt Text Settings', 'ai-alt-text-pro' ); ?>
			</h1>

			<div class="ai-atp-card">
				<form method="post" action="options.php">
					<?php settings_fields( 'ai_atp_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ai_atp_api_key"><?php esc_html_e( 'API Key', 'ai-alt-text-pro' ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" type="text" id="ai_atp_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Your AI provider token.', 'ai-alt-text-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ai_atp_license_key"><?php esc_html_e( 'License Key (Premium)', 'ai-alt-text-pro' ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( self::OPTION_LICENSE ); ?>" type="text" id="ai_atp_license_key" value="<?php echo esc_attr( $license ); ?>" class="regular-text" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Unlocks unlimited generation & multi-language support.', 'ai-alt-text-pro' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Settings', 'ai-alt-text-pro' ) ); ?>
				</form>

				<p class="ai-atp-description">
					<?php
					printf(
						/* translators: 1: used count 2: quota */
						esc_html__( 'Free plan allows %2$d generations per month. You have used %1$d of %2$d.', 'ai-alt-text-pro' ),
						$used,
						$limit
					);
					?>
				</p>

				<div class="ai-atp-quota <?php echo esc_attr( $state ); ?>">
					<div class="ai-atp-quota-row">
						<div
							class="ai-atp-bar"
							role="progressbar"
							aria-valuenow="<?php echo esc_attr( $pct ); ?>"
							aria-valuemin="0"
							aria-valuemax="100"
							aria-label="<?php echo esc_attr__( 'Free quota usage', 'ai-alt-text-pro' ); ?>"
						>
							<div class="ai-atp-fill" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
						</div>
						<div class="ai-atp-label">
							<?php
							printf(
								/* translators: 1: used count 2: quota */
								esc_html__( '%1$d / %2$d used', 'ai-alt-text-pro' ),
								$used,
								$limit
							);
							?>
						</div>
					</div>
				</div>

				<?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="ai-atp-inline-notice ai-atp-inline--success">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Settings saved successfully.', 'ai-alt-text-pro' ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( empty( $api_key ) ) : ?>
					<div class="ai-atp-inline-notice ai-atp-inline--info">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Enter your API key to enable automatic alt text generation on upload.', 'ai-alt-text-pro' ); ?></span>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function plugin_action_links( array $links ) : array {
		$url    = admin_url( 'options-general.php?page=ai-alt-text-pro' );
		$custom = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'ai-alt-text-pro' ) . '</a>';
		array_unshift( $links, $custom );
		return $links;
	}

	/** ======================================================================
	 * Assets
	 * ====================================================================== */
	public function enqueue_assets( string $hook_suffix ) : void {
		if ( 'settings_page_ai-alt-text-pro' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'ai-atp-admin',
			plugin_dir_url( __FILE__ ) . 'assets/admin.css',
			[],
			AI_ATP_VERSION
		);
		wp_enqueue_style( 'dashicons' );
	}

	/** ======================================================================
	 * Public helper: generate for an attachment id (used by upload/bulk/REST)
	 * ====================================================================== */
	public function generate_for_attachment( int $attachment_id, bool $force = false ) : bool {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return false;
		}

		// Skip if alt exists unless forcing regeneration
		$current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( ! $force && ! empty( $current_alt ) ) {
			return true; // treat as success (nothing to do)
		}

		$img_info = @getimagesize( $file_path );
		if ( false === $img_info || empty( $img_info['mime'] ) || stripos( $img_info['mime'], 'image/' ) !== 0 ) {
			return false;
		}

		$filesize_mb = filesize( $file_path ) / ( 1024 * 1024 );
		if ( $filesize_mb > self::MAX_FILESIZE_MB ) {
			return false;
		}

		$license     = get_option( self::OPTION_LICENSE, '' );
		$has_license = ! empty( $license );
		$count       = (int) get_option( self::OPTION_FREE_COUNT, 0 );

		if ( ! $has_license && $count >= self::FREE_LIMIT ) {
			return false;
		}

		$generated = $this->generate_alt_text( $file_path, $img_info['mime'], $has_license ? get_locale() : '' );
		if ( ! $generated ) {
			return false;
		}

		$generated = $this->clean_alt_text( $generated );
		if ( '' === $generated ) {
			return false;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $generated );

		if ( ! $has_license ) {
			update_option( self::OPTION_FREE_COUNT, min( $count + 1, PHP_INT_MAX ) );
		}

		return true;
	}

	/** ======================================================================
	 * Upload hook: generate alt when new image is added
	 * ====================================================================== */
	public function maybe_generate_alt_text( int $attachment_id ) : void {
		// Only on direct upload path — generation logic handled in helper.
		$this->generate_for_attachment( $attachment_id, false );
	}

	private function clean_alt_text( string $text ) : string {
		$text = wp_strip_all_tags( $text, true );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		if ( strlen( $text ) > self::ALT_MAX_LEN ) {
			$text = mb_substr( $text, 0, self::ALT_MAX_LEN - 1 ) . '…';
		}
		return $text;
	}

	private function generate_alt_text( string $file_path, string $mime, string $locale = '' ) {
		$api_key = get_option( self::OPTION_API_KEY, '' );
		if ( empty( $api_key ) ) {
			return false;
		}

		$contents = @file_get_contents( $file_path );
		if ( false === $contents ) {
			return false;
		}

		$body = [
			'image_b64' => base64_encode( $contents ),
			'mime'      => $mime,
		];

		if ( ! empty( $locale ) ) {
			$body['locale'] = $locale;
		}

		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'ai-alt-text-pro/' . AI_ATP_VERSION,
			],
			'body'        => wp_json_encode( $body ),
			'timeout'     => 25,
			'redirection' => 3,
		];

		$response = wp_safe_remote_post( 'https://api.example.com/generate-alt', $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'AI Alt Text API error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'AI Alt Text API HTTP ' . $code . ' body: ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $data['alt_text'] ) && is_string( $data['alt_text'] ) ) {
			return $data['alt_text'];
		}

		return false;
	}

	/** ======================================================================
	 * Cron & Notices
	 * ====================================================================== */
	public function add_monthly_schedule( array $schedules ) : array {
		if ( ! isset( $schedules['ai_atp_monthly'] ) ) {
			$schedules['ai_atp_monthly'] = [
				'interval' => MONTH_IN_SECONDS,
				'display'  => __( 'Once Monthly (AI Alt Text Pro)', 'ai-alt-text-pro' ),
			];
		}
		return $schedules;
	}

	public function reset_free_count() : void {
		update_option( self::OPTION_FREE_COUNT, 0 );
	}

	public function admin_notice() : void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$count   = (int) get_option( self::OPTION_FREE_COUNT, 0 );
		$license = get_option( self::OPTION_LICENSE, '' );

		if ( empty( $license ) && $count >= self::FREE_LIMIT ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'AI Alt Text Pro: free monthly limit reached. Enter a license key for unlimited usage.', 'ai-alt-text-pro' ) .
			'</p></div>';
		}
	}

	/** ======================================================================
	 * Lifecycle
	 * ====================================================================== */
	public static function activate() : void {
		if ( false === get_option( self::OPTION_FREE_COUNT, false ) ) {
			add_option( self::OPTION_FREE_COUNT, 0, '', false );
		}

		if ( ! wp_next_scheduled( 'ai_atp_reset_count' ) ) {
			wp_schedule_event( time(), 'ai_atp_monthly', 'ai_atp_reset_count' );
		}
	}

	public static function deactivate() : void {
		$timestamp = wp_next_scheduled( 'ai_atp_reset_count' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ai_atp_reset_count' );
		}
	}
}

// Bootstrap
AI_Alt_Text_Pro::instance();
