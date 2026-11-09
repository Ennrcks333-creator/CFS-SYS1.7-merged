<?php
// api/content.php — PUBLIC, read-only. No login needed; only published posts are ever returned.
// Usage: GET api/content.php?type=news | announcement | event
require __DIR__ . '/db.php';
 
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
 
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
 
// Whitelist: the client's "type" only selects one of these fixed rules, it is never put into SQL.
$rules = [
    'news'         => ['extra' => '',
                       'order' => 'is_featured DESC, published_at DESC', 'limit' => 5],
    'announcement' => ['extra' => 'AND (expires_on IS NULL OR expires_on >= CURDATE())',  // hide expired
                       'order' => 'published_at DESC, id ASC',           'limit' => 6],
    'event'        => ['extra' => 'AND event_date >= CURDATE()',                          // hide past events
                       'order' => 'event_date ASC, id ASC',              'limit' => 6],
];
 
$type = $_GET['type'] ?? '';
if (!isset($rules[$type])) {
    http_response_code(400);
    echo json_encode(['error' => 'type must be news, announcement or event']);
    exit;
}
$rule = $rules[$type];
 
try {
    $sql = "SELECT id, title, body, category, is_featured, tag, tag_label,
                   event_date, event_time, venue, published_at
            FROM content_posts
            WHERE status = 'published'
              AND category = :category
              AND published_at IS NOT NULL AND published_at <= NOW()
              {$rule['extra']}
            ORDER BY {$rule['order']}
            LIMIT {$rule['limit']}";           // extra/order/limit come from the whitelist above only
    $stmt = db()->prepare($sql);
    $stmt->execute([':category' => $type]);
    $rows = $stmt->fetchAll();
 
    foreach ($rows as &$r) {
        $r['is_featured'] = (bool)$r['is_featured'];   // so JS gets true/false, not "0"/"1"
    }
    unset($r);
 
    echo json_encode(['items' => $rows], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('content.php: ' . $e->getMessage());     // details go to the PHP log, not the browser
    http_response_code(500);
    echo json_encode(['error' => 'Could not load content']);
}