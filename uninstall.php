<?php
/**
 * EasyTuner Sync Pro — Uninstall
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes all plugin data: database table, options, transients, and scheduled actions.
 *
 * @package EasyTuner_Sync_Pro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop the sync log table
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}et_sync_logs" );

// Delete all plugin options
delete_option( 'et_api_email' );
delete_option( 'et_api_password' );
delete_option( 'et_category_mapping' );
delete_option( 'et_sync_batch_size' );
delete_option( 'et_auto_sync' );

// Delete transients
delete_transient( 'et_sync_running' );
delete_transient( 'et_sync_progress' );

// Unschedule Action Scheduler tasks
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'et_sync_scheduled_task' );
    as_unschedule_all_actions( 'et_sync_batch_process' );
}
