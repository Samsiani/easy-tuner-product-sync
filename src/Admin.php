<?php
/**
 * Admin — Registers the admin menu, settings page, and all AJAX handlers.
 *
 * @package    EasyTuner_Sync_Pro
 * @namespace  AutoSync
 * @since      2.0.0
 */

namespace AutoSync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin class.
 *
 * @since 2.0.0
 */
class Admin {

    /**
     * Admin page slug.
     *
     * @var string
     */
    const MENU_SLUG = 'easytuner-sync-pro';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_et_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_et_fetch_categories', array( $this, 'ajax_fetch_categories' ) );
        add_action( 'wp_ajax_et_save_mapping', array( $this, 'ajax_save_mapping' ) );
        add_action( 'wp_ajax_et_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_et_delete_log', array( $this, 'ajax_delete_log' ) );
        add_action( 'wp_ajax_et_clear_all_logs', array( $this, 'ajax_clear_all_logs' ) );
    }

    /**
     * Add admin menu page.
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'EasyTuner Sync Pro', 'easytuner-sync-pro' ),
            __( 'EasyTuner Sync', 'easytuner-sync-pro' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_admin_page' ),
            'dashicons-update',
            56
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'et-sync-admin',
            ET_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            ET_SYNC_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script(
            'et-sync-admin',
            ET_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            ET_SYNC_VERSION,
            true
        );

        // Localize script
        wp_localize_script( 'et-sync-admin', 'etSyncAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'et_sync_nonce' ),
            'i18n'    => array(
                'testingConnection'  => __( 'Testing connection...', 'easytuner-sync-pro' ),
                'connectionSuccess'  => __( 'Connection successful!', 'easytuner-sync-pro' ),
                'connectionFailed'   => __( 'Connection failed.', 'easytuner-sync-pro' ),
                'fetchingCategories' => __( 'Fetching categories...', 'easytuner-sync-pro' ),
                'savingSettings'     => __( 'Saving settings...', 'easytuner-sync-pro' ),
                'settingsSaved'      => __( 'Settings saved!', 'easytuner-sync-pro' ),
                'syncStarting'       => __( 'Starting sync...', 'easytuner-sync-pro' ),
                'syncComplete'       => __( 'Sync completed!', 'easytuner-sync-pro' ),
                'syncFailed'         => __( 'Sync failed.', 'easytuner-sync-pro' ),
                'processing'         => __( 'Processing...', 'easytuner-sync-pro' ),
                'confirmSync'        => __( 'Are you sure you want to start the sync?', 'easytuner-sync-pro' ),
                'confirmDeleteLog'   => __( 'Are you sure you want to delete this log entry?', 'easytuner-sync-pro' ),
                'confirmClearLogs'   => __( 'Are you sure you want to delete ALL log entries? This cannot be undone.', 'easytuner-sync-pro' ),
                'deletingLog'        => __( 'Deleting...', 'easytuner-sync-pro' ),
                'clearingLogs'       => __( 'Clearing logs...', 'easytuner-sync-pro' ),
                'logDeleted'         => __( 'Log entry deleted.', 'easytuner-sync-pro' ),
                'logsCleared'        => __( 'All logs cleared.', 'easytuner-sync-pro' ),
                'deleteError'        => __( 'Failed to delete log.', 'easytuner-sync-pro' ),
            ),
        ) );
    }

    /**
     * Render the admin page.
     */
    public function render_admin_page() {
        // Get current tab
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';

        // Verify tab is valid
        $valid_tabs = array( 'settings', 'mapping', 'sync', 'logs' );
        if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
            $current_tab = 'settings';
        }
        ?>
        <div class="wrap et-sync-admin">
            <h1><?php esc_html_e( 'EasyTuner Sync Pro', 'easytuner-sync-pro' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=settings' ) ); ?>"
                   class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'easytuner-sync-pro' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=mapping' ) ); ?>"
                   class="nav-tab <?php echo 'mapping' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Category Mapping', 'easytuner-sync-pro' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=sync' ) ); ?>"
                   class="nav-tab <?php echo 'sync' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Sync Control', 'easytuner-sync-pro' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=logs' ) ); ?>"
                   class="nav-tab <?php echo 'logs' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Sync Logs', 'easytuner-sync-pro' ); ?>
                </a>
            </nav>

            <div class="et-sync-tab-content">
                <?php
                switch ( $current_tab ) {
                    case 'mapping':
                        $this->render_mapping_tab();
                        break;
                    case 'sync':
                        $this->render_sync_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    default:
                        $this->render_settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Settings tab.
     */
    private function render_settings_tab() {
        $email    = get_option( 'et_api_email', '' );
        $password = get_option( 'et_api_password', '' );
        $batch_size = get_option( 'et_sync_batch_size', 20 );
        $auto_sync  = get_option( 'et_auto_sync', 0 );
        ?>
        <div class="et-sync-settings">
            <h2><?php esc_html_e( 'API Settings', 'easytuner-sync-pro' ); ?></h2>

            <form id="et-settings-form">
                <?php wp_nonce_field( 'et_save_settings', 'et_settings_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="et_api_email"><?php esc_html_e( 'API Username', 'easytuner-sync-pro' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="et_api_email" name="et_api_email"
                                   value="<?php echo esc_attr( $email ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="et_api_password"><?php esc_html_e( 'API Password', 'easytuner-sync-pro' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="et_api_password" name="et_api_password"
                                   value="<?php echo esc_attr( $password ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="et_sync_batch_size"><?php esc_html_e( 'Batch Size', 'easytuner-sync-pro' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="et_sync_batch_size" name="et_sync_batch_size"
                                   value="<?php echo esc_attr( $batch_size ); ?>" min="1" max="100" class="small-text">
                            <p class="description">
                                <?php esc_html_e( 'Number of products to process per batch during sync.', 'easytuner-sync-pro' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Automatic Sync', 'easytuner-sync-pro' ); ?>
                        </th>
                        <td>
                            <label for="et_auto_sync">
                                <input type="checkbox" id="et_auto_sync" name="et_auto_sync" value="1"
                                       <?php checked( 1, $auto_sync ); ?>>
                                <?php esc_html_e( 'Enable daily automatic sync (runs at 3:00 AM)', 'easytuner-sync-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="et-save-settings">
                        <?php esc_html_e( 'Save Settings', 'easytuner-sync-pro' ); ?>
                    </button>
                    <button type="button" class="button" id="et-test-connection">
                        <?php esc_html_e( 'Test Connection', 'easytuner-sync-pro' ); ?>
                    </button>
                </p>
            </form>

            <div id="et-connection-result" class="et-result-message" style="display:none;"></div>
        </div>
        <?php
    }

    /**
     * Render the Category Mapping tab.
     */
    private function render_mapping_tab() {
        $mapping = get_option( 'et_category_mapping', array() );

        // Get WooCommerce categories
        $wc_categories = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
        ) );
        ?>
        <div class="et-sync-mapping">
            <h2><?php esc_html_e( 'Category Mapping', 'easytuner-sync-pro' ); ?></h2>

            <p class="description">
                <?php esc_html_e( 'Map API categories to WooCommerce categories. Only enabled categories will be synced.', 'easytuner-sync-pro' ); ?>
            </p>

            <p>
                <button type="button" class="button" id="et-fetch-categories">
                    <?php esc_html_e( 'Fetch API Categories', 'easytuner-sync-pro' ); ?>
                </button>
            </p>

            <form id="et-mapping-form">
                <?php wp_nonce_field( 'et_save_mapping', 'et_mapping_nonce' ); ?>

                <table class="widefat striped" id="et-mapping-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Enable', 'easytuner-sync-pro' ); ?></th>
                            <th><?php esc_html_e( 'API Category', 'easytuner-sync-pro' ); ?></th>
                            <th><?php esc_html_e( 'Products', 'easytuner-sync-pro' ); ?></th>
                            <th><?php esc_html_e( 'WooCommerce Category', 'easytuner-sync-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $mapping ) ) : ?>
                            <tr class="et-no-categories">
                                <td colspan="4">
                                    <?php esc_html_e( 'No categories loaded. Click "Fetch API Categories" to load categories from the API.', 'easytuner-sync-pro' ); ?>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $mapping as $api_cat => $config ) : ?>
                                <tr data-category="<?php echo esc_attr( $api_cat ); ?>">
                                    <td>
                                        <input type="checkbox" name="mapping[<?php echo esc_attr( $api_cat ); ?>][enabled]"
                                               value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>>
                                    </td>
                                    <td><?php echo esc_html( $api_cat ); ?></td>
                                    <td><?php echo isset( $config['item_count'] ) ? esc_html( $config['item_count'] ) : '-'; ?></td>
                                    <td>
                                        <select name="mapping[<?php echo esc_attr( $api_cat ); ?>][wc_category]">
                                            <option value=""><?php esc_html_e( '-- Select Category --', 'easytuner-sync-pro' ); ?></option>
                                            <?php foreach ( $wc_categories as $wc_cat ) : ?>
                                                <option value="<?php echo esc_attr( $wc_cat->term_id ); ?>"
                                                        <?php selected( isset( $config['wc_category'] ) ? $config['wc_category'] : '', $wc_cat->term_id ); ?>>
                                                    <?php echo esc_html( $wc_cat->name ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="et-save-mapping">
                        <?php esc_html_e( 'Save Mapping', 'easytuner-sync-pro' ); ?>
                    </button>
                </p>
            </form>

            <div id="et-mapping-result" class="et-result-message" style="display:none;"></div>

            <!-- Template for category rows -->
            <script type="text/html" id="tmpl-et-category-row">
                <tr data-category="{{data.name}}">
                    <td>
                        <input type="checkbox" name="mapping[{{data.name}}][enabled]" value="1">
                    </td>
                    <td>{{data.name}}</td>
                    <td>{{data.item_count}}</td>
                    <td>
                        <select name="mapping[{{data.name}}][wc_category]">
                            <option value=""><?php esc_html_e( '-- Select Category --', 'easytuner-sync-pro' ); ?></option>
                            <?php foreach ( $wc_categories as $wc_cat ) : ?>
                                <option value="<?php echo esc_attr( $wc_cat->term_id ); ?>">
                                    <?php echo esc_html( $wc_cat->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </script>
        </div>
        <?php
    }

    /**
     * Render the Sync Control tab.
     */
    private function render_sync_tab() {
        $scheduler   = EasyTunerPlugin()->scheduler;
        $is_running  = $scheduler->is_sync_running();
        $next_sync   = $scheduler->get_next_scheduled_sync();
        ?>
        <div class="et-sync-control">
            <h2><?php esc_html_e( 'Sync Control', 'easytuner-sync-pro' ); ?></h2>

            <div class="et-sync-status-panel">
                <h3><?php esc_html_e( 'Sync Status', 'easytuner-sync-pro' ); ?></h3>

                <?php if ( $is_running ) : ?>
                    <div class="et-sync-running">
                        <span class="spinner is-active"></span>
                        <?php esc_html_e( 'A sync is currently running...', 'easytuner-sync-pro' ); ?>
                    </div>
                <?php else : ?>
                    <p><?php esc_html_e( 'No sync currently running.', 'easytuner-sync-pro' ); ?></p>
                <?php endif; ?>

                <?php if ( $next_sync ) : ?>
                    <p>
                        <strong><?php esc_html_e( 'Next scheduled sync:', 'easytuner-sync-pro' ); ?></strong>
                        <?php echo esc_html( wp_date( 'F j, Y g:i a', $next_sync ) ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="et-sync-actions">
                <h3><?php esc_html_e( 'Manual Sync', 'easytuner-sync-pro' ); ?></h3>

                <p class="description">
                    <?php esc_html_e( 'Start a manual sync of all enabled categories. The sync will process products in batches to avoid timeouts.', 'easytuner-sync-pro' ); ?>
                </p>

                <p>
                    <button type="button" class="button button-primary" id="et-start-sync" <?php disabled( $is_running ); ?>>
                        <?php esc_html_e( 'Start Sync', 'easytuner-sync-pro' ); ?>
                    </button>
                </p>

                <div id="et-sync-progress" style="display:none;">
                    <h4><?php esc_html_e( 'Sync Progress', 'easytuner-sync-pro' ); ?></h4>
                    <div class="et-progress-bar">
                        <div class="et-progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="et-progress-stats">
                        <span class="et-progress-text">0%</span>
                        <span class="et-progress-details"></span>
                    </div>
                </div>

                <div id="et-sync-result" class="et-result-message" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Sync Logs tab.
     */
    private function render_logs_tab() {
        $logger   = EasyTunerPlugin()->logger;
        $page     = isset( $_GET['log_page'] ) ? max( 1, absint( $_GET['log_page'] ) ) : 1;
        $per_page = 20;
        $logs     = $logger->get_logs( $page, $per_page );
        $total    = $logger->get_total_logs();
        $pages    = ceil( $total / $per_page );
        ?>
        <div class="et-sync-logs">
            <h2><?php esc_html_e( 'Sync Logs', 'easytuner-sync-pro' ); ?></h2>

            <?php if ( ! empty( $logs ) ) : ?>
                <p>
                    <button type="button" class="button" id="et-clear-all-logs">
                        <?php esc_html_e( 'Clear All Logs', 'easytuner-sync-pro' ); ?>
                    </button>
                </p>
            <?php endif; ?>

            <div id="et-logs-result" class="et-result-message" style="display:none;"></div>

            <?php if ( empty( $logs ) ) : ?>
                <p><?php esc_html_e( 'No sync logs available.', 'easytuner-sync-pro' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" id="et-logs-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'easytuner-sync-pro' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'easytuner-sync-pro' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'easytuner-sync-pro' ); ?></th>
                            <th><?php esc_html_e( 'Updated', 'easytuner-sync-pro' ); ?></th>
                            <th><?php esc_html_e( 'Errors', 'easytuner-sync-pro' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'easytuner-sync-pro' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'easytuner-sync-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr data-log-id="<?php echo esc_attr( $log['id'] ); ?>">
                                <td><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $log['sync_date'] ) ) ); ?></td>
                                <td><?php echo esc_html( ucfirst( $log['sync_type'] ) ); ?></td>
                                <td><?php echo esc_html( $log['products_created'] ); ?></td>
                                <td><?php echo esc_html( $log['products_updated'] ); ?></td>
                                <td>
                                    <?php if ( $log['errors_count'] > 0 ) : ?>
                                        <span class="et-error-count"><?php echo esc_html( $log['errors_count'] ); ?></span>
                                    <?php else : ?>
                                        <?php echo esc_html( '0' ); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="et-status-<?php echo esc_attr( $log['status'] ); ?>">
                                        <?php echo esc_html( ucfirst( $log['status'] ) ); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ( ! empty( $log['error_details'] ) ) : ?>
                                        <button type="button" class="button button-small et-view-errors"
                                                data-errors="<?php echo esc_attr( wp_json_encode( $log['error_details'] ) ); ?>">
                                            <?php esc_html_e( 'View Errors', 'easytuner-sync-pro' ); ?>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small et-delete-log"
                                            data-log-id="<?php echo esc_attr( $log['id'] ); ?>">
                                        <?php esc_html_e( 'Delete', 'easytuner-sync-pro' ); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $pages > 1 ) : ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php
                            echo wp_kses_post( paginate_links( array(
                                'base'    => add_query_arg( 'log_page', '%#%' ),
                                'format'  => '',
                                'current' => $page,
                                'total'   => $pages,
                            ) ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Error Details Modal -->
            <div id="et-error-modal" class="et-modal" style="display:none;">
                <div class="et-modal-content">
                    <span class="et-modal-close">&times;</span>
                    <h3><?php esc_html_e( 'Error Details', 'easytuner-sync-pro' ); ?></h3>
                    <div id="et-error-list"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for testing API connection.
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        $email    = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
        $password = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '';

        if ( empty( $email ) || empty( $password ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter both username and password.', 'easytuner-sync-pro' ) ) );
        }

        $api    = EasyTunerPlugin()->api;
        $result = $api->test_connection( $email, $password );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler for fetching API categories.
     */
    public function ajax_fetch_categories() {
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        $api        = EasyTunerPlugin()->api;
        $categories = $api->get_categories_for_mapping();

        if ( is_wp_error( $categories ) ) {
            wp_send_json_error( array( 'message' => $categories->get_error_message() ) );
        }

        // Get existing mapping to preserve settings
        $existing_mapping = get_option( 'et_category_mapping', array() );

        // Update mapping with new categories
        $new_mapping = array();
        foreach ( $categories as $cat ) {
            $name = $cat['name'];
            $new_mapping[ $name ] = array(
                'enabled'     => isset( $existing_mapping[ $name ]['enabled'] ) ? $existing_mapping[ $name ]['enabled'] : 0,
                'wc_category' => isset( $existing_mapping[ $name ]['wc_category'] ) ? $existing_mapping[ $name ]['wc_category'] : '',
                'item_count'  => $cat['item_count'],
            );
        }

        // Save updated mapping
        update_option( 'et_category_mapping', $new_mapping );

        wp_send_json_success( array(
            'categories' => $categories,
            'message'    => sprintf(
                /* translators: %d: Number of categories */
                __( 'Found %d categories.', 'easytuner-sync-pro' ),
                count( $categories )
            ),
        ) );
    }

    /**
     * AJAX handler for saving category mapping.
     */
    public function ajax_save_mapping() {
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        $mapping_data = isset( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array();

        // Sanitize and process mapping data
        $sanitized_mapping = array();
        if ( is_array( $mapping_data ) ) {
            foreach ( $mapping_data as $api_cat => $config ) {
                $api_cat = sanitize_text_field( $api_cat );
                $sanitized_mapping[ $api_cat ] = array(
                    'enabled'     => isset( $config['enabled'] ) ? 1 : 0,
                    'wc_category' => isset( $config['wc_category'] ) ? absint( $config['wc_category'] ) : 0,
                    'item_count'  => isset( $config['item_count'] ) ? absint( $config['item_count'] ) : 0,
                );
            }
        }

        update_option( 'et_category_mapping', $sanitized_mapping );

        wp_send_json_success( array( 'message' => __( 'Mapping saved successfully!', 'easytuner-sync-pro' ) ) );
    }

    /**
     * AJAX handler for saving settings.
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        $email      = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
        $password   = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '';
        $batch_size = isset( $_POST['batch_size'] ) ? max( 1, min( 100, absint( $_POST['batch_size'] ) ) ) : 20;
        $auto_sync  = isset( $_POST['auto_sync'] ) ? 1 : 0;

        update_option( 'et_api_email', $email );
        update_option( 'et_api_password', $password );
        update_option( 'et_sync_batch_size', $batch_size );
        update_option( 'et_auto_sync', $auto_sync );

        // Update scheduled sync
        $scheduler = EasyTunerPlugin()->scheduler;
        $scheduler->schedule_daily_sync();

        wp_send_json_success( array( 'message' => __( 'Settings saved successfully!', 'easytuner-sync-pro' ) ) );
    }

    /**
     * AJAX handler for deleting a single log entry.
     */
    public function ajax_delete_log() {
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        $log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;

        if ( $log_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid log ID.', 'easytuner-sync-pro' ) ) );
        }

        $logger = EasyTunerPlugin()->logger;
        $result = $logger->delete_log( $log_id );

        if ( $result ) {
            wp_send_json_success( array( 'message' => __( 'Log entry deleted successfully.', 'easytuner-sync-pro' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to delete log entry.', 'easytuner-sync-pro' ) ) );
        }
    }

    /**
     * AJAX handler for clearing all log entries.
     */
    public function ajax_clear_all_logs() {
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        $logger = EasyTunerPlugin()->logger;
        $result = $logger->delete_all_logs();

        if ( false !== $result ) {
            wp_send_json_success( array( 'message' => __( 'All log entries cleared successfully.', 'easytuner-sync-pro' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to clear log entries.', 'easytuner-sync-pro' ) ) );
        }
    }
}
