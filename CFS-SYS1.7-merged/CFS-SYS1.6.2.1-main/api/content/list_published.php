<?php
/**
 * GET /api/content/list_published.php?type=news|announcement|event   (PUBLIC, read-only)
 * Only published posts are ever returned. Expired announcements and past events are hidden.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';

requireMethod('GET');

// The client's "type" only picks one of these fixed rules; it is never put into the SQL.
$rules = [
    'news'         => ['extra' => '',
                       'order' => 'is_featured DESC, published_at DESC', 'limit' => 5],
    'announcement' => ['extra' => 'AND (expires_on IS NULL OR expires_on >= CURDATE())',
                       'order' => 'published_at DESC, id ASC',           'limit' => 6],
    'event'        => ['extra' => 'AND event_date >= CURDATE()',
                       'order' => 'event_date ASC, id ASC',              'limit' => 6],
];

$type = $_GET['type'] ?? '';
if (!is_string($type) || !isset($rules[$type])) {
    jsonError(400, 'type must be news, announcement or event.');
}
$rule = $rules[$type];

$sql = "SELECT id, title, body, category, is_featured, cover_path, tag, tag_label,
               event_date, event_time, venue, published_at,
               (blocks IS NOT NULL AND blocks <> '' AND blocks <> '[]') AS has_details
        FROM content_posts
        WHERE status = 'published'
          AND category = :category
          AND published_at IS NOT NULL AND published_at <= NOW()
          {$rule['extra']}
        ORDER BY {$rule['order']}
        LIMIT {$rule['limit']}";            // extra / order / limit come only from the whitelist above
$stmt = $pdo->prepare($sql);
$stmt->execute(['category' => $type]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['is_featured'] = (bool)$r['is_featured'];
    $r['has_details'] = (bool)$r['has_details'];
}
unset($r);

jsonResponse(200, ['items' => $rows]);
