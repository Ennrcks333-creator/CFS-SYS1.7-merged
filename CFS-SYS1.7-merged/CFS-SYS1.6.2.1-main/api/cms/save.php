<?php
/**
 * POST /api/cms/save.php   (staff only)
 * Body: { id?, category, status: draft|published, title, body, blocks[], cover_path?, is_featured?,
 *         tag?, tag_label?, expires_on?, event_date?, event_time?, venue? }
 * ---------------------------------------------------------------------
 * WHAT IT DOES: creates a new post (when "id" is missing) or updates an old one.
 * Replies with { id, status, slug }.
 *
 * WHAT CHANGED FOR SUPABASE (PostgreSQL is a little different from MariaDB):
 *   1. Posts are saved with $cloud (Supabase) instead of $pdo.
 *   2. MariaDB's  IF(a, b, c)  does not exist in PostgreSQL. We use
 *      CASE WHEN a THEN b ELSE c END  instead. Same idea, different words.
 *   3. MariaDB's  lastInsertId()  is replaced by  RETURNING id  (PostgreSQL
 *      hands back the new id as part of the INSERT itself).
 *   4. is_featured is a real true/false column now. PHP's  false  turns into an
 *      EMPTY text when sent to the database, which PostgreSQL rejects. So we
 *      send the words 'true' / 'false' instead.
 *   5. The audit log lives in MariaDB, a DIFFERENT database, so it can't be
 *      part of the same transaction. We save the post first, then log it.
 */

require __DIR__ . '/../../config/database.php';   // $pdo   (MariaDB: users + audit_logs)
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/cloud.php';      // $cloud (Supabase: content_posts)  <-- NEW
require __DIR__ . '/_lib.php';
require __DIR__ . '/_fallback.php';               // gives us cmsRefreshFallback()  <-- NEW

requireMethod('POST');
$staff = cmsRequireStaff($pdo);   // who is logged in? (checked against MariaDB)
$in    = readJsonBody();          // the data the staff screen sent us

// ---------- common fields (every kind of post has these) ----------
$category = $in['category'] ?? '';
if (!in_array($category, ['news', 'announcement', 'event'], true)) {
    jsonError(400, 'Unknown content type.');
}
$status = $in['status'] ?? 'draft';
if (!in_array($status, ['draft', 'published'], true)) {
    jsonError(400, 'Unknown status.');
}
// cmsStr() cleans text: trims spaces, removes weird characters, cuts it to a max length
$title = cmsStr($in['title'] ?? '', 150);
if ($title === '') {
    jsonError(400, 'Please give this a title.');
}
$body = cmsStr($in['body'] ?? '', $category === 'announcement' ? 600 : 300, true);

