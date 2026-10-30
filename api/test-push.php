<?php
// TEMPORARY: sends a test alert to every saved subscriber. Delete this file after testing.
require_once __DIR__ . '/../core.php';
header('Content-Type: application/json');

try {
    if (!class_exists('Minishlink\WebPush\WebPush')) {
        echo json_encode(['error' => 'Push library not installed (vendor missing)']); exit;
    }

    $webPush = new Minishlink\WebPush\WebPush(['VAPID' => [
        'subject' => VAPID_SUBJECT, 'publicKey' => VAPID_PUBLIC, 'privateKey' => VAPID_PRIVATE,
    ]]);

    $subs = db()->query('SELECT endpoint, p256dh, auth FROM subscriptions')->fetchAll();
    foreach ($subs as $s) {
        $webPush->queueNotification(
            Minishlink\WebPush\Subscription::create([
                'endpoint' => $s['endpoint'],
                'keys'     => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
            ]),
            json_encode(['title' => 'Test alert', 'body' => 'If you can see this, notifications work.', 'url' => './'])
        );
    }

    $results = [];
    foreach ($webPush->flush() as $report) {
        $results[] = [
            'ok'      => $report->isSuccess(),
            'expired' => $report->isSubscriptionExpired(),
            'reason'  => $report->getReason(),
        ];
    }
    echo json_encode(['subscribers' => count($subs), 'results' => $results]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
