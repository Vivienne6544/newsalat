<?php
require __DIR__ . '/core.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Optional secret to stop random people triggering it
// if (($_GET['secret'] ?? '') !== 'newsalat123') { http_response_code(401); exit; }

try {
  $result = runCron();
  echo json_encode(['ok' => true, 'time' => date('c'), 'result' => $result]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
