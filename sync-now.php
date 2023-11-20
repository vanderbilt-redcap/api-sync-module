<?php
$retryLogId = (int) @$_POST['retry-log-id'];
if($retryLogId && !$module->isImportInProgress()){
    $result = $module->queryLogs("select progress where log_id = ?", [$retryLogId]);
    $row = $result->fetch_assoc();

    $module->log("Retrying last failed import from where it left off");
    $module->setImportProgress($row['progress']);
}

$module->setProjectSetting('sync-now', true);

header('Location: ' . $module->getUrl('api-sync.php'));
