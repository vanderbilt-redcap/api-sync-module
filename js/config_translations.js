$(document).ready(function() {
	api_sync_module.rename_columns = function(table) {
		$(table).find('th').each(function(i, th) {
			if (i != 0) {
				$(th).text('Translated Name #' + i);
			}
		});
	}
	
	api_sync_module.serialize_table = function(table) {
		// return table contents as CSV string
		var tbody = $(table).find('tbody');
		var lines = [];
		$(tbody).children('tr').each(function(i, tr) {
			var entries = [];
			$(tr).find('td > div').each(function(j, div) {
				entries.push($(div).text().trim());
			});
			lines.push(entries.join(', '));
		});
		return lines.join('\r\n');
	}
	
	// append stylesheet to head element of DOM
	$('head').append("<link rel='stylesheet' type='text/css' href='" + api_sync_module.css_url + "'/>");
	
	// prevent form resubmission
	if (window.history.replaceState) {
		window.history.replaceState(null, null, window.location.href);
	}
	
	// show applicable error
	if (api_sync_module.import_error_message != '') {
		alert(api_sync_module.import_error_message);
	}
	if (api_sync_module.table_saved_error_message != '') {
		alert(api_sync_module.table_saved_error_message);
	}

	// // EVENT HANDLING
	// highlight clicked rows/cols
	$('body').on('click', null, function() {
		// remove existing highlights
		$('tr, td, th').removeClass('highlight');
		$('.remove-btn.btn-primary').removeClass('btn-primary').addClass('btn-outline-primary');
	});
	$('body').on('click', '.translations-tbl td', function(event) {
		// remove existing highlights
		$('tr, td, th').removeClass('highlight');
		
		var clicked_row = $(this).closest('tr');
		$(clicked_row).addClass('highlight');
		
		$('.remove-btn.btn-primary').removeClass('btn-primary').addClass('btn-outline-primary');
		$(this).closest('.card-body').prev('div.table-controls').find('.remove-btn').removeClass('btn-outline-primary').addClass('btn-primary');
		event.stopPropagation();
	});
	$('body').on('click', '.translations-tbl th', function(event) {
		// remove existing highlights
		$('tr, td, th').removeClass('highlight');
		
		$(this).addClass('highlight');
		var col_index = $(this).index();
		$(this).closest('.translations-tbl').find('td:nth-child(' + (col_index + 1) + ')').each(function(i, td) {
			$(td).addClass('highlight');
		});
		
		$('.remove-btn.btn-primary').removeClass('btn-primary').addClass('btn-outline-primary');
		$(this).closest('.card-body').prev('div.table-controls').find('.remove-btn').removeClass('btn-outline-primary').addClass('btn-primary');
		event.stopPropagation();
	});

	// add row or col
	$('body').on('click', '.add-row-btn', function() {
		var tbl = $(this).parent().next('.card-body').find('.translations-tbl')
		var cols = $(tbl).find('thead th').length;
		var new_row = "<tr class='border-bottom'>";
		for (i = 0; i < cols; i++) {
			new_row += "<td><div contenteditable></div></td>";
		}
		new_row += "</tr>";
		$(tbl).find('tbody').append(new_row);
	});
	$('body').on('click', '.add-col-btn', function() {
		var tbl = $(this).parent().next('.card-body').find('.translations-tbl')
		$(tbl).find('thead tr').append('<th></th>');
		$(tbl).find('tbody tr').each(function(i, row) {
			$(row).append("<td><div contenteditable></div></td>");
		});
		api_sync_module.rename_columns(tbl);
	});

	// remove highlighted table row or column
	$('body').on('click', '.remove-btn', function() {
		var tbl = $(this).parent().next('.card-body').find('.translations-tbl')
		var rows = $(tbl).find('tbody tr').length;
		var cols = $(tbl).find('thead th').length;
		var remove_mode = $('th.highlight').length > 0 ? 'col' : 'row';
		
		if ((rows > 0 && remove_mode == 'row') || (cols > 2 && remove_mode == 'col')) {
			$('.highlight').remove();
			$('.remove-btn').removeClass('btn-outline-primary btn-primary')
			$('.remove-btn').addClass('btn-outline-primary')
		}
		
		// rename column headings
		if (remove_mode == 'col') {
			api_sync_module.rename_columns(tbl);
		}
	});
	
	$('body').on('click', '.save-btn', function() {
		var tbl = $(this).parent().next('.card-body').find('.translations-tbl')
		var tbl_csv = api_sync_module.serialize_table(tbl);
		var card = $(this).closest('div.card');
		
		// show loader
		var loader = $(card).find('.loader-container');
		$(loader).css('display', 'flex');
		
		$.ajax({
			type: 'POST',
			url: '?prefix=api_sync&page=config_translations&pid=' + api_sync_module.pid,
			data: {
				table_saved: true,
				translations: tbl_csv,
				'translations-type': $(this).attr('data-translation-type'),
				'project-api-key': $(card).find('span.project-api-key').text(),
				'server-url': (card).find('span.server-url').text(),
				'server-type': $(card).find('span.server-type').text()
			},
			error: function(response, status, err) {
				alert("There was an issue saving translations updates: " + err);
			},
			complete: function(response, status) {
				$(loader).css('display', 'none');
				$('.save-btn.btn-info').removeClass('btn-info').addClass('btn-outline-info');
			}
		});
	});
	
	// enable save button when translations table changes
	$('body').on('input', '.translations-tbl td', function() {
		var savebtn = $(this).closest('.card-body').prev('div.table-controls').find('.save-btn');
		$(savebtn).removeClass('btn-outline-info btn-info');
		$(savebtn).addClass('btn-info');
	});
	
	// export translations from table
	$('body').on('click', '.export-btn', function() {
		// write csv_contents string using table contents
		var tbl = $(this).parent().next().find('.translations-tbl');
		var csv_contents = api_sync_module.serialize_table(tbl);
		
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

	// show import translations file modal
	$('body').on('click', '.import-btn', function() {
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