<?php

$module->removeLogs('message in (?,?)', ['API Request', 'API Response']);
echo 'success';