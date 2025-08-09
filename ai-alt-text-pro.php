<?php
/**
 * Plugin Name: AI Alt Text Pro
 * Plugin URI:  https://example.com/ai-alt-text-pro
 * Description: Automatically generates image alt text using AI. Free plan includes a monthly limit; enter a license key to unlock unlimited & multi-language support.
 * Version:     1.1.0
 * Author:      ChatGPT
 * License:     GPL-2.0-or-later
 * Text Domain: ai-alt-text-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Alt_Text_Pro {

	const OPTION_API_KEY    = 'ai_atp_api_key';
	const OPTION_LICENSE    = 'ai_atp_license_key';
	const OPTION_FREE_COUNT = 'ai_atp_free_count';

	const FREE_LIMIT        = 10;       // Free version monthly quota
	const MAX_FILESIZE_MB   = 15;       // Safety guard on huge uploads
	const ALT_MAX_LEN       = 160;      // A sensible alt text ceiling

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

		// Lifecycle
		register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
		register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );
		register_uninstall_hook( __FILE__, [ __CLASS__, 'uninstall' ] );
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

		// We keep count as an integer option; no settings field for it.
		if ( false === get_option( self::OPTION_FREE_COUNT, false ) ) {
			add_option( self::OPTION_FREE_COUNT, 0, '', false );
		}
	}

	public function sanitize_token( $value ) : string {
		// Trim, strip tags, allow only safe token chars
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
		?>
		<div class="wrap ai-atp-wrap">
			<h1>
				<span class="dashicons dashicons-format-image ai-atp-logo" aria-hidden="true"></span>
				<?php esc_html_e( 'AI Alt Text Settings', 'ai-alt-text-pro' ); ?>
			</h1>

			<div class="ai-atp-card">
				<form method="post" action="options.php">
					<?php
					settings_fields( 'ai_atp_settings' );
					?>
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
						$count,
						self::FREE_LIMIT
					);
					?>
				</p>
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
		// Load only on our settings page
		if ( 'settings_page_ai-alt-text-pro' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'ai-atp-admin',
			plugin_dir_url( __FILE__ ) . 'assets/admin.css',
			[],
			'1.1.0'
		);
		wp_enqueue_style( 'dashicons' );
	}

	/** ======================================================================
	 * Core: Generate alt text on upload
	 * ====================================================================== */
	public function maybe_generate_alt_text( int $attachment_id ) : void {
		// Only for logged-in users who can upload files
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// Must be an image attachment
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		// Skip if alt already present
		$current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( ! empty( $current_alt ) ) {
			return;
		}

		// Mime/type validation + size guard
		$file_path = get_attached_file( $attachment_id );
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return;
		}

		$img_info = @getimagesize( $file_path );
		if ( false === $img_info || empty( $img_info['mime'] ) || stripos( $img_info['mime'], 'image/' ) !== 0 ) {
			return;
		}

		$filesize_mb = filesize( $file_path ) / ( 1024 * 1024 );
		if ( $filesize_mb > self::MAX_FILESIZE_MB ) {
			// Too big — avoid blocking requests or timeouts
			return;
		}

		$license     = get_option( self::OPTION_LICENSE, '' );
		$has_license = ! empty( $license );
		$count       = (int) get_option( self::OPTION_FREE_COUNT, 0 );

		if ( ! $has_license && $count >= self::FREE_LIMIT ) {
			// Quota reached
			return;
		}

		// Generate (blocking call). For scale, consider scheduling via cron/queue.
		$generated = $this->generate_alt_text( $file_path, $img_info['mime'], $has_license ? get_locale() : '' );

		if ( ! $generated ) {
			return;
		}

		$generated = $this->clean_alt_text( $generated );

		if ( '' === $generated ) {
			return;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $generated );

		if ( ! $has_license ) {
			update_option( self::OPTION_FREE_COUNT, min( $count + 1, PHP_INT_MAX ) );
		}
	}

	private function clean_alt_text( string $text ) : string {
		$text = wp_strip_all_tags( $text, true );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		// Keep it short and useful
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

		// Read and encode
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
				'User-Agent'    => 'ai-alt-text-pro/' . ( defined( 'AI_ATP_VERSION' ) ? AI_ATP_VERSION : '1.1.0' ),
			],
			'body'      => wp_json_encode( $body ),
			'timeout'   => 25,
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
		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

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
		// Ensure options exist
		if ( false === get_option( self::OPTION_FREE_COUNT, false ) ) {
			add_option( self::OPTION_FREE_COUNT, 0, '', false );
		}

		if ( ! wp_next_scheduled( 'ai_atp_reset_count' ) ) {
			// Schedule first reset roughly one month from now
			wp_schedule_event( time(), 'ai_atp_monthly', 'ai_atp_reset_count' );
		}
	}

	public static function deactivate() : void {
		$timestamp = wp_next_scheduled( 'ai_atp_reset_count' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ai_atp_reset_count' );
		}
	}

	public static function uninstall() : void {
		// Remove options on uninstall (optional). Comment these out if you prefer persistence.
		delete_option( self::OPTION_API_KEY );
		delete_option( self::OPTION_LICENSE );
		delete_option( self::OPTION_FREE_COUNT );
	}
}

// Bootstrap
AI_Alt_Text_Pro::instance();

