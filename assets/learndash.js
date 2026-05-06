/**
 * WB CPT & Taxonomy Order - LearnDash hierarchy ordering.
 *
 * @package WB_CPT_Taxonomy_Order
 */

(function ($) {
	'use strict';

	$(function () {
		var $sortable = $('#wb-cpto-sortable');
		var $status = $('.wb-cpto-status');
		var saveTimeout;

		if (!$sortable.length) {
			return;
		}

		var mode = $sortable.data('mode') || 'courses';
		var courseId = $sortable.data('course-id') || 0;
		var lessonId = $sortable.data('lesson-id') || 0;

		$sortable.sortable({
			handle: '.wb-cpto-handle',
			placeholder: 'wb-cpto-item ui-sortable-placeholder',
			axis: 'y',
			cursor: 'grabbing',
			opacity: 0.8,
			tolerance: 'pointer',
			update: function () {
				updateOrderNumbers();
				clearTimeout(saveTimeout);
				saveTimeout = setTimeout(saveOrder, 400);
			}
		});

		function updateOrderNumbers() {
			$sortable.find('.wb-cpto-item').each(function (i) {
				$(this).find('.wb-cpto-order').text(i + 1);
			});
		}

		function saveOrder() {
			var order = [];
			$sortable.find('.wb-cpto-item').each(function () {
				order.push($(this).data('id'));
			});

			$status
				.removeClass('saved error')
				.addClass('saving')
				.text(wbCptoLD.saving)
				.show();

			$.ajax({
				url: wbCptoLD.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wb_cpto_ld_save_order',
					nonce: wbCptoLD.nonce,
					mode: mode,
					course_id: courseId,
					lesson_id: lessonId,
					order: order
				},
				success: function (response) {
					if (response && response.success) {
						$status
							.removeClass('saving error')
							.addClass('saved')
							.text(wbCptoLD.saved);

						setTimeout(function () {
							$status.fadeOut();
						}, 1800);
					} else {
						$status
							.removeClass('saving saved')
							.addClass('error')
							.text((response && response.data && response.data.message) || wbCptoLD.error);
					}
				},
				error: function () {
					$status
						.removeClass('saving saved')
						.addClass('error')
						.text(wbCptoLD.error);
				}
			});
		}
	});
})(jQuery);
