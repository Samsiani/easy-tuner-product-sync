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
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
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

// PSR-4 SPL Autoloader — maps AutoSync\ to src/
spl_autoload_register( function ( $class ) {
    $prefix   = 'AutoSync\\';
    $base_dir = ET_SYNC_PLUGIN_DIR . 'src/';
    $len      = strlen( $prefix );

    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

use AutoSync\Admin;
use AutoSync\API;
use AutoSync\Image;
use AutoSync\Logger;
use AutoSync\Scheduler;
use AutoSync\Sync;

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
     * @var API
     */
    public $api;

    /**
     * Admin handler instance (null outside admin context).
     *
     * @var Admin|null
     */
    public $admin;

    /**
     * Sync handler instance.
     *
     * @var Sync
     */
    public $sync;

    /**
     * Image handler instance.
     *
     * @var Image
     */
    public $image;

    /**
     * Scheduler handler instance.
     *
     * @var Scheduler
     */
    public $scheduler;

    /**
     * Logger instance.
     *
     * @var Logger
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
        $this->init_hooks();
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
        $this->api       = new API();
        $this->image     = new Image();
        $this->logger    = new Logger();
        $this->sync      = new Sync();
        $this->scheduler = new Scheduler();

        // Initialize admin only in admin context
        if ( is_admin() ) {
            $this->admin = new Admin();
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
            if ( ! as_next_scheduled_action( Scheduler::SCHEDULED_SYNC_HOOK ) ) {
                as_schedule_recurring_action( strtotime( 'tomorrow 3:00am' ), DAY_IN_SECONDS, Scheduler::SCHEDULED_SYNC_HOOK );
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
            as_unschedule_all_actions( Scheduler::SCHEDULED_SYNC_HOOK );
            as_unschedule_all_actions( Scheduler::BATCH_PROCESS_HOOK );
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
 * Get the main EasyTuner Sync Pro plugin instance.
 *
 * @return EasyTuner_Sync_Pro
 */
function EasyTunerPlugin() {
    return EasyTuner_Sync_Pro::instance();
}

// Initialize the plugin
EasyTunerPlugin();
