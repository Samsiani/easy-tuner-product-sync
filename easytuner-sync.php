<?php
/**
 * Plugin Name: EasyTuner Sync Pro
 * Plugin URI: https://easytuner.net
 * Description: High-performance, secure WooCommerce product synchronization from EasyTuner API with category mapping, sync locking, and image deduplication.
 * Version: 2.0.0
 * Author: EasyTuner
 * Author URI: https://easytuner.net
 * Text Domain: easytuner-sync-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 *
 * @package EasyTuner_Sync_Pro
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'ET_SYNC_VERSION', '2.0.0' );
define( 'ET_SYNC_PLUGIN_FILE', __FILE__ );
define( 'ET_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ET_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ET_SYNC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main EasyTuner Sync Pro class.
 *
 * @since 2.0.0
 */
final class EasyTuner_Sync_Pro {

    /**
     * Single instance of the class.
     *
     * @var EasyTuner_Sync_Pro
     */
    private static $instance = null;

    /**
     * API handler instance.
     *
     * @var ET_API
     */
    public $api;

    /**
     * Admin handler instance.
     *
     * @var ET_Admin
     */
    public $admin;

    /**
     * Sync handler instance.
     *
     * @var ET_Sync
     */
    public $sync;

    /**
     * Image handler instance.
     *
     * @var ET_Image
     */
    public $image;

    /**
     * Scheduler handler instance.
     *
     * @var ET_Scheduler
     */
    public $scheduler;

    /**
     * Logger instance.
     *
     * @var ET_Logger
     */
    public $logger;

    /**
     * Get single instance of the class.
     *
     * @return EasyTuner_Sync_Pro
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files.
     */
    private function includes() {
        require_once ET_SYNC_PLUGIN_DIR . 'includes/class-et-api.php';
        require_once ET_SYNC_PLUGIN_DIR . 'includes/class-et-image.php';
        require_once ET_SYNC_PLUGIN_DIR . 'includes/class-et-logger.php';
        require_once ET_SYNC_PLUGIN_DIR . 'includes/class-et-sync.php';
        require_once ET_SYNC_PLUGIN_DIR . 'includes/class-et-scheduler.php';
        require_once ET_SYNC_PLUGIN_DIR . 'includes/class-et-admin.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Register activation/deactivation hooks
        register_activation_hook( ET_SYNC_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( ET_SYNC_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Initialize plugin after plugins are loaded
        add_action( 'plugins_loaded', array( $this, 'init' ) );

        // Add SSL bypass filter for EasyTuner API
        add_filter( 'https_ssl_verify', array( $this, 'bypass_ssl_for_easytuner' ), 10, 2 );
        add_filter( 'https_local_ssl_verify', array( $this, 'bypass_ssl_for_easytuner' ), 10, 2 );
    }

    /**
     * Initialize plugin components.
     */
    public function init() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        // Initialize components
        $this->api       = new ET_API();
        $this->image     = new ET_Image();
        $this->logger    = new ET_Logger();
        $this->sync      = new ET_Sync();
        $this->scheduler = new ET_Scheduler();

        // Initialize admin only in admin context
        if ( is_admin() ) {
            $this->admin = new ET_Admin();
        }

        // Load text domain for translations
        load_plugin_textdomain( 'easytuner-sync-pro', false, dirname( ET_SYNC_PLUGIN_BASENAME ) . '/languages' );
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        // Create sync log table
        $this->create_log_table();

        // Set default options
        $this->set_default_options();

        // Schedule daily sync if Action Scheduler is available
        if ( function_exists( 'as_schedule_recurring_action' ) ) {
            if ( ! as_next_scheduled_action( 'et_sync_scheduled_task' ) ) {
                as_schedule_recurring_action( strtotime( 'tomorrow 3:00am' ), DAY_IN_SECONDS, 'et_sync_scheduled_task' );
            }
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        // Unschedule Action Scheduler tasks
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'et_sync_scheduled_task' );
            as_unschedule_all_actions( 'et_sync_batch_process' );
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create sync log database table.
     */
    private function create_log_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'et_sync_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sync_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sync_type varchar(50) NOT NULL DEFAULT 'manual',
            products_updated int(11) NOT NULL DEFAULT 0,
            products_created int(11) NOT NULL DEFAULT 0,
            errors_count int(11) NOT NULL DEFAULT 0,
            error_details longtext,
            status varchar(20) NOT NULL DEFAULT 'completed',
            PRIMARY KEY (id),
            KEY sync_date (sync_date),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Set default plugin options.
     */
    private function set_default_options() {
        $defaults = array(
            'et_api_email'        => '',
            'et_api_password'     => '',
            'et_category_mapping' => array(),
            'et_sync_batch_size'  => 20,
            'et_auto_sync'        => 0,
        );

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }

    /**
     * Bypass SSL verification for EasyTuner API.
     *
     * @param bool   $verify Whether to verify SSL certificate.
     * @param string $url    The URL being requested.
     * @return bool
     */
    public function bypass_ssl_for_easytuner( $verify, $url ) {
        if ( strpos( $url, 'easytuner.net:8090' ) !== false ) {
            return false;
        }
        return $verify;
    }

    /**
     * Display WooCommerce missing notice.
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                printf(
                    /* translators: %s: WooCommerce plugin name */
                    esc_html__( 'EasyTuner Sync Pro requires %s to be installed and activated.', 'easytuner-sync-pro' ),
                    '<strong>WooCommerce</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }
}

/**
 * Get the main instance of EasyTuner Sync Pro.
 *
 * @return EasyTuner_Sync_Pro
 */
function ET_Sync() {
    return EasyTuner_Sync_Pro::instance();
}

// Initialize the plugin
ET_Sync();