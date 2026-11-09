<?php
/**
 * api/cms/_lib.php — shared helpers for every api/cms/*.php endpoint.
 * Not an endpoint itself. Each endpoint loads config/database.php, helpers.php and
 * auth.php first (same as api/auth/login.php), then requires this file.
 */

if (!defined('CMS_IMG_RE')) {
    // The ONLY shape of image path we accept or ever store: uploads/content/<32 hex>.<ext>
    define('CMS_IMG_RE', '/^uploads\/content\/[a-f0-9]{32}\.(jpg|png|webp)$/');
}

/**
 * Staff gate. Call at the top of every CMS endpoint.
 * - state-changing requests must carry the X-Requested-With header (a cross-site page cannot send it)
 * - the user comes from the SERVER session, never from anything the browser sends
 * - role/active flag are re-checked in the database on every request
 */
function cmsRequireStaff(PDO $pdo): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'cfs-cms') {
        jsonError(403, 'Request blocked.');
    }
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        jsonError(401, 'Please sign in again.');
    }
    $stmt = $pdo->prepare('SELECT id, full_name, role, is_active FROM users WHERE id = :id');
    $stmt->execute(['id' => $uid]);
    $user = $stmt->fetch();
    if (!$user || !$user['is_active']) {
        jsonError(401, 'Please sign in again.');
    }
    if (!in_array($user['role'], ['registrar', 'admin'], true)) {
        jsonError(403, 'Only school staff can manage website content.');
    }
    return $user;
}

/** Trim, strip control characters, cap the length. $multiline keeps line breaks. */
function cmsStr($v, int $max, bool $multiline = false): string {
    if (!is_string($v)) {
        return '';
    }
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $clean = preg_replace('/[^\P{Cc}\n]/u', '', $v);   // remove control chars except newline
    if ($clean === null) {
        return '';                                      // invalid UTF-8
    }
    if (!$multiline) {
        $clean = str_replace("\n", ' ', $clean);
    }
    return mb_substr(trim($clean), 0, $max);
}

/** 'YYYY-MM-DD' or null. Anything else is rejected. */
function cmsDate($v, string $label): ?string {
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_string($v)) {
        jsonError(400, "Invalid $label.");
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        jsonError(400, "Invalid $label.");
    }
    return $v;
}

function cmsImageExists(string $path): bool {
    return preg_match(CMS_IMG_RE, $path) === 1 && is_file(dirname(__DIR__, 2) . '/' . $path);
}

function cmsSlug(string $title): string {
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
    $s = substr(trim((string)$s, '-'), 0, 60);
    if ($s === '') {
        $s = 'post';
    }
    return $s . '-' . bin2hex(random_bytes(3));         // random tail keeps the UNIQUE slug collision-free
}

/**
 * Validate + rebuild the free-form content blocks from scratch.
 * Only three block types exist, every field is length-capped, and photos
 * must be files this site uploaded itself. Nothing else gets through.
 */
function cmsCleanBlocks($blocks): array {
    if (!is_array($blocks)) {
        return [];
    }
    if (count($blocks) > 60) {
        jsonError(400, 'A post can have at most 60 content blocks.');
    }
    $out = [];
    foreach ($blocks as $b) {
        if (!is_array($b) || !isset($b['type']) || !is_string($b['type'])) {
            continue;
        }
        if ($b['type'] === 'heading') {
            $t = cmsStr($b['text'] ?? '', 150);
            if ($t !== '') {
                $out[] = ['type' => 'heading', 'text' => $t];
            }
        } elseif ($b['type'] === 'text') {
            $t = cmsStr($b['text'] ?? '', 5000, true);
            if ($t !== '') {
                $out[] = ['type' => 'text', 'text' => $t];
            }
        } elseif ($b['type'] === 'photo') {
            $src = $b['src'] ?? '';
            if (!is_string($src) || !cmsImageExists($src)) {
                jsonError(400, 'One of the photos is not valid. Please upload it again.');
            }
            $size = $b['size'] ?? 'medium';
            if (!in_array($size, ['small', 'medium', 'full'], true)) {
                $size = 'medium';
            }
            $out[] = ['type' => 'photo', 'src' => $src, 'caption' => cmsStr($b['caption'] ?? '', 200), 'size' => $size];
        }
    }
    return $out;
}

/** Decode the stored blocks column; never trust it blindly either. */
function cmsDecodeBlocks($json): array {
    if (!is_string($json) || $json === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function cmsAudit(PDO $pdo, int $actorId, string $action, int $entityId, array $details = []): void {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (actor_id, action, entity, entity_id, details)
         VALUES (:actor, :action, :entity, :entity_id, :details)'
    );
    $stmt->execute([
        'actor'     => $actorId,
        'action'    => $action,
        'entity'    => 'content_post',
        'entity_id' => $entityId,
        'details'   => json_encode($details, JSON_UNESCAPED_UNICODE),
    ]);
}
