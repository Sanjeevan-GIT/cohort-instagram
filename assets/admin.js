/**
 * Fallback image rows + media library picker.
 */
(function ($) {
	'use strict';

	function reindexRows() {
		$('#cohort-ig-fallbacks-table tbody tr').each(function (i) {
			$(this)
				.find('input')
				.each(function () {
					var name = $(this).attr('name');
					if (!name) {
						return;
					}
					$(this).attr(
						'name',
						name.replace(/fallback\[\d+]/, 'fallback[' + i + ']')
					);
				});
		});
	}

	function bindSelect($row) {
		$row.find('.cohort-ig-select-image').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var frame = wp.media({
				title: 'Select fallback image',
				button: { text: 'Use image' },
				multiple: false,
				library: { type: 'image' },
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var thumb =
					(attachment.sizes &&
						attachment.sizes.thumbnail &&
						attachment.sizes.thumbnail.url) ||
					attachment.url;
				$row.find('.cohort-ig-attachment-id').val(attachment.id);
				$row
					.find('.cohort-ig-preview')
					.html(
						'<img src="' +
							thumb +
							'" alt="" style="width:100%;height:100%;object-fit:cover;" />'
					);
			});
			frame.open();
		});
	}

	$(function () {
		$('#cohort-ig-fallbacks-table tbody tr').each(function () {
			bindSelect($(this));
		});

		$('#cohort-ig-add-row').on('click', function (e) {
			e.preventDefault();
			var tpl = $('#cohort-ig-fallback-row-tpl').html();
			if (!tpl) {
				return;
			}
			var i = $('#cohort-ig-fallbacks-table tbody tr').length;
			var html = tpl.replace(/__i__/g, String(i));
			var $row = $(html);
			$('#cohort-ig-fallbacks-table tbody').append($row);
			bindSelect($row);
			reindexRows();
		});

		$('#cohort-ig-fallbacks-table').on('click', '.cohort-ig-remove-row', function (e) {
			e.preventDefault();
			var $tbody = $('#cohort-ig-fallbacks-table tbody');
			if ($tbody.find('tr').length <= 1) {
				var $row = $(this).closest('tr');
				$row.find('.cohort-ig-attachment-id').val('0');
				$row.find('.cohort-ig-preview').empty();
				$row.find('input[type="text"], input[type="url"]').val('');
				return;
			}
			$(this).closest('tr').remove();
			reindexRows();
		});
	});
})(jQuery);
