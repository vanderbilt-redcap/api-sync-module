<?php
namespace Vanderbilt\APISyncExternalModule;

use Exception;
use REDCap;

class APISyncExternalModule extends \ExternalModules\AbstractExternalModule{
	const RECORD_STATUS_KEY_PREFIX = 'record-status-';

	const UPDATE = 'update';
	const DELETE = 'delete';
	const QUEUED = 'queued';
	const IN_PROGRESS = 'in progress';

	function cron($cronInfo){
		$originalPid = $_GET['pid'];

		$cronName = $cronInfo['cron_name'];

		foreach($this->framework->getProjectsWithModuleEnabled() as $localProjectId){
			// This automatically associates all log statements with this project.
			$_GET['pid'] = $localProjectId;

			if($cronName === 'exports'){
				$this->handleExports();
			}
			else if($cronName === 'imports'){
				$this->handleImports();
			}
			else{
				throw new Exception("Unsupported cron name: $cronName");
			}
		}

		// Put the pid back the way it was before this cron job (likely doesn't matter, but wanted to be safe)
		$_GET['pid'] = $originalPid;

		return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
	}

	private function areAnyEmpty($array){
		$filteredArray = array_filter($array);
		return count($array) != count($filteredArray);
	}

	private function handleExports(){
		$servers = $this->framework->getSubSettings('export-servers');

		$firstServer = $servers[0];
		$firstProject = @$firstServer['export-projects'][0];
		if($this->areAnyEmpty([
			@$firstServer['export-redcap-url'],
			@$firstProject['export-api-key'],
			@$firstProject['export-project-name']
		])){
			return;
		}

		foreach([self::UPDATE, self::DELETE] as $type){
			$this->export($servers, $type);
		}
	}

	private function export($servers, $type){
		$recordIdFieldName = $this->getRecordIdField();

		// Mark records as "in progress" before retrieving their IDs so we can distinguish
		// between records queued before and after the export starts.
		// Records queued afterward should remain queued to trigger the next export.
		$this->markQueuedRecordsAsInProgress($type);

		$chunks = array_chunk($this->getInProgressRecordsIds($type), $this->getExportBatchSize($type));
		for($i=0; $i<count($chunks); $i++) {
			$recordIds = $chunks[$i];
			$batchText = "batch " . ($i+1) . " of " . count($chunks);

			$this->log("Preparing to export {$type}s for $batchText", [
				'details' => json_encode(['Record IDs' => $recordIds], JSON_PRETTY_PRINT)
			]);

			if($type === self::UPDATE){
				$data = REDCap::getData($this->getProjectId(), 'json', $recordIds);
			}

			foreach ($servers as $server) {
				$url = $server['export-redcap-url'];
				$logUrl = $this->formatURLForLogs($url);

				foreach ($server['export-projects'] as $project) {
					$getProjectExportMessage = function($action) use ($type, $logUrl, $project){
						return "
							<div>$action exporting {$type}s to the following project at $logUrl:</div>
							<div class='remote-project-title'>" . $project['export-project-name'] . "</div>
						";
					};

					$this->log($getProjectExportMessage('Started'));

					try {
						$apiKey = $project['export-api-key'];

						$args = ['content' => 'record'];

						if($type === self::UPDATE){
							$recordIdPrefix = $project['export-record-id-prefix'];
							if($recordIdPrefix){
								$data = json_decode($data, true);
								$this->prefixRecordIds($data, $recordIdFieldName, $recordIdPrefix);
								$data = json_encode($data);
							}

							$args['overwriteBehavior'] = 'overwrite';
							$args['data'] = $data;
						}
						else if($type === self::DELETE){
							$args['action'] = 'delete';
							$args['records'] = $recordIds;
						}
						else{
							throw new Exception("Unsupported export type: $type");
						}

						$results = $this->apiRequest($url, $apiKey, $args);

						$this->log(
							$getProjectExportMessage('Finished'),
							['details' => json_encode($results, JSON_PRETTY_PRINT)]
						);
					} catch (Exception $e) {
						$this->handleException($e);
					}
				}
			}

			$this->removeStatusForInProgressRecords($type, $recordIds);
			$this->log("Finished exporting {$type}s for $batchText");
		}
	}

