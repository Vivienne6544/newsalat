<?php
/*
 * newsalat core.php: config + all backend functions in one file.
 *
 * One-time setup:
 *   1. composer require minishlink/web-push
 *   2. Make VAPID keys:
 *      php -r "require 'vendor/autoload.php'; print_r(Minishlink\WebPush\VAPID::createVapidKeys());"
 *   3. Paste the keys below, and the public key into index.html too.
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
date_default_timezone_set('Africa/Nairobi');

/* ============================ 1. CONFIG ============================ */

const DB_HOST = 'sql201.cpanelfree.com';
const DB_NAME = 'cpfr_43244101_newsalatdbs';
const DB_USER = 'cpfr_43244101';
const DB_PASS = 'ogYY5j73l7';

const ALERT_GAP_MINUTES = 5;     // at most one alert round per this many minutes
const FRESH_HOURS       = 3;     // only alert about stories newer than this
const MAX_ARTICLES      = 50;    // how many the app shows at once

const VAPID_PUBLIC  = 'BMsbTYvOFBsPKK9k5xKz8dyJ2IDDUVFWI2z3S1BK1-fKsA_qZbLITw_CL2ON6AojvW5SV28WLcm7SbGYEaVU2lg';
const VAPID_PRIVATE = '1IglVbtNk32-2tyiSENTdv0F4pWCTxskXyHHkpt0EhI';
const VAPID_SUBJECT = 'mailto:viviennembithi@gmail.com';

// To add a topic: add a line here AND in the TOPICS list in index.html.
// Keywords are matched anywhere in title/summary, ignoring case. First topic that matches wins.
const TOPICS = [
    'finance'     => ['finance bill', 'finance act', 'mswada wa fedha', 'VAT', 'excise duty', 'withholding tax', 'surcharge', 'fiscal deficit', 'repeal', 'amendment'],
    'protest'     => ['protest', 'maandamano', 'demonstration', 'demonstrators', 'riot', 'march', 'picket', 'clashes'],
    'agriculture' => ['agriculture', 'farming', 'farmers', 'kilimo', 'wakulima', 'maize', 'milk', 'fertilizer', 'harvest', 'tea', 'coffee', 'crops', 'livestock'],
      'politics'    => ['politics', 'political', 'government', 'serikali', 'siasa', 'bunge', 'parliament', 'senate', 'national assembly', 'mp', 'cabinet', 'impeachment', 'opposition', 'president', 'governor', 'election', 'uchaguzi', 'iebc', 'ruto', 'raila', 'gachagua', 'kalonzo', 'sifuna'],
];


// source name => RSS address (fix any that stop working)
const FEEDS = [
    'Nation'    => 'https://nation.africa/kenya/rss.xml',
    'Standard'  => 'https://www.standardmedia.co.ke/rss/headlines.php',
    'Citizen'   => 'https://www.citizen.digital/feeds/rss',
    'Tuko'      => 'https://www.tuko.co.ke/rss',
    'BBC Africa'=> 'https://feeds.bbci.co.uk/news/world/africa/rss.xml',
];

// Every topic must ALSO match one of these words (whole words, any case).
const KENYA_WORDS = ['kenya', 'kenyan', 'nairobi', 'mombasa', 'kisumu', 'nakuru', 'eldoret', 'kibera', 'cbd',
                     'ruto', 'raila', 'gachagua', 'kalonzo', 'kenyatta', 'gen z',
                     'parliament', 'senate', 'kra', 'iebc', 'treasury', 'county', 'governor'];

// Kenyan outlets skip the Kenya-word check. Make this [] for strict mode (every source must match).
const TRUSTED_SOURCES = ['Nation', 'Standard', 'Citizen', 'Tuko', 'YouTube: Rogers Kakasungura', 'YouTube: Citizen TV'];

// source name => YouTube channel ID. Add a channel = add one line (and add its name to TRUSTED_SOURCES if trusted).
const YOUTUBE_CHANNELS = [
    'YouTube: Rogers Kakasungura' => 'UCRvrvGIjbwXqxK4ssy36xlw',
    'YouTube: Citizen TV'         => 'UChBQgieUidXV1CmDxSdRm3g',
];

/* ============================ 2. DATABASE ============================ */

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/* ============================ 3. FETCH ============================ */

