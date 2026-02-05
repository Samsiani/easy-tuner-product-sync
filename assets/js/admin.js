/**
 * EasyTuner Sync Pro Admin JavaScript
 *
 * @package EasyTuner_Sync_Pro
 * @since 2.0.0
 */

(function($) {
    'use strict';

    // Localized data
    var ajaxUrl = etSyncAdmin.ajaxUrl;
    var nonce = etSyncAdmin.nonce;
    var i18n = etSyncAdmin.i18n;

    /**
     * Show a result message.
     *
     * @param {jQuery} $container Container element.
     * @param {string} message    Message to display.
     * @param {string} type       Message type (success, error, info).
     */
    function showMessage($container, message, type) {
        $container
            .removeClass('success error info')
            .addClass(type)
            .html(message)
            .show();
    }

    /**
     * Hide a result message.
     *
     * @param {jQuery} $container Container element.
     */
    function hideMessage($container) {
        $container.hide().empty();
    }

    /**
     * Settings Tab - Save Settings.
     */
    $('#et-settings-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $submitBtn = $('#et-save-settings');
        var $result = $('#et-connection-result');
        var originalText = $submitBtn.text();

        $submitBtn.text(i18n.savingSettings).prop('disabled', true);
        hideMessage($result);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'et_save_settings',
                nonce: nonce,
                email: $('#et_api_email').val(),
                password: $('#et_api_password').val(),
                batch_size: $('#et_sync_batch_size').val(),
                auto_sync: $('#et_auto_sync').is(':checked') ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    showMessage($result, response.data.message, 'success');
                } else {
                    showMessage($result, response.data.message, 'error');
                }
            },
            error: function() {
                showMessage($result, 'Request failed. Please try again.', 'error');
            },
            complete: function() {
                $submitBtn.text(originalText).prop('disabled', false);
            }
        });
    });

    /**
     * Settings Tab - Test Connection.
     */
    $('#et-test-connection').on('click', function() {
        var $btn = $(this);
        var $result = $('#et-connection-result');
        var originalText = $btn.text();

        $btn.text(i18n.testingConnection).prop('disabled', true);
        hideMessage($result);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'et_test_connection',
                nonce: nonce,
                email: $('#et_api_email').val(),
                password: $('#et_api_password').val()
            },
            success: function(response) {
                if (response.success) {
                    var msg = response.data.message;
                    if (response.data.categories) {
                        msg += '<br>Categories: ' + response.data.categories;
                    }
                    if (response.data.products) {
                        msg += '<br>Total Products: ' + response.data.products;
                    }
                    showMessage($result, msg, 'success');
                } else {
                    showMessage($result, response.data.message, 'error');
                }
            },
            error: function() {
                showMessage($result, i18n.connectionFailed, 'error');
            },
            complete: function() {
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });

    /**
     * Category Mapping Tab - Fetch Categories.
     */
    $('#et-fetch-categories').on('click', function() {
        var $btn = $(this);
        var $result = $('#et-mapping-result');
        var originalText = $btn.text();

        $btn.text(i18n.fetchingCategories).prop('disabled', true);
        hideMessage($result);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'et_fetch_categories',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage($result, response.data.message, 'success');
                    // Reload page to show updated categories
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showMessage($result, response.data.message, 'error');
                }
            },
            error: function() {
                showMessage($result, 'Failed to fetch categories.', 'error');
            },
            complete: function() {
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });

    /**
     * Category Mapping Tab - Save Mapping.
     */
    $('#et-mapping-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $submitBtn = $('#et-save-mapping');
        var $result = $('#et-mapping-result');
        var originalText = $submitBtn.text();

        // Collect mapping data
        var mappingData = {};
        $('#et-mapping-table tbody tr').each(function() {
            var $row = $(this);
            var category = $row.data('category');
            if (category) {
                mappingData[category] = {
                    enabled: $row.find('input[type="checkbox"]').is(':checked') ? 1 : 0,
                    wc_category: $row.find('select').val() || 0
                };
            }
        });

        $submitBtn.text(i18n.savingSettings).prop('disabled', true);
        hideMessage($result);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'et_save_mapping',
                nonce: nonce,
                mapping: mappingData
            },
            success: function(response) {
                if (response.success) {
                    showMessage($result, response.data.message, 'success');
                } else {
                    showMessage($result, response.data.message, 'error');
                }
            },
            error: function() {
                showMessage($result, 'Failed to save mapping.', 'error');
            },
            complete: function() {
                $submitBtn.text(originalText).prop('disabled', false);
            }
        });
    });

    /**
     * Sync Control Tab - Start Sync.
     */
    var syncInProgress = false;
    var currentSyncId = null;
    var totalProducts = 0;
    var processedProducts = 0;
    var syncResults = { created: 0, updated: 0, errors: [] };

    $('#et-start-sync').on('click', function() {
        if (syncInProgress) {
            return;
        }

        if (!confirm(i18n.confirmSync)) {
            return;
        }

        var $btn = $(this);
        var $progress = $('#et-sync-progress');
        var $result = $('#et-sync-result');

        // Reset state
        syncInProgress = true;
        processedProducts = 0;
        syncResults = { created: 0, updated: 0, errors: [] };

        $btn.text(i18n.syncStarting).prop('disabled', true);
        hideMessage($result);
        $progress.show();
        updateProgressBar(0);

        // Start the sync
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'et_sync_start',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    currentSyncId = response.data.sync_id;
                    totalProducts = response.data.total_products;
                    updateProgressDetails(response.data.message);
                    
                    // Start processing batches
                    processBatch(0);
                } else {
                    showMessage($result, response.data.message, 'error');
                    resetSyncUI($btn);
                }
            },
            error: function() {
                showMessage($result, i18n.syncFailed, 'error');
                resetSyncUI($btn);
            }
        });
    });

    /**
     * Process a batch of products.
     *
     * @param {number} offset Current offset.
     */
    function processBatch(offset) {
        if (!syncInProgress || !currentSyncId) {
            return;
        }

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'et_sync_process_batch',
                nonce: nonce,
                sync_id: currentSyncId,
                offset: offset
            },
            success: function(response) {
                if (response.success) {
                    processedProducts = response.data.processed;
                    syncResults.created += response.data.created;
                    syncResults.updated += response.data.updated;
                    syncResults.errors = syncResults.errors.concat(response.data.errors);

                    // Update progress
                    var percent = Math.round((processedProducts / totalProducts) * 100);
                    updateProgressBar(percent);
                    updateProgressDetails(response.data.message);

                    if (response.data.complete) {
                        // Sync complete
                        syncComplete();
                    } else {
                        // Process next batch
                        processBatch(processedProducts);
                    }
                } else {
                    // Handle fatal error response from server
                    var errorMessage = response.data.message || i18n.syncFailed;
                    if (response.data.fatal) {
                        errorMessage = 'Fatal Error: ' + errorMessage;
                    }
                    showMessage($('#et-sync-result'), errorMessage, 'error');
                    syncFailed(errorMessage);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // Server error (500, timeout, etc.)
                var errorMessage = 'Server Error';
                if (textStatus === 'timeout') {
                    errorMessage = 'Server Timeout: The request took too long to complete.';
                } else if (jqXHR.status === 500) {
                    errorMessage = 'Server Error (500): Internal Server Error. The sync has been interrupted.';
                } else if (jqXHR.status === 0) {
                    errorMessage = 'Connection Error: Unable to reach the server.';
                } else {
                    errorMessage = 'Server Error (' + (jqXHR.status || textStatus) + '): ' + (errorThrown || 'Unknown error occurred.');
                }

                showMessage($('#et-sync-result'), errorMessage, 'error');

                // Attempt to log the server error via a separate AJAX call
                logServerError(errorMessage, offset);

                syncFailed(errorMessage);
            }
        });
    }

    /**
     * Attempt to log a server error via AJAX.
     *
     * @param {string} errorMessage The error message to log.
     * @param {number} offset       The offset where the error occurred.
     */
    function logServerError(errorMessage, offset) {
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'et_sync_log_error',
                nonce: nonce,
                sync_id: currentSyncId,
                error_message: errorMessage,
                offset: offset
            },
            // We don't care about the response here, just try to log
            error: function() {
                // Silently fail - we've already shown the error to the user
            }
        });
    }

    /**
     * Handle sync failure.
     *
     * @param {string} errorMessage Error message.
     */
    function syncFailed(errorMessage) {
        updateProgressDetails('Sync failed: ' + errorMessage);
        resetSyncUI($('#et-start-sync'));
    }

    /**
     * Handle sync completion.
     */
    function syncComplete() {
        var $result = $('#et-sync-result');
        var $btn = $('#et-start-sync');

        updateProgressBar(100);

        var message = i18n.syncComplete + '<br>';
        message += 'Created: ' + syncResults.created + '<br>';
        message += 'Updated: ' + syncResults.updated;

        if (syncResults.errors.length > 0) {
            message += '<br>Errors: ' + syncResults.errors.length;
        }

        showMessage($result, message, syncResults.errors.length > 0 ? 'info' : 'success');
        resetSyncUI($btn);
    }

    /**
     * Update the progress bar.
     *
     * @param {number} percent Progress percentage.
     */
    function updateProgressBar(percent) {
        $('.et-progress-fill').css('width', percent + '%');
        $('.et-progress-text').text(percent + '%');
    }

    /**
     * Update progress details text.
     *
     * @param {string} message Status message.
     */
    function updateProgressDetails(message) {
        $('.et-progress-details').text(message);
    }

    /**
     * Reset the sync UI.
     *
     * @param {jQuery} $btn The sync button.
     */
    function resetSyncUI($btn) {
        syncInProgress = false;
        currentSyncId = null;
        $btn.text('Start Sync').prop('disabled', false);
    }

    /**
     * Sync Logs Tab - View Error Details.
     */
    $(document).on('click', '.et-view-errors', function() {
        var errors = $(this).data('errors');
        var $modal = $('#et-error-modal');
        var $list = $('#et-error-list');

        $list.empty();

        if (errors && errors.length > 0) {
            $.each(errors, function(i, error) {
                var $item = $('<div class="et-error-item">');
                
                if (error.time) {
                    $item.append('<div class="et-error-time">' + error.time + '</div>');
                }
                
                if (error.sku) {
                    $item.append('<div class="et-error-sku">SKU: ' + error.sku + '</div>');
                }
                
                $item.append('<div class="et-error-message">' + error.message + '</div>');
                
                $list.append($item);
            });
        } else {
            $list.html('<p>No error details available.</p>');
        }

        $modal.show();
    });

    /**
     * Close error modal.
     */
    $(document).on('click', '.et-modal-close, .et-modal', function(e) {
        if (e.target === this) {
            $('#et-error-modal').hide();
        }
    });

    /**
     * Close modal on Escape key.
     */
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#et-error-modal').hide();
        }
    });

})(jQuery);
