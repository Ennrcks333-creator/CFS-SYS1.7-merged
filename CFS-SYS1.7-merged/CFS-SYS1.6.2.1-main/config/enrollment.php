<?php
/**
 * Shared by api/enrollment/submit.php and api/enrollment/save_draft.php.
 * Include after database.php, helpers.php and auth.php.
 *
 * A "draft" is simply an enrollment_applications row with status 'draft'
 * (the column already allowed it). Submitting a draft turns the SAME row into
 * 'submitted', so nothing is duplicated.
 */

/** Validates the JSON body of submit/save_draft and returns clean values (ends the request with 400 if invalid). */
function parseEnrollmentInput(array $input): array
{
    $missing = missingFields($input, ['enrollment_type', 'grade_level']);
    if (!empty($missing)) {
        jsonError(400, 'Missing required fields: ' . implode(', ', $missing));
    }

    $enrollmentType = $input['enrollment_type'];
    $gradeLevel     = (int) $input['grade_level'];

    if (!in_array($enrollmentType, ['new', 'continuing'], true)) {
        jsonError(400, "enrollment_type must be 'new' or 'continuing'.");
    }
    if ($gradeLevel < 1 || $gradeLevel > 6) {
        jsonError(400, 'grade_level must be between 1 and 6. Kindergarten applicants use the screening request flow instead.');
    }
    if ($enrollmentType === 'new' && $gradeLevel !== 1) {
        jsonError(400, 'New students must enroll at Grade 1.');
    }

    $relationship = $input['guardian_relationship'] ?? null;
    if ($relationship !== null && $relationship !== '' &&
        (!is_string($relationship) || !in_array($relationship, ['mother', 'father', 'legal_guardian'], true))) {
        jsonError(400, "guardian_relationship must be 'mother', 'father' or 'legal_guardian'.");
    }
    $relationship = ($relationship === '' ? null : $relationship);

    $hasStudentId = isset($input['student_id']) && $input['student_id'] !== '' && $input['student_id'] !== null;
    $needDetails  = ($enrollmentType === 'new') || !$hasStudentId;

    $student = null;
    if ($needDetails) {
        $missing = missingFields($input, ['first_name', 'last_name', 'birthdate']);
        if (!empty($missing)) {
            jsonError(400, 'Missing required fields: ' . implode(', ', $missing));
        }
        foreach (['first_name', 'last_name', 'birthdate'] as $k) {
            if (!is_string($input[$k])) {
                jsonError(400, "$k must be text.");
            }
        }
        $first = trim($input['first_name']);
        $last  = trim($input['last_name']);
        if (textLength($first) > 100 || textLength($last) > 100) {
            jsonError(400, 'Names must be at most 100 characters.');
        }
        if (!isValidDate($input['birthdate'])) {
            jsonError(400, 'birthdate must be in YYYY-MM-DD format.');
        }
        if ($input['birthdate'] > date('Y-m-d')) {
            jsonError(400, 'birthdate cannot be in the future.');
        }
        $sex = $input['sex'] ?? null;
        if ($sex === '') { $sex = null; }
        if ($sex !== null && (!is_string($sex) || !in_array($sex, ['male', 'female'], true))) {
            jsonError(400, "sex must be 'male' or 'female'.");
        }
        $address = optionalText($input, 'address', 255);
        $lrn = $input['lrn'] ?? null;
        if ($lrn !== null && $lrn !== '') {
            $lrn = is_string($lrn) ? preg_replace('/[\s-]+/', '', $lrn) : '';
            if (!preg_match('/^\d{12}$/', $lrn)) {
                jsonError(400, 'LRN must be 12 digits (spaces are ignored).');
            }
        } else {
            $lrn = null;
        }
        $student = ['first' => $first, 'last' => $last, 'birthdate' => $input['birthdate'],
                    'sex' => $sex, 'address' => $address, 'lrn' => $lrn];
    }

    $draftId = null;
    if (isset($input['application_id']) && $input['application_id'] !== null && $input['application_id'] !== '') {
        $draftId = requireIntField($input, 'application_id');
    }

    return [
        'type'            => $enrollmentType,
        'grade'           => $gradeLevel,
        'relationship'    => $relationship,
        'guardian_mobile' => optionalText($input, 'guardian_mobile', 30),
        'em_name'         => optionalText($input, 'emergency_contact_name', 150),
        'em_mobile'       => optionalText($input, 'emergency_contact_mobile', 30),
        'student_id'      => $hasStudentId ? (int) $input['student_id'] : null,
        'student'         => $student,
        'draft_id'        => $draftId,
    ];
}

/**
 * Creates the application (or, when $p['draft_id'] is set, updates that guardian's
 * existing DRAFT) with the given status: 'draft' or 'submitted'.
 * Returns [applicationId, studentId]; ends the request with an error JSON on failure.
 */
