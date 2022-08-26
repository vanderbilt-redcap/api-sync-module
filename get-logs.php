<?php
$start = $module->requireDateParameter('start', 'Y-m-d');
$end = $module->requireDateParameter('end', 'Y-m-d');

$end = date('Y-m-d', strtotime($end . ' + 1 day'));
$hasDetailsClause = "details != ''";

if(version_compare(REDCAP_VERSION, '10.8.2', '<')){
	// This REDCap version does not support functions or comparisons in select log queries.
	// Just always show the details button on older versions.
	$hasDetailsClause = 1;
}

$results = $module->queryLogs("
	select log_id, timestamp, message, failure, $hasDetailsClause as hasDetails
	where timestamp >= ? and timestamp < ?
	order by log_id desc
", [$start, $end]);

$allowedHtml = [
	'<div>',
	'</div>',
	"<div class='remote-project-title'>",
	'<b>',
	'</b>',
	"<a href='",
	"' target='_blank'>",
	'</a>',
];

$rows = [];
while($row = $results->fetch_assoc()){
	$escapedRow = [];
	foreach($row as $key=>$value){
		$escapedRow[$key] = htmlentities($value, ENT_QUOTES);
	}

	foreach($allowedHtml as $s){
		$escapedRow['message'] = str_replace(htmlentities($s, ENT_QUOTES), html_entity_decode($s, ENT_QUOTES), $escapedRow['message']);
	}

	$rows[] = $escapedRow;
}

?>

{
	"data": <?=json_encode($rows, JSON_PRETTY_PRINT)?>
}