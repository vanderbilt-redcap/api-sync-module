$(document).ready(function() {
	api_sync_module.addTableRow = function() {
		
	}

	// append stylesheet to head element of DOM
	$('head').append("<link rel='stylesheet' type='text/css' href='" + api_sync_module.css_url + "'/>");
	
	// prevent form resubmission
	if (window.history.replaceState) {
		window.history.replaceState(null, null, window.location.href);
	}
	
	// show import error
	if (api_sync_module.import_error_message != '') {
		alert(api_sync_module.import_error_message);
	}

	// // EVENT HANDLING
	// highlight clicked rows/cols
	$('body').on('click', null, function(event) {
		// remove existing highlights
		$('tr, td, th').removeClass('highlight');
		$('.remove-btn').attr('disabled', true);
	});
	$('body').on('click', '.translations-tbl td', function(event) {
		// remove existing highlights
		$('tr, td, th').removeClass('highlight');
		$('.remove-btn').attr('disabled', true);
		
		var clicked_row = $(this).closest('tr');
		$(clicked_row).addClass('highlight');
		
		$(this).closest('.card-body').prev('div.table-controls').find('.remove-btn').attr('disabled', false);
		event.stopPropagation();
	});
	$('body').on('click', '.translations-tbl th', function(event) {
		// remove existing highlights
		$('tr, td, th').removeClass('highlight');
		$('.remove-btn').attr('disabled', true);
		
		$(this).addClass('highlight');
		var col_index = $(this).index();
		$(this).closest('.translations-tbl').find('td:nth-child(' + (col_index + 1) + ')').each(function(i, td) {
			$(td).addClass('highlight');
		});
		$(this).closest('.card-body').prev('div.table-controls').find('.remove-btn').attr('disabled', false);
		event.stopPropagation();
	});

	// remove highlighted table row or column
	$('body').on('click', '.remove-btn', function(event) {
		var tbl = $(this).parent().next('.card-body').find('.translations-tbl')
		var rows = $(tbl).find('tbody tr').length;
		var cols = $(tbl).find('thead th').length;
		var remove_mode = $('th.highlight').length > 0 ? 'col' : 'row';
		
		if ((rows > 0 && remove_mode == 'row') || (cols > 2 && remove_mode == 'col')) {
			$('.highlight').remove();
		}
		
		// rename column headings
		if (remove_mode == 'col') {
			$(tbl).find('th').each(function(i, th) {
				if (i != 0) {
					$(th).text('Translated Name #' + i);
				}
			});
		}
	});

	// show import translations file modal
	$('body').on('click', '.import-btn', function(event) {
		// put project api key and server url in form
		var card = $(this).closest('div.card');
		var proj_api_key = $(card).find('span.project-api-key').text();
		var server_url = $(card).find('span.server-url').text();
		var server_type = $(card).find('span.server-type').text();
		var translations_type = $(this).attr('data-translation-type');
		
		$("input#project-api-key").val(proj_api_key);
		$("input#server-url").val(server_url);
		$("input#server-type").val(server_type);
		$("input#translations-type").val(translations_type);
		
		$("#import-translations").modal("show");
	});

	// export translations from table
	$('body').on('click', '.export-btn', function(event) {
		// write csv_contents string using table contents
		var tbody = $(this).parent().next().find('.translations-tbl > tbody');
		var lines = [];
		$(tbody).children('tr').each(function(i, tr) {
			var entries = [];
			$(tr).find('td > div').each(function(j, div) {
				entries.push($(div).text().trim());
			});
			lines.push(entries.join(', '));
		});
		var csv_contents = lines.join('\r\n');
		
		// create temporary anchor element to download file to user
		var a = $("<a style='display: none;'/>");
		var url = window.URL.createObjectURL(new Blob([csv_contents], {type: "data:text/csv;charset=utf-8"}));
		a.attr("href", url);
		a.attr("download", 'translations.csv');
		$("body").append(a);
		a[0].click();
		window.URL.revokeObjectURL(url);
		a.remove();
	});

	// change display name in file upload input element
	$('body').on('change', ".custom-file-input", function() {		// attach file (and maybe add input)
		var fileName = $(this).val().split('\\').pop()
		if (fileName.length > 40) {
			fileName = fileName.slice(0, 40) + "..."
		}
		
		$(this).next('label').html(fileName)
	})

	// removed uploaded file
	$('body').on('click', ".remove-file", function() {				// click remove (remove file attachment/input)
		var modal = $(this).closest('.modal-body')
		var target_input = $(this).parent().prev('div').children('.custom-file-input')
		var parent_group = $(this).closest('.input-group')
		
		if (modal.children('.input-group').length == 1) {
			target_input.val('')
			target_input.next('label').html("Choose file")
		} else {
			parent_group.remove()
		}
	})

	// submit translations file
	$('body').on('click', "#import-submit", function() {
		$("form#translation-file").submit();
	});
});