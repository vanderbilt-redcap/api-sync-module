<div id="api-sync-module-wrapper">
	<?=$module->initializeJavascriptModuleObject()?>
	<script>
		ExternalModules.Vanderbilt.APISyncExternalModule.details = {}
		ExternalModules.Vanderbilt.APISyncExternalModule.showDetails = function(logId){
			simpleDialog('<pre>' + this.details[logId] + '</pre>', 'Details', null, window.innerWidth-100)
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
	$syncNow = $module->getProjectSetting('sync-now');
	if($syncNow){
		?>A sync is scheduled to start in less than a minute...<?php
	}
	else{
		?>
		<form action="<?=$module->getUrl('sync-now.php')?>" method="post">
			<button>Sync Now</button>
		</form>
		<?php
	}
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
				limit 5000
			");

			if($results->num_rows === 0){
				?>
				<tr>
					<td>No logs available</td>
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