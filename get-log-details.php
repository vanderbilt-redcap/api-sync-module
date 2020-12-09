<?php

$result = $module->queryLogs('select details where log_id = ?', $_GET['log-id']);
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