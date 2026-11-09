<?php
/**
 * api/cms/_fallback.php
 * ---------------------------------------------------------------------
 * WHAT THIS FILE DOES (in plain words):
 *   It holds ONE function, cmsRefreshFallback(), that makes a backup copy
 *   of all PUBLISHED posts and saves it as a file called  fallback.json
 *   in the project's main folder.
 *
 *   The public website reads that file ONLY when Supabase can't be reached
 *   (no internet, project paused, etc.).
 *
 * WHO CALLS IT:
 *   - save.php and delete.php   (after every successful save/delete)
 *   - refresh_fallback.php      (when you want to refresh the backup by hand)
 *
 * IMPORTANT RULES:
 *   - ONLY published posts go in the file. The file is public: anyone can open
 *     http://localhost/.../fallback.json, so drafts must never be inside it.
 *   - If the refresh fails, the OLD file is kept. We write to a temporary file
 *     first, then swap it in, so the real file is never left half-written.
 */

// $cloud = the Supabase connection from config/cloud.php
// Returns true if the file was updated, false if something went wrong.
function cmsRefreshFallback(PDO $cloud): bool
{
    try {
        // 1. Ask Supabase for every published post.
        //    to_char(...) writes published_at in a standard format ("2026-09-12T00:00:00Z")
        //    that every browser understands, the same style Supabase's REST API uses.
        $stmt = $cloud->query(
            "SELECT id, title, body, category, is_featured, tag, tag_label,
                    event_date, event_time, venue, cover_path, blocks, expires_on,
                    to_char(published_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS published_at
             FROM content_posts
             WHERE status = 'published'
             ORDER BY id"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Tidy two columns so the JSON looks exactly like what Supabase's REST API returns
        foreach ($rows as &$r) {
            $r['is_featured'] = (bool)$r['is_featured'];            // true / false
            if (is_string($r['blocks'])) {
                $r['blocks'] = json_decode($r['blocks'], true);     // text -> real list
            }
        }
        unset($r);

        // 3. Turn the PHP array into JSON text
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        // 4. Save safely: write to "fallback.json.tmp" first, then rename it over the real file.
        //    dirname(__DIR__, 2) = go up two folders (api/cms -> project main folder).
        $target = dirname(__DIR__, 2) . '/fallback.json';
        $tmp    = $target . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        return rename($tmp, $target);

    } catch (Throwable $e) {
        // Never break the save/delete just because the backup failed.
        error_log('cmsRefreshFallback: ' . $e->getMessage());
        return false;
    }
}
