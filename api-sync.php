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

			ExternalModules.Vanderbilt.APISyncExternalModule.trimPreIndentation(div.find('pre')[0])

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
		#api-sync-module-wrapper th{
			font-weight: bold;
		}

		#api-sync-module-wrapper .remote-project-title{
			margin-top: 5px;
			margin-left: 15px;
			font-weight: bold;
		}
	</style>

	<div style="color: #800000;font-size: 16px;font-weight: bold;"><?=$module->getModuleName()?></div>
	<br>
	<?php
	$module->renderSyncNowHtml();
	?>
	<br>
	<br>
	<br>
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
						<td><?=$row['message']?></td>
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