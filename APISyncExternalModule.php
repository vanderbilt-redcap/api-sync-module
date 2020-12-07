<?php
namespace Vanderbilt\APISyncExternalModule;

require_once __DIR__ . '/Progress.php';

use Exception;
use REDCap;

class APISyncExternalModule extends \ExternalModules\AbstractExternalModule{
	const RECORD_STATUS_KEY_PREFIX = 'record-status-';
	const IMPORT_PROGRESS_SETTING_KEY = 'import-progress';

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

	function getFieldsWithoutIdentifiers(){
		$fields = REDCap::getDataDictionary($this->getProjectId(), 'array');
		$fieldNames = [];
		foreach($fields as $fieldName=>$details){
			if($details['identifier'] !== 'y'){
				$fieldNames[] = $fieldName;
			}
		}

		return $fieldNames;
	}

	private function export($servers, $type){
		if(!$this->isTimeToRunExports()){
			return;
		}

		/**
		 * WHEN MODIFYING EXPORT BEHAVIOR
		 * If further export permissions tweaks are made, Paul recommended selecting
		 * an API key to use/mimic during export to make use of existing export options.
		 * This would replace the $dateShiftDates and getFieldsWithoutIdentifiers() features below.
		 * The existing solutions may still be better, but we should consider
		 * this alternative just to make sure.
		 */

		$recordIdFieldName = $this->getRecordIdField();
		$dateShiftDates = $this->getProjectSetting('export-shift-dates') === true;
		
		$fields = []; // An empty array will cause all fields to be included by default
		if($this->getProjectSetting('export-exclude-identifiers') === true){
			$fields = $this->getFieldsWithoutIdentifiers();
		}

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
				$data = REDCap::getData(
					$this->getProjectId(),
					'json',
					$recordIds,
					$fields,
					[],
					[],
					false,
					false,
					false,
					false,
					false,
					false,
					false,
					$dateShiftDates
				);
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

                        $recordIdPrefix = $project['export-record-id-prefix'];

                        if($type === self::UPDATE){
							if($recordIdPrefix){
								$data = json_decode($data, true);
								$this->prepareImportData($data, $recordIdFieldName, $recordIdPrefix);
								$data = json_encode($data, JSON_PRETTY_PRINT);
							}

							$args['overwriteBehavior'] = 'overwrite';
							$args['data'] = $data;
						}
						else if($type === self::DELETE){
                            if ($recordIdPrefix) {
                                foreach ($recordIds as &$rId) {
                                    $rId = $recordIdPrefix . $rId;
                                }
                            }

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
		/**
		 * The following query may deadlock on large updates.  Possible solutions include:
		 * 1. Performing a select first
		 * 2. Updating rows individually or in batches instead of all at once.
		 */
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
		$progress = new Progress($this);

		$servers = $this->framework->getSubSettings('servers');
		foreach($servers as $server){
			if($this->isTimeToRunImports($server)){
				// addServer() will have no effect if the server is already in progress.
				$progress->addServer($server);
			}
		}

		try{
			$this->importNextBatch($progress);
		}
		catch(Exception $e){
			$this->handleException($e);
			$progress->finishCurrentProject();
		}

		$this->setImportProgress($progress->serialize());
	}

	function getImportProgress(){
		return $this->getProjectSetting(self::IMPORT_PROGRESS_SETTING_KEY);
	}

	function setImportProgress($progress){
		$this->setProjectSetting(self::IMPORT_PROGRESS_SETTING_KEY, $progress);
	}

	function formatURLForLogs($url){
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

	private function isTimeToRunExports(){
		$exportNow = $this->getProjectSetting('export-now');
		if($exportNow){
			$this->removeProjectSetting('export-now');
			return true;
		}

		if($this->getProjectSetting('export-every-minute')){
			return true;
		}

		$minute = $this->getProjectSetting('export-minute');
		$hour = $this->getProjectSetting('export-hour');

		return $this->isTimeToRun($minute, $hour);
	}

	private function isTimeToRunImports($server){
		$syncNow = $this->getProjectSetting('sync-now');
		if($syncNow){
			$this->removeProjectSetting('sync-now');
			return true;
		}

		return $this->isTimeToRun(
			$server['daily-record-import-minute'],
			$server['daily-record-import-hour']
		);
	}

	private function isTimeToRun($minute, $hour){
		if(empty($minute)){
			return false;
		}

		$minute = (int) $minute;

		// We check the cron start time instead of the current time
		// in case another module's cron job ran us into the next minute.
		$cronStartTime = $_SERVER["REQUEST_TIME_FLOAT"];
		$currentMinute = (int) date('i', $cronStartTime);  // The cast is especially important here to get rid of a possible leading zero.

		if($minute !== $currentMinute){
			return false;
		}

		if(empty($hour)){
			return true;
		}

		$hour = (int) $hour;
		$currentHour = (int) date('G', $cronStartTime);
		return $hour === $currentHour;
	}

	function importNextBatch(&$progress){
		$project =& $progress->getCurrentProject();
		if($project === null){
			// No projects are in progress.
			return;
		}

		$url = $progress->getCurrentServerUrl();
		$apiKey = $project['api-key'];
		
		if($progress->getBatchIndex() === 0){
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

			$batchSize = @$project['import-batch-size'];
			if(empty($batchSize)){
				// This calculation should NOT be changed without testing older PHP versions.
				// PHP 7 is much more memory efficient on REDCap imports than PHP 5.
				// Use the number of fields times number of records as a metric to determine a reasonable chunk size.
				// The following calculation caused about 500MB of maximum memory usage when importing the TIN Database (pid 61715) on the Vanderbilt REDCap test server.
				$numberOfDataPoints = count($fieldNames) * count($recordIds);
				$numberOfBatches = $numberOfDataPoints / 100000;
				$batchSize = round(count($recordIds) / $numberOfBatches);
			}

			$project['record-ids'] = $recordIds;
			$project['record-id-field-name'] = $recordIdFieldName;
			$project['import-batch-size'] = $batchSize;
		}
		else{
			$recordIds = $project['record-ids'];
			$recordIdFieldName = $project['record-id-field-name'];
			$batchSize = $project['import-batch-size'];
		}

		$batches = array_chunk($recordIds, $batchSize);
		$batchIndex = $progress->getBatchIndex();
		$batch = $batches[$batchIndex];
		$batchText = "batch " . ($batchIndex+1) . " of " . count($batches);

		$this->log("Exporting $batchText");
		$response = $this->apiRequest($url, $apiKey, [
			'content' => 'record',
			'format' => 'json',
			'records' => $batch
		]);

		$this->prepareImportData($response, $recordIdFieldName, $project['record-id-prefix']);

		$stopEarly = $this->importBatch($project, $batchText, $batchSize, $response, $progress);
		
		$progress->incrementBatch();
		if($progress->getBatchIndex() === count($batches) || $stopEarly){
			$progress->finishCurrentProject();
		}
	}

	private function prepareImportData(&$data, $recordIdFieldName, $prefix){
		$metadata = $this->getMetadata($this->getProjectId());
		$formNamesByField = [];
		foreach($metadata as $fieldName=>$field){
			$formNamesByField[$fieldName] = $field['form_name'];
		}

		foreach($data as &$instance){
			$instance[$recordIdFieldName] = $prefix . $instance[$recordIdFieldName];

			$this->removeInvalidIncompleteStatuses($instance, $formNamesByField);
		}
	}

	private function removeInvalidIncompleteStatuses(&$instance, $formNamesByField){
		$formValueCounts = [];
		foreach($formNamesByField as $fieldName=>$formName){
			if(!isset($formValueCounts[$formName])){
				$formValueCounts[$formName] = 0;
			}

			$value = @$instance[$fieldName];
			if(
				$value !== ''
				// The following likely means the field only exists in the local project...we'll allow that without an error for now...
				&& $value !== null 
			){
				$formValueCounts[$formName]++;
			}
		}

		foreach($formValueCounts as $formName=>$count){
			$completeFieldName = "{$formName}_complete";
			if($count === 0 && $instance[$completeFieldName] === '0'){
				// Remove complete statuses for forms without data.
				// We do this because REDCap incorrectly introduces incomplete ('0') statuses during export if no status is actually set.
				// This will misrepresent the case where someone intentionally marked a form as incomplete without setting any data,
				// but that's not as important of a use case.
				unset($instance[$completeFieldName]);
			}
		}
	}

	private function importBatch($project, $batchTextPrefix, $batchSize, $response, &$progress){
		// Split the import up into chunks as well to handle projects with many instances per record ID.
		$chunks = array_chunk($response, $batchSize);
		$batchCount = count($chunks);

		/**
		 * To make cron runs more predictably, we could only run one batch (or a couple of batches)
		 * per cron run, then continue the job during the next cron process.
		 */
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
			$logParams = [
				'details' => json_encode($results, JSON_PRETTY_PRINT)
			];

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
				$logParams['failure'] = true;
				$logParams['progress'] = $progress->serialize();
			}

			$this->log("Import $message for $batchText", $logParams);

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

		$url = "$url/api/";

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

		if($this->getProjectSetting('log-requests')){
			$this->log('API Request', [
				'details' => json_encode(array_merge($data,[
					'url' => $url
				]), JSON_PRETTY_PRINT),
			]);
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
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
			if(!is_array($values)){
				$values = [$values];
			}

			foreach($values as $value){
				if (!empty($value) && (!ctype_digit($value) || $value < $min || $value > $max)) {
					return "The $settingName specified must be between $min and $max.\n";
				}
			}
		};

		$message = "";
		$message .= $checkNumericSetting('daily-record-import-hour', 'import hour', 0, 23);
		$message .= $checkNumericSetting('daily-record-import-minute', 'import minute', 0, 59);
		$message .= $checkNumericSetting('export-hour', 'export hour', 0, 23);
		$message .= $checkNumericSetting('export-minute', 'export minute', 0, 59);

		return $message;
	}

	function isImportInProgress(){
		return $this->getImportProgress() !== null;  
	}

	function renderSyncNowHtml(){
		$syncNow = $this->getProjectSetting('sync-now');
		$currentSyncMessage = null;
		if($syncNow){
			$currentSyncMessage = "An import is scheduled to start in less than a minute.";
		}
		else if ($this->isImportInProgress()){
			$currentSyncMessage = "A sync is in progress.";
		}

		if($currentSyncMessage){
			?>
			<p>
				<?=$currentSyncMessage?>  <a href="<?=$this->getUrl('cancel-sync.php');?>">Click here</a> to cancel it.
			</p>
			<?php
		}
		else{
			$syncNowUrl = $this->getUrl('sync-now.php');
			?>
			<form action="<?=$syncNowUrl?>" method="post">
				<button>Import Now</button> - Imports from all sources now.
			</form>
			<form action="<?=$syncNowUrl?>" method="post" class="retry" style="display: none">
				<button>Retry Failed Import</button> - Continues the last failed import where it left off.
				<input type="hidden" name="retry-log-id">
			</form>
			<?php
		}
	}

	function cancelSync(){
		$this->log("Cancelling current import");
		$this->removeProjectSetting('sync-now');
		$this->removeProjectSetting(self::IMPORT_PROGRESS_SETTING_KEY);
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

// Shim for function that doesn't exist until php 7.
// This is safe because it's defined in the module's namespace.
function array_key_first($array){
	reset($array);
	return key($array);
}