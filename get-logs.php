<?php
$start = $_GET['start'];
$end = $_GET['end'];

$hasDetailsClause = "details != ''";

if(version_compare(REDCAP_VERSION, '10.8.2', '<')){
	// This REDCap version does not support functions or comparisons in select log queries.
	// Just always show the details button on older versions.
	$hasDetailsClause = 1;
}

$results = $module->queryLogs("
	select log_id, timestamp, message, failure, $hasDetailsClause as hasDetails
	where timestamp >= ? and timestamp <= ?
	order by log_id desc
", [$start, $end]);

$rows = [];
while($row = $results->fetch_assoc()){
	$rows[] = $row;
}

?>

{
	"data": <?=json_encode($rows, JSON_PRETTY_PRINT)?>
}