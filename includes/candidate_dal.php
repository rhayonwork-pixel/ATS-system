<?php
/**
 * Candidate profile — Data Access Layer.
 *
 * Every function here does exactly one thing: run a parameterized query and
 * return the result. No RBAC, no derived/presentation values, no markup.
 * That belongs in candidate_view_model.php, which is the only file that
 * calls these.
 *
 * Every write function returns bool (or an id) rather than letting a
 * PDOException escape to the caller, so a database failure becomes a normal
 * "it didn't work" the caller can act on — not a stack trace on the page.
 */

/** Fetch by application id, with a fallback for a pre-migration schema. */
function candidate_dal_fetch(PDO $pdo, int $applicationId): ?array {
    $full = "SELECT a.id application_id,a.stage,a.status app_status,a.applied_at,a.updated_at,
            a.cover_letter,a.why_us,a.assigned_to,
            c.id candidate_id,c.first_name,c.last_name,c.email,c.phone,c.rating,c.resume_path,c.profile_image,
            c.portfolio_url,c.source,c.consent_at,c.created_at candidate_created,
            c.current_title,c.experience_level,c.skills,c.education,c.notes candidate_notes,c.record_status,
            j.id job_id,j.title job_title,j.tags job_tags,j.location job_location,j.employment_type,
            d.name department, u.name assigned_name
        FROM applications a
        JOIN candidates c ON c.id=a.candidate_id
        JOIN jobs j ON j.id=a.job_id
        LEFT JOIN departments d ON d.id=j.department_id
        LEFT JOIN users u ON u.id=a.assigned_to
        WHERE a.id=? LIMIT 1";
    try {
        $stmt = $pdo->prepare($full);
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        // A column from a later migration is missing. Fall back to the
        // original field set rather than letting the exception surface, so
        // the profile still renders — just without the newer details.
        $legacy = "SELECT a.id application_id,a.stage,a.applied_at,a.cover_letter,a.why_us,
                c.id candidate_id,c.first_name,c.last_name,c.email,c.phone,c.rating,c.resume_path,
                c.profile_image,c.portfolio_url,c.source,
                j.id job_id,j.title job_title,j.tags job_tags,d.name department
            FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id
            LEFT JOIN departments d ON d.id=j.department_id WHERE a.id=? LIMIT 1";
        try {
            $stmt = $pdo->prepare($legacy);
            $stmt->execute([$applicationId]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e2) {
            return null;
        }
    }
}

function candidate_dal_resume_indexed(PDO $pdo, int $candidateId): bool {
    try {
        $s = $pdo->prepare('SELECT CHAR_LENGTH(COALESCE(resume_text, "")) FROM candidates WHERE id=?');
        $s->execute([$candidateId]);
        return ((int)$s->fetchColumn()) > 40;
    } catch (Throwable $e) {
        return false;
    }
}

function candidate_dal_notes(PDO $pdo, int $candidateId): array {
    $s = $pdo->prepare('SELECT n.*,u.name author FROM candidate_notes n
                        LEFT JOIN users u ON u.id=n.author_id
                        WHERE n.candidate_id=? ORDER BY n.created_at DESC');
    $s->execute([$candidateId]);
    return $s->fetchAll() ?: [];
}

function candidate_dal_feedback(PDO $pdo, int $applicationId): array {
    $s = $pdo->prepare('SELECT f.*,u.name author FROM candidate_feedback f
                        LEFT JOIN users u ON u.id=f.author_id
                        WHERE f.application_id=? ORDER BY f.created_at DESC');
    $s->execute([$applicationId]);
    return $s->fetchAll() ?: [];
}

function candidate_dal_suggestions(PDO $pdo, int $applicationId): array {
    $s = $pdo->prepare('SELECT s.*,j.title suggested_title,u.name author FROM candidate_role_suggestions s
                        JOIN jobs j ON j.id=s.suggested_job_id
                        LEFT JOIN users u ON u.id=s.author_id
                        WHERE s.application_id=? ORDER BY s.created_at DESC');
    $s->execute([$applicationId]);
    return $s->fetchAll() ?: [];
}

function candidate_dal_open_roles(PDO $pdo, int $excludeJobId): array {
    $s = $pdo->prepare("SELECT id,title FROM jobs WHERE status='open' AND id<>? ORDER BY title");
    $s->execute([$excludeJobId]);
    return $s->fetchAll() ?: [];
}

function candidate_dal_stage_review(PDO $pdo, int $applicationId, string $stageType): ?array {
    $s = $pdo->prepare('SELECT r.*,u.name reviewer FROM stage_reviews r
                        LEFT JOIN users u ON u.id=r.reviewer_id
                        WHERE r.application_id=? AND r.stage_type=? LIMIT 1');
    $s->execute([$applicationId, $stageType]);
    return $s->fetch() ?: null;
}

function candidate_dal_employee_record(PDO $pdo, int $applicationId): ?array {
    $s = $pdo->prepare('SELECT e.*,d.name department_name FROM employees e
                        LEFT JOIN departments d ON d.id=e.department_id
                        WHERE e.application_id=? LIMIT 1');
    $s->execute([$applicationId]);
    return $s->fetch() ?: null;
}

function candidate_dal_departments(PDO $pdo): array {
    $s = $pdo->prepare('SELECT id,name FROM departments ORDER BY name');
    $s->execute();
    return $s->fetchAll() ?: [];
}

function candidate_dal_ai_analysis(PDO $pdo, int $applicationId): ?array {
    $s = $pdo->prepare('SELECT * FROM candidate_ai_analysis WHERE application_id=? LIMIT 1');
    $s->execute([$applicationId]);
    return $s->fetch() ?: null;
}

function candidate_dal_activity(PDO $pdo, int $applicationId, int $candidateId): array {
    $s = $pdo->prepare("SELECT al.*,u.name actor FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id
        WHERE (al.entity_type='application' AND al.entity_id=?) OR (al.entity_type='candidate' AND al.entity_id=?)
        ORDER BY al.created_at DESC LIMIT 25");
    $s->execute([$applicationId, $candidateId]);
    return $s->fetchAll() ?: [];
}

/* ---------------------------------------------------------------------- *
 * Writes. Each returns bool (or the current stage / a friendly error
 * message) rather than throwing, so the caller decides the flash message.
 * ---------------------------------------------------------------------- */

function candidate_dal_current_stage(PDO $pdo, int $applicationId): ?string {
    $s = $pdo->prepare('SELECT stage FROM applications WHERE id=?');
    $s->execute([$applicationId]);
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
}

function candidate_dal_update_stage(PDO $pdo, int $applicationId, string $stage): bool {
    return $pdo->prepare('UPDATE applications SET stage=? WHERE id=?')->execute([$stage, $applicationId]);
}

function candidate_dal_set_rating(PDO $pdo, int $candidateId, int $rating): bool {
    return $pdo->prepare('UPDATE candidates SET rating=? WHERE id=?')->execute([$rating, $candidateId]);
}

function candidate_dal_add_note(PDO $pdo, int $candidateId, int $authorId, string $note): bool {
    return $pdo->prepare('INSERT INTO candidate_notes(candidate_id,author_id,note) VALUES(?,?,?)')
        ->execute([$candidateId, $authorId, $note]);
}

function candidate_dal_add_feedback(PDO $pdo, int $applicationId, int $authorId, string $fit, string $notes): bool {
    return $pdo->prepare('INSERT INTO candidate_feedback(application_id,author_id,fit,notes) VALUES(?,?,?,?)')
        ->execute([$applicationId, $authorId, $fit, $notes]);
}

function candidate_dal_add_suggestion(PDO $pdo, int $applicationId, int $jobId, string $note, int $authorId): bool {
    return $pdo->prepare('INSERT INTO candidate_role_suggestions(application_id,suggested_job_id,note,author_id) VALUES(?,?,?,?)')
        ->execute([$applicationId, $jobId, $note, $authorId]);
}

function candidate_dal_save_stage_review(PDO $pdo, int $applicationId, string $stageType, ?int $rating, ?string $feedback, ?string $notes, int $reviewerId): bool {
    return $pdo->prepare('INSERT INTO stage_reviews(application_id,stage_type,rating,feedback,notes,reviewer_id) VALUES(?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE rating=VALUES(rating),feedback=VALUES(feedback),notes=VALUES(notes),reviewer_id=VALUES(reviewer_id)')
        ->execute([$applicationId, $stageType, $rating, $feedback, $notes, $reviewerId]);
}

/** The small subset of fields ai_analyze needs, kept separate from the full fetch. */
function candidate_dal_fetch_for_ai(PDO $pdo, int $applicationId): ?array {
    $s = $pdo->prepare("SELECT a.id application_id,a.cover_letter,a.why_us,c.id candidate_id,c.resume_path,c.portfolio_url,j.title,j.tags
                        FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id
                        WHERE a.id=? LIMIT 1");
    $s->execute([$applicationId]);
    return $s->fetch() ?: null;
}

function candidate_dal_save_ai_analysis(PDO $pdo, int $applicationId, array $a): bool {
    return $pdo->prepare('INSERT INTO candidate_ai_analysis(application_id,overall_score,category_scores,summary,strengths,concerns,recommendation,ai_notes) VALUES(?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE overall_score=VALUES(overall_score),category_scores=VALUES(category_scores),summary=VALUES(summary),strengths=VALUES(strengths),concerns=VALUES(concerns),recommendation=VALUES(recommendation),ai_notes=VALUES(ai_notes),generated_at=CURRENT_TIMESTAMP')
        ->execute([$applicationId, $a['overall_score'], json_encode($a['category_scores']), $a['summary'],
                   json_encode($a['strengths']), json_encode($a['concerns']), $a['recommendation'], $a['ai_notes']]);
}

/** The small subset of fields convert_employee needs. */
function candidate_dal_fetch_for_employee_conversion(PDO $pdo, int $applicationId): ?array {
    $s = $pdo->prepare("SELECT a.id application_id,a.stage,c.id candidate_id,c.first_name,c.last_name,j.title
                        FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id
                        WHERE a.id=? LIMIT 1");
    $s->execute([$applicationId]);
    return $s->fetch() ?: null;
}

function candidate_dal_employee_exists(PDO $pdo, int $applicationId): bool {
    $s = $pdo->prepare('SELECT id FROM employees WHERE application_id=?');
    $s->execute([$applicationId]);
    return (bool)$s->fetchColumn();
}

/** Returns the new employee id on success, or a string error message on failure. */
function candidate_dal_create_employee(PDO $pdo, array $row, int $applicationId, string $hiredPosition, string $employeeNumber, ?int $departmentId, string $startDate) {
    try {
        $s = $pdo->prepare('INSERT INTO employees(candidate_id,application_id,applied_position,employee_number,job_title,department_id,start_date,status) VALUES(?,?,?,?,?,?,?,?)');
        $s->execute([$row['candidate_id'], $applicationId, $row['title'], $employeeNumber, $hiredPosition, $departmentId, $startDate, 'active']);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'Duplicate')
            ? 'That employee number is already in use.'
            : 'Could not create the employee record.';
    }
}
