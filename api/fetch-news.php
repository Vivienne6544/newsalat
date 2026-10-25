<?php
require_once __DIR__ . '/../core.php';
$result = runCron();
echo json_encode($result);
