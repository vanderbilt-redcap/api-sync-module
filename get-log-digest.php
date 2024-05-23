<?php
$hasDetailsClause = "details != ''";

if(version_compare(REDCAP_VERSION, '10.8.2', '<')){
	// This REDCap version does not support functions or comparisons in select log queries.
	// Just always show the details button on older versions.
	$hasDetailsClause = 1;
}

$digestLog = new \Vanderbilt\APISyncExternalModule\DigestLog($module);

$module_id_query = $module->framework->query(
	"SELECT external_module_id FROM redcap_external_modules WHERE directory_prefix = ?",
	[$module->PREFIX]
);
$this_module_id = $module_id_query->fetch_assoc()['external_module_id'];

$start_log_id = 1;
$end_log_id = $start_log_id;
$min_log_id = $start_log_id;

$results = $module->queryLogs("
	select MAX(log_id) as max, MIN(log_id) as min
	where external_module_id = ? and project_id = ?
", [$this_module_id, $module->getProjectId()]);

while($row = $results->fetch_assoc()){
	$start_log_id = $row['max'];
	$min_log_id = $row['min'];
	$end_log_id = $start_log_id - 10;
}

$message_subtrings = $digestLog::createLikeStatements();

// NOTE: probably don't need EM id or project_id
// NOTE: $message_subtrings can't be included as a ? parameter
$all_accounted = false;
do  {
	$results = $module->queryLogs("
	select log_id, timestamp, message, failure, $hasDetailsClause as hasDetails
	where external_module_id = ? and project_id = ?
	and log_id >= ? and log_id <= ?
	and $message_subtrings
	order by log_id desc
", [$this_module_id, $module->getProjectId(), $end_log_id, $start_log_id]);

	while($row = $results->fetch_assoc()){
		$digestLog->parseLogRow($row);
	}

	$all_accounted = $digestLog->isDone();

	$start_log_id = $end_log_id;
	$end_log_id = max($end_log_id - 10, $min_log_id);
	if ($start_log_id == $end_log_id) { $all_accounted = true; }
} while (!$all_accounted);

$min_url_statuses = $digestLog->getDigest();

?>


{
	"data": <?=json_encode($min_url_statuses, JSON_PRETTY_PRINT)?>
}
