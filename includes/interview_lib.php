<?php
/**
 * Acme ATS — interview meeting lifecycle.
 *
 * This deliberately depends on config.php only. interview-room.php is opened by
 * candidates who have no account, so it cannot pull in auth.php (which forces a
 * login redirect). Everything here works from an interview row.
 *
 *   scheduled -> ready -> in_progress -> ended -> review_pending -> reviewed
 *
 * Scoring is only reachable from review_pending onward. That rule is enforced
 * in the POST handler in interviews.php, not by hiding the form.
 */

require_once __DIR__ . '/config.php';

function interview_states(): array {
    return [
        'scheduled'      => ['Scheduled',      'The room is not open yet.'],
        'ready'          => ['Ready',          'Start time reached — the room can be joined.'],
        'in_progress'    => ['In progress',    'The meeting is happening now.'],
        'ended'          => ['Meeting ended',  'The call is over.'],
        'review_pending' => ['Review pending', 'Waiting on the interviewer’s score and review.'],
        'reviewed'       => ['Reviewed',       'Score and final review submitted.'],
    ];
}

function interview_state_label(string $state): string {
    return interview_states()[$state][0] ?? ucfirst(str_replace('_', ' ', $state));
}

/**
 * The state to show. A cancelled or no-show interview overrides the lifecycle,
 * and a scheduled interview whose start time has passed reads as "ready" without
 * needing a background job to move it along.
 */
function interview_state(array $row): string {
    $status = $row['status'] ?? 'scheduled';
    if ($status === 'cancelled') return 'cancelled';
    if ($status === 'no_show')   return 'no_show';

    $state = $row['meeting_state'] ?? 'scheduled';
    if ($state === 'scheduled' && !empty($row['starts_at']) && strtotime($row['starts_at']) <= time()) {
        return 'ready';
    }
    return $state;
}

function interview_state_badge(array $row): string {
    $state = interview_state($row);
    $label = $state === 'cancelled' ? 'Cancelled' : ($state === 'no_show' ? 'No show' : interview_state_label($state));
    return '<span class="istate istate-' . e($state) . '"><span class="istate-dot"></span>' . e($label) . '</span>';
}

/** Only from review_pending onward may a score be recorded. */
function interview_accepts_review(array $row): bool {
    return in_array(interview_state($row), ['review_pending', 'reviewed'], true);
}

/** The meeting is live or ready to be live — notes yes, scoring no. */
function interview_in_meeting(array $row): bool {
    return in_array(interview_state($row), ['ready', 'in_progress'], true);
}

/** Recommendations offered on the final review form. */
function interview_recommendations(): array {
    return [
        'proceed'      => 'Proceed to next stage',
        'offer'        => 'Proceed to offer',
        'hold'         => 'Hold / keep warm',
        'more_info'    => 'Needs another conversation',
        'reject'       => 'Do not proceed',
    ];
}

function interview_recommendation_label(?string $key): string {
    if ($key === null || $key === '') return '';
    return interview_recommendations()[$key] ?? $key;
}

/**
 * Move an interview to a new lifecycle state, keeping the legacy status column
 * in step so interviews.php, dashboard.php and pipeline.php keep working.
 */