// ---------- is this an update of an existing post? ----------
$id = null;
if (isset($in['id']) && $in['id'] !== null && $in['id'] !== '') {
    $id = filter_var($in['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        jsonError(400, 'Invalid post.');
    }
    // Look the post up in Supabase to make sure it really exists
    $stmt = $cloud->prepare('SELECT id, category, slug FROM content_posts WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        jsonError(404, 'That post no longer exists.');
    }
    if ($existing['category'] !== $category) {
        jsonError(400, 'The type of a post cannot be changed.');
    }
}

// ---------- fields that only some kinds of posts use ----------
$blocks = [];
$cover = null; $featured = 0;
$tag = null; $tagLabel = null; $expires = null;
$eventDate = null; $eventTime = null; $venue = null;

if ($category !== 'announcement') {
    $blocks = cmsCleanBlocks($in['blocks'] ?? []);   // rebuild the blocks safely from scratch
}

if ($category === 'news') {
    $c = $in['cover_path'] ?? null;
    if ($c !== null && $c !== '') {
        if (!is_string($c) || !cmsImageExists($c)) {
            jsonError(400, 'The cover photo is not valid. Please upload it again.');
        }
        $cover = $c;
    }
    $featured = !empty($in['is_featured']) ? 1 : 0;
}

if ($category === 'announcement') {
    $tag = $in['tag'] ?? 'info';
    if (!in_array($tag, ['urgent', 'info', 'event'], true)) {
        jsonError(400, 'Unknown announcement type.');
    }
    $tagLabel = mb_strtoupper(cmsStr($in['tag_label'] ?? '', 30));
    if ($tagLabel === '') {
        $tagLabel = 'NOTICE';
    }
    $expires = cmsDate($in['expires_on'] ?? null, 'hide-after date');
}

if ($category === 'event') {
    $eventDate = cmsDate($in['event_date'] ?? null, 'event date');
    $eventTime = cmsStr($in['event_time'] ?? '', 50);
    $venue     = cmsStr($in['venue'] ?? '', 100);
}

// ---------- rules before something can go live ----------
if ($status === 'published') {
    if ($category === 'announcement' && $body === '') {
        jsonError(400, 'Please write the announcement message.');
    }
    if ($category === 'news' && $body === '') {
        jsonError(400, 'Please add a short summary for the homepage card.');
    }
    if ($category === 'event' && $eventDate === null) {
        jsonError(400, 'Please choose the event date.');
    }
}

// Blocks are saved as JSON text (announcements have none)
$blocksJson = $category === 'announcement' ? null : json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$isPub      = $status === 'published' ? 1 : 0;
// PostgreSQL wants the WORDS true/false for a yes/no column (see note 4 at the top)
$featuredSql = $featured === 1 ? 'true' : 'false';

// ---------- write to Supabase ----------
// A transaction = "do all of these steps, or none of them".
$cloud->beginTransaction();
try {
    if ($id === null) {
        // ===== NEW POST =====
        $slug = cmsSlug($title);   // a web-friendly name like "my-title-a1b2c3"
        $stmt = $cloud->prepare(
            'INSERT INTO content_posts
               (title, slug, body, blocks, category, status, event_date, event_time, venue,
                cover_path, is_featured, tag, tag_label, expires_on, author_id, published_at)
             VALUES
               (:title, :slug, :body, :blocks, :category, :status, :event_date, :event_time, :venue,
                :cover, :featured, :tag, :tag_label, :expires, :author,
                CASE WHEN CAST(:is_pub AS integer) = 1 THEN now() ELSE NULL END)
             RETURNING id'
        );
        $stmt->execute([
            'title' => $title, 'slug' => $slug, 'body' => $body, 'blocks' => $blocksJson,
            'category' => $category, 'status' => $status,
            'event_date' => $eventDate, 'event_time' => $eventTime, 'venue' => $venue,
            'cover' => $cover, 'featured' => $featuredSql, 'tag' => $tag, 'tag_label' => $tagLabel,
            'expires' => $expires, 'author' => $staff['id'], 'is_pub' => $isPub,
        ]);
        $id = (int)$stmt->fetchColumn();   // RETURNING id gave us the new id
        $action = 'content.created';
    } else {
        // ===== EDIT AN EXISTING POST =====
        $slug = $existing['slug'];   // the slug never changes after the post is created
        $stmt = $cloud->prepare(
            'UPDATE content_posts SET
               title = :title, body = :body, blocks = :blocks, status = :status,
               event_date = :event_date, event_time = :event_time, venue = :venue,
               cover_path = :cover, is_featured = :featured, tag = :tag, tag_label = :tag_label,
               expires_on = :expires,
               published_at = CASE WHEN CAST(:is_pub AS integer) = 1
                                   THEN COALESCE(published_at, now())
                                   ELSE published_at END
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title, 'body' => $body, 'blocks' => $blocksJson, 'status' => $status,
            'event_date' => $eventDate, 'event_time' => $eventTime, 'venue' => $venue,
            'cover' => $cover, 'featured' => $featuredSql, 'tag' => $tag, 'tag_label' => $tagLabel,
            'expires' => $expires, 'is_pub' => $isPub, 'id' => $id,
        ]);
        $action = 'content.updated';
    }

    // Only ONE headline story at a time: un-feature every other news post
    if ($category === 'news' && $featured === 1) {
        $stmt = $cloud->prepare("UPDATE content_posts SET is_featured = false WHERE category = 'news' AND id <> :id");
        $stmt->execute(['id' => $id]);
    }

    $cloud->commit();   // everything worked: make it permanent
} catch (Throwable $e) {
    if ($cloud->inTransaction()) {
        $cloud->rollBack();   // something failed: undo everything
    }
    error_log('cms/save.php: ' . $e->getMessage());
    jsonError(500, 'Could not save. Please try again.');
}

// ---------- audit log (in MariaDB) ----------
// Done AFTER the save. If logging fails we only note it in the error log;
// we do NOT tell the staff the save failed, because the post WAS saved.
try {
    cmsAudit($pdo, (int)$staff['id'], $action, $id, ['title' => $title, 'status' => $status]);
} catch (Throwable $e) {
    error_log('cms/save.php audit: ' . $e->getMessage());
}

// ---------- refresh the offline backup (fallback.json) ----------
// If this fails, the post is STILL saved. We just keep the old backup file.
cmsRefreshFallback($cloud);

jsonResponse(200, ['id' => $id, 'status' => $status, 'slug' => $slug]);
