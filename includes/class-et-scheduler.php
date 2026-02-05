<?php
/**
 * EasyTuner Background Scheduler
 *
 * Handles Action Scheduler integration for background sync processing.
 *
 * @package EasyTuner_Sync_Pro
 * @since 2.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ET_Scheduler class.
 *
 * @since 2.0.0
 */
class ET_Scheduler {

    /**
     * Action hook for scheduled sync.
     *
     * @var string
     */
    const SCHEDULED_SYNC_HOOK = 'et_sync_scheduled_task';

    /**
     * Action hook for batch processing.
     *
     * @var string
     */
    const BATCH_PROCESS_HOOK = 'et_sync_batch_process';

    /**
     * Constructor.
     */
    public function __construct() {
        // Register Action Scheduler hooks
        add_action( self::SCHEDULED_SYNC_HOOK, array( $this, 'run_scheduled_sync' ) );
        add_action( self::BATCH_PROCESS_HOOK, array( $this, 'process_batch' ), 10, 2 );
    }

    /**
     * Run the scheduled daily sync.
     */
    public function run_scheduled_sync() {
        // Check if auto sync is enabled
        if ( ! get_option( 'et_auto_sync', 0 ) ) {
            return;
        }

        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Run the full sync
        $sync = ET_Sync()->sync;
        $sync->run_full_sync( 'scheduled' );
    }

    /**
     * Process a batch of products in the background.
     *
     * @param string $sync_id   Unique sync session ID.
     * @param int    $offset    Current offset in the product list.
     */
    public function process_batch( $sync_id, $offset = 0 ) {
        $products   = get_transient( $sync_id );
        $batch_size = get_option( 'et_sync_batch_size', 20 );

        if ( false === $products ) {
            // Sync session expired or completed
            delete_transient( 'et_sync_running' );
            delete_transient( 'et_sync_progress' );
            return;
        }

        // Get batch of products
        $batch  = array_slice( $products, $offset, $batch_size );
        $sync   = ET_Sync()->sync;
        $logger = ET_Sync()->logger;

        $progress = get_transient( 'et_sync_progress' ) ?: array(
            'created' => 0,
            'updated' => 0,
            'errors'  => 0,
        );

        foreach ( $batch as $product_data ) {
            $result = $sync->sync_product( $product_data );

            if ( isset( $result['error'] ) ) {
                $progress['errors']++;
                $logger->record_error(
                    $result['error'],
                    isset( $product_data['item']['id'] ) ? $product_data['item']['id'] : ''
                );
            } elseif ( 'created' === $result['action'] ) {
                $progress['created']++;
                $logger->record_created( $result['product_id'], $result['sku'] );
            } elseif ( 'updated' === $result['action'] ) {
                $progress['updated']++;
                $logger->record_updated( $result['product_id'], $result['sku'] );
            }
        }

        // Update progress
        set_transient( 'et_sync_progress', $progress, HOUR_IN_SECONDS );

        $new_offset = $offset + count( $batch );

        if ( $new_offset >= count( $products ) ) {
            // Sync complete
            $logger->complete_log( $progress['errors'] > 0 ? 'partial' : 'completed' );
            delete_transient( $sync_id );
            delete_transient( 'et_sync_running' );
            delete_transient( 'et_sync_progress' );
        } else {
            // Schedule next batch
            $this->schedule_batch( $sync_id, $new_offset );
        }
    }

    /**
     * Schedule a batch for processing.
     *
     * @param string $sync_id Unique sync session ID.
     * @param int    $offset  Offset to start processing from.
     */
    public function schedule_batch( $sync_id, $offset ) {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + 1,
                self::BATCH_PROCESS_HOOK,
                array( $sync_id, $offset ),
                'easytuner-sync'
            );
        }
    }

    /**
     * Start a background sync.
     *
     * @return array|WP_Error Sync info or error.
     */
    public function start_background_sync() {
        // Check if already running
        if ( get_transient( 'et_sync_running' ) ) {
            return new WP_Error(
                'sync_already_running',
                __( 'A sync is already in progress.', 'easytuner-sync-pro' )
            );
        }

        // Get products from API
        $api      = ET_Sync()->api;
        $products = $api->get_products_for_sync();

        if ( is_wp_error( $products ) ) {
            return $products;
        }

        if ( empty( $products ) ) {
            return new WP_Error(
                'no_products',
                __( 'No products to sync. Please check your category mapping.', 'easytuner-sync-pro' )
            );
        }

        // Store products in transient
        $sync_id = 'et_sync_bg_' . wp_generate_uuid4();
        set_transient( $sync_id, $products, HOUR_IN_SECONDS );

        // Set running flag
        set_transient( 'et_sync_running', true, HOUR_IN_SECONDS );

        // Initialize progress
        set_transient( 'et_sync_progress', array(
            'created' => 0,
            'updated' => 0,
            'errors'  => 0,
            'total'   => count( $products ),
        ), HOUR_IN_SECONDS );

        // Start logger
        $logger = ET_Sync()->logger;
        $logger->start_log( 'background' );

        // Schedule first batch
        $this->schedule_batch( $sync_id, 0 );

        return array(
            'sync_id'        => $sync_id,
            'total_products' => count( $products ),
            'message'        => __( 'Background sync started.', 'easytuner-sync-pro' ),
        );
    }

    /**
     * Schedule the daily sync.
     *
     * @param string $time Time to run (e.g., '03:00').
     */
    public function schedule_daily_sync( $time = '03:00' ) {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return;
        }

        // Unschedule existing
        as_unschedule_all_actions( self::SCHEDULED_SYNC_HOOK );

        if ( ! get_option( 'et_auto_sync', 0 ) ) {
            return;
        }

        // Schedule new recurring action
        $timestamp = strtotime( 'today ' . $time );
        if ( $timestamp < time() ) {
            $timestamp = strtotime( 'tomorrow ' . $time );
        }

        as_schedule_recurring_action(
            $timestamp,
            DAY_IN_SECONDS,
            self::SCHEDULED_SYNC_HOOK,
            array(),
            'easytuner-sync'
        );
    }

    /**
     * Get the next scheduled sync time.
     *
     * @return int|false Timestamp of next scheduled sync or false.
     */
    public function get_next_scheduled_sync() {
        if ( function_exists( 'as_next_scheduled_action' ) ) {
            return as_next_scheduled_action( self::SCHEDULED_SYNC_HOOK );
        }
        return false;
    }

    /**
     * Check if a sync is currently running.
     *
     * @return bool True if running.
     */
    public function is_sync_running() {
        return (bool) get_transient( 'et_sync_running' );
    }

    /**
     * Get current sync progress.
     *
     * @return array|false Progress array or false if not running.
     */
    public function get_sync_progress() {
        if ( ! $this->is_sync_running() ) {
            return false;
        }

        return get_transient( 'et_sync_progress' );
    }

    /**
     * Cancel a running sync.
     *
     * @return bool True if cancelled.
     */
    public function cancel_sync() {
        // Unschedule any pending batches
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::BATCH_PROCESS_HOOK );
        }

        // Clear transients
        delete_transient( 'et_sync_running' );
        delete_transient( 'et_sync_progress' );

        // Complete the log as cancelled
        $logger = ET_Sync()->logger;
        $logger->complete_log( 'cancelled' );

        return true;
    }
}
