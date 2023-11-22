<script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/8.11.8/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/spin.js/2.3.2/spin.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.6/dist/loadingoverlay.min.js" integrity="sha384-L2MNADX6uJTVkbDNELTUeRjzdfJToVbpmubYJ2C74pwn8FHtJeXa+3RYkDRX43zQ" crossorigin="anonymous"></script>

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

	<style>
		#api-sync-module-wrapper .top-button-container{
			margin-top: 20px;
			margin-bottom: 50px;
		}

		#api-sync-module-wrapper .top-button-container button{
			margin: 3px;
			min-width: 200px;
		}

		#api-sync-module-wrapper > label{
			min-width: 70px;
			margin-right: 5px;
			text-align: right;
		}

		#api-sync-module-wrapper th{
			font-weight: bold;
		}

		#api-sync-module-wrapper .remote-project-title{
			margin-top: 5px;
			margin-left: 15px;
			font-weight: bold;
		}

		#api-sync-module-wrapper td.message{
			  max-width: 800px;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		#api-sync-module-wrapper td:nth-child(3) button{
			margin: 0px 2px;
		}

		#api-sync-module-wrapper a{
			/* This currently only exists for the output of the formatURLForLogs() method. */
			text-decoration: underline;
		}

		.api-sync-module-spinner{
			position: relative;
			height: 60px;
			margin-top: -10px;
		}

		.swal2-popup{
		  font-size: 14px;
		  width: 500px;
		}

		.swal2-content{
		  font-weight: 500;
		}

		#api-sync-module-log-entries_wrapper{
			max-width: 900px;
			margin-right: 18px;
		}

		#api-sync-module-log-entries{
			width: 100%;
		}
	</style>

	<div style="color: #800000;font-size: 16px;font-weight: bold;"><?=$module->getModuleName()?></div>

	<div class="top-button-container">
		<button class="api-sync-export-queued-button">Export Queued Records</button> - Exports recently added/updated/deleted records now.
		<br>
		<button class="api-sync-export-all-button">Export All Records</button> - Exports all existing records now.  No records will be removed.
		<br>
		<button class="api-sync-clear-export-queue-button">Clear Export Queue</button> - Unqueues any records currently queued for export.
		<br>
		<button class="api-sync-cancel-export-button">Cancel Export</button> - Cancels the active export.
		<br>
		<button class="api-sync-delete-request-content-logs">Delete Request Content Logs</button> - Deletes all logs created by the "Log request contents & responses" setting.
		<?php
		$module->renderSyncNowHtml();
		?>
	</div>

	<h5>Recent Log Entries</h5>
	<p>(refresh the page to see the latest)</p>

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
				if(args.successMessageSuffix === undefined){
					args.successMessageSuffix = '  Check this page again after about a minute to see export progress logs.'
				}

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

			$('.api-sync-export-queued-button').click(function(){
				ajaxRequest({
					url: <?=json_encode($module->getUrl('export-now.php'))?>,
					loadingMessage: 'Marking queued records for export now...',
					successMessage: 'Queued records have been marked for export now.'
				})
			})

			$('.api-sync-export-all-button').click(function(){
				ajaxRequest({
					url: <?=json_encode($module->getUrl('export-all-records-now.php'))?>,
					loadingMessage: 'Queuing all records for export...',
					successMessage: 'All records have been queued for export.'
				})
			})

			$('.api-sync-clear-export-queue-button').click(function(){
				ajaxRequest({
					url: <?=json_encode($module->getUrl('clear-export-queue.php'))?>,
					loadingMessage: 'Clearing export queue...',
					successMessage: 'The export queue has been cleared!  Only records saved from this moment forward will be included in the next export.'
				})
			})

			$('.api-sync-cancel-export-button').click(function(){
				ajaxRequest({
					url: <?=json_encode($module->getUrl('cancel-export.php'))?>,
					loadingMessage: 'Cancelling the current export...',
					successMessage: 'The current export has been cancelled and will stop after the current sub-batch finishes.'
				})
			})

			$('.api-sync-delete-request-content-logs').click(function(){
				ajaxRequest({
					url: <?=json_encode($module->getUrl('delete-request-content-logs.php'))?>,
					loadingMessage: 'Deleting request content logs...',
					successMessage: 'Request content logs have been successfully deleted.',
					successMessageSuffix: ''
				})
			})
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
					url: <?=json_encode($module->getUrl('get-logs.php'))?>,
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
				"autoWidth": false,
				"searching": true,
				"order": [[ 0, "desc" ]],
				"columns": [
					{
						data: 'timestamp',
						title: 'Date/Time',
						width: 125
					},
					{
						data: 'message',
						title: 'Message'
					},
					{
						title: 'Actions',
						render: function(data, type, row, meta){
							var html = ''
							var logId = row.log_id
							
							if(row.hasDetails){
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