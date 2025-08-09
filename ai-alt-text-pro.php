diff --git a//dev/null b/wordpress/ai-alt-text-pro/ai-alt-text-pro.php
index 0000000000000000000000000000000000000000..ad8ca7a5ea003f51ba72f2278f3745c0d87da0aa 100644
--- a//dev/null
+++ b/wordpress/ai-alt-text-pro/ai-alt-text-pro.php
@@ -0,0 +1,235 @@
+<?php
+/**
+ * Plugin Name: AI Alt Text Pro
+ * Plugin URI: https://example.com/ai-alt-text-pro
+ * Description: Automatically generates image alt text using AI. Includes premium features like unlimited generation and multi-language support.
+ * Version: 1.0.0
+ * Author: ChatGPT
+ * License: GPL2
+ * Text Domain: ai-alt-text-pro
+ */
+
+if ( ! defined( 'ABSPATH' ) ) {
+    exit; // Exit if accessed directly
+}
+
+class AI_Alt_Text_Pro {
+    const OPTION_API_KEY = 'ai_atp_api_key';
+    const OPTION_LICENSE = 'ai_atp_license_key';
+    const OPTION_FREE_COUNT = 'ai_atp_free_count';
+    const FREE_LIMIT = 10; // free version limit
+
+    public function __construct() {
+        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
+        add_action( 'add_attachment', array( $this, 'maybe_generate_alt_text' ) );
+        add_action( 'admin_notices', array( $this, 'admin_notice' ) );
+        add_action( 'ai_atp_reset_count', array( $this, 'reset_free_count' ) );
+        add_filter( 'cron_schedules', array( $this, 'add_monthly_schedule' ) );
+        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
+        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
+    }
+
+    /**
+     * Load plugin textdomain for translations.
+     */
+    public function load_textdomain() {
+        load_plugin_textdomain( 'ai-alt-text-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
+    }
+
+    public function register_settings_page() {
+        add_options_page(
+            __( 'AI Alt Text', 'ai-alt-text-pro' ),
+            __( 'AI Alt Text', 'ai-alt-text-pro' ),
+            'manage_options',
+            'ai-alt-text-pro',
+            array( $this, 'render_settings_page' )
+        );
+    }
+
+    public function render_settings_page() {
+        if ( ! current_user_can( 'manage_options' ) ) {
+            return;
+        }
+
+        if ( isset( $_POST['ai_atp_save_settings'] ) && check_admin_referer( 'ai_atp_save' ) ) {
+            update_option( self::OPTION_API_KEY, sanitize_text_field( $_POST['ai_atp_api_key'] ) );
+            update_option( self::OPTION_LICENSE, sanitize_text_field( $_POST['ai_atp_license_key'] ) );
+            echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'ai-alt-text-pro' ) . '</p></div>';
+        }
+
+        $api_key  = get_option( self::OPTION_API_KEY );
+        $license  = get_option( self::OPTION_LICENSE );
+        $count    = (int) get_option( self::OPTION_FREE_COUNT, 0 );
+        ?>
+        <div class="wrap ai-atp-wrap">
+            <h1><span class="dashicons dashicons-format-image ai-atp-logo"></span><?php echo esc_html__( 'AI Alt Text Settings', 'ai-alt-text-pro' ); ?></h1>
+            <div class="ai-atp-card">
+                <form method="post" action="">
+                    <?php wp_nonce_field( 'ai_atp_save' ); ?>
+                    <table class="form-table">
+                        <tr>
+                            <th scope="row"><label for="ai_atp_api_key"><?php esc_html_e( 'API Key', 'ai-alt-text-pro' ); ?></label></th>
+                            <td><input name="ai_atp_api_key" type="text" id="ai_atp_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" /></td>
+                        </tr>
+                        <tr>
+                            <th scope="row"><label for="ai_atp_license_key"><?php esc_html_e( 'License Key (Premium)', 'ai-alt-text-pro' ); ?></label></th>
+                            <td><input name="ai_atp_license_key" type="text" id="ai_atp_license_key" value="<?php echo esc_attr( $license ); ?>" class="regular-text" /></td>
+                        </tr>
+                    </table>
+                    <?php submit_button( __( 'Save Settings', 'ai-alt-text-pro' ), 'primary', 'ai_atp_save_settings' ); ?>
+                </form>
+                <p class="ai-atp-description"><?php esc_html_e( 'Free plan allows 10 alt text generations per month. Enter a license key to unlock unlimited and multi-language features.', 'ai-alt-text-pro' ); ?></p>
+                <p class="ai-atp-description"><?php printf( esc_html__( 'You have used %1$d of %2$d free generations this month.', 'ai-alt-text-pro' ), $count, self::FREE_LIMIT ); ?></p>
+            </div>
+        </div>
+        <?php
+    }
+
+    /**
+     * Enqueue admin assets.
+     */
+    public function enqueue_assets( $hook ) {
+        if ( 'settings_page_ai-alt-text-pro' !== $hook ) {
+            return;
+        }
+
+        wp_enqueue_style(
+            'ai-atp-admin',
+            plugin_dir_url( __FILE__ ) . 'assets/admin.css',
+            array(),
+            '1.0.0'
+        );
+
+        wp_enqueue_style( 'dashicons' );
+    }
+
+    public function maybe_generate_alt_text( $attachment_id ) {
+        $file = get_attached_file( $attachment_id );
+        if ( ! $file || wp_attachment_is_image( $attachment_id ) === false ) {
+            return;
+        }
+
+        $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
+        if ( ! empty( $alt ) ) {
+            return; // Alt text already exists
+        }
+
+        $count    = (int) get_option( self::OPTION_FREE_COUNT, 0 );
+        $license  = get_option( self::OPTION_LICENSE );
+        $has_license = ! empty( $license );
+
+        if ( ! $has_license && $count >= self::FREE_LIMIT ) {
+            return; // free limit reached
+        }
+
+        $generated = $this->generate_alt_text( $file );
+
+        if ( $generated ) {
+            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $generated ) );
+            if ( ! $has_license ) {
+                update_option( self::OPTION_FREE_COUNT, $count + 1 );
+            }
+        }
+    }
+
+    protected function generate_alt_text( $file ) {
+        $api_key  = get_option( self::OPTION_API_KEY );
+        $license  = get_option( self::OPTION_LICENSE );
+        if ( empty( $api_key ) ) {
+            return false;
+        }
+
+        $image_data = base64_encode( file_get_contents( $file ) );
+
+        $body = array( 'image' => $image_data );
+        if ( ! empty( $license ) ) {
+            $body['license'] = $license;
+            $body['locale']  = get_locale();
+        }
+
+        $response = wp_remote_post(
+            'https://api.example.com/generate-alt',
+            array(
+                'headers' => array(
+                    'Authorization' => 'Bearer ' . $api_key,
+                    'Content-Type'  => 'application/json',
+                ),
+                'body'    => wp_json_encode( $body ),
+                'timeout' => 60,
+            )
+        );
+
+        if ( is_wp_error( $response ) ) {
+            error_log( 'AI Alt Text API error: ' . $response->get_error_message() );
+            return false;
+        }
+
+        $data = json_decode( wp_remote_retrieve_body( $response ), true );
+        if ( isset( $data['alt_text'] ) ) {
+            return $data['alt_text'];
+        }
+
+        error_log( 'AI Alt Text API error: no alt_text in response.' );
+        return false;
+    }
+
+    /**
+     * Add custom monthly schedule for cron.
+     */
+    public function add_monthly_schedule( $schedules ) {
+        if ( ! isset( $schedules['monthly'] ) ) {
+            $schedules['monthly'] = array(
+                'interval' => MONTH_IN_SECONDS,
+                'display'  => __( 'Once Monthly', 'ai-alt-text-pro' ),
+            );
+        }
+
+        return $schedules;
+    }
+
+    /**
+     * Reset the free generation count.
+     */
+    public function reset_free_count() {
+        update_option( self::OPTION_FREE_COUNT, 0 );
+    }
+
+    /**
+     * Display admin notice when free limit reached.
+     */
+    public function admin_notice() {
+        if ( ! current_user_can( 'upload_files' ) ) {
+            return;
+        }
+
+        $count   = (int) get_option( self::OPTION_FREE_COUNT, 0 );
+        $license = get_option( self::OPTION_LICENSE );
+
+        if ( empty( $license ) && $count >= self::FREE_LIMIT ) {
+            echo '<div class="notice notice-warning"><p>' . esc_html__( 'AI Alt Text Pro free limit reached. Enter a license key for unlimited usage.', 'ai-alt-text-pro' ) . '</p></div>';
+        }
+    }
+
+    /**
+     * Schedule and unschedule events on activation/deactivation.
+     */
+    public static function activate() {
+        if ( ! wp_next_scheduled( 'ai_atp_reset_count' ) ) {
+            wp_schedule_event( time(), 'monthly', 'ai_atp_reset_count' );
+        }
+    }
+
+    public static function deactivate() {
+        $timestamp = wp_next_scheduled( 'ai_atp_reset_count' );
+        if ( $timestamp ) {
+            wp_unschedule_event( $timestamp, 'ai_atp_reset_count' );
+        }
+    }
+}
+
+new AI_Alt_Text_Pro();
+
+register_activation_hook( __FILE__, array( 'AI_Alt_Text_Pro', 'activate' ) );
+register_deactivation_hook( __FILE__, array( 'AI_Alt_Text_Pro', 'deactivate' ) );
+
+?>
