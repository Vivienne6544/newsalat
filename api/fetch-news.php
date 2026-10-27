<?php
require_once __DIR__ . '/../core.php';

header('Content-Type: application/json');

try {
    $result = runCron();
    echo json_encode([
        'success' => true,
        'message' => 'News cache updated',
        'data' => $result,
        'time' => date('c')
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
