<?php
/**
 * EasyTuner Image Handler
 *
 * Handles image downloading, deduplication, and attachment management.
 *
 * @package EasyTuner_Sync_Pro
 * @since 2.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ET_Image class.
 *
 * @since 2.0.0
 */
class ET_Image {

    /**
     * Meta key for storing source URL for deduplication.
     *
     * @var string
     */
    const SOURCE_URL_META_KEY = '_et_source_url';

    /**
     * Constructor.
     */
    public function __construct() {
        // Constructor reserved for future use
    }

    /**
     * Get or download image for a product.
     *
     * This method first checks if the image already exists in the media library
     * (by checking the _et_source_url meta). If found, it returns the existing
     * attachment ID. If not, it downloads and imports the image.
     *
     * @param string $url     The image URL from the API.
     * @param int    $post_id The product post ID to attach the image to.
     * @return int|WP_Error Attachment ID on success, WP_Error on failure.
     */
    public function get_or_download_image( $url, $post_id ) {
        if ( empty( $url ) ) {
            return new WP_Error( 'empty_url', __( 'Image URL is empty.', 'easytuner-sync-pro' ) );
        }

        // Normalize URL for comparison
        $url = esc_url_raw( $url );

        // Check for existing image with same source URL (deduplication)
        $existing_id = $this->find_existing_attachment( $url );

        if ( $existing_id ) {
            return $existing_id;
        }

        // Download and import the image
        return $this->download_and_import_image( $url, $post_id );
    }

