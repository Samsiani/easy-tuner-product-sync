<?php
/**
 * EasyTuner Sync Logger
 *
 * Handles logging of sync operations and errors.
 *
 * @package EasyTuner_Sync_Pro
 * @since 2.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ET_Logger class.
 *
 * @since 2.0.0
 */
class ET_Logger {

    /**
     * Database table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * Current log entry ID.
     *
     * @var int|null
     */
    private $current_log_id = null;

    /**
     * Error details collector.
     *
     * @var array
     */
    private $errors = array();

    /**
     * Products created counter.
     *
     * @var int
     */
    private $products_created = 0;

    /**
     * Products updated counter.
     *
     * @var int
     */
    private $products_updated = 0;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'et_sync_logs';
    }

    /**
     * Start a new sync log entry.
     *
     * @param string $sync_type Type of sync (manual, scheduled, background).
     * @return int Log entry ID.
     */
    public function start_log( $sync_type = 'manual' ) {
        global $wpdb;

        $wpdb->insert(
            $this->table_name,
            array(
                'sync_date'        => current_time( 'mysql' ),
                'sync_type'        => sanitize_text_field( $sync_type ),
                'products_updated' => 0,
                'products_created' => 0,
                'errors_count'     => 0,
                'error_details'    => '',
                'status'           => 'in_progress',
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
        );

        $this->current_log_id   = $wpdb->insert_id;
        $this->errors           = array();
        $this->products_created = 0;
        $this->products_updated = 0;

        return $this->current_log_id;
    }

    /**
     * Record a product creation.
     *
     * @param int    $product_id Product ID.
     * @param string $sku        Product SKU.
     */
    public function record_created( $product_id, $sku = '' ) {
        $this->products_created++;
        $this->update_current_log();
    }

    /**
     * Record a product update.
     *
     * @param int    $product_id Product ID.
     * @param string $sku        Product SKU.
     */
    public function record_updated( $product_id, $sku = '' ) {
        $this->products_updated++;
        $this->update_current_log();
    }

    /**
     * Record an error.
     *
     * @param string $message Error message.
     * @param string $sku     Product SKU (optional).
     * @param string $context Additional context (optional).
     */
    public function record_error( $message, $sku = '', $context = '' ) {
        $error = array(
            'time'    => current_time( 'mysql' ),
            'message' => $message,
        );

        if ( ! empty( $sku ) ) {
            $error['sku'] = $sku;
        }

        if ( ! empty( $context ) ) {
            $error['context'] = $context;
        }

        $this->errors[] = $error;
        $this->update_current_log();
    }

    /**
     * Complete the current sync log.
     *
     * @param string $status Final status (completed, failed, partial).
     */
    public function complete_log( $status = 'completed' ) {
        if ( ! $this->current_log_id ) {
            return;
        }

        global $wpdb;

        $wpdb->update(
            $this->table_name,
            array(
                'products_updated' => $this->products_updated,
                'products_created' => $this->products_created,
                'errors_count'     => count( $this->errors ),
                'error_details'    => wp_json_encode( $this->errors ),
                'status'           => sanitize_text_field( $status ),
            ),
            array( 'id' => $this->current_log_id ),
            array( '%d', '%d', '%d', '%s', '%s' ),
            array( '%d' )
        );

        $this->current_log_id = null;
    }

    /**
     * Update the current log entry.
     */
    private function update_current_log() {
        if ( ! $this->current_log_id ) {
            return;
        }

        global $wpdb;

        $wpdb->update(
            $this->table_name,
            array(
                'products_updated' => $this->products_updated,
                'products_created' => $this->products_created,
                'errors_count'     => count( $this->errors ),
                'error_details'    => wp_json_encode( $this->errors ),
            ),
            array( 'id' => $this->current_log_id ),
            array( '%d', '%d', '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Get sync logs with pagination.
     *
     * @param int $page     Page number.
     * @param int $per_page Items per page.
     * @return array Array of log entries.
     */
    public function get_logs( $page = 1, $per_page = 20 ) {
        global $wpdb;

        $offset = ( $page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY sync_date DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        // Decode error details JSON
        foreach ( $logs as &$log ) {
            if ( ! empty( $log['error_details'] ) ) {
                $log['error_details'] = json_decode( $log['error_details'], true );
            } else {
                $log['error_details'] = array();
            }
        }

        return $logs;
    }

    /**
     * Get total count of log entries.
     *
     * @return int Total count.
     */
    public function get_total_logs() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
    }

    /**
     * Get a single log entry by ID.
     *
     * @param int $log_id Log entry ID.
     * @return array|null Log entry or null if not found.
     */
    public function get_log( $log_id ) {
        global $wpdb;

        $log = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                $log_id
            ),
            ARRAY_A
        );

        if ( $log && ! empty( $log['error_details'] ) ) {
            $log['error_details'] = json_decode( $log['error_details'], true );
        }

        return $log;
    }

    /**
     * Get the latest sync log.
     *
     * @return array|null Latest log entry or null.
     */
    public function get_latest_log() {
        global $wpdb;

        $log = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$this->table_name} ORDER BY sync_date DESC LIMIT 1",
            ARRAY_A
        );

        if ( $log && ! empty( $log['error_details'] ) ) {
            $log['error_details'] = json_decode( $log['error_details'], true );
        }

        return $log;
    }

    /**
     * Delete old log entries.
     *
     * @param int $days Keep logs for this many days.
     * @return int Number of deleted entries.
     */
    public function cleanup_old_logs( $days = 30 ) {
        global $wpdb;

        return $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "DELETE FROM {$this->table_name} WHERE sync_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    /**
     * Get sync statistics.
     *
     * @param int $days Number of days for statistics.
     * @return array Statistics array.
     */
    public function get_statistics( $days = 30 ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total_syncs,
                    SUM(products_created) as total_created,
                    SUM(products_updated) as total_updated,
                    SUM(errors_count) as total_errors,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_syncs,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_syncs
                FROM {$this->table_name}
                WHERE sync_date >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ),
            ARRAY_A
        );

        return array(
            'total_syncs'      => (int) $stats['total_syncs'],
            'total_created'    => (int) $stats['total_created'],
            'total_updated'    => (int) $stats['total_updated'],
            'total_errors'     => (int) $stats['total_errors'],
            'successful_syncs' => (int) $stats['successful_syncs'],
            'failed_syncs'     => (int) $stats['failed_syncs'],
        );
    }

    /**
     * Get current sync progress.
     *
     * @return array Progress data.
     */
    public function get_current_progress() {
        return array(
            'created' => $this->products_created,
            'updated' => $this->products_updated,
            'errors'  => count( $this->errors ),
        );
    }
}
