<?php
use REDCap;

$recordIdFieldName = REDCap::getRecordIdField();
$records = json_decode(REDCap::getData($module->getProjectId(), 'json', null, $recordIdFieldName), true);

foreach($records as $record){
	$recordId = $record[$recordIdFieldName];
	$module->queueForUpdate($recordId);
}

echo 'success';