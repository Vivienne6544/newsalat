<?php
/*
 * newsalat api.php: thin entry point. All the real work is in core.php.
 *   ?action=articles&topic=&q=   -> news list (JSON)
 *   ?action=subscribe   (POST)   -> save push subscription + topics
 *   ?action=unsubscribe (POST)   -> remove push subscription
 *   ?action=cron                 -> fetch, filter, save, send alerts
 */

require_once __DIR__ . '/core.php';

header('Content-Type: application/json; charset=utf-8');

function reply($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// body of POST requests (index.html sends JSON)
function body() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

try {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'articles':
            reply(getArticles(trim($_GET['topic'] ?? ''), trim($_GET['q'] ?? '')));

        case 'subscribe':
            $b = body();
            if (!saveSubscription($b['subscription'] ?? [], $b['topics'] ?? [])) {
                reply(['error' => 'Invalid subscription'], 400);
            }
            reply(['ok' => true]);

        case 'unsubscribe':
            $b = body();
            if (empty($b['endpoint'])) reply(['error' => 'Missing endpoint'], 400);
            removeSubscription($b['endpoint']);
            reply(['ok' => true]);

             case 'cron':
            $lock = sys_get_temp_dir() . '/newsalat_last_fetch';
            if (file_exists($lock) && time() - filemtime($lock) < 300) {
                reply(['skipped' => 'fetched less than 5 minutes ago']);
            }
            touch($lock);
            reply(runCron());   // add a secret key check here when you deploy

        default:
            reply(['error' => 'Unknown action'], 404);
    }
} catch (Throwable $e) {
    reply(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
