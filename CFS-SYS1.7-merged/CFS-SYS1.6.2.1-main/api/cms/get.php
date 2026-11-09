<?php
/**
 * GET /api/cms/get.php?id=123   (staff only)
 * ---------------------------------------------------------------------
 * WHAT IT DOES: returns ONE post with all its fields (drafts too).
 * The staff editor calls this when you click "Edit" on a card.
 */

require __DIR__ . '/../../config/database.php';   // $pdo   (MariaDB) - for the login check
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/cloud.php';      // $cloud (Supabase) - NEW
require __DIR__ . '/_lib.php';

requireMethod('GET');
cmsRequireStaff($pdo);

// $_GET['id'] is whatever came after "?id=" in the web address.
// NEVER trust it: filter_var checks that it is a whole number of at least 1.
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    jsonError(400, 'Invalid post.');
}

// ":id" is a placeholder. The real value is sent separately in execute(),
// so a hacker typing SQL into the address can't change the query (prepared statement).
$stmt = $cloud->prepare(
    'SELECT id, title, body, blocks, category, status, is_featured, cover_path, tag, tag_label,
            expires_on, event_date, event_time, venue, published_at
     FROM content_posts WHERE id = :id'
);
$stmt->execute(['id' => $id]);
$post = $stmt->fetch();                           // one row, or false if none
if (!$post) {
    jsonError(404, 'That post no longer exists.');
}

$post['is_featured'] = (bool)$post['is_featured'];
// "blocks" is stored as JSON text; turn it back into a PHP list (and double-check it)
$post['blocks']      = cmsDecodeBlocks($post['blocks']);

jsonResponse(200, ['post' => $post]);
