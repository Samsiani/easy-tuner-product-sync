<?php
/**
 * API — Handles all HTTP communication with the EasyTuner remote API.
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
 * API class.
 *
 * @since 2.0.0
 */
class API {

    /**
     * API Base URL.
     *
     * @var string
     */
    private $base_url = 'https://easytuner.net:8090';

    /**
     * Cached auth token.
     *
     * @var string|null
     */
    private $token = null;

    /**
     * Token expiration timestamp.
     *
     * @var int
     */
    private $token_expires = 0;

    /**
     * Constructor.
     */
    public function __construct() {
        // Constructor reserved for future use
    }

    /**
     * Get HTTP request arguments with SSL bypass.
     *
     * @param array $additional_args Additional arguments to merge.
     * @return array
     */
    private function get_request_args( $additional_args = array() ) {
        $default_args = array(
            'sslverify' => false,
            'timeout'   => 30,
        );

        return wp_parse_args( $additional_args, $default_args );
    }

    /**
     * Authenticate with the API and get a bearer token.
     *
     * @param string|null $email    Email address (uses saved option if null).
     * @param string|null $password Password (uses saved option if null).
     * @return string|\WP_Error Token on success, WP_Error on failure.
     */
    public function authenticate( $email = null, $password = null ) {
        // Use saved credentials if not provided
        if ( is_null( $email ) ) {
            $email = get_option( 'et_api_email', '' );
        }
        if ( is_null( $password ) ) {
            $password = get_option( 'et_api_password', '' );
        }

        // Validate credentials are present
        if ( empty( $email ) || empty( $password ) ) {
            return new \WP_Error(
                'missing_credentials',
                __( 'API credentials are not configured.', 'easytuner-sync-pro' )
            );
        }

        $url      = $this->base_url . '/User/Login';
        $args     = $this->get_request_args( array(
            'body' => array(
                'Email'    => sanitize_text_field( $email ),
                'Password' => $password,
            ),
        ) );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'api_connection_error',
                sprintf(
                    /* translators: %s: Error message */
                    __( 'Failed to connect to API: %s', 'easytuner-sync-pro' ),
                    $response->get_error_message()
                )
            );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $response_code ) {
            $error_message = isset( $body['message'] ) ? $body['message'] : __( 'Unknown error', 'easytuner-sync-pro' );
            return new \WP_Error(
                'api_auth_error',
                sprintf(
                    /* translators: 1: Response code, 2: Error message */
                    __( 'Authentication failed (HTTP %1$d): %2$s', 'easytuner-sync-pro' ),
                    $response_code,
                    $error_message
                )
            );
        }

        if ( ! isset( $body['token'] ) || empty( $body['token'] ) ) {
            return new \WP_Error(
                'invalid_token_response',
                __( 'API did not return a valid token.', 'easytuner-sync-pro' )
            );
        }

        // Cache the token for 1 hour
        $this->token         = $body['token'];
        $this->token_expires = time() + HOUR_IN_SECONDS;

        return $this->token;
    }

    /**
     * Get a valid authentication token.
     *
     * @return string|\WP_Error Token on success, WP_Error on failure.
     */
    public function get_token() {
        // Return cached token if still valid
        if ( ! empty( $this->token ) && time() < $this->token_expires ) {
            return $this->token;
        }

        return $this->authenticate();
    }

    /**
     * Test the API connection with provided credentials.
     *
     * @param string $email    Email address.
     * @param string $password Password.
     * @return array|\WP_Error Connection test result or WP_Error on failure.
     */
    public function test_connection( $email, $password ) {
        $token = $this->authenticate( $email, $password );

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // Try to fetch inventories to verify full access
        $inventories = $this->get_inventories( $token );

        if ( is_wp_error( $inventories ) ) {
            return $inventories;
        }

        return array(
            'success'    => true,
            'message'    => __( 'Connection successful!', 'easytuner-sync-pro' ),
            'categories' => count( $inventories ),
            'products'   => $this->count_total_products( $inventories ),
        );
    }

    /**
     * Get all inventories (categories) from the API.
     *
     * @param string|null $token Optional token to use.
     * @return array|\WP_Error Inventories array or WP_Error on failure.
     */
    public function get_inventories( $token = null ) {
        if ( is_null( $token ) ) {
            $token = $this->get_token();
            if ( is_wp_error( $token ) ) {
                return $token;
            }
        }

        $url  = $this->base_url . '/Data/GetAllInventories';
        $args = $this->get_request_args( array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            ),
        ) );

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'api_request_error',
                sprintf(
                    /* translators: %s: Error message */
                    __( 'Failed to fetch inventories: %s', 'easytuner-sync-pro' ),
                    $response->get_error_message()
                )
            );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $response_code ) {
            return new \WP_Error(
                'api_data_error',
                sprintf(
                    /* translators: %d: HTTP response code */
                    __( 'API returned error code: %d', 'easytuner-sync-pro' ),
                    $response_code
                )
            );
        }

        if ( ! is_array( $body ) ) {
            return new \WP_Error(
                'invalid_response_format',
                __( 'API returned invalid data format.', 'easytuner-sync-pro' )
            );
        }

        return $body;
    }

    /**
     * Get API categories for mapping UI.
     *
     * @return array|\WP_Error Array of category names and item counts, or WP_Error.
     */
    public function get_categories_for_mapping() {
        $inventories = $this->get_inventories();

        if ( is_wp_error( $inventories ) ) {
            return $inventories;
        }

        $categories = array();
        foreach ( $inventories as $inventory ) {
            if ( isset( $inventory['name'] ) ) {
                $categories[] = array(
                    'name'        => $inventory['name'],
                    'item_count'  => isset( $inventory['items'] ) ? count( $inventory['items'] ) : 0,
                );
            }
        }

        return $categories;
    }

    /**
     * Count total products across all inventories.
     *
     * @param array $inventories Inventories array.
     * @return int Total product count.
     */
    private function count_total_products( $inventories ) {
        $count = 0;
        foreach ( $inventories as $inventory ) {
            if ( isset( $inventory['items'] ) && is_array( $inventory['items'] ) ) {
                $count += count( $inventory['items'] );
            }
        }
        return $count;
    }

    /**
     * Get products for sync based on enabled categories.
     *
     * @return array|\WP_Error Array of products with category info, or WP_Error.
     */
    public function get_products_for_sync() {
        $inventories = $this->get_inventories();

        if ( is_wp_error( $inventories ) ) {
            return $inventories;
        }

        $mapping  = get_option( 'et_category_mapping', array() );
        $products = array();

        foreach ( $inventories as $inventory ) {
            $category_name = isset( $inventory['name'] ) ? $inventory['name'] : '';

            // Skip if category is not enabled for sync
            if ( ! isset( $mapping[ $category_name ] ) || empty( $mapping[ $category_name ]['enabled'] ) ) {
                continue;
            }

            $wc_category_id = isset( $mapping[ $category_name ]['wc_category'] )
                ? absint( $mapping[ $category_name ]['wc_category'] )
                : 0;

            if ( isset( $inventory['items'] ) && is_array( $inventory['items'] ) ) {
                foreach ( $inventory['items'] as $item ) {
                    $products[] = array(
                        'item'           => $item,
                        'api_category'   => $category_name,
                        'wc_category_id' => $wc_category_id,
                    );
                }
            }
        }

        return $products;
    }
}
