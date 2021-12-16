<?php
// Input validation to make UC Denver happy.
$type = $_GET['type'];
if(!in_array($type, ['exports', 'imports'])){
    throw new Exception('Unknown type');
}

$module->cron([
    'cron_name' => $type
]);