    /**
     * Find existing attachment by source URL.
     *
     * @param string $url The source URL to search for.
     * @return int|false Attachment ID if found, false otherwise.
     */
    public function find_existing_attachment( $url ) {
        global $wpdb;

        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = %s AND meta_value = %s
                LIMIT 1",
                self::SOURCE_URL_META_KEY,
                $url
            )
        );

        return $attachment_id ? absint( $attachment_id ) : false;
    }

    /**
     * Download and import an image to the media library.
     *
     * @param string $url     The image URL to download.
     * @param int    $post_id The product post ID to attach the image to.
     * @return int|WP_Error Attachment ID on success, WP_Error on failure.
     */
    public function download_and_import_image( $url, $post_id ) {
        // Load required WordPress files
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download the image with SSL bypass
        $response = wp_remote_get( $url, array(
            'sslverify' => false,
            'timeout'   => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'download_failed',
                sprintf(
                    /* translators: %s: Error message */
                    __( 'Failed to download image: %s', 'easytuner-sync-pro' ),
                    $response->get_error_message()
                )
            );
        }

        $response_code   = wp_remote_retrieve_response_code( $response );
        $image_contents  = wp_remote_retrieve_body( $response );
        $content_type    = wp_remote_retrieve_header( $response, 'content-type' );

        if ( 200 !== $response_code || empty( $image_contents ) ) {
            return new WP_Error(
                'download_failed',
                sprintf(
                    /* translators: %d: HTTP response code */
                    __( 'Failed to download image (HTTP %d).', 'easytuner-sync-pro' ),
                    $response_code
                )
            );
        }

        // Validate content type is an image
        if ( ! $this->is_valid_image_type( $content_type ) ) {
            return new WP_Error(
                'invalid_image_type',
                sprintf(
                    /* translators: %s: Content type */
                    __( 'Invalid image content type: %s', 'easytuner-sync-pro' ),
                    $content_type
                )
            );
        }

        // Get filename from URL, preserving original name
        $filename = $this->get_filename_from_url( $url, $content_type );

        // Upload the image to WordPress uploads directory
        $upload = wp_upload_bits( $filename, null, $image_contents );

        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error(
                'upload_failed',
                sprintf(
                    /* translators: %s: Error message */
                    __( 'Failed to save image: %s', 'easytuner-sync-pro' ),
                    $upload['error']
                )
            );
        }

        // Create the attachment in the media library
        $attachment_id = $this->create_attachment( $upload, $post_id, $filename );

        if ( is_wp_error( $attachment_id ) ) {
            // Clean up uploaded file on error
            @unlink( $upload['file'] );
            return $attachment_id;
        }

        // Store the source URL for deduplication
        update_post_meta( $attachment_id, self::SOURCE_URL_META_KEY, $url );

        return $attachment_id;
    }

    /**
     * Create attachment from uploaded file.
     *
     * @param array  $upload   Upload data from wp_upload_bits.
     * @param int    $post_id  Parent post ID.
     * @param string $filename Original filename.
     * @return int|WP_Error Attachment ID on success, WP_Error on failure.
     */
    private function create_attachment( $upload, $post_id, $filename ) {
        $file_path = $upload['file'];
        $file_type = wp_check_filetype( basename( $file_path ), null );

        $attachment_data = array(
            'post_mime_type' => $file_type['type'],
            'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attachment_id = wp_insert_attachment( $attachment_data, $file_path, $post_id );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return new WP_Error(
                'attachment_failed',
                __( 'Failed to create attachment.', 'easytuner-sync-pro' )
            );
        }

        // Generate attachment metadata
        $attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
        wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

        return $attachment_id;
    }

    /**
     * Set product featured image.
     *
     * @param int $product_id    Product ID.
     * @param int $attachment_id Attachment ID.
     * @return bool True on success, false on failure.
     */
    public function set_product_featured_image( $product_id, $attachment_id ) {
        return set_post_thumbnail( $product_id, $attachment_id );
    }

    /**
     * Check if content type is a valid image.
     *
     * @param string $content_type Content-Type header value.
     * @return bool True if valid image type.
     */
    private function is_valid_image_type( $content_type ) {
        $valid_types = array(
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
        );

        // Handle content types with charset or other params
        $content_type = strtolower( trim( explode( ';', $content_type )[0] ) );

        return in_array( $content_type, $valid_types, true );
    }

    /**
     * Get filename from URL with extension validation.
     *
     * @param string $url          Image URL.
     * @param string $content_type Content-Type header for extension fallback.
     * @return string Sanitized filename.
     */
    private function get_filename_from_url( $url, $content_type ) {
        $parsed_url = wp_parse_url( $url );
        $path       = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
        $filename   = basename( $path );

        // Remove query strings from filename
        $filename = preg_replace( '/\?.*$/', '', $filename );

        // If no valid extension, try to add one based on content type
        $extension = pathinfo( $filename, PATHINFO_EXTENSION );
        if ( empty( $extension ) || ! $this->is_valid_image_extension( $extension ) ) {
            $extension = $this->get_extension_from_content_type( $content_type );
            $filename  = pathinfo( $filename, PATHINFO_FILENAME ) . '.' . $extension;
        }

        // Fallback to generated name if still empty
        if ( empty( pathinfo( $filename, PATHINFO_FILENAME ) ) ) {
            $filename = 'easytuner-image-' . wp_generate_uuid4() . '.' . $extension;
        }

        return sanitize_file_name( $filename );
    }

    /**
     * Check if file extension is valid for images.
     *
     * @param string $extension File extension.
     * @return bool True if valid.
     */
    private function is_valid_image_extension( $extension ) {
        $valid_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' );
        return in_array( strtolower( $extension ), $valid_extensions, true );
    }

    /**
     * Get file extension from content type.
     *
     * @param string $content_type Content-Type header.
     * @return string File extension.
     */
    private function get_extension_from_content_type( $content_type ) {
        $type_map = array(
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/bmp'  => 'bmp',
        );

        $content_type = strtolower( trim( explode( ';', $content_type )[0] ) );

        return isset( $type_map[ $content_type ] ) ? $type_map[ $content_type ] : 'jpg';
    }

    /**
     * Process product image from API item.
     *
     * @param array $item       API item data.
     * @param int   $product_id Product ID.
     * @return array Result with status and message.
     */
    public function process_product_image( $item, $product_id ) {
        // Check if product already has a featured image
        if ( has_post_thumbnail( $product_id ) ) {
            return array(
                'success' => true,
                'message' => __( 'Product already has a featured image.', 'easytuner-sync-pro' ),
                'skipped' => true,
            );
        }

        // Get image URL from item
        $image_url = '';
        if ( isset( $item['photoIds'] ) && is_array( $item['photoIds'] ) && ! empty( $item['photoIds'][0] ) ) {
            $image_url = $item['photoIds'][0];
        }

        if ( empty( $image_url ) ) {
            return array(
                'success' => false,
                'message' => __( 'No image URL provided.', 'easytuner-sync-pro' ),
            );
        }

        // Get or download the image
        $attachment_id = $this->get_or_download_image( $image_url, $product_id );

        if ( is_wp_error( $attachment_id ) ) {
            return array(
                'success' => false,
                'message' => $attachment_id->get_error_message(),
            );
        }

        // Set as featured image
        $this->set_product_featured_image( $product_id, $attachment_id );

        return array(
            'success'       => true,
            'message'       => __( 'Image processed successfully.', 'easytuner-sync-pro' ),
            'attachment_id' => $attachment_id,
        );
    }
}
