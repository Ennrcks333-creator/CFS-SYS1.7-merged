<?php
/**
 * GET /api/cms/refresh_fallback.php   (staff only)
 * ---------------------------------------------------------------------
 * WHAT IT DOES: rebuilds fallback.json from Supabase right now.
 *
 * WHEN TO USE IT:
 *   - The very first time (to create the file).
 *   - After you edited a post directly in Supabase's Table Editor
 *     (our PHP didn't see that change, so the backup is out of date).
 *
 * HOW: sign in as the registrar first, then open this address in the browser:
 *   http://localhost:8080/cfs-sys1.6/api/cms/refresh_fallback.php
 * You should see {"refreshed":true}
 */

require __DIR__ . '/../../config/database.php';   // $pdo   (MariaDB) - for the login check
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/cloud.php';      // $cloud (Supabase)
require __DIR__ . '/_lib.php';
require __DIR__ . '/_fallback.php';               // gives us cmsRefreshFallback()

requireMethod('GET');
cmsRequireStaff($pdo);                            // only signed-in staff may run this

$ok = cmsRefreshFallback($cloud);
if (!$ok) {
    jsonError(500, 'Could not write fallback.json. Check that the project folder is writable.');
}
jsonResponse(200, ['refreshed' => true]);
