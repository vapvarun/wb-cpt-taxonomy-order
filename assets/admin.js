/**
 * WB CPT & Taxonomy Order - Admin JavaScript
 *
 * @package WB_CPT_Taxonomy_Order
 * @version 1.0.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var $sortable = $('#wb-cpto-sortable');
        var $status = $('.wb-cpto-status');
        var saveTimeout;

        if (!$sortable.length) {
            return;
        }

        // Get the type (posts or terms) and taxonomy.
        var itemType = $sortable.data('type') || 'posts';
        var taxonomy = $sortable.data('taxonomy') || '';

        // Initialize sortable.
        $sortable.sortable({
            handle: '.wb-cpto-handle',
            placeholder: 'wb-cpto-item ui-sortable-placeholder',
            axis: 'y',
            cursor: 'grabbing',
            opacity: 0.8,
            tolerance: 'pointer',
            update: function(event, ui) {
                // Update order numbers.
                updateOrderNumbers();

                // Debounce save.
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(saveOrder, 500);
            }
        });

        /**
         * Update visible order numbers
         */
        function updateOrderNumbers() {
            $sortable.find('.wb-cpto-item').each(function(index) {
                $(this).find('.wb-cpto-order').text(index + 1);
            });
        }

        /**
         * Save order via AJAX
         */
        function saveOrder() {
            var order = [];

            $sortable.find('.wb-cpto-item').each(function() {
                order.push($(this).data('id'));
            });

            // Determine action and nonce based on type.
            var action = (itemType === 'terms') ? 'wb_cpto_save_term_order' : 'wb_cpto_save_order';
            var nonce = (itemType === 'terms') ? wbCptoAdmin.termNonce : wbCptoAdmin.nonce;

            // Build data object.
            var data = {
                action: action,
                nonce: nonce,
                order: order
            };

            // Add taxonomy for term ordering.
            if (itemType === 'terms' && taxonomy) {
                data.taxonomy = taxonomy;
            }

            // Show saving status.
            $status
                .removeClass('saved error')
                .addClass('saving')
                .text(wbCptoAdmin.saving)
                .show();

            $.ajax({
                url: wbCptoAdmin.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        $status
                            .removeClass('saving error')
                            .addClass('saved')
                            .text(wbCptoAdmin.saved);

                        // Hide after 2 seconds.
                        setTimeout(function() {
                            $status.fadeOut();
                        }, 2000);
                    } else {
                        $status
                            .removeClass('saving saved')
                            .addClass('error')
                            .text(response.data.message || wbCptoAdmin.error);
                    }
                },
                error: function() {
                    $status
                        .removeClass('saving saved')
                        .addClass('error')
                        .text(wbCptoAdmin.error);
                }
            });
        }
    });

})(jQuery);
