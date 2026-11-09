<?php
/**
 * GET /api/cms/list_posts.php   (staff only)
 * ---------------------------------------------------------------------
 * WHAT IT DOES: sends back EVERY post (drafts included), newest edit first.
 * The "Website content" tab in the registrar dashboard uses it to draw the cards.
 *
 * TWO DATABASES ARE USED HERE:
 *   $pdo   (MariaDB)  -> only to check "is this person a logged-in registrar?"
 *   $cloud (Supabase) -> where the posts actually live
 */

// "require" = load another PHP file first. Order matters:
require __DIR__ . '/../../config/database.php';   // gives us $pdo   (MariaDB)
require __DIR__ . '/../../config/helpers.php';    // gives us jsonResponse(), jsonError() ...
require __DIR__ . '/../../config/auth.php';       // login helpers
require __DIR__ . '/../../config/cloud.php';      // gives us $cloud (Supabase)  <-- NEW
require __DIR__ . '/_lib.php';                    // CMS helper functions

requireMethod('GET');            // reject anything that is not a GET request
cmsRequireStaff($pdo);           // stops here with an error if not a signed-in registrar/admin

// Ask Supabase for the posts. Only the columns the card list needs.
// ORDER BY ... DESC = newest first.  LIMIT 300 = never send more than 300.
$stmt = $cloud->query(
    'SELECT id, title, category, status, is_featured, cover_path, event_date, expires_on,
            published_at, updated_at
     FROM content_posts
     ORDER BY updated_at DESC, id DESC
     LIMIT 300'
);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);   // all rows as a PHP array

// Make sure is_featured is a real true/false in the JSON we send back
foreach ($posts as &$p) {
    $p['is_featured'] = (bool)$p['is_featured'];
}
unset($p);

jsonResponse(200, ['posts' => $posts]);       // send JSON back to the browser
