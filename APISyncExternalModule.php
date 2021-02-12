<?php
namespace Vanderbilt\APISyncExternalModule;

require_once __DIR__ . '/classes/Progress.php';
require_once __DIR__ . '/classes/BatchBuilder.php';

use DateTime;
use Exception;
use REDCap;
use stdClass;

class APISyncExternalModule extends \ExternalModules\AbstractExternalModule{
	const IMPORT_PROGRESS_SETTING_KEY = 'import-progress';

	const UPDATE = 'update';
	const DELETE = 'delete';

	const EXPORT_CANCELLED_MESSAGE = 'Export cancelled.';

	const DATA_VALUES_MAX_LENGTH = (2^16) - 1;
	const MAX_LOG_QUERY_PERIOD = '1 week';

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
		// In case the previous export was cancelled, or the button pushed when an export wasn't active.
		$this->setExportCancelled(false);

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

		try{
			$this->export($servers);
		}
		catch(\Exception $e){
			if($e->getMessage() === self::EXPORT_CANCELLED_MESSAGE){
				// No reason to report this exception since this is an expected use case.
			}
			else{
				$this->handleException($e);
			}
		}
	}

	function getIdentifiers(){
		$fields = REDCap::getDataDictionary($this->getProjectId(), 'array');
		$fieldNames = [];
		foreach($fields as $fieldName=>$details){
			if($details['identifier'] === 'y'){
				$fieldNames[] = $fieldName;
			}
		}

		return $fieldNames;
	}

	// This method can be removed once it makes it into a REDCap version.
	private function getLogTable(){
		$result = $this->query('select log_event_table from redcap_projects where project_id = ?', $this->getProjectId());
		return $result->fetch_assoc()['log_event_table'];
	}

	private function getLastExportedLogId(){
		$result = $this->query(
			"
				select log_event_id
				from " . $this->getLogTable() . "
				where
					project_id = ?
					and ts >= ?
				order by log_event_id asc
				limit 1;
			",
			[
				$this->getProjectId(),
				(new DateTime)->modify('-' . self::MAX_LOG_QUERY_PERIOD)->format('YmdHis')
			]
		);

		$weekOldId = $result->fetch_assoc()['log_event_id'];
		$lastExportedId = $this->getProjectSetting('last-exported-log-id');

		/**
		 * Even if a last exported ID is set, we never want to look back more than week
		 * because I don't trust REDCap's current indexing to handle queries looking back than far.
		 */
		return max($weekOldId, $lastExportedId);
	}

	private function getLatestLogId(){
		$result = $this->query("
			select log_event_id
			from " . $this->getLogTable() . "
			order by log_event_id desc
			limit 1;
		", []);

		$row = $result->fetch_assoc();
		return $row['log_event_id'];
	}

	private function getAllFieldNames(){
		$dictionary = REDCap::getDataDictionary($this->getProjectId(), 'array');
		return array_keys($dictionary);
	}

	private function getBatchesToSync(){
		$batchBuilder = new BatchBuilder($this->getExportBatchSize());

		if($this->getProjectSetting('export-all-records') === true){
			$this->removeProjectSetting('export-all-records');

			$recordIdFieldName = $this->getRecordIdField();
			$records = json_decode(REDCap::getData($this->getProjectId(), 'json', null, $recordIdFieldName), true);
			$latestLogId = $this->getLatestLogId();

			foreach($records as $record){
				// An empty fields array will cause all fields to be pulled.
				$batchBuilder->addEvent($latestLogId, $record[$recordIdFieldName], 'UPDATE', []);
			}

			return $batchBuilder->getBatches();
		}

		$lastExportedLogId = $this->getLastExportedLogId();		
		$result = $this->query("
			select log_event_id, pk, event, data_values
			from " . $this->getLogTable() . "
			where
				event in ('INSERT', 'UPDATE', 'DELETE')
				and project_id = ?
				and log_event_id > ?
			order by log_event_id asc
		", [
			$this->getProjectId(),
			$lastExportedLogId
		]);

		while($row = $result->fetch_assoc()){
			$fields = $this->getChangedFieldNamesForLogRow($row['data_values'], $this->getAllFieldNames());
			$batchBuilder->addEvent($row['log_event_id'], $row['pk'], $row['event'], $fields);
		}

		$batches = $batchBuilder->getBatches();
		return $batches;
	}

	function getChangedFieldNamesForLogRow($dataValues, $allFieldNames){
		if(strlen($dataValues) === self::DATA_VALUES_MAX_LENGTH){
			// The data_values column was maxed out, so all changes were not included.
			// Sync all fields to make sure all changes are synced.
			return $allFieldNames;
		}

		preg_match_all('/\n([a-z0-9_]+)/', "\n$dataValues", $matches);

		/**
		 * There are cases where non-field names will be matched (ex: the text after a newline in a textarea).
		 * Use array_intersect() to weed these invalid field names out.
		 */
		return array_intersect($allFieldNames, $matches[1]);
	}

	private function export($servers){
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
		$maxSubBatchSize = $this->getExportSubBatchSize();

		$excludedFieldNames = [];
		if($this->getProjectSetting('export-exclude-identifiers') === true){
			$excludedFieldNames = $this->getIdentifiers();
		}

		$batches = $this->getBatchesToSync();
		for($i=0; $i<count($batches); $i++) {
			$batch = $batches[$i];

			$type = $batch->getType();
			$lastLogId = $batch->getLastLogId();
			$recordIds = $batch->getRecordIds();
			$fieldsByRecord = $batch->getFieldsByRecord();

			$batchText = "batch " . ($i+1) . " of " . count($batches);

			$this->log("Preparing to export {$type}s for $batchText", [
				'details' => json_encode([
					'Last Log ID' => $lastLogId,
					'Record IDs' => $recordIds
				], JSON_PRETTY_PRINT)
			]);

			if($type === self::UPDATE){
				$fields = $batch->getFields();

				if(!empty($fields)){
					$fields[] = $recordIdFieldName;
				}

				$data = json_decode(REDCap::getData(
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
				), true);

				$subBatchData = [];
				$subBatchSize = 0;
				$subBatchNumber = 1;
				for($rowIndex=0; $rowIndex<count($data); $rowIndex++){
					$row = $data[$rowIndex];
					foreach($batch->getFields() as $field){
						if(!isset($fieldsByRecord[$row[$recordIdFieldName]][$field])){
							// This field didn't change for this record, so don't include it in the export.
							unset($row[$field]);
						}
					}

					foreach($excludedFieldNames as $excludedFieldName){
						unset($row[$excludedFieldName]);
					}

					$rowSize = strlen(json_encode($row));

					$spaceLeftInSubBatch = $maxSubBatchSize - $subBatchSize;
					if($rowSize > $spaceLeftInSubBatch){
						if($subBatchSize === 0){
							$this->log("The export failed because the sub-batch size setting is not large enough to handle the data in the details of this log message.", [
								'details' => json_encode($row, JSON_PRETTY_PRINT)
							]);

							throw new Exception("The export failed because of a sub-batch size error.  See the API Sync page for project " . $this->getProjectId() . " for details.");
						}

						$this->exportSubBatch($servers, $type, $subBatchData, $subBatchNumber);
						$subBatchData = [];
						$subBatchSize = 0;
						$subBatchNumber++;
					}

					$subBatchData[] = $row;
					$subBatchSize += $rowSize;

					$isLastRow = $rowIndex === count($data)-1;
					if($isLastRow){
						$this->exportSubBatch($servers, $type, $subBatchData, $subBatchNumber);
					}
				}
			}
			else if($type === self::DELETE){
				$this->exportSubBatch($servers, $type, $recordIds, 1);
			}
			else{
				throw new Exception("Unsupported export type: $type");
			}

			$this->setProjectSetting('last-exported-log-id', $lastLogId);
			$this->log("Finished exporting {$type}s for $batchText");
		}
	}

	private function exportSubBatch($servers, $type, $data, $subBatchNumber){
		$recordIdFieldName = $this->getRecordIdField();

		foreach ($servers as $server) {
			$url = $server['export-redcap-url'];
			$logUrl = $this->formatURLForLogs($url);

			foreach ($server['export-projects'] as $project) {
				$getProjectExportMessage = function($action) use ($type, $subBatchNumber, $logUrl, $project){
					return "
						<div>$action exporting $type sub-batch $subBatchNumber to the following project at $logUrl:</div>
						<div class='remote-project-title'>" . $project['export-project-name'] . "</div>
					";
				};

				$this->log($getProjectExportMessage('Started'));

				$apiKey = $project['export-api-key'];

				$args = ['content' => 'record'];
				
				$recordIdPrefix = $project['export-record-id-prefix'];

				if($type === self::UPDATE){
					if($recordIdPrefix){
						$this->prepareImportData($data, $recordIdFieldName, $recordIdPrefix);
					}

					$args['overwriteBehavior'] = 'overwrite';
					$args['data'] = json_encode($data, JSON_PRETTY_PRINT);;
				}
				else if($type === self::DELETE){
					if ($recordIdPrefix) {
						foreach ($data as &$rId) {
							$rId = $recordIdPrefix . $rId;
						}
					}

					$args['action'] = 'delete';
					$args['records'] = $data;
				}

				$results = $this->apiRequest($url, $apiKey, $args);

				$this->log(
					$getProjectExportMessage('Finished'),
					['details' => json_encode($results, JSON_PRETTY_PRINT)]
				);

				if($this->isExportCancelled()){
					$this->log(self::EXPORT_CANCELLED_MESSAGE);
					throw new \Exception(self::EXPORT_CANCELLED_MESSAGE);
				}
			}
		}
	}

	function isExportCancelled(){
		return $this->getProjectSetting('export-cancelled') === true;
	}

	function setExportCancelled($value){
		return $this->setProjectSetting('export-cancelled', $value);
	}

	private function getExportBatchSize(){
		$size = (int) $this->getProjectSetting('export-batch-size');
		if(!$size){
			$size = 100;
		}

		return $size;
	}

	private function getExportSubBatchSize(){
		$size = $this->getProjectSetting('export-sub-batch-size');
		if($size === null){
			/**
			 * Size estimates won't be exact, so we use 7MB to make sure we stay below an 8MB maximum.
			 * This is chosen semi-arbitrarily.  For SAVE O2 We did see that OSHU's REDCap server
			 * seemed to be limited to sending 16MB curl POST requests.
			 */
			$size = 7;
		}

		// Return the size in bytes
		return $size*1024*1024;
	}

	function clearExportQueue(){
		$this->setProjectSetting('last-exported-log-id', $this->getLatestLogId());
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

		if(count($parts) === 1){
			$domainName = $parts[0];
		}
		else{
			$domainName = $parts[1];
		}

		return "<b><a href='$url' target='_blank'>$domainName</a></b>";
	}

	private function handleException($e){
		$this->log("An error occurred.  Click 'Show Details' for more info.", [
			'details' => $e->getMessage() . "\n" . $e->getTraceAsString()
		]);

		$this->sendErrorEmail("The API Sync module has encountered an error on project " . $this->getProjectId() . ".  If this error is not addressed within " . self::MAX_LOG_QUERY_PERIOD . ", some changes will not be automatically synced.");
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

		// will only build translations if doc_id present in $project['form-translations'/'event-translations']
		$this->buildTranslations($project);

		// translate foreign form names to local form names if form-translations array present in project
		if (gettype($project['form-translations']) == 'array') {
			$this->translateFormNames($response, $project['form-translations']);
		}

		// translate foreign event names to local event names if event-translations array present in project
		if (gettype($project['event-translations']) == 'array') {
			$this->translateEventNames($response, $project['event-translations']);
		}

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
			$currentSyncMessage = "An import may be in progress.";
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
	
	private function translateFormNames(&$data, $translations) {
		// translate [redcap_repeat_instrument] and [$form . '_complete'] fields where applicable
		foreach ($data as &$instance) {
			foreach ($translations as $local_name => $foreign_names) {
				// [redcap_repeat_instrument] component
				if (in_array($instance['redcap_repeat_instrument'], $foreign_names, true)) {
					$instance['redcap_repeat_instrument'] = $local_name;
				}
				
				// [$form . '_complete'] components
				foreach($foreign_names as $foreign_form_name) {
					if (isset($instance[$foreign_form_name . '_complete'])) {
						$instance[$local_name . '_complete'] = $instance[$foreign_form_name . '_complete'];
						unset($instance[$foreign_form_name . '_complete']);
					}
				}
			}
		}
	}
	
	private function translateEventNames(&$data, $translations) {
		// translate [redcap_event_name] fields where applicable
		foreach ($data as &$instance) {
			foreach ($translations as $local_name => $foreign_names) {
				// [redcap_event_name] component
				$event_name_pieces = explode('_arm_', $instance['redcap_event_name']);
				$event_name = $event_name_pieces[0];
				$event_arm_number = $event_name_pieces[1];
				if (in_array($event_name, $foreign_names, true)) {
					$instance['redcap_event_name'] = $local_name . "_arm_" . $event_arm_number;
					break;
				}
			}
		}
	}
	
	private function buildTranslations(&$project) {
		// if user supplied a form-translations file, convert it to array usable by translateFormNames
		if (ctype_digit($project['form-translations'])) {
			// get file information for configured form translations file
			$form_doc_id = $project['form-translations'];
			$file_info = $this->getEdocInfo($form_doc_id);
			
			if ($file_info) {
				// ensure it's a plaintext csv that we can open and get a file handle to
				$extension = strtolower($file_info['file_extension']);
				$mime_type = strtolower($file_info['mime_type']);
				if (
					(strpos($extension, 'csv') !== false) AND
					(strpos($mime_type, 'text/plain') !== false) AND
					($file = fopen($file_info['filepath'], 'r'))
				) {
					$project['form-translations'] = [];
					while ($form_names = fgetcsv($file)) {
						// convert to REDCap form name format
						foreach ($form_names as $i => $label) {
							$form_names[$i] = $this->formatFormName($label);
						}
						
						$local_name = array_shift($form_names);
						$project['form-translations'][$local_name] = $form_names;
					}
				}
			}
		}
		
		// if user supplied a event-translations file, convert it to array usable by translateEventNames
		if (ctype_digit($project['event-translations'])) {
			// get file information for configured event translations file
			$event_doc_id = $project['event-translations'];
			$file_info = $this->getEdocInfo($event_doc_id);
			if ($file_info) {
				// ensure it's a plaintext csv that we can open and get a file handle to
				$extension = strtolower($file_info['file_extension']);
				$mime_type = strtolower($file_info['mime_type']);
				if (
					(strpos($extension, 'csv') !== false) AND
					(strpos($mime_type, 'text/plain') !== false) AND
					($file = fopen($file_info['filepath'], 'r'))
				) {
					$project['event-translations'] = [];
					while ($event_names = fgetcsv($file)) {
						// convert to REDCap event name format
						foreach ($event_names as $i => $label) {
							$event_names[$i] = $this->formatEventName($label);
						}
						
						$local_name = array_shift($event_names);
						$project['event-translations'][$local_name] = $event_names;
					}
				}
			}
		}
	}
	
	private function getEdocInfo($doc_id) {
		$pid = $this->getProjectId();
		if (!ctype_digit($doc_id) or !ctype_digit($pid)) {
			return false;
		}
		$sql = "SELECT doc_id, stored_name, doc_name, mime_type, file_extension FROM redcap_edocs_metadata WHERE project_id='$pid' AND doc_id='$doc_id'";
		$result = db_query($sql);
		while ($row = db_fetch_assoc($result)) {
			if ($row['doc_id'] == $doc_id) {
				$row['filepath'] = EDOC_PATH . $row['stored_name'];
				return $row;
			}
		}
	}

	private function formatEventName($event_label) {
		// // code copied from \Project::getUniqueEventNames
		$event_name = trim(label_decode($event_label));
		// Remove all spaces and non-alphanumeric characters, then make it lower case.
		$event_name = preg_replace("/[^0-9a-z_ ]/i", '', $event_name);
		$event_name = strtolower(substr(str_replace(" ", "_", $event_name), 0, 18));
		// Remove any underscores at the beginning
		while (substr($event_name, 0, 1) == "_") {
			$event_name = substr($event_name, 1);
		}
		// Remove any underscores at the end
		while (substr($event_name, -1, 1) == "_") {
			$event_name = substr($event_name, 0, -1);
		}
		// If event name is still blank (maybe because of using multi-byte characters)
		if ($event_name == '') {
			// Get first 10 letters of MD5 of the event label
			$event_name = substr(md5($event_label), 0, 10);
		}
		return $event_name;
	}

	private function formatFormName($form_label) {
		// code copied from \REDCap::setFormName
		$form_name = strip_tags(label_decode($form_label));
		$form_name = preg_replace("/[^a-z0-9_]/", "", str_replace(" ", "_", strtolower(html_entity_decode($form_name, ENT_QUOTES))));
		// Remove any double underscores, beginning numerals, and beginning/ending underscores
		while (strpos($form_name, "__") !== false) 		$form_name = str_replace("__", "_", $form_name);
		while (substr($form_name, 0, 1) == "_") 		$form_name = substr($form_name, 1);
		while (substr($form_name, -1) == "_") 			$form_name = substr($form_name, 0, -1);
		while (is_numeric(substr($form_name, 0, 1))) 	$form_name = substr($form_name, 1);
		while (substr($form_name, 0, 1) == "_") 		$form_name = substr($form_name, 1);
		// Cannot begin with numeral and cannot be blank
		if (is_numeric(substr($form_name, 0, 1)) || $form_name == "") {
			$form_name = substr(preg_replace("/[0-9]/", "", md5($form_name)), 0, 4) . $form_name;
		}
		// Make sure it's less than 50 characters long
		$form_name = substr($form_name, 0, 50);
		while (substr($form_name, -1) == "_") $form_name = substr($form_name, 0, -1);
		return $form_name;
	}
}

// Shim for function that doesn't exist until php 7.
// This is safe because it's defined in the module's namespace.
function array_key_first($array){
	reset($array);
	return key($array);
}