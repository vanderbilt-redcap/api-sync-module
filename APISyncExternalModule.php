<?php
namespace Vanderbilt\APISyncExternalModule;

use Exception;

class APISyncExternalModule extends \ExternalModules\AbstractExternalModule{
	function cron(){
		// TODO - move this query to the framework for others to use!!!
		$results = $this->query("
			select project_id
			from redcap_external_modules m
			join redcap_external_module_settings s
				on m.external_module_id = s.external_module_id
			where
				m.directory_prefix = '" . $this->PREFIX . "'
				and s.value = 'true'
				and s.`key` = 'enabled'
		");

		$originalPid = $_GET['pid'];

		while($row = $results->fetch_assoc()){
			$localProjectId = $row['project_id'];

			// This automatically associates all log statements with this project.
			$_GET['pid'] = $localProjectId;

			$servers = $this->getSubSettings('servers', $localProjectId);
			foreach($servers as $server){
				if(!$this->isTimeToRun($server)){
					continue;
				}

				$url = $server['redcap-url'];

				// This log mainly exists to show that the sync process has started, since the next log
				// doesn't occur until after the API request to get the project name (which could fail).
				$this->log("Started sync with server: $url");

				foreach($server['projects'] as $project){
					try{
						$this->importRecords($localProjectId, $url, $project);
					}
					catch(Exception $e){
						$this->log("An error occurred: " . $e->getMessage(), [
							'details' => $e->getTraceAsString()
						]);
					}
				}

				$this->log("Finished sync with server: $url");
			}
		}

		// Put the pid back the way it was before this cron job (likely doesn't matter, but wanted to be safe)
		$_GET['pid'] = $originalPid;

		return 'The ' . $this->getModuleName() . ' External Module job completed successfully.';
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

	function importRecords($localProjectId, $url, $project){
		$apiKey = $project['api-key'];

		$response = json_decode($this->apiRequest($url, $apiKey, [
			'content' => 'project'
		]), true);

		$remoteProjectTitle = $response['project_title'];

		$this->log("
			<div>Importing records (and overwriting matching local records) from the remote project titled:</div>
			<div class='remote-project-title'>$remoteProjectTitle</div>
		");

		$format = 'csv';
		$response = $this->apiRequest($url, $apiKey, [
			'content' => 'record',
			'format' => $format
		]);

		$results = \REDCap::saveData((int)$localProjectId, $format, $response, 'overwrite');

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
		}


		$this->log("Import $message", [
			'details' => json_encode($results, JSON_PRETTY_PRINT)
		]);
	}

	private function apiRequest($url, $apiKey, $data){
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

		return $output;
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
}