// Returns a plain list of items: title, summary, link, source, published_at
function fetchFeeds() {
    $items = [];
    libxml_use_internal_errors(true);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: newsalat/1.0\r\n"]]);

    foreach (FEEDS as $source => $url) {
        $xml = @file_get_contents($url, false, $ctx);
        if (!$xml) continue;
        $feed = simplexml_load_string($xml);
        if (!$feed || !isset($feed->channel->item)) continue;

        foreach ($feed->channel->item as $it) {
            $summary = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string)$it->description, ENT_QUOTES, 'UTF-8'))));
            $time    = strtotime((string)$it->pubDate) ?: time();
            $items[] = [
                'title'        => trim((string)$it->title),
                'summary'      => mb_substr($summary, 0, 220),
                'link'         => cleanLink((string)$it->link),
                'source'       => $source,
                'published_at' => date('Y-m-d H:i:s', min($time, time())),
            ];
        }
    }
    return $items;
}

// Latest videos from each channel in YOUTUBE_CHANNELS (free feed, no API key). Same shape as fetchFeeds().
function fetchYoutube() {
    $items = [];
    libxml_use_internal_errors(true);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: newsalat/1.0\r\n"]]);

    foreach (YOUTUBE_CHANNELS as $source => $channelId) {
        $xml = @file_get_contents('https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId, false, $ctx);
        if (!$xml) continue;
        $feed = simplexml_load_string($xml);
        if (!$feed || !isset($feed->entry)) continue;

        foreach ($feed->entry as $e) {
            $media   = $e->children('http://search.yahoo.com/mrss/');
            $summary = trim(preg_replace('/\s+/', ' ', (string)$media->group->description));
            $time    = strtotime((string)$e->published) ?: time();
            $items[] = [
                'title'        => trim((string)$e->title),
                'summary'      => mb_substr($summary, 0, 220),
                         'link'         => cleanLink((string)$e->link->attributes()['href']),
                'match_text'   => $summary,   // full description, used only for sorting into topics
                'source'       => $source,
                'published_at' => date('Y-m-d H:i:s', min($time, time())),
            ];
        }
    }
    return $items;
}

/* ============================ 4. DEDUPE HELPER ============================ */

// Removes tracking bits so the same story always has the same link.
function cleanLink($url) {
    $p = parse_url(trim($url));
    if (!$p || empty($p['host'])) return trim($url);

    $query = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        foreach (array_keys($q) as $k) {
            if (stripos($k, 'utm_') === 0 || in_array(strtolower($k), ['fbclid', 'gclid', 'ref'])) unset($q[$k]);
        }
        $query = $q ? '?' . http_build_query($q) : '';
    }
    return ($p['scheme'] ?? 'https') . '://' . $p['host'] . ($p['path'] ?? '') . $query;
}

/* ============================ 5. FILTER ============================ */

// Returns the topic id of the first keyword found, or null. Case-insensitive.
// true if any word from the list appears as a whole word (plurals allowed), ignoring case
function hasWord($text, $words) {
    foreach ($words as $w) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?:s|es)?(?![\p{L}\p{N}])/iu', $text)) return true;
    }
    return false;
}

// Returns the topic id or null. A topic needs its own word AND a Kenya word.
function matchTopic($text, $source = '') {
    $kenyan = in_array($source, TRUSTED_SOURCES) || hasWord($text, KENYA_WORDS);
    if (!$kenyan) return null;
    foreach (TOPICS as $topic => $words) {
        if (hasWord($text, $words)) return $topic;
    }
    return null;
}
/* ============================ 6. STORE ============================ */

