<?php
/**
 * POST /api/cms/delete.php   (staff only)
 * Body: { id }
 * ---------------------------------------------------------------------
 * WHAT IT DOES: permanently deletes one post from the Supabase database.
 * The audit log entry (who deleted what) is written in MariaDB afterwards.
 */

require __DIR__ . '/../../config/database.php';   // $pdo   (MariaDB: login check + audit log)
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/cloud.php';      // $cloud (Supabase: the posts)  <-- NEW
require __DIR__ . '/_lib.php';
require __DIR__ . '/_fallback.php';               // gives us cmsRefreshFallback()  <-- NEW

requireMethod('POST');
$staff = cmsRequireStaff($pdo);
$in    = readJsonBody();

// Make sure "id" is a real whole number (never trust what the browser sends)
$id = filter_var($in['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    jsonError(400, 'Invalid post.');
}

// Find the post first, so we know its title for the audit log
$stmt = $cloud->prepare('SELECT title FROM content_posts WHERE id = :id');
$stmt->execute(['id' => $id]);
$post = $stmt->fetch();
if (!$post) {
    jsonError(404, 'That post no longer exists.');
}

// Delete it from Supabase
try {
    $stmt = $cloud->prepare('DELETE FROM content_posts WHERE id = :id');
    $stmt->execute(['id' => $id]);
} catch (Throwable $e) {
    error_log('cms/delete.php: ' . $e->getMessage());
    jsonError(500, 'Could not delete. Please try again.');
}

// Write the audit log in MariaDB. A logging problem must not hide the fact
// that the delete already happened, so we only record it in the error log.
try {
    cmsAudit($pdo, (int)$staff['id'], 'content.deleted', $id, ['title' => $post['title']]);
} catch (Throwable $e) {
    error_log('cms/delete.php audit: ' . $e->getMessage());
}

// Refresh the offline backup so the deleted post disappears from it too.
// If this fails, the delete is STILL done. We just keep the old backup file.
cmsRefreshFallback($cloud);

jsonResponse(200, ['deleted' => true]);
