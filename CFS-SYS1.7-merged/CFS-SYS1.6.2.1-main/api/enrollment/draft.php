<?php
/**
 * GET /api/enrollment/draft.php?application_id=12
 * Guardian only. Returns everything the wizard needs to resume one of the
 * guardian's own DRAFT applications (409 if it is no longer a draft).
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';

requireMethod('GET');
$guardian = requireRole('guardian');

$id = filter_input(INPUT_GET, 'application_id', FILTER_VALIDATE_INT);
if (!$id) {
    jsonError(400, 'application_id (integer) is required.');
}

$stmt = $pdo->prepare(
    'SELECT a.id, a.application_no, a.status, a.enrollment_type, a.grade_level, a.guardian_relationship,
            a.emergency_contact_name, a.emergency_contact_mobile,
            s.id AS student_id, s.first_name, s.last_name, s.birthdate, s.sex, s.address, s.lrn,
            (SELECT COUNT(*) FROM enrollment_applications o WHERE o.student_id = s.id AND o.id <> a.id) AS student_other_apps
     FROM enrollment_applications a
     JOIN students s ON s.id = a.student_id
     WHERE a.id = :id AND a.guardian_id = :g'
);
$stmt->execute(['id' => $id, 'g' => $guardian['id']]);
$draft = $stmt->fetch();
if (!$draft) {
    jsonError(404, 'Draft not found.');
}
if ($draft['status'] !== 'draft') {
    jsonError(409, 'This application was already submitted.');
}

$stmt = $pdo->prepare('SELECT doc_type, status FROM documents WHERE application_id = :id');
$stmt->execute(['id' => $id]);

jsonResponse(200, ['draft' => $draft, 'documents' => $stmt->fetchAll()]);
