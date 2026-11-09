<?php
/**
 * GET /api/content/view.php?id=123   (PUBLIC, read-only)
 * One published post with its full content, for the "read more" window on the website.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';

requireMethod('GET');

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    jsonError(400, 'Invalid post.');
}

$stmt = $pdo->prepare(
    "SELECT id, title, body, blocks, category, cover_path, event_date, event_time, venue, published_at
     FROM content_posts
     WHERE id = :id AND status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()"
);
$stmt->execute(['id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) {
    jsonError(404, 'This post is not available.');
}

// decode the stored blocks (an unreadable value just means "no extra content")
$blocks = json_decode((string)$post['blocks'], true);
$post['blocks'] = is_array($blocks) ? $blocks : [];

jsonResponse(200, ['post' => $post]);
