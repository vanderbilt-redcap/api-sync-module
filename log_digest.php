<script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/8.11.8/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/spin.js/2.3.2/spin.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.6/dist/loadingoverlay.min.js" integrity="sha384-L2MNADX6uJTVkbDNELTUeRjzdfJToVbpmubYJ2C74pwn8FHtJeXa+3RYkDRX43zQ" crossorigin="anonymous"></script>

<? $module->includeCss("css/pages.css"); ?>

<div id="api-sync-module-wrapper">
	<?=$module->initializeJavascriptModuleObject()?>
	<script>
		ExternalModules.Vanderbilt.APISyncExternalModule.showDetails = function(logId){
			var width = window.innerWidth - 100;
			var height = window.innerHeight - 200;
			$.get(<?=json_encode($module->getUrl('get-log-details.php') . '&log-id=')?> + logId, function(details){
				var content = '<pre style="max-height: ' + height + 'px">' + details + '</pre>'
				simpleDialog(content, 'Details', null, width)
			})
		}

		ExternalModules.Vanderbilt.APISyncExternalModule.showSyncCancellationDetails = function(){
			var div = $('#api-sync-module-cancellation-details').clone()
			div.show()

			var pre = div.find('pre');

			// Replace tabs with spaces for easy copy pasting into the mysql command line interface
			pre.html(pre.html().replace(/\t/g, '    '))

			ExternalModules.Vanderbilt.APISyncExternalModule.trimPreIndentation(pre[0])

			simpleDialog(div, 'Sync Cancellation', null, 1000)
		}

		ExternalModules.Vanderbilt.APISyncExternalModule.trimPreIndentation = function(pre){
			var content = pre.innerHTML
			var firstNonWhitespaceIndex = content.search(/\S/)
			var leadingWhitespace = content.substr(0, firstNonWhitespaceIndex)
			pre.innerHTML = content.replace(new RegExp(leadingWhitespace, 'g'), '');
		}
	</script>


	<div style="color: #800000;font-size: 16px;font-weight: bold;"><?=$module->getModuleName()?> - Latest Activity </div>

	<?php
	$start = (new DateTime())->sub(date_interval_create_from_date_string('7 days'))->format('Y-m-d');
	$end = (new DateTime())->format('Y-m-d');
	?>

	<label>Start Date</label><input name='start' type='date' value='<?=$start?>'><br>
	<label>End Date</label><input name='end' type='date' value='<?=$end?>'><br>
	<br>

	<table id="api-sync-module-log-entries" class="table table-striped table-bordered"></table>

	<script>
		Swal = Swal.mixin({
			buttonsStyling: false,
			allowOutsideClick: false
		})

		$(function(){
			var ajaxRequest = function(args) {
				var spinnerElement = $('<div class="api-sync-module-spinner"></div>')[0]
				new Spinner().spin(spinnerElement)

				Swal.fire({
					title: spinnerElement,
					text: args.loadingMessage,
					showConfirmButton: false
				})

				var startTime = Date.now()
				$.post(args.url, { redcap_csrf_token: '<?= $module->getCSRFToken() ?>'}, function (response) {
					var millisPassed = Date.now() - startTime
					var delay = 2000 - millisPassed
					if (delay < 0) {
						delay = 0
					}

					setTimeout(function () {
						if (response === 'success') {
							Swal.fire('', args.successMessage + args.successMessageSuffix)
						}
						else {
							Swal.fire('', 'An error occurred.  Please see the browser console for details.')
							console.log('API Sync AJAX Response:', response)
						}
					}, delay)
				})
			}
		})

		$(function(){
			$.LoadingOverlay('show') // hide empty table during initial load

			const wrapper = $('#api-sync-module-wrapper')
			const startInput = wrapper.find('input[name=start]')
			const endInput = wrapper.find('input[name=end]')

			$.fn.dataTable.ext.errMode = 'throw';
			var table = $('#api-sync-module-log-entries').DataTable({
				"pageLength": 10,
				"processing": true,
				"ajax": {
					url: <?=json_encode($module->getUrl('get-log-digest.php'))?>,
					data: data => {
						data.start = startInput.val()
						data.end = endInput.val()
					},
					error: function (jqXHR, textStatus, errorThrown) {
						$.LoadingOverlay('hide')

						Swal.fire({
							title: 'Error',
							text: jqXHR.responseText
						})
					}
				},
				"autoWidth": true,
				"searching": true,
				"order": [[ 0, "desc" ]],
				"columns": [
					{
						data: 'timestamp',
						title: 'Date/Time',
						width: 125
					},
					{
						data: 'import_source',
						title: 'Import Source'
					},
					{
						data: 'n_records',
						title: 'Number of Records Pulled',
						width: 125
					},
					{
						data: 'status',
						title: 'Status'
					},
					{
						title: 'Actions',
						render: function(data, type, row, meta){
							var html = ''
							var logId = row.error_log_id

							if(logId){
								html += "<button onclick='ExternalModules.Vanderbilt.APISyncExternalModule.showDetails(" + logId + ")'>Show Details</button>"
							}

							// Only allow retrying the last failed import.
							if(row.failure && meta.row === 1){
								var form = $('#api-sync-module-wrapper form.retry')
								form.find('input[name=retry-log-id]').val(logId)
								form.show()
							}

							return html
						}
					},
				],
				"dom": 'Blftip'
		    }).on( 'draw', function () {
				var ellipsis = $('.dataTables_paginate .ellipsis')
				ellipsis.addClass('paginate_button')
				ellipsis.click(function(e){
					var jumpToPage = async function(){
						const response = await Swal.fire({
							text: 'What page number would like like to jump to?',
							input: 'text',
							showCancelButton: true
						})

						var page = response.value

						var pageCount = table.page.info().pages

						if(isNaN(page) || page < 1 || page > pageCount){
							Swal.fire('', 'You must enter a page between 1 and ' + pageCount)
						}
						else{
							table.page(page-1).draw('page')
						}
					}

					jumpToPage()

					return false
				})
		    }).on( 'processing.dt', function(e, settings, processing){
		    	if(processing){
					$.LoadingOverlay('show')
		    	}
		    	else{
		    		$.LoadingOverlay('hide')
		    	}
		    })

			startInput.change(() => table.ajax.reload())
			endInput.change(() => table.ajax.reload())

			$.LoadingOverlaySetup({
				'background': 'rgba(30,30,30,0.7)'
			})
		})
	</script>
</div>