	private function getExportBatchSize($type){
		if($type === self::DELETE){
			// If any record fails to delete it will stop the deletion of other records.
			// The simplest solution for this was to limit the batch size to one.
			// In the future, we could potentially parse failed record IDs out of responses and remove them from batches instead.
			return 1;
		}

		$size = $this->getProjectSetting('export-batch-size');
		if(!$size){
			$size = 100;
		}

		return $size;
	}

	private function markQueuedRecordsAsInProgress($type){
		$this->recordStatusQuery(
			"update",
			"set s.value = '" . $this->getRecordStatus($type, self::IN_PROGRESS) . "'",
			"s.value = '" . $this->getRecordStatus($type, self::QUEUED) . "'"
		);
	}

	private function getInProgressRecordsIds($type){
		$result = $this->recordStatusQuery(
			"select `key` from",
			"",
			"s.value = '" . self::getRecordStatus($type, self::IN_PROGRESS) . "'"
		);

		$recordIds = [];
		while($row = $result->fetch_assoc()) {
			$key = $row['key'];
			$recordIds[] = substr($key, strlen(self::RECORD_STATUS_KEY_PREFIX));
		}

		return $recordIds;
	}

	private function removeStatusForInProgressRecords($type, $recordIds){
		$keys = [];
		foreach($recordIds as $recordId){
			$keys[] = self::RECORD_STATUS_KEY_PREFIX . $recordId;
		}

		$this->recordStatusQuery(
			"delete s from",
			"",
			$this->framework->getSQLInClause('`key`', $keys) . " and s.value = '" . self::getRecordStatus($type, self::IN_PROGRESS) . "'"
		);
	}

	private function recordStatusQuery($actionClause, $setClause, $whereClause){
		$projectId = $this->getProjectId();

		$sql = "
			$actionClause
			redcap_external_module_settings s
			join redcap_external_modules m
				on m.external_module_id = s.external_module_id
			$setClause
			where
				m.directory_prefix = '" . db_real_escape_string($this->PREFIX) . "'
				and project_id = $projectId
				and `key` like '" . self::RECORD_STATUS_KEY_PREFIX . "%'
				and $whereClause
		";

		return $this->query($sql);
	}

	private function handleImports(){
		$servers = $this->framework->getSubSettings('servers');
		foreach($servers as $server){
			if(!$this->isTimeToRun($server)){
				continue;
			}

			$url = $server['redcap-url'];
			$logUrl = $this->formatURLForLogs($url);
			$serverStartMessage = "Started import from $logUrl";
			$serverFinishMessage = "Finished import from $logUrl";

			$this->makeSureLastSyncFinished($url, $serverStartMessage, $serverFinishMessage);

			// This log mainly exists to show that the sync process has started, since the next log
			// doesn't occur until after the API request to get the project name (which could fail).
			$this->log($serverStartMessage);

			foreach($server['projects'] as $project){
				try{
					// The following function takes about 15 minutes to export project 48364 (10,445 records, 1,428 fields, 20MB)
					// from redcap.vanderbilt.edu to Mark's local.
					$this->importRecords($url, $project);
				}
				catch(Exception $e){
					$this->handleException($e);
				}
			}

			$this->log($serverFinishMessage);
		}
	}

	private function formatURLForLogs($url){
		$parts = explode('://', $url);
		$domainName = $parts[1];

		return "<b><a href='$url' target='_blank'>$domainName</a></b>";
	}

	private function handleException($e){
		$message = "An error occurred.";
		$this->log("$message  Click 'Show Details' for more info.", [
			'details' => $e->getMessage() . "\n" . $e->getTraceAsString()
		]);

		$this->sendErrorEmail($message);
	}

