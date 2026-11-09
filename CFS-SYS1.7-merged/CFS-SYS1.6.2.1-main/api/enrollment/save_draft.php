<?php
/**
 * POST /api/enrollment/save_draft.php   (JSON)
 *
 * Guardian only. "Save & exit" in the enrollment wizard. Takes the same body as
 * submit.php (student name + birthdate are still required, because the student
 * record needs them) and stores it as an application with status 'draft'.
 * Send "application_id" to update a draft you saved earlier; without it a new
 * draft is created. Drafts are invisible to the registrar until submitted.
 * Documents are attached with api/uploads/document.php (it already accepts drafts).
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';   // abortWith()
require __DIR__ . '/../../config/enrollment.php';

requireMethod('POST');
$guardian = requireRole('guardian');

$p = parseEnrollmentInput(readJsonBody());
[$applicationId, $studentId] = saveEnrollmentApplication($pdo, (int) $guardian['id'], $p, 'draft');

$stmt = $pdo->prepare('SELECT application_no FROM enrollment_applications WHERE id = :id');
$stmt->execute(['id' => $applicationId]);

jsonResponse(200, [
    'application_id' => $applicationId,
    'application_no' => $stmt->fetchColumn(),
    'student_id'     => $studentId,
    'status'         => 'draft',
]);
