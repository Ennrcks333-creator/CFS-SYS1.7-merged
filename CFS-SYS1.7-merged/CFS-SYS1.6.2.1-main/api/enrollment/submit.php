<?php
/**
 * POST /api/enrollment/submit.php
 *
 * Creates an enrollment application for a Gr.1-6 student (new or
 * continuing), then generates their fee items. Kindergarten/transferee
 * applicants don't use this - they go through api/screening/request.php.
 *
 * guardian_id comes from the session, never from the request body.
 *
 * Body (JSON):
 *   Common (all optional): guardian_mobile, guardian_relationship
 *     (mother|father|legal_guardian), emergency_contact_name,
 *     emergency_contact_mobile
 *
 *   New student (always Grade 1):
 *     { "enrollment_type":"new", "grade_level":1, "first_name", "last_name",
 *       "birthdate":"YYYY-MM-DD", "sex":"male|female", "address", "lrn" }
 *
 *   Continuing student - EITHER an existing child on this account:
 *     { "enrollment_type":"continuing", "grade_level":3, "student_id":7 }
 *   OR (first time this child is in the system) the child's details, same
 *   fields as a new student plus "lrn":
 *     { "enrollment_type":"continuing", "grade_level":3, "first_name", "last_name", "birthdate", "lrn" ... }
 *
 *   Optional "application_id": the guardian's own DRAFT (see save_draft.php). It is
 *   updated and turned into a submitted application instead of creating a new row.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/registrar.php';   // abortWith()
require __DIR__ . '/../../config/enrollment.php';

requireMethod('POST');
$guardian   = requireRole('guardian');
$guardianId = (int) $guardian['id'];

$p = parseEnrollmentInput(readJsonBody());
[$applicationId, $studentId] = saveEnrollmentApplication($pdo, $guardianId, $p, 'submitted');

// No fees here: the invoice (fee items) is created when the registrar approves the application.

$stmt = $pdo->prepare('SELECT application_no FROM enrollment_applications WHERE id = :id');
$stmt->execute(['id' => $applicationId]);

jsonResponse(201, [
    'application_id' => $applicationId,
    'application_no' => $stmt->fetchColumn(),
    'student_id'     => $studentId,
    'status'         => 'submitted',
]);
