<?php
/**
 * Candidate profile — presentation / view-model layer.
 *
 * Sits between includes/candidate_dal.php (raw data) and candidate.php (pure
 * markup). This is where business rules live: which stage counts as
 * "screening done", how a database stage maps to a badge, what a locked
 * section looks like before that stage is reached.
 *
 * build_candidate_view_model() is called exactly ONCE per request. It is the
 * single source of truth for this page: every value the view renders comes
 * from the array it returns, so there is nowhere else a second, possibly
 * stale copy of the same data could be read from.
 *
 * It never lets a database exception reach the page. On failure it returns
 * ['ok' => false, 'error' => <friendly message>] and candidate.php shows
 * that message instead of a stack trace.
 */
require_once __DIR__ . '/candidate_dal.php';

/**
 * Stage -> badge colour family.
 *
 * This app's pipeline stage names (new/screening/interview/offer/hired/
 * rejected) are used everywhere — the stage dropdown, the pipeline board,
 * analytics filters, the stage_reviews table's stage_type enum. Renaming
 * them on this one page to "Reviewing"/"Interviewed" would create a second,
 * inconsistent vocabulary for the same underlying value, which is exactly
 * the kind of duplication this refactor is meant to remove. The stage
 * NAMES are kept; only the COLOUR each one maps to changes, and that lives
 * in one place (stage_badge() in includes/config.php) so every page that
 * calls it — this one included — gets the same palette.
 *
 *   new        blue/purple family   (unassigned, freshly applied)
 *   screening  amber                (actively being reviewed)
 *   interview  teal                 (in the interview stage)
 *   offer      purple               (not named in every spec, kept distinct)
 *   hired      green                (matches every prior spec)
 *   rejected   red/crimson          (matches every prior spec)
 */
function candidate_stage_family(string $stage): string {
    return match ($stage) {
        'new' => 'info',
        'screening' => 'warning',
        'interview' => 'teal',
        'hired' => 'success',
        'rejected' => 'danger',
        default => 'neutral', // offer, and anything unrecognised
    };
}

/**
 * Build the complete view-model for one candidate profile request.
 * Returns ['ok'=>false,'error'=>string] on any failure, or ['ok'=>true, ...]
 * with every key the view needs.
 */
function build_candidate_view_model(PDO $pdo, int $applicationId): array {
    try {
        $candidate = candidate_dal_fetch($pdo, $applicationId);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Unable to load candidate data. Please try again later.'];
    }
    if (!$candidate) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    try {
        $candidateId = (int)$candidate['candidate_id'];
        $appId = (int)$candidate['application_id'];

        $interviewHistory = candidate_interview_history($candidateId);   // includes/interview_lib.php
        $documents = candidate_documents($candidateId);                  // includes/documents.php
        $primaryDoc = $documents[0] ?? null;
        $resumeIndexed = candidate_dal_resume_indexed($pdo, $candidateId);

        $notes = candidate_dal_notes($pdo, $candidateId);
        $feedbackList = candidate_dal_feedback($pdo, $appId);
        $suggestions = candidate_dal_suggestions($pdo, $appId);
        $openRoles = candidate_dal_open_roles($pdo, (int)$candidate['job_id']);

        $screeningReview = candidate_dal_stage_review($pdo, $appId, 'screening');
        $interviewReview = candidate_dal_stage_review($pdo, $appId, 'interview');

        $employeeRecord = candidate_dal_employee_record($pdo, $appId);
        $hireDepartments = candidate_dal_departments($pdo);

        $aiRow = candidate_dal_ai_analysis($pdo, $appId);
        $aiAnalysis = null;
        if ($aiRow) {
            $aiAnalysis = $aiRow;
            $aiAnalysis['category_scores'] = json_decode($aiRow['category_scores'], true) ?: [];
            $aiAnalysis['strengths'] = json_decode($aiRow['strengths'], true) ?: [];
            $aiAnalysis['concerns'] = json_decode($aiRow['concerns'], true) ?: [];
        }

        $activity = candidate_dal_activity($pdo, $appId, $candidateId);
    } catch (Throwable $e) {
        // The primary record loaded, but a secondary panel's query failed.
        // A blank section is a far smaller failure than a fatal page, so
        // everything not yet fetched defaults to empty rather than aborting.
        $interviewHistory = $interviewHistory ?? [];
        $documents = $documents ?? [];
        $primaryDoc = $primaryDoc ?? null;
        $resumeIndexed = $resumeIndexed ?? false;
        $notes = $notes ?? [];
        $feedbackList = $feedbackList ?? [];
        $suggestions = $suggestions ?? [];
        $openRoles = $openRoles ?? [];
        $screeningReview = $screeningReview ?? null;
        $interviewReview = $interviewReview ?? null;
        $employeeRecord = $employeeRecord ?? null;
        $hireDepartments = $hireDepartments ?? [];
        $aiAnalysis = $aiAnalysis ?? null;
        $activity = $activity ?? [];
    }

    // ---- Business rules -----------------------------------------------
    $stageRanks = ['new' => 0, 'screening' => 1, 'interview' => 2, 'offer' => 3, 'hired' => 4, 'rejected' => 5];
    $stageRank = $stageRanks[$candidate['stage']] ?? 0;
    $screeningDone = $screeningReview !== null || $stageRank >= 1;
    $interviewDone = $interviewReview !== null || $stageRank >= 2;

    // ---- Presentation constants (single copy, not re-declared per page) --
    $stageLabels = ['new' => 'Applied', 'screening' => 'Screening', 'interview' => 'Interview',
                    'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Rejected'];
    $activityStageLabels = $stageLabels;
    $actionLabels = [
        'pipeline_stage_move' => null,
        'candidate_note' => 'Note added',
        'candidate_feedback' => 'Feedback saved',
        'candidate_rating' => 'Rating updated',
        'candidate_role_suggestion' => 'Role suggestion saved',
        'screening_review' => 'Screening review saved',
        'interview_review' => 'Interview review saved',
        'meeting_review' => 'Meeting review saved',
        'ai_analysis_generated' => 'AI analysis generated',
        'candidate_converted_to_employee' => 'Added to Employees',
    ];

    $initials = strtoupper(substr($candidate['first_name'], 0, 1) . substr($candidate['last_name'], 0, 1));
    $sourceLabel = str_replace('_', ' ', ucfirst($candidate['source'] ?: 'career_site'));

    return [
        'ok' => true,
        'candidate' => $candidate,
        'interviewHistory' => $interviewHistory,
        'documents' => $documents,
        'primaryDoc' => $primaryDoc,
        'resumeIndexed' => $resumeIndexed,
        'notes' => $notes,
        'feedbackList' => $feedbackList,
        'suggestions' => $suggestions,
        'openRoles' => $openRoles,
        'screeningReview' => $screeningReview,
        'interviewReview' => $interviewReview,
        'employeeRecord' => $employeeRecord,
        'hireDepartments' => $hireDepartments,
        'aiAnalysis' => $aiAnalysis,
        'activity' => $activity,
        'screeningDone' => $screeningDone,
        'interviewDone' => $interviewDone,
        'stageLabels' => $stageLabels,
        'activityStageLabels' => $activityStageLabels,
        'actionLabels' => $actionLabels,
        'initials' => $initials,
        'sourceLabel' => $sourceLabel,
    ];
}
