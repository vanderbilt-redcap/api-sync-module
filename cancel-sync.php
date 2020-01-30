<?php

$module->cancelSync();

header('Location: ' . $module->getUrl('api-sync.php'));