	private function makeSureLastSyncFinished($url, $serverStartMessage, $serverFinishMessage){
		$getLastMessageLogId = function($message){
			$message = db_real_escape_string($message);
			$results = $this->queryLogs("select log_id where message = '$message' order by log_id desc limit 1");
			$row = $results->fetch_assoc();
			if(!$row){
				return null;
			}

			return $row['log_id'];
		};

		$lastStart = $getLastMessageLogId($serverStartMessage);
		$lastFinish = $getLastMessageLogId($serverFinishMessage);

		if($lastStart === null){
			// A sync has never been run on this project.
			return;
		}

		if($lastFinish === null || $lastStart > $lastFinish){
			$this->sendErrorEmail("The last daily sync did not complete.");
		}
	}

	private function sendErrorEmail($message){
		if(!method_exists($this->framework, 'getProject')){
			// This REDCap version is older and doesn't have the methods needed for error reporting.
			return;
		}

		if($this->getProjectSetting('disable-error-emails') === true){
			return;
		}

		$url = $this->getUrl('api-sync.php');
		$message .= "  See the logs on <a href='$url'>this page</a> for details.";

		$project = $this->framework->getProject();
		$users = $project->getUsers();

		$emails = [];
		foreach($users as $user){
			if($user->isSuperUser()){
				$emails[] = $user->getEmail();
			}
		}

		global $homepage_contact_email;
		if(empty($emails)){
			// There aren't any super users on the project.  Send to the system admin instead.
			$emails[] = $homepage_contact_email;
		}

		REDCap::email(
			implode(', ', $emails),
			$homepage_contact_email,
			"REDCap API Sync Module Error",
			$message
		);
	}

	private function isTimeToRun($server){
		$syncNow = $this->getProjectSetting('sync-now');
		if($syncNow){
			$this->removeProjectSetting('sync-now');
			return true;
		}

		$dailyRecordImportHour = $server['daily-record-import-hour'];
		$dailyRecordImportMinute = $server['daily-record-import-minute'];

		if(empty($dailyRecordImportHour) || empty($dailyRecordImportMinute)){
			return false;
		}

		$dailyRecordImportHour = (int) $dailyRecordImportHour;
		$dailyRecordImportMinute = (int) $dailyRecordImportMinute;

		// We check the cron start time instead of the current time
		// in case another module's cron job ran us into the next minute.
		$cronStartTime = $_SERVER["REQUEST_TIME_FLOAT"];

		$currentHour = (int) date('G', $cronStartTime);
		$currentMinute = (int) date('i', $cronStartTime);  // The cast is especially important here to get rid of a possible leading zero.

		return $dailyRecordImportHour === $currentHour && $dailyRecordImportMinute === $currentMinute;
	}

