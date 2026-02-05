<?php
/**
 * EasyTuner Product Sync Handler
 *
 * Handles the product synchronization logic with WooCommerce.
 *
 * @package EasyTuner_Sync_Pro
 * @since 2.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ET_Sync class.
 *
 * @since 2.0.0
 */
class ET_Sync {

    /**
     * Batch size for processing.
     *
     * @var int
     */
    private $batch_size;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->batch_size = get_option( 'et_sync_batch_size', 20 );

        // Register AJAX handlers
        add_action( 'wp_ajax_et_sync_start', array( $this, 'ajax_start_sync' ) );
        add_action( 'wp_ajax_et_sync_process_batch', array( $this, 'ajax_process_batch' ) );
        add_action( 'wp_ajax_et_sync_get_status', array( $this, 'ajax_get_sync_status' ) );
        add_action( 'wp_ajax_et_sync_log_error', array( $this, 'ajax_log_error' ) );
    }

    /**
     * AJAX handler to start a new sync.
     */
    public function ajax_start_sync() {
        // Verify nonce
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        // Get products from API
        $api      = ET_Sync()->api;
        $products = $api->get_products_for_sync();

        if ( is_wp_error( $products ) ) {
            wp_send_json_error( array( 'message' => $products->get_error_message() ) );
        }

        if ( empty( $products ) ) {
            wp_send_json_error( array( 'message' => __( 'No products to sync. Please check your category mapping.', 'easytuner-sync-pro' ) ) );
        }

        // Store products in a transient for batch processing
        $sync_id = 'et_sync_' . wp_generate_uuid4();
        set_transient( $sync_id, $products, HOUR_IN_SECONDS );

        // Start logger
        $logger = ET_Sync()->logger;
        $log_id = $logger->start_log( 'manual' );

        wp_send_json_success( array(
            'sync_id'        => $sync_id,
            'log_id'         => $log_id,
            'total_products' => count( $products ),
            'batch_size'     => $this->batch_size,
            'message'        => sprintf(
                /* translators: %d: Number of products */
                __( 'Found %d products to sync.', 'easytuner-sync-pro' ),
                count( $products )
            ),
        ) );
    }

    /**
     * AJAX handler to process a batch of products.
     */
    public function ajax_process_batch() {
        // Verify nonce
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        $sync_id = isset( $_POST['sync_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_id'] ) ) : '';
        $log_id  = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
        $offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

        if ( empty( $sync_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid sync session.', 'easytuner-sync-pro' ) ) );
        }

        // Get products from transient
        $products = get_transient( $sync_id );

        if ( false === $products ) {
            wp_send_json_error( array( 'message' => __( 'Sync session expired. Please start a new sync.', 'easytuner-sync-pro' ) ) );
        }

        // Get batch of products
        $batch   = array_slice( $products, $offset, $this->batch_size );
        $results = array(
            'created' => 0,
            'updated' => 0,
            'errors'  => array(),
        );

        $logger = ET_Sync()->logger;

        // Set log ID to persist state across AJAX requests
        if ( $log_id > 0 ) {
            $logger->set_log_id( $log_id );
        }

        try {
            foreach ( $batch as $product_data ) {
                try {
                    $result = $this->sync_product( $product_data );

                    if ( isset( $result['error'] ) ) {
                        $results['errors'][] = $result['error'];
                        $logger->record_error(
                            $result['error'],
                            isset( $product_data['item']['id'] ) ? $product_data['item']['id'] : ''
                        );
                    } elseif ( $result['action'] === 'created' ) {
                        $results['created']++;
                        $logger->record_created( $result['product_id'], $result['sku'] );
                    } elseif ( $result['action'] === 'updated' ) {
                        $results['updated']++;
                        $logger->record_updated( $result['product_id'], $result['sku'] );
                    }
                } catch ( Exception $e ) {
                    $error_message = sprintf(
                        /* translators: 1: SKU, 2: Error message */
                        __( 'Exception processing SKU %1$s: %2$s', 'easytuner-sync-pro' ),
                        isset( $product_data['item']['id'] ) ? $product_data['item']['id'] : 'unknown',
                        $e->getMessage()
                    );
                    $results['errors'][] = $error_message;
                    $logger->record_error(
                        $error_message,
                        isset( $product_data['item']['id'] ) ? $product_data['item']['id'] : ''
                    );
                }
            }

            $new_offset   = $offset + count( $batch );
            $is_complete  = $new_offset >= count( $products );

            if ( $is_complete ) {
                // Complete the log
                $logger->complete_log( empty( $results['errors'] ) ? 'completed' : 'partial' );
                // Delete the transient
                delete_transient( $sync_id );
            }

            wp_send_json_success( array(
                'processed' => $new_offset,
                'total'     => count( $products ),
                'created'   => $results['created'],
                'updated'   => $results['updated'],
                'errors'    => $results['errors'],
                'complete'  => $is_complete,
                'message'   => $is_complete
                    ? __( 'Sync completed successfully!', 'easytuner-sync-pro' )
                    : sprintf(
                        /* translators: 1: Processed count, 2: Total count */
                        __( 'Processed %1$d of %2$d products...', 'easytuner-sync-pro' ),
                        $new_offset,
                        count( $products )
                    ),
            ) );
        } catch ( Exception $e ) {
            // Fatal error during batch processing - mark log as failed
            $logger->mark_as_failed( $e->getMessage() );
            delete_transient( $sync_id );

            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %s: Error message */
                    __( 'Fatal error during sync: %s', 'easytuner-sync-pro' ),
                    $e->getMessage()
                ),
                'fatal' => true,
            ) );
        }
    }

    /**
     * AJAX handler to get current sync status.
     */
    public function ajax_get_sync_status() {
        // Verify nonce
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        // Check if there's an active background sync
        $is_running = get_transient( 'et_sync_running' );

        if ( $is_running ) {
            $progress = get_transient( 'et_sync_progress' );
            wp_send_json_success( array(
                'running'   => true,
                'progress'  => $progress ? $progress : array(),
            ) );
        }

        // Get latest log
        $logger     = ET_Sync()->logger;
        $latest_log = $logger->get_latest_log();

        wp_send_json_success( array(
            'running'    => false,
            'latest_log' => $latest_log,
        ) );
    }

    /**
     * AJAX handler to log a server-side error from the client.
     *
     * This is used when the client detects a server error (500, timeout, etc.)
     * and wants to ensure the sync log is properly marked as failed.
     */
    public function ajax_log_error() {
        // Verify nonce
        check_ajax_referer( 'et_sync_nonce', 'nonce' );

        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easytuner-sync-pro' ) ) );
        }

        $sync_id       = isset( $_POST['sync_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_id'] ) ) : '';
        $log_id        = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
        $error_message = isset( $_POST['error_message'] ) ? sanitize_text_field( wp_unslash( $_POST['error_message'] ) ) : __( 'Unknown server error', 'easytuner-sync-pro' );
        // The offset parameter is passed for debugging context but not currently stored.
        // It can be useful to know at which batch offset the error occurred.

        // Clean up the transient
        if ( ! empty( $sync_id ) ) {
            delete_transient( $sync_id );
        }

        // Mark the log as failed
        $logger = ET_Sync()->logger;

        // Set log ID to persist state across AJAX requests
        if ( $log_id > 0 ) {
            $logger->set_log_id( $log_id );
        }

        $logger->mark_as_failed( $error_message );

        wp_send_json_success( array(
            'message' => __( 'Error logged successfully.', 'easytuner-sync-pro' ),
        ) );
    }

    /**
     * Sync a single product.
     *
     * This implements the "Sync Locking" logic:
     * - New products: Create as Draft with full data
     * - Existing products: Only update price and stock
     *
     * @param array $product_data Product data from API.
     * @return array Result with action, product_id, sku, or error.
     */
    public function sync_product( $product_data ) {
        $item           = $product_data['item'];
        $wc_category_id = $product_data['wc_category_id'];

        // Validate required fields
        if ( ! isset( $item['id'] ) || empty( $item['id'] ) ) {
            return array( 'error' => __( 'Product missing ID/SKU.', 'easytuner-sync-pro' ) );
        }

        $sku        = sanitize_text_field( $item['id'] );
        $product_id = wc_get_product_id_by_sku( $sku );

        try {
            if ( $product_id ) {
                // Existing product - only update price and stock (Sync Locking)
                return $this->update_existing_product( $product_id, $item );
            } else {
                // New product - create with full data
                return $this->create_new_product( $item, $wc_category_id );
            }
        } catch ( Exception $e ) {
            return array(
                'error' => sprintf(
                    /* translators: 1: SKU, 2: Error message */
                    __( 'Error processing SKU %1$s: %2$s', 'easytuner-sync-pro' ),
                    $sku,
                    $e->getMessage()
                ),
            );
        }
    }

    /**
     * Create a new product.
     *
     * @param array $item           API item data.
     * @param int   $wc_category_id WooCommerce category ID.
     * @return array Result array.
     */
    private function create_new_product( $item, $wc_category_id ) {
        $product = new WC_Product_Simple();

        // Set SKU
        $sku = sanitize_text_field( $item['id'] );
        $product->set_sku( $sku );

        // Set name
        if ( isset( $item['name'] ) ) {
            $product->set_name( sanitize_text_field( $item['name'] ) );
        }

        // Set price
        if ( isset( $item['sellingPrice'] ) ) {
            $product->set_regular_price( floatval( $item['sellingPrice'] ) );
        }

        // Set stock
        $manage_stock = isset( $item['manage_stock'] ) ? (bool) $item['manage_stock'] : true;
        $product->set_manage_stock( $manage_stock );

        if ( $manage_stock && isset( $item['stock'] ) ) {
            $product->set_stock_quantity( intval( $item['stock'] ) );
            $product->set_stock_status( intval( $item['stock'] ) > 0 ? 'instock' : 'outofstock' );
        }

        // Set category
        if ( $wc_category_id > 0 ) {
            $product->set_category_ids( array( $wc_category_id ) );
        }

        // Set status as Draft (per requirements)
        $product->set_status( 'draft' );

        // Save product
        $product_id = $product->save();

        if ( ! $product_id ) {
            return array( 'error' => __( 'Failed to create product.', 'easytuner-sync-pro' ) );
        }

        // Process featured image
        if ( isset( $item['photoIds'] ) && is_array( $item['photoIds'] ) && ! empty( $item['photoIds'] ) ) {
            $image_handler = ET_Sync()->image;
            $image_result  = $image_handler->process_product_image( $item, $product_id );

            if ( ! $image_result['success'] ) {
                // Log image error but don't fail the product creation
                ET_Sync()->logger->record_error(
                    $image_result['message'],
                    $sku,
                    'image_download'
                );
            }
        }

        return array(
            'action'     => 'created',
            'product_id' => $product_id,
            'sku'        => $sku,
        );
    }

    /**
     * Update an existing product.
     *
     * Only updates price and stock (Sync Locking - preserves name, status, category, images).
     *
     * @param int   $product_id Existing product ID.
     * @param array $item       API item data.
     * @return array Result array.
     */
    private function update_existing_product( $product_id, $item ) {
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return array( 'error' => __( 'Product not found.', 'easytuner-sync-pro' ) );
        }

        $sku = sanitize_text_field( $item['id'] );

        // Only update price
        if ( isset( $item['sellingPrice'] ) ) {
            $product->set_regular_price( floatval( $item['sellingPrice'] ) );
        }

        // Only update stock
        $manage_stock = isset( $item['manage_stock'] ) ? (bool) $item['manage_stock'] : $product->get_manage_stock();
        $product->set_manage_stock( $manage_stock );

        if ( $manage_stock && isset( $item['stock'] ) ) {
            $product->set_stock_quantity( intval( $item['stock'] ) );
            $product->set_stock_status( intval( $item['stock'] ) > 0 ? 'instock' : 'outofstock' );
        }

        // Save product
        $product->save();

        return array(
            'action'     => 'updated',
            'product_id' => $product_id,
            'sku'        => $sku,
        );
    }

    /**
     * Run a full sync (for Action Scheduler).
     *
     * @param string $sync_type Type of sync (scheduled, manual, background).
     * @return array Sync results.
     */
    public function run_full_sync( $sync_type = 'scheduled' ) {
        // Set running flag
        set_transient( 'et_sync_running', true, HOUR_IN_SECONDS );

        // Get products from API
        $api      = ET_Sync()->api;
        $products = $api->get_products_for_sync();

        if ( is_wp_error( $products ) ) {
            delete_transient( 'et_sync_running' );
            return array(
                'success' => false,
                'message' => $products->get_error_message(),
            );
        }

        if ( empty( $products ) ) {
            delete_transient( 'et_sync_running' );
            return array(
                'success' => false,
                'message' => __( 'No products to sync.', 'easytuner-sync-pro' ),
            );
        }

        // Start logging
        $logger = ET_Sync()->logger;
        $logger->start_log( $sync_type );

        $results = array(
            'created' => 0,
            'updated' => 0,
            'errors'  => 0,
        );

        try {
            foreach ( $products as $product_data ) {
                try {
                    $result = $this->sync_product( $product_data );

                    // Update progress transient
                    set_transient( 'et_sync_progress', $results, HOUR_IN_SECONDS );

                    if ( isset( $result['error'] ) ) {
                        $results['errors']++;
                        $logger->record_error(
                            $result['error'],
                            isset( $product_data['item']['id'] ) ? $product_data['item']['id'] : ''
                        );
                    } elseif ( $result['action'] === 'created' ) {
                        $results['created']++;
                        $logger->record_created( $result['product_id'], $result['sku'] );
                    } elseif ( $result['action'] === 'updated' ) {
                        $results['updated']++;
                        $logger->record_updated( $result['product_id'], $result['sku'] );
                    }
                } catch ( Exception $e ) {
                    $results['errors']++;
                    $logger->record_error(
                        $e->getMessage(),
                        isset( $product_data['item']['id'] ) ? $product_data['item']['id'] : ''
                    );
                }
            }

            // Complete logging
            $logger->complete_log( $results['errors'] > 0 ? 'partial' : 'completed' );

        } catch ( Exception $e ) {
            // Fatal error - mark log as failed
            $logger->mark_as_failed( $e->getMessage() );

            // Clear running flag
            delete_transient( 'et_sync_running' );
            delete_transient( 'et_sync_progress' );

            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'results' => $results,
            );
        }

        // Clear running flag
        delete_transient( 'et_sync_running' );
        delete_transient( 'et_sync_progress' );

        return array(
            'success' => true,
            'results' => $results,
        );
    }
}
