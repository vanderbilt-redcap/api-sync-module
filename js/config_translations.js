$(document).ready(function() {
	api_sync_module.addTableRow = function() {
		
	}

	// append stylesheet to head element of DOM
	$('head').append("<link rel='stylesheet' type='text/css' href='" + api_sync_module.css_url + "'/>");

	// // EVENT HANDLING
	// highlight clicked rows
	$('.translations-tbl').on('click', '.translations-tbl td', function(event) {
		var clicked_row = $(this).find('tr');
		$('tr').removeClass('highlight');
		$(clicked_row).addClass('highlight');
		console.log('clicked_row', clicked_row);
	});

	// show import translations file modal
	$('body').on('click', '.import-btn', function(event) {
		// put project api key and server url in form
		var card = $(this).closest('div.card');
		var proj_api_key = $(card).find('span.project-api-key').text();
		var server_url = $(card).find('span.server-url').text();
		var server_type = $(card).find('span.server-type').text();
		var translations_type = $(this).attr('data-translation-type');
		console.log('translations_type', translations_type);
		
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

	if (window.history.replaceState) {
		window.history.replaceState(null, null, window.location.href);
	}
	
	if (api_sync_module.import_error_message != '') {
		alert(api_sync_module.import_error_message);
	}
});