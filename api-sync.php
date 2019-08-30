<script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/8.11.8/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/spin.js/2.3.2/spin.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.6/dist/loadingoverlay.min.js" integrity="sha384-L2MNADX6uJTVkbDNELTUeRjzdfJToVbpmubYJ2C74pwn8FHtJeXa+3RYkDRX43zQ" crossorigin="anonymous"></script>

<div id="api-sync-module-wrapper">
	<?=$module->initializeJavascriptModuleObject()?>
	<script>
		ExternalModules.Vanderbilt.APISyncExternalModule.details = {}

		ExternalModules.Vanderbilt.APISyncExternalModule.showDetails = function(logId){
			var width = window.innerWidth - 100;
			var height = window.innerHeight - 200;
			var content = '<pre style="max-height: ' + height + 'px">' + this.details[logId] + '</pre>'

			simpleDialog(content, 'Details', null, width)
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
			min-width: 160px;
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
		<?php
		$module->renderSyncNowHtml();
		?>
	</div>

	<h5>Recent Log Entries</h5>
	<p>(refresh the page to see the latest)</p>

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
				$.post(args.url, null, function (response) {
					var millisPassed = Date.now() - startTime
					var delay = 2000 - millisPassed
					if (delay < 0) {
						delay = 0
					}

					setTimeout(function () {
						if (response === 'success') {
							Swal.fire('', args.successMessage + '  Check this page again after about a minute to see export progress logs.')
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
		})

		$(function(){
			$.fn.dataTable.ext.errMode = 'throw';

			var lastOverlayDisplayTime = 0
			var table = $('#api-sync-module-log-entries').DataTable({
				"pageLength": 100,
		        "processing": true,
		        "serverSide": true,
		        "ajax": {
					url: <?=json_encode($module->getUrl('get-logs.php'))?>
				},
				"autoWidth": false,
				"searching": false,
				"order": [[ 0, "desc" ]],
				"columns": [
					{
						data: 'timestamp',
						title: 'Date/Time'
					},
					{
						data: 'message',
						title: 'Message'
					},
					{
						data: 'details',
						title: 'Details',
						render: function(data, type, row, meta){
							if(!data){
								return ''
							}

							ExternalModules.Vanderbilt.APISyncExternalModule.details[row.log_id] = data
							return "<button onclick='ExternalModules.Vanderbilt.APISyncExternalModule.showDetails(" + row.log_id + ")'>Show Details</button>"
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
					lastOverlayDisplayTime = Date.now()
		    	}
		    	else{
		    		var secondsSinceDisplay = Date.now() - lastOverlayDisplayTime
		    		var delay = Math.max(300, secondsSinceDisplay)
		    		setTimeout(function(){
						$.LoadingOverlay('hide')
		    		}, delay)
		    	}
		    })

			$.LoadingOverlaySetup({
				'background': 'rgba(30,30,30,0.7)'
			})
		})
	</script>
</div>