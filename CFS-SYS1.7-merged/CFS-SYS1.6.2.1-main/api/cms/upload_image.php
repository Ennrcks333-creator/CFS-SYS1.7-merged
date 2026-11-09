<?php
/**
 * POST /api/cms/upload_image.php   (staff only, multipart field "image")
 * Saves a public website photo to uploads/content/ and returns { path }.
 * Unlike enrollment documents, these are meant to be public, so they live in a web-served folder.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/_lib.php';

requireMethod('POST');
cmsRequireStaff($pdo);

$maxBytes = 5 * 1024 * 1024;
$allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    jsonError(400, 'No photo was received.');
}
$f = $_FILES['image'];
if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
    jsonError(400, 'That photo is larger than 5 MB.');
}
if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    jsonError(400, 'The photo could not be uploaded. Please try again.');
}
if ($f['size'] > $maxBytes) {
    jsonError(400, 'That photo is larger than 5 MB.');
}

// Judge by the file's real content, never by its name or the browser-supplied type.
$info = @getimagesize($f['tmp_name']);
if ($info === false || !isset($allowed[$info['mime']])) {
    jsonError(400, 'Only JPG, PNG or WEBP photos are accepted.');
}
if ($info[0] > 8000 || $info[1] > 8000) {
    jsonError(400, 'That photo is too large in size. Please use one under 8000 pixels wide.');
}

$dir = dirname(__DIR__, 2) . '/uploads/content';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    error_log('cms/upload_image.php: cannot create ' . $dir);
    jsonError(500, 'The server could not store the photo.');
}
// Defense in depth: even if something odd lands here, Apache will not run it.
$ht = $dir . '/.htaccess';
if (!is_file($ht)) {
    file_put_contents($ht, "Options -Indexes\n<FilesMatch \"(?i)\\.(php|phtml|phar|pl|py|cgi)$\">\n  Require all denied\n</FilesMatch>\n");
}

$name = bin2hex(random_bytes(16)) . '.' . $allowed[$info['mime']];   // random name, extension from real type
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
    error_log('cms/upload_image.php: move_uploaded_file failed');
    jsonError(500, 'The server could not store the photo.');
}

jsonResponse(200, ['path' => 'uploads/content/' . $name]);