// Keeps only matching items and saves them. The unique link skips duplicates.
// Returns how many were new.
function saveNew($items) {
    $stmt = db()->prepare(
        'INSERT IGNORE INTO articles (title, summary, link, source, topic, published_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $new = 0;
    foreach ($items as $i) {
        if ($i['title'] === '' || $i['link'] === '') continue;
                      $topic = matchTopic($i['title'] . ' ' . ($i['match_text'] ?? $i['summary']), $i['source']) ?? 'general';
        $stmt->execute([$i['title'], $i['summary'], $i['link'], $i['source'], $topic, $i['published_at']]);
        $new += $stmt->rowCount();
    }
    return $new;
}

/* ============================ 7. READ FOR THE APP ============================ */

function getArticles($topic = '', $search = '') {
    $sql = 'SELECT title, summary, link, source, topic, published_at FROM articles WHERE 1';
    $args = [];
    if ($topic !== '' && isset(TOPICS[$topic])) {
        $sql .= ' AND topic = ?';
        $args[] = $topic;
    }
    if ($search !== '') {
        $sql .= ' AND (title LIKE ? OR summary LIKE ?)';
        $args[] = $args[] = '%' . $search . '%';
    }
    $sql .= ' ORDER BY published_at DESC LIMIT ' . (int)MAX_ARTICLES;
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/* ============================ 8. SUBSCRIPTIONS ============================ */

function saveSubscription($sub, $topics) {
    $endpoint = $sub['endpoint'] ?? '';
    $p256dh   = $sub['keys']['p256dh'] ?? '';
    $auth     = $sub['keys']['auth'] ?? '';
    if (!$endpoint || !$p256dh || !$auth) return false;

        $topics = array_values(array_intersect((array)$topics, array_merge(['all'], array_keys(TOPICS))));
    $stmt = db()->prepare(
        'INSERT INTO subscriptions (endpoint, p256dh, auth, topics) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), topics = VALUES(topics)'
    );
    return $stmt->execute([$endpoint, $p256dh, $auth, implode(',', $topics)]);
}

function removeSubscription($endpoint) {
    return db()->prepare('DELETE FROM subscriptions WHERE endpoint = ?')->execute([$endpoint]);
}

/* ============================ 9. ALERTS ============================ */

// Sends at most one alert per user per round, and at most one round per ALERT_GAP_MINUTES.
function sendAlerts() {
    if (!class_exists('Minishlink\WebPush\WebPush')) return 0;

    // anti-spam: too soon since the last alert round?
    $last = db()->query('SELECT MAX(notified_at) FROM articles')->fetchColumn();
    if ($last && strtotime($last) > time() - ALERT_GAP_MINUTES * 60) return 0;

    $since = date('Y-m-d H:i:s', time() - FRESH_HOURS * 3600);
    $stmt  = db()->prepare('SELECT title, link, topic FROM articles
                            WHERE notified_at IS NULL AND published_at > ? ORDER BY published_at DESC');
    $stmt->execute([$since]);
    $fresh = $stmt->fetchAll();
    if (!$fresh) return 0;

    $webPush = new Minishlink\WebPush\WebPush(['VAPID' => [
        'subject' => VAPID_SUBJECT, 'publicKey' => VAPID_PUBLIC, 'privateKey' => VAPID_PRIVATE,
    ]]);

    $queued = 0;
    foreach (db()->query('SELECT endpoint, p256dh, auth, topics FROM subscriptions') as $s) {
        $wanted = array_filter(explode(',', $s['topics']));
        $mine   = array_values(array_filter($fresh, fn($a) => in_array('all', $wanted) || in_array($a['topic'], $wanted)));
        if (!$mine) continue;

        if (count($mine) === 1) {
            $payload = ['title' => 'Breaking: ' . ucfirst($mine[0]['topic']), 'body' => $mine[0]['title'], 'url' => $mine[0]['link']];
        } else {
            $names   = implode(', ', array_map('ucfirst', array_unique(array_column($mine, 'topic'))));
            $payload = ['title' => count($mine) . ' new stories', 'body' => $names . ': ' . $mine[0]['title'], 'url' => './'];
        }
        $webPush->queueNotification(
            Minishlink\WebPush\Subscription::create([
                'endpoint' => $s['endpoint'],
                'keys'     => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
            ]),
            json_encode($payload)
        );
        $queued++;
    }

    foreach ($webPush->flush() as $report) {
        if ($report->isSubscriptionExpired()) removeSubscription($report->getEndpoint());
    }

    // mark everything as handled (this also skips old stories next time)
    db()->prepare('UPDATE articles SET notified_at = ? WHERE notified_at IS NULL')->execute([date('Y-m-d H:i:s')]);
    return $queued;
}

/* ============================ 10. CRON ============================ */

function runCron() {
     $items = array_merge(fetchFeeds(), fetchYoutube());
    $new   = saveNew($items);
    $sent  = sendAlerts();
    return ['fetched' => count($items), 'new' => $new, 'alerts_sent' => $sent];
}