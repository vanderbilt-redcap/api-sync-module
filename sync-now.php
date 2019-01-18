<?php
$module->setProjectSetting('sync-now', true);
header('Location: ' . $module->getUrl('api-sync.php'));