function set_interview_state(int $interviewId, string $state, array $extra = []): void {
    if (!array_key_exists($state, interview_states())) return;

    $sets   = ['meeting_state=?'];
    $params = [$state];

    if ($state === 'in_progress') {
        $sets[] = 'started_at=COALESCE(started_at, NOW())';
    }
    if ($state === 'ended' || $state === 'review_pending') {
        $sets[] = 'ended_at=COALESCE(ended_at, NOW())';
        // A finished meeting is "completed" in the legacy column, unless it was cancelled.
        $sets[] = 'status=IF(status IN ("cancelled","no_show"), status, "completed")';
    }
    if ($state === 'reviewed') {
        $sets[] = 'reviewed_at=NOW()';
        $sets[] = 'status=IF(status IN ("cancelled","no_show"), status, "completed")';
    }
    foreach ($extra as $col => $val) {
        if (!preg_match('/^[a-z_]+$/', $col)) continue;
        $sets[] = $col . '=?';
        $params[] = $val;
    }

    $params[] = $interviewId;
    db()->prepare('UPDATE interviews SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
}

/**
 * Interview history for one candidate, across every application they have.
 * Used by the candidate profile — it reads the existing candidate/application
 * relationship rather than storing anything new against the candidate.
 */
function candidate_interview_history(int $candidateId): array {
    try {
        $s = db()->prepare("SELECT i.*, j.title job_title, u.name interviewer_name, rv.name reviewer_name
                            FROM interviews i
                            JOIN applications a ON a.id = i.application_id
                            JOIN jobs j ON j.id = a.job_id
                            LEFT JOIN users u ON u.id = i.interviewer_id
                            LEFT JOIN users rv ON rv.id = i.reviewer_id
                            WHERE a.candidate_id = ?
                            ORDER BY i.starts_at DESC");
        $s->execute([$candidateId]);
        return $s->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Candidate-side access
//
// A room code alone is not proof of identity — codes get forwarded. Each
// interview carries its own unguessable candidate token, so a candidate can
// only ever open the one interview that belongs to them.
// ---------------------------------------------------------------------------

/** How many minutes before the start time a candidate may enter the room. */
function interview_join_window(): int {
    return max(0, (int)setting('interview_join_window_minutes', '15'));
}

function generate_candidate_token(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Look up an interview from a candidate token, verifying that the token really
 * belongs to that room. Returns null when anything does not line up.
 */
function interview_for_candidate_token(string $roomCode, string $token): ?array {
    if ($roomCode === '' || $token === '' || !preg_match('/^[a-f0-9]{32}$/i', $token)) return null;
    try {
        $s = db()->prepare("SELECT i.*, c.id candidate_id, c.first_name, c.last_name, c.email,
                                   j.title, u.name interviewer_name
                            FROM interviews i
                            JOIN applications a ON a.id = i.application_id
                            JOIN candidates c ON c.id = a.candidate_id
                            JOIN jobs j ON j.id = a.job_id
                            LEFT JOIN users u ON u.id = i.interviewer_id
                            WHERE i.room_code = ? LIMIT 1");
        $s->execute([$roomCode]);
        $row = $s->fetch() ?: null;
    } catch (Throwable $e) { return null; }

    if (!$row) return null;
    // Constant-time compare so the token cannot be probed a character at a time.
    if (!hash_equals((string)($row['candidate_token'] ?? ''), $token)) return null;
    return $row;
}

/** How long an unrefreshed presence stamp stays valid. */
function meeting_presence_window(): int {
    return max(10, (int)setting('meeting_presence_seconds', '25'));
}

/** Is the interviewer actually sitting in the room right now? */
function interviewer_is_present(array $row): bool {
    $stamp = $row['interviewer_last_seen'] ?? null;
    // Fall back to the legacy room ping so this keeps working on rows written
    // before the waiting room existed.
    if (!$stamp && ($row['room_status'] ?? 'idle') === 'live') $stamp = $row['room_last_ping'] ?? null;
    if (!$stamp) return false;
    return (time() - strtotime((string)$stamp)) <= meeting_presence_window();
}

/** Kept for callers that only care whether anyone is in the room. */
function interview_room_is_live(array $row): bool {
    return interviewer_is_present($row);
}

/** Has the meeting finished, one way or another? */
function interview_is_over(array $row): bool {
    if (($row['status'] ?? '') === 'cancelled') return true;
    return in_array(interview_state($row), ['ended', 'review_pending', 'reviewed'], true);
}

/**
 * Can the candidate join, and what should the button say?
 *
 * The rule is deliberately "at the scheduled time and any time afterwards
 * while the interview is still open":
 *
 *     now >= starts_at - joinWindow   AND   interview not ended
 *
 * Nothing here closes the door because the start time has passed, and the
 * interviewer does not have to arrive first — a candidate who is early or on
 * time gets into the waiting room either way.
 *
 * Returns [allowed, message, state] where state is one of:
 *   not_available | available | interviewer_ready | waiting | requested
 *   | admitted | ended | cancelled
 */
function candidate_join_state(array $row): array {
    if (($row['status'] ?? 'scheduled') === 'cancelled') {
        return [false, 'This interview was cancelled. Please contact the recruiter who scheduled it.', 'cancelled'];
    }
    if (interview_is_over($row)) {
        return [false, 'This interview has ended.', 'ended'];
    }

    $request = $row['candidate_request_state'] ?? 'none';
    if ($request === 'admitted') {
        return [true, 'You have been admitted. Join when you are ready.', 'admitted'];
    }

    $startsAt = strtotime((string)$row['starts_at']);
    $opensAt  = $startsAt - (interview_join_window() * 60);
    $timeOk   = time() >= $opensAt;
    $present  = interviewer_is_present($row);

    // Either condition opens the door. Neither one closes it once open.
    if (!$timeOk && !$present) {
        return [
            false,
            'Your interview starts at ' . date('g:i A', $startsAt) . '.',
            'not_available',
        ];
    }

    if ($request === 'requested') {
        return [true, 'Your interviewer has received your request.', 'requested'];
    }
    if ($request === 'waiting') {
        return [true, 'Waiting for your interviewer to arrive.', 'waiting'];
    }
    if ($present) {
        return [true, 'Your interviewer is already in the meeting.', 'interviewer_ready'];
    }
    return [true, 'The interview room is ready.', 'available'];
}

/** Kept for callers that only need the yes/no and the message. */
function candidate_can_join(array $row): array {
    [$allowed, $message] = candidate_join_state($row);
    return [$allowed, $message];
}

/** The scheduled interviews a candidate should see for one application. */
function upcoming_interviews_for_application(int $applicationId): array {
    try {
        $s = db()->prepare("SELECT i.*, j.title, u.name interviewer_name
                            FROM interviews i
                            JOIN applications a ON a.id = i.application_id
                            JOIN jobs j ON j.id = a.job_id
                            LEFT JOIN users u ON u.id = i.interviewer_id
                            WHERE i.application_id = ?
                              AND i.status <> 'cancelled'
                              AND i.meeting_state NOT IN ('reviewed')
                              AND i.starts_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                            ORDER BY i.starts_at ASC");
        $s->execute([$applicationId]);
        return $s->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

// ---------------------------------------------------------------------------
// Waiting room and admission
// ---------------------------------------------------------------------------

/** Record that one side is currently in the room. Called by the pollers. */
function touch_interview_presence(int $interviewId, string $side): void {
    $column = $side === 'interviewer' ? 'interviewer_last_seen' : 'candidate_last_seen';
    try {
        db()->prepare("UPDATE interviews SET $column = NOW() WHERE id = ?")->execute([$interviewId]);
    } catch (Throwable $e) { /* presence is best-effort */ }
}

/**
 * Put the candidate in the waiting room, or move them from waiting to
 * requesting once the interviewer shows up.
 *
 * This is idempotent by design: it writes the interview row rather than
 * inserting anything, so clicking Join twice, refreshing, or reconnecting all
 * land on the same single state. An admitted candidate is never pushed back.
 */
function candidate_enter_waiting(array $row): string {
    $current = $row['candidate_request_state'] ?? 'none';
    if ($current === 'admitted') return 'admitted';

    $target = interviewer_is_present($row) ? 'requested' : 'waiting';
    if ($current === $target) return $current;

    try {
        // Only set candidate_requested_at the first time they ask, so the
        // interviewer sees how long someone has actually been waiting.
        db()->prepare("UPDATE interviews
                       SET candidate_request_state = ?,
                           candidate_requested_at = COALESCE(candidate_requested_at, NOW())
                       WHERE id = ? AND candidate_request_state <> 'admitted'")
            ->execute([$target, (int)$row['id']]);
    } catch (Throwable $e) { return $current; }
    return $target;
}

/** The interviewer lets the candidate in. */
function admit_candidate(int $interviewId, int $adminId): void {
    db()->prepare("UPDATE interviews
                   SET candidate_request_state = 'admitted',
                       candidate_admitted_at = NOW(),
                       candidate_admitted_by = ?
                   WHERE id = ?")
        ->execute([$adminId, $interviewId]);
}

/** Candidates waiting on this interview, for the interviewer's prompt. */
function pending_candidate_request(array $row): ?array {
    $state = $row['candidate_request_state'] ?? 'none';
    if (!in_array($state, ['waiting', 'requested'], true)) return null;
    return [
        'state'      => $state,
        'since'      => $row['candidate_requested_at'] ?? null,
        'name'       => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        'email'      => $row['email'] ?? '',
        'photo'      => $row['profile_image'] ?? null,
    ];
}
