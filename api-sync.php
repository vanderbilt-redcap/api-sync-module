<script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/8.11.8/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/spin.js/2.3.2/spin.min.js"></script>

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
		  width: 475px;
		}

		.swal2-content{
		  font-weight: 500;
		}
	</style>

	<div style="color: #800000;font-size: 16px;font-weight: bold;"><?=$module->getModuleName()?></div>

	<div class="top-button-container">
		<button class="api-sync-export-button">Export All Records Now</button> - Exports all records to all destinations now.  No records will be removed.
		<script>
			Swal = Swal.mixin({
				buttonsStyling: false,
				allowOutsideClick: false
			})

			$('.api-sync-export-button').click(function(){
				var spinnerElement = $('<div class="api-sync-module-spinner"></div>')[0]
				new Spinner().spin(spinnerElement)

				Swal.fire({
					title: spinnerElement,
					text: 'Queuing all records for export...',
					showConfirmButton: false
				})

				var startTime = Date.now()
				$.post(<?=json_encode($module->getUrl('queue-all-records-for-export.php'))?>, null, function(response){
					var millisPassed = Date.now() - startTime
					var delay = 2000 - millisPassed
					if(delay < 0){
						delay = 0
					}

					setTimeout(function(){
						if(response === 'success'){
							Swal.fire('', 'All records have been queued for export.  Check this page again after about a minute to see export progress logs.')
						}
						else{
							Swal.fire('', 'An error occurred.  Please see the browser console for details.')
							console.log('API Sync Queue All Records Response:', response	)
						}
					}, delay)
				})
			})
		</script>
		<?php
		$module->renderSyncNowHtml();
		?>
	</div>

	<h5>Recent Log Entries</h5>
	<p>(refresh the page to see the latest)</p>
	<table class="table table-striped" style="max-width: 1000px;">
		<thead>
			<tr>
				<th style="min-width: 160px;">Date/Time</th>
				<th>Message</th>
				<th style="min-width: 125px;">Details</th>
			</tr>
		</thead>
		<tbody>
			<?php

			$results = $module->queryLogs("
				select log_id, timestamp, message, details
				order by log_id desc
				limit 2000
			");

			if($results->num_rows === 0){
				?>
				<tr>
					<td colspan="3">No logs available</td>
				</tr>
				<?php
			}
			else{
				while($row = $results->fetch_assoc()){
					$logId = $row['log_id'];
					$details = $row['details'];
					?>
					<tr>
						<td><?=$row['timestamp']?></td>
						<td class="message"><?=$row['message']?></td>
						<td>
							<?php if(!empty($details)) { ?>
								<button onclick="ExternalModules.Vanderbilt.APISyncExternalModule.showDetails(<?=$logId?>)">Show Details</button>
								<script>
									ExternalModules.Vanderbilt.APISyncExternalModule.details[<?=$logId?>] = <?=json_encode($details)?>
								</script>
							<?php } ?>
						</td>
					</tr>
					<?php
				}
			}
			?>
		</tbody>
	</table>
</div>