function saveEnrollmentApplication(PDO $pdo, int $guardianId, array $p, string $status): array
{
    try {
        $schoolYear = $pdo->query('SELECT id, enrollment_open FROM school_years WHERE is_current = 1 LIMIT 1')->fetch();
        if (!$schoolYear) {
            jsonError(500, 'No current school year is configured.');
        }
        if (!$schoolYear['enrollment_open']) {
            jsonError(403, 'Enrollment is not currently open.');
        }

        $pdo->beginTransaction();

        $draft = null;
        if ($p['draft_id'] !== null) {
            $stmt = $pdo->prepare(
                'SELECT id, student_id, status FROM enrollment_applications
                 WHERE id = :id AND guardian_id = :g FOR UPDATE'
            );
            $stmt->execute(['id' => $p['draft_id'], 'g' => $guardianId]);
            $draft = $stmt->fetch();
            if (!$draft) {
                abortWith($pdo, 404, 'Draft not found.');
            }
            if ($draft['status'] !== 'draft') {
                abortWith($pdo, 409, 'This application was already submitted.');
            }
        }

        // ---- the student ----
        if ($p['student'] !== null) {
            $s = $p['student'];
            $reuse = false;
            if ($draft) {
                // Only edit the draft's student row if no other application uses it.
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrollment_applications WHERE student_id = :s AND id <> :a');
                $stmt->execute(['s' => $draft['student_id'], 'a' => $draft['id']]);
                $reuse = (int) $stmt->fetchColumn() === 0;
            }
            $cols = [
                'first_name' => $s['first'], 'last_name' => $s['last'], 'birthdate' => $s['birthdate'],
                'sex' => $s['sex'], 'address' => $s['address'], 'lrn' => $s['lrn'],
            ];
            if ($reuse) {
                $stmt = $pdo->prepare(
                    'UPDATE students SET first_name = :first_name, last_name = :last_name, birthdate = :birthdate,
                            sex = :sex, address = :address, lrn = :lrn
                     WHERE id = :id AND guardian_id = :g'
                );
                $stmt->execute($cols + ['id' => $draft['student_id'], 'g' => $guardianId]);
                $studentId = (int) $draft['student_id'];
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO students (guardian_id, first_name, last_name, birthdate, sex, address, lrn)
                     VALUES (:g, :first_name, :last_name, :birthdate, :sex, :address, :lrn)'
                );
                $stmt->execute($cols + ['g' => $guardianId]);
                $studentId = (int) $pdo->lastInsertId();
            }
        } else {
            $studentId = (int) $p['student_id'];
            $stmt = $pdo->prepare('SELECT id FROM students WHERE id = :id AND guardian_id = :g');
            $stmt->execute(['id' => $studentId, 'g' => $guardianId]);
            if (!$stmt->fetch()) {
                abortWith($pdo, 404, 'Student not found on your account.');
            }
        }

        // ---- the application ----
        $isSubmit = $status === 'submitted';
        $fields = [
            'student_id' => $studentId, 'type' => $p['type'], 'grade' => $p['grade'], 'status' => $status,
            'rel' => $p['relationship'], 'em_name' => $p['em_name'], 'em_mobile' => $p['em_mobile'],
        ];
        if ($draft) {
            $stmt = $pdo->prepare(
                'UPDATE enrollment_applications
                 SET student_id = :student_id, enrollment_type = :type, grade_level = :grade, status = :status,
                     submitted_at = ' . ($isSubmit ? 'NOW()' : 'NULL') . ',
                     guardian_relationship = :rel, emergency_contact_name = :em_name, emergency_contact_mobile = :em_mobile
                 WHERE id = :id'
            );
            $stmt->execute($fields + ['id' => $draft['id']]);
            $applicationId = (int) $draft['id'];
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO enrollment_applications
                    (school_year_id, student_id, guardian_id, enrollment_type, grade_level, status, submitted_at,
                     guardian_relationship, emergency_contact_name, emergency_contact_mobile)
                 VALUES (:sy, :student_id, :g, :type, :grade, :status, ' . ($isSubmit ? 'NOW()' : 'NULL') . ',
                         :rel, :em_name, :em_mobile)'
            );
            $stmt->execute($fields + ['sy' => $schoolYear['id'], 'g' => $guardianId]);
            $applicationId = (int) $pdo->lastInsertId();
        }

        if ($p['guardian_mobile'] !== null) {
            $stmt = $pdo->prepare('UPDATE users SET mobile = :m WHERE id = :id');
            $stmt->execute(['m' => $p['guardian_mobile'], 'id' => $guardianId]);
        }

        $pdo->commit();
        return [$applicationId, $studentId];

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (($e->errorInfo[1] ?? null) === 1062) {
            if (stripos($e->getMessage(), 'lrn') !== false) {
                jsonError(409, 'A student with this LRN is already registered.');
            }
            jsonError(409, 'This student already has an application for the current school year.');
        }
        error_log('saveEnrollmentApplication failed: ' . $e->getMessage());
        jsonError(500, $status === 'draft' ? 'Could not save the draft.' : 'Could not submit the application.');
    }
}
