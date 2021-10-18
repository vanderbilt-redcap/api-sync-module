<?php

// Cast to an int to make UC Denver's security scanner happy for SAVE-O2
$logId = (int) $_GET['log-id'];

$result = $module->queryLogs('select details where log_id = ?', $logId);
$row = $result->fetch_assoc();

if($row === false){
    echo "Log id not found.";
}
else{
    $details = $row['details'];

    if(empty($details)){
        echo "No details were found.";
    }
    else{
        echo $details;
    }
}