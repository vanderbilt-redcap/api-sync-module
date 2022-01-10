<?php
namespace Vanderbilt\APISyncExternalModule;

require_once __DIR__ . '/classes/Progress.php';
require_once __DIR__ . '/classes/BatchBuilder.php';

use DateTime;
use Exception;
use REDCap;
use stdClass;

const CHECKBOX_DELIMITER = '___';

class APISyncExternalModule extends \ExternalModules\AbstractExternalModule{
	const IMPORT_PROGRESS_SETTING_KEY = 'import-progress';

	const UPDATE = 'update';
	const DELETE = 'delete';
	const TRANSLATION_TABLE_CELL = "<td><textarea></textarea></td>";

	const EXPORT_CANCELLED_MESSAGE = 'Export cancelled.';

	const DATA_VALUES_MAX_LENGTH = (2^16) - 1;
	const MAX_LOG_QUERY_PERIOD = 7;

	private $settingPrefix;
	private $cachedSettings;

	function cron($cronInfo){
		/**
		 * We know 2g is required to prevent exports from crashing on the SAMMC project.
		 * This was set to 4g somewhat arbitrarily.  Hopefully that will cover many potential future use cases.
		 */
		ini_set('memory_limit', '4g');

		$originalPid = $_GET['pid'] ?? null;

		$cronName = $cronInfo['cron_name'];

		foreach($this->framework->getProjectsWithModuleEnabled() as $localProjectId){
			// This automatically associates all log statements with this project.
			$_GET['pid'] = $localProjectId;

			$this->settingPrefix = substr($cronName, 0, -1); // remove the 's'

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
		/**
		 * This amount of time was chosen semi-arbitrarily.
		 * A time limit less than the cron max run time of 24 hours is important.
		 * Ideally the cron wouldn't only run for a few minutes max, but VUMC Project 111585 has batches that last a couple of hours.
		 * We might as well set this high, at least until we can justify including sub-batches in the export progress.
		 */
		$twentyHours = 60*60*20;
		set_time_limit($twentyHours);

		// In case the previous export was cancelled, or the button pushed when an export wasn't active.
		$this->setExportCancelled(false);

		$servers = $this->framework->getSubSettings('export-servers');

		$firstServer = $servers[0] ?? null;
		$firstProject = $firstServer['export-projects'][0] ?? null;
		if($this->areAnyEmpty([
			$firstServer['export-redcap-url'] ?? null,
			$firstProject['export-api-key'] ?? null,
			$firstProject['export-project-name'] ?? null
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
				(new DateTime)->modify('-' . self::MAX_LOG_QUERY_PERIOD . ' days')->format('YmdHis')
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

	private function addBatchesSinceLastExport($batchBuilder){
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
		/**
		 * WHEN MODIFYING EXPORT BEHAVIOR
		 * If further export permissions tweaks are made, Paul recommended selecting
		 * an API key to use/mimic during export to make use of existing export options.
		 * This would replace the $dateShiftDates and getFieldsWithoutIdentifiers() features below.
		 * The existing solutions may still be better, but we should consider
		 * this alternative just to make sure.
		 */

		$recordIdFieldName = $this->getRecordIdField();

		$exportProgress = $this->getProjectSetting('export-progress');
		if($exportProgress === null){
			if(!$this->isTimeToRunExports()){
				return;
			}

			$startingBatchIndex = 0;

			$batchBuilder = new BatchBuilder($this->getExportBatchSize());
			$latestLogId = $this->getLatestLogId();

			$exportAllRecords = $this->getProjectSetting('export-all-records') === true;
			if($exportAllRecords){
				$this->removeProjectSetting('export-all-records');
				$records = json_decode(REDCap::getData($this->getProjectId(), 'json', null, $recordIdFieldName), true);

				foreach($records as $record){
					// An empty fields array will cause all fields to be pulled.
					$batchBuilder->addEvent($latestLogId, $record[$recordIdFieldName], 'UPDATE', []);
				}
			}
			else{
				$this->addBatchesSinceLastExport($batchBuilder);
			}

			$batches = $batchBuilder->getBatches();
			if(empty($batches)){
				/**
				 * No recent changes exist to sync.
				 * Update the last exported log ID to whatever the latest ID across all projects is
				 * in order to ensure that we don't check this date range again.
				 * This can reduce query time significantly on projects thar are updated infrequently,
				 * especially when the server is under heavy load.
				 */
				$this->setProjectSetting('last-exported-log-id', $latestLogId);
				return;
			}
		}
		else{
			// Continue an export in progress
			$this->removeProjectSetting('export-progress');

			[$startingBatchIndex, $batches] = unserialize($exportProgress);
		}

		$dateShiftDates = $this->getProjectSetting('export-shift-dates') === true;
		$maxSubBatchSize = $this->getExportSubBatchSize();

		$excludedFieldNames = [];
		if($this->getProjectSetting('export-exclude-identifiers') === true){
			$excludedFieldNames = $this->getIdentifiers();
		}

		for($i=0; $i<count($batches); $i++) {
			if($this->isCronRunningTooLong()){
				$remainingBatches = array_splice($batches, $i);

				$this->setProjectSetting('export-progress', serialize([
					$startingBatchIndex+$i,
					$remainingBatches
				]));

				return;
			}

			$batch = $batches[$i];

			$type = $batch->getType();
			$lastLogId = $batch->getLastLogId();
			$recordIds = $batch->getRecordIds();
			$fieldsByRecord = $batch->getFieldsByRecord();

			$batchText = "batch " . ($startingBatchIndex+$i+1) . " of " . ($startingBatchIndex+count($batches));

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
						if(
							$field !== $recordIdFieldName
							&&
							!isset($fieldsByRecord[$row[$recordIdFieldName]][$field])
						){
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

	private function isCronRunningTooLong(){
		return time() >= $_SERVER['REQUEST_TIME_FLOAT'] + 55;
	}

	function logDetails($message, $details){
		$parts = str_split($details, 65535);
		$params = [
			'details' => array_shift($parts)
		];

		$n = 2;
		foreach($parts as $part){
			$params["details$n"] = $part;
			$n++;
		}

		return $this->log($message, $params);
	}

	function getProjects($server){
		$fieldListSettingName = $this->getPrefixedSettingName("field-list");
		$projects = $server[$this->getPrefixedSettingName("projects")];

		$incorrectlyLocatedFieldLists = $projects[$fieldListSettingName] ?? null;
		if($incorrectlyLocatedFieldLists !== null){
			// Recover from a getSubSettings() bug which was fixed in framework version 9.
			foreach ($projects as $i=>&$project) {
				$project[$fieldListSettingName] = $incorrectlyLocatedFieldLists[$i] ?? null;
			}

			unset($projects[$fieldListSettingName]);
		}

		return $projects;
	}

	private function exportSubBatch($servers, $type, $data, $subBatchNumber){
		$recordIdFieldName = $this->getRecordIdField();

		foreach ($servers as $server) {
			$url = $server['export-redcap-url'];
			$logUrl = $this->formatURLForLogs($url);

			foreach ($this->getProjects($server) as $project) {
				$getProjectExportMessage = function($action) use ($type, $subBatchNumber, $logUrl, $project){
					return "
						<div>$action exporting $type sub-batch $subBatchNumber to the following project at $logUrl:</div>
						<div class='remote-project-title'>" . $project['export-project-name'] . "</div>
					";
				};

				$this->log($getProjectExportMessage('Started'));

				$apiKey = $project['export-api-key'];

				$args = ['content' => 'record'];
				
				if($type === self::UPDATE){
					$prepped_data = $this->prepareData($project, $data, $recordIdFieldName);
					$args['overwriteBehavior'] = 'overwrite';
					$args['data'] = json_encode($prepped_data, JSON_PRETTY_PRINT);
				}
				else if($type === self::DELETE){
					$recordIdPrefix = $project['export-record-id-prefix'];
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
			// A size of 100 caused our 4g memory limit to be reached on VUMC project 111585.
			$size = 50;
		}

		return $size;
	}

	private function getExportSubBatchSize(){
		$size = $this->getProjectSetting('export-sub-batch-size');
		if($size === null){
			/**
			 * A 7MB limit was added semi-arbitrarily.  We know requests greater than 16MB were truncated
			 * and returning an empty error message when OSHU was attempting to push to Vanderbilt.
			 * This value might be heavily dependent on the networks/firewalls between each specific source & destination.
			 */
			$size = 7;
		}

		// Return the size in bytes
		return $size*1024*1024;
	}

	function clearExportQueue(){
		$this->setProjectSetting('last-exported-log-id', $this->getLatestLogId());
	}

	private function getImportServers(){
		return $this->framework->getSubSettings('servers');
	}

	private function handleImports(){
		$progress = new Progress($this);

		$servers = $this->getImportServers();
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
			'details' => $e->getMessage() . "\n\n" . $e->getTraceAsString()
		]);

		$message = "The API Sync module has encountered an error on project " . $this->getProjectId() . ".  The sync will be automatically re-tried, but action is likely required before it will succeed.";

		if($this->settingPrefix === 'export'){
			/**
			 * In a worst case scenario, the first failed sync would not occur until approximately a day after the first unsynced change,
			 * and the next successful sync may not occur until a day after the problem is fixed.  Because of this, we advertise a window
			 * two days shorter than MAX_LOG_QUERY_PERIOD for fixing the issue.
			 */
			$fixDayRange = self::MAX_LOG_QUERY_PERIOD-2 . '-' . self::MAX_LOG_QUERY_PERIOD;

			$message .= "  If this message persists longer than an approximate $fixDayRange day cutoff, older changes will be skipped to conserve server resources.  This cutoff is not possible to predict precisely since it is dependent on actual cron run times for this and other modules.  If the cutoff is reached, a full sync (or manual export/import) will be required to ensure all older changes were synced.  If this message persists longer than " . self::MAX_LOG_QUERY_PERIOD . " days, please disable this sync to prevent unnecessary server resource consumption.";
		}

		$this->sendErrorEmail($message);
	}

	private function sendErrorEmail($message){
		$url = $this->getUrl('api-sync.php');
		$message .= "  See the logs on <a href='$url'>this page</a> for details.";

		$usernames = $this->getProjectSetting('error-recipients');
		$emails = [];
		if(!empty($usernames)){
			foreach($usernames as $username){
				if(!empty($username)){
					$emails[] = $this->getUser($username)->getEmail();
				}
			}
		}

		if(empty($emails)){
			$users = $this->getProject()->getUsers();
			foreach($users as $user){
				if($user->hasDesignRights()){
					$emails[] = $user->getEmail();
				}
			}
		}

		global $homepage_contact_email;

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

		$minute = $this->getProjectSetting('export-minute');
		$hour = $this->getProjectSetting('export-hour');

		return $this->isTimeToRun($minute, $hour, null);
	}

	private function isTimeToRunImports($server){
		$syncNow = $this->getProjectSetting('sync-now');
		if($syncNow){
			$this->removeProjectSetting('sync-now');
			return true;
		}

		return $this->isTimeToRun(
			$server['daily-record-import-minute'],
			$server['daily-record-import-hour'],
			$server
		);
	}

	private function isTimeToRun($minute, $hour, $server){
		if(empty($minute)){
			// Don't sync if this field is not set
			return false;
		}
		else if(empty($hour)){
			// We're syncing hourly, so use the current hour.
			$hour = 'H';
		}

		if($server === null){
			$lastRunTime = $this->getProjectSetting('last-export-time');
		}
		else{
			$lastRunTime = $server['last-import-time'] ?? null;
		}

		if(empty($lastRunTime)){
			/**
			 * This is the first time this sync has run.
			 * Don't actually sync, but set a last run time to the current time as if we did.
			 * This is will cause the next scheduled sync to occur normally.
			 */
			$this->setLastRunTime(time(), $server);
			return false;
		}

		$scheduledTime = strtotime(date("Y-m-d $hour:$minute:00"));
		if(
			$scheduledTime > time() // Not time to sync yet
			||
			/**
			 * Either the current scheduled sync has already run, or this sync has not run for the first time yet
			 * and we're waiting for the first scheduled time after the last run time was set initially above.
			 */
			$lastRunTime >= $scheduledTime
		){
			return false;
		}

		// Set the scheduled time instead of the actual run time to simplify checking logic.
		$this->setLastRunTime($scheduledTime, $server);

		return true;
	}

	private function setLastRunTime($scheduledTime, $server){
		/**
		 * The string cast is required to prevent the setting config dialog from omitting the value,
		 * and removing it from the database if settings are saved.
		 * This is really only needed for imports, but we do it for exports too for consistency.
		 */
		$scheduledTime = (string) $scheduledTime;

		if($server === null){
			$this->setProjectSetting('last-export-time', $scheduledTime);
		}
		else{
			$servers = $this->getImportServers();
			for($i=0; $i<count($servers); $i++){
				if($servers[$i]['redcap-url'] === $server['redcap-url']){
					$importTimes = $this->getProjectSetting('last-import-time');
					$importTimes[$i] = $scheduledTime;
					$this->setProjectSetting('last-import-time', $importTimes);
				}
			}
		}
	}

	function importNextBatch(Progress &$progress){
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
		
		$response = $this->prepareData($project, $response, $recordIdFieldName);
		
		$stopEarly = $this->importBatch($project, $batchText, $batchSize, $response, $progress);
		
		$progress->incrementBatch();
		if($progress->getBatchIndex() === count($batches) || $stopEarly){
			$progress->finishCurrentProject();
		}
	}

	private function prepareData(&$project, $data, $recordIdFieldName){
		// perform translations if configured
		$this->buildTranslations($project);
		if ($this->translationsAreBuilt($project)) {
			$this->translateFormNames($data, $project);
			$this->translateEventNames($data, $project);
		}
		
		$proj_key_prefix = $this->getProjectTypePrefix($project);
		$prefix = $project[$proj_key_prefix . 'record-id-prefix'];
		$metadata = $this->getMetadata($this->getProjectId());
		$formNamesByField = [];
		foreach($metadata as $fieldName=>$field){
			$formNamesByField[$fieldName] = $field['form_name'];
		}

		foreach($data as &$instance){
			if ($prefix) {
				$instance[$recordIdFieldName] = $prefix . $instance[$recordIdFieldName];
			}

			$this->removeInvalidIncompleteStatuses($instance, $formNamesByField);
			$this->filterByFieldList($project, $instance);
		}
		
		return $data;
	}

	private function getPrefixedSettingName($name){
		$prefix = $this->settingPrefix;

		if(
			// At some point it might make sense to refactor other references to use this function
			// and add more items to this array.
			in_array($name, ['projects'])
			&&
			$prefix === 'import'
		){
			// This setting predated the prefixing.  Do not prepend the prefix.
		}
		else{
			$name = "$prefix-$name";
		}

		return $name;
	}

	function filterByFieldList($project, &$instance){
		$type = $project[$this->getPrefixedSettingName('field-list-type')];
		$fieldList = $project[$this->getPrefixedSettingName('field-list')] ?? [];

		if(empty($type)){
			$type = $this->getCachedProjectSetting($this->getPrefixedSettingName('field-list-type-all'));
			$fieldList = $this->getCachedProjectSetting($this->getPrefixedSettingName('field-list-all'));
		}

		if($type === 'include'){
			$fieldList = array_merge($fieldList, $this->getREDCapIdentifierFields());
		}

		/**
		 * When nothing is selected, a lone null value appears.  Remove it.
		 */
		$fieldList = array_filter($fieldList);

		$fieldList = array_flip($fieldList);

		foreach(array_keys($instance) as $field){
			$fieldWithoutCheckboxSuffix = explode(CHECKBOX_DELIMITER, $field)[0];
			$isset = isset($fieldList[$fieldWithoutCheckboxSuffix]);

			if(
				($type === 'include' && !$isset)
				||
				($type === 'exclude' && $isset)
			){
				unset($instance[$field]);
			}
		}
	}

	private function getREDCapIdentifierFields(){
		if(!isset($this->redcapIdentifierFields)){
			$this->redcapIdentifierFields = [
				$this->getRecordIdField(),
				'redcap_event_name',
				'redcap_repeat_instrument',
				'redcap_repeat_instance'
			];
		}

		return $this->redcapIdentifierFields;
	}

	private function removeInvalidIncompleteStatuses(&$instance, $formNamesByField){
		$formValueCounts = [];
		foreach($formNamesByField as $fieldName=>$formName){
			if(!isset($formValueCounts[$formName])){
				$formValueCounts[$formName] = 0;
			}

			$value = $instance[$fieldName] ?? null;
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
			if($count === 0 && ($instance[$completeFieldName] ?? null) === '0'){
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

	private function isLocalhost($domainAndPath){
		$parts = explode('/', $domainAndPath);
		$domain = $parts[0];
		$ip = gethostbyname($domain);
		return $ip === '127.0.0.1';
	}

	private function apiRequest($url, $apiKey, $data){
		$separator = '://';
		$parts = explode($separator, $url);
		$domainAndPath = array_pop($parts);
		$protocol = array_pop($parts);
		$destinationIsLocalhost = $this->isLocalhost($domainAndPath);
		
		if(
			empty($protocol) // Add https if missing
			||
			!$destinationIsLocalhost  // Force non-localhost URLs to use HTTPS to protect API keys.
		){
			$protocol = 'https';
		}

		$url = $protocol . $separator . $domainAndPath . "/api/";

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

		if($this->getCachedProjectSetting('log-requests')){
			$this->logDetails('API Request', json_encode(array_merge($data,[
				'url' => $url
			]), JSON_PRETTY_PRINT));
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$destinationIsLocalhost);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));

		$tries = 0;
		while($tries < 3){
			$output = curl_exec($ch);

			if($this->getCachedProjectSetting('log-requests')){
				$this->logDetails('API Response', $output);
			}

			$errorNumber = curl_errno($ch);

			if($errorNumber === 56){
				/**
				 * This is a CURLE_RECV_ERROR like "SSL read" or "TCP connection reset by peer".
				 * These are most often cause by temporary network issues.
				 * Wait a few seconds and try again.
				 */
				sleep(3);
			}
			else{
				/**
				 * Either the request succeeded, or there was some other type of error.
				 * Either way, don't retry
				 */
				break;
			}

			$tries++;
		}

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);

		curl_close($ch);

		if(!empty($error)){
			throw new Exception("CURL Error $errorNumber: $error");
		}
		else if(empty($output)){
			throw new Exception("An empty response was received.  Automatic batch sizes may be too large, causing the remote server to run out of memory when parsing the request.  Please try reducing the batch or sub-batch size, and report this error to datacore@vumc.org so automatic batch size detection can be improved in this case.");
		}

		$decodedOutput = json_decode($output, true);

		if($httpCode !== 200){
			if(
				$httpCode === 400
				&&
				$data['action'] === 'delete'
				&&
				$decodedOutput['error'] === $GLOBALS['lang']['api_131'] . ' ' . $data['records'][0]
			){
				/**
				 * Do nothing.  This likely means the record was already manually deleted in the destination project,
				 * and can safely be ignored.
				 */
			}
			else{
				if(is_array($decodedOutput)){
					// If the output is valid JSON, include a more readable version of it in the exception.
					$output = json_encode($decodedOutput, JSON_PRETTY_PRINT);
				}

				throw new Exception("HTTP error code $httpCode received: $output");
			}
		}

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

	public function translateFormNames(&$data, &$project) {
		$proj_prefix = $this->getProjectTypePrefix($project);
		$translations = $project[$proj_prefix . 'form-translations'];
		if (gettype($translations) != 'array') {
			return;
		}
		
		// translate [redcap_repeat_instrument] and [$form . '_complete'] fields where applicable
		foreach ($data as &$instance) {
			foreach ($translations as $i => $form_names) {
				// [redcap_repeat_instrument] component
				$local_form_name = $form_names[0];
				$export_form_name = $form_names[1];
				if ($proj_prefix == 'export-') {
					// export logic
					if ($instance['redcap_repeat_instrument'] == $local_form_name) {
						$instance['redcap_repeat_instrument'] = $export_form_name;
					}
					
					// [$form . '_complete'] components
					if (isset($instance[$local_form_name . '_complete'])) {
						$instance[$export_form_name . '_complete'] = $instance[$local_form_name . '_complete'];
						unset($instance[$local_form_name . '_complete']);
					}
				} else {
					// import logic
					if (in_array($instance['redcap_repeat_instrument'], $form_names, true)) {
						$instance['redcap_repeat_instrument'] = $local_form_name;
					}
					
					// [$form . '_complete'] components
					foreach($form_names as $other_form_name) {
						if (isset($instance[$other_form_name . '_complete'])) {
							$instance[$local_form_name . '_complete'] = $instance[$other_form_name . '_complete'];
							unset($instance[$other_form_name . '_complete']);
						}
					}
				}
			}
		}
	}
	
	public function translateEventNames(&$data, &$project) {
		$proj_prefix = $this->getProjectTypePrefix($project);
		$translations = $project[$proj_prefix . 'event-translations'];
		if (gettype($translations) != 'array') {
			return;
		}
		// translate [redcap_event_name] fields where applicable
		foreach ($data as &$instance) {
			foreach ($translations as $event_names) {
				// [redcap_event_name] component
				$local_event_name = $event_names[0];
				$export_event_name = $event_names[1];
				$event_name_pieces = explode('_arm_', $instance['redcap_event_name']);
				$event_name = $event_name_pieces[0];
				$event_arm_number = $event_name_pieces[1];
				if ($proj_prefix == 'export-') {
					// export logic
					if ($event_name == $local_event_name) {
						$instance['redcap_event_name'] = $export_event_name . "_arm_" . $event_arm_number;
						break;
					}
				} else {
					// import logic
					if (in_array($event_name, $event_names, true)) {
						$instance['redcap_event_name'] = $local_event_name . "_arm_" . $event_arm_number;
						break;
					}
				}
			}
		}
	}
	
	public function buildTranslations(&$project) {
		// function will only build translations if json present in $project['form-translations'/'event-translations']
		if ($this->translationsAreBuilt($project)) {
			return;
		}
		$proj_key_prefix = $this->getProjectTypePrefix($project);
		
		foreach (['form', 'event'] as $type) {
			$setting = &$project[$proj_key_prefix . "$type-translations"];
			$setting = json_decode($setting, true);
			if ($setting) {
				foreach($setting as $i => $row) {
					foreach($row as $j => $name) {
						$func_name = "format" . ucfirst($type) . "Name";
						$setting[$i][$j] = $this->$func_name($name);
					}
				}
			} else {
				unset($project[$proj_key_prefix . "$type-translations"]);
			}
		}
	}

	private function translationsAreBuilt($project) {
		$translation_settings = [
			'form-translations',
			'event-translations',
			'export-form-translations',
			'export-event-translations'
		];
		foreach($translation_settings as $name) {
			if (gettype($project[$name] ?? null) == 'array') {
				return true;
			}
		}
	}

	private function getProjectTypePrefix(&$project) {
		foreach ($project as $setting_name => $setting_value) {
			if (strpos($setting_name, 'export-') !== false) {
				return 'export-';
			}
		}
		return '';
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

	public function importTranslationsFile() {
		// this function returns null (when successful) or a string error message
		$validation = $this->validateImport();
		if (gettype($validation) == 'string') {
			// return error string
			return $validation;
		}
		
		// read csv lines into translation matrix from file
		$uploaded_filepath = $_FILES['attach-file-1']['tmp_name'];
		$translation_matrix = [];
		if ($uploaded_csv = fopen($uploaded_filepath, 'r')) {
			while ($csv = fgetcsv($uploaded_csv)) {
				if (!$csv_field_count) {
					$csv_field_count = count($csv);
				} else {
					if ($csv_field_count != count($csv)) {
						return "Invalid CSV file contents -- each row should contain the same amount of columns.";
					}
				}
				$translation_matrix[] = strip_tags(label_decode($csv));
			}
		} else {
			return "Couldn't open the uploaded file.";
		}
		
		if (empty($translation_matrix) or $csv_field_count < 2) {
			"Couldn't parse uploaded CSV file into a valid translation matrix.";
		}
		
		// save translations to appropriate setting key/index
		$this->saveTranslations($translation_matrix, $validation['target_server_type'], $validation['target_server_index'], $validation['target_project_index']);
	}

	public function importTranslationsTable() {
		// this function returns null (when successful) or a string error message
		$validation = $this->validateImport();
		if (gettype($validation) == 'string') {
			return $validation;
		}
		
		// escaping here causes issues with detecting newlines in the preg_split call below
		// $translations = db_escape($_POST['translations']);
		$translations = $_POST['translations'];
		
		$translation_matrix = [];
		foreach(preg_split("/((\r?\n)|(\r\n?))/", $translations) as $line){
			$translation_matrix[] = str_getcsv(db_escape($line));
			foreach($translation_matrix as &$arr) {
				foreach ($arr as $i => $name) {
					$arr[$i] = strip_tags(label_decode(trim($name)));
				}
			}
		}
		
		// save translations to appropriate setting key/index
		$this->saveTranslations($translation_matrix, $validation['target_server_type'],$validation['target_server_index'], $validation['target_project_index']);
	}
	
	private function validateImport() {
		// returns an error string or, settings valid, an array with target project/server information
		$project_api_key = preg_replace('[^\dABCDEF]', '', $_POST['project-api-key']);
		$server_url = $_POST['server-url'];
		$server_type = htmlentities($_POST['server-type'], ENT_QUOTES);
		
		// validate server_type
		if ($server_type != 'import' and $server_type != 'export') {
			return "Server type '$server_type' not recognized.";
		}
		
		if (!isset($_POST['table_saved'])) {
			// check for file error / 0 size
			if ($_FILES['attach-file-1']['error'] != '0' or $_FILES['attach-file-1']['size'] == '0') {
				return "There was an issue uploading the file to the server.";
			}
		}
		
		// find the target server
		$server_settings_key = $server_type == 'import' ? 'servers' : 'export-servers';
		$servers = $this->getSubSettings($server_settings_key);
		$server_setting_key_prefix = $server_type == 'export' ? 'export-' : '';
		
		foreach ($servers as $server_index => $server) {
			if ($server[$server_setting_key_prefix . 'redcap-url'] == $server_url) {
				$target_server = $server;
				$target_server_index = $server_index;
				break;
			}
		}
		if (empty($target_server)) {
			return "Couldn't find server in settings with URL: '" . htmlentities($server_url, ENT_QUOTES) . "'.";
		}
		
		// find target project in target server
		foreach($target_server[$server_setting_key_prefix . 'projects'] as $project_index => $project) {
			if ($project[$server_setting_key_prefix . 'api-key'] == $project_api_key) {
				$target_project_index = $project_index;
				break;
			}
		}
		if (!isset($target_project_index)) {
			return "Couldn't find project in server settings with API key: '$project_api_key'.";
		}
		
		return [
			'target_server' => $target_server,
			'target_server_type' => $server_type,
			'target_server_index' => $target_server_index,
			'target_project_index' => $target_project_index
		];
	}
	
	private function saveTranslations($translation_matrix, $target_server_type, $target_server_index, $target_project_index) {
		// save translations to appropriate setting key/index
		$serial_translations = json_encode($translation_matrix);
		$translations_type = $_POST['translations-type'];
		if ($target_server_type == 'export') {
			$translations_key = "export-$translations_type-translations";
		} else {
			$translations_key = "$translations_type-translations";
		}
		$current_translations = $this->getProjectSetting($translations_key);
		$current_translations[$target_server_index][$target_project_index] = $serial_translations;
		$this->setProjectSetting($translations_key, $current_translations);
	}

	public function getRemoteProjectTitle($remote_api_endpoint, $api_key) {
		$api_url = preg_replace("~redcap/.+~", "redcap/api/", $remote_api_endpoint);
		
		$data = array(
			'token' => $api_key,
			'content' => 'project',
			'format' => 'json',
			'returnFormat' => 'json'
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $api_url);
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
		curl_close($ch);
		
		try {
			$obj = json_decode($output);
		}
		catch (\Exception $e) {
			// bad json
		}
		if (!empty($obj->project_title)) {
			return $obj->project_title;
		}
		return "";
	}

	function cacheProjectSetting($key, $value){
		$this->cachedSettings[$key] = $value;
	}

	function getCachedProjectSetting($key){
		if(!isset($this->cachedSettings[$key])){
			$this->cacheProjectSetting($key, $this->getProjectSetting($key));
		}

		return $this->cachedSettings[$key];
	}

	// This should likely be replaced by functionality in the module framework at some point.
	function requireDateParameter($paramName, $dateFormat, $array = null){
		if($array === null){
			if(isset($_GET[$paramName])){
				$array = $_GET;		
			}
			else{
				$array = $_POST;
			}
		}

		$value = $array[$paramName] ?? null;

		// Non-string values might not be a real use case, but let's makes sure the triple equals work below.
		$value = (string) $value;

		$newValue = date($dateFormat, strtotime($value));
		if(empty($value) || $newValue !== $value){
			throw new Exception('Invalid date value supplied!');
		}

		// Return the $newValue for security scanners that aren't smart enough to detect the exception above.
		return $newValue;
	}
}

// Shim for function that doesn't exist until php 7.
// This is safe because it's defined in the module's namespace.
function array_key_first($array){
	reset($array);
	return key($array);
}