	function importRecords($url, $project){
		$apiKey = $project['api-key'];

		$this->log("
			<div>Exporting records from the remote project titled:</div>
			<div class='remote-project-title'>" . $this->getProjectTitle($url, $apiKey) . "</div>
		");

		$fieldNames = $this->apiRequest($url, $apiKey, [
			'content' => 'exportFieldNames'
		]);

		$recordIdFieldName = $fieldNames[0]['export_field_name'];

		$records = $this->apiRequest($url, $apiKey, [
			'content' => 'record',
			'fields' => [$recordIdFieldName]
		]);

		$recordIds = [];
		foreach($records as $record){
			$recordIds[] = $record[$recordIdFieldName];
		}

		// Use the number of fields times number of records as a metric to determine a reasonable chunk size.
		// The following calculation caused about 500MB of maximum memory usage when importing the TIN Database (pid 61715) on the Vanderbilt REDCap test server.
		$numberOfDataPoints = count($fieldNames) * count($recordIds);
		$numberOfBatches = $numberOfDataPoints / 100000;
		$batchSize = round(count($recordIds) / $numberOfBatches);
		$chunks = array_chunk($recordIds, $batchSize);

		for($i=0; $i<count($chunks); $i++){
			$chunk = $chunks[$i];

			$batchText = "batch " . ($i+1) . " of " . count($chunks);

			$this->log("Exporting $batchText");
			$response = $this->apiRequest($url, $apiKey, [
				'content' => 'record',
				'format' => 'json',
				'records' => $chunk
			]);

			$this->prefixRecordIds($response, $recordIdFieldName, $project['record-id-prefix']);

			$stopEarly = $this->importBatch($project, $batchText, $batchSize, $response);

			if($stopEarly){
				return;
			}
		}
	}

	private function prefixRecordIds(&$data, $recordIdFieldName, $prefix){
		foreach($data as &$instance){
			$instance[$recordIdFieldName] = $prefix . $instance[$recordIdFieldName];
		}
	}

	private function importBatch($project, $batchTextPrefix, $batchSize, $response){
		// Split the import up into chunks as well to handle projects with many instances per record ID.
		$chunks = array_chunk($response, $batchSize);
		$batchCount = count($chunks);

		for($i=0; $i<$batchCount; $i++){
			$chunk = $chunks[$i];

			$batchNumber = $i+1;
			$batchText = $batchTextPrefix . ", sub-batch $batchNumber of $batchCount";

			$this->log("Importing $batchText (and overwriting matching local records)");
			$results = \REDCap::saveData(
					(int)$this->getProjectId(),
					'json',
					json_encode($chunk),
					'overwrite',
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					null,
					true // $removeLockedFields - We want to allow importing of locked forms/instances.
			);

			$results = $this->adjustSaveResults($results);

			$stopEarly = false;
			if(empty($results['errors'])){
				$message = "completed ";

				if(empty($results['warnings'])){
					$message .= 'successfully';
				}
				else{
					$message .= 'with warnings';
				}
			}
			else{
				$message = "did NOT complete successfully";
				$stopEarly = true;
			}

			$this->log("Import $message for $batchText", [
				'details' => json_encode($results, JSON_PRETTY_PRINT)
			]);

			if(!$project['leave-unlocked']){
				$this->log("Locking all forms/instances for $batchText");
				$this->framework->records->lock($results['ids']);
			}

			if($stopEarly){
				$this->sendErrorEmail("REDCap was unable to import some record data.");
				return true;
			}
		}

		return false;
	}

	private function adjustSaveResults($results){
		$results['warnings'] = array_filter($results['warnings'], function($warning){
			global $lang;

			if(strpos($warning[3], $lang['data_import_tool_197']) !== -1){
				return false;
			}

			return true;
		});

		return $results;
	}

	private function getProjectTitle($url, $apiKey){
		$response = $this->apiRequest($url, $apiKey, [
			'content' => 'project'
		]);

		return $response['project_title'];
	}

	private function apiRequest($url, $apiKey, $data){
		if(strpos($url, '://') === false){
			$url = "https://$url";
		}

		$data = array_merge(
			[
				'token' => $apiKey,
				'format' => 'json',
				'type' => 'flat',
				'rawOrLabel' => 'raw',
				'rawOrLabelHeaders' => 'raw',
				'exportCheckboxLabel' => 'false',
				'exportSurveyFields' => 'false',
				'exportDataAccessGroups' => 'false',
				'returnFormat' => 'json'
			],
			$data
		);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "$url/api/");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));

		$output = curl_exec($ch);

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);

		curl_close($ch);

		if(!empty($error)){
			throw new Exception("CURL Error: $error");
		}

		if($httpCode !== 200){
			throw new Exception("HTTP error code $httpCode received: $output");
		}

		$decodedOutput = json_decode($output, true);
		if(!$decodedOutput){
			throw new Exception("An unexpected response was returned: $output");
		}

		return $decodedOutput;
	}

	function validateSettings($settings){
		$checkNumericSetting = function($settingKey, $settingName, $min, $max) use ($settings) {
			$values = $settings[$settingKey];
			foreach($values as $value){
				if (!empty($value) && (!ctype_digit($value) || $value < $min || $value > $max)) {
					return "The $settingName specified must be between $min and $max.\n";
				}
			}
		};

		$message = "";
		$message .= $checkNumericSetting('daily-record-import-hour', 'hour', 0, 23);
		$message .= $checkNumericSetting('daily-record-import-minute', 'minute', 0, 59);

		return $message;
	}

	function renderSyncNowHtml(){
		$syncNow = $this->getProjectSetting('sync-now');
		$currentSyncMessage = null;
		if($syncNow){
			$currentSyncMessage = "An import is scheduled to start in less than a minute...";
		}
		else{
			$result = $this->query("
				select cron_run_start
				from redcap_crons c
				join redcap_crons_history h
					on c.cron_id = h.cron_id
				join redcap_external_modules m
					on m.external_module_id = c.external_module_id
				where 
					directory_prefix = '" . $this->PREFIX . "'
					and cron_run_end is null
				order by ch_id desc
			");

			$row = $result->fetch_assoc();
			if($row){
				$currentSyncMessage = "A sync is in progress...";
			}
		}

		if($currentSyncMessage){
			?>
			<p><?=$currentSyncMessage?>  For information on canceling it, <a href="javascript:ExternalModules.Vanderbilt.APISyncExternalModule.showSyncCancellationDetails()" style="text-decoration: underline">click here</a>.</p>

			<div id="api-sync-module-cancellation-details" style="display: none;">
				<p>Only a REDCap system administrator can cancel a sync in progress.</p>

				<p>If you are an administrator, make sure any long running cron processes have finished (or kill them manually).  Once you're sure no cron API Sync tasks are still running, use the following query to manually mark the previous API Sync job as completed so another one can be started:</p>
				<br>
				<br>
				<pre>
					update
						redcap_crons c
						join redcap_crons_history h
							on c.cron_id = h.cron_id
						join redcap_external_modules m
							on m.external_module_id = c.external_module_id
					set
						cron_run_end = now(),
						cron_info = 'The job died unexpectedly.  The run end time was manually set via SQL query.'
					where
						directory_prefix = '<?=$this->PREFIX?>'
						and cron_run_end is null
				</pre>
			</div>
			<?php
		}
		else{
			?>
			<form action="<?=$this->getUrl('sync-now.php')?>" method="post">
				<button>Import Now</button> - Imports from all sources now.
			</form>
			<?php
		}
	}

	function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id){
		$this->queueForUpdate($record);
	}

	function queueForUpdate($record){
		$this->setRecordStatus($record, self::getRecordStatus(self::UPDATE, self::QUEUED));
	}

	function redcap_every_page_before_render(){
	    if(@$_GET['route'] == 'DataEntryController:deleteRecord'){
	        $this->queueForDelete($_POST['record']);
	    }
	}

	function queueForDelete($record){
		$this->setRecordStatus($record, self::getRecordStatus(self::DELETE, self::QUEUED));
	}

	private function setRecordStatus($record, $status){
		$this->setProjectSetting(self::RECORD_STATUS_KEY_PREFIX . $record, $status);
	}

	private function getRecordStatus($type, $status){
		return "$type $status";
	}

	/*
	 * This method will be included in framework version 3, likely in REDCap version 9.3.1.
	 * If/when the min REDCap version for this module increases past that point,
	 * this method can be removed.
	 */
	function getRecordIdField($pid = null){
		$pid = db_escape($this->requireProjectId($pid));

		$result = $this->query("
			select field_name
			from redcap_metadata
			where project_id = $pid
			order by field_order
			limit 1
		");

		$row = $result->fetch_assoc();

		return $row['field_name'];
	}
}