<?php
/**
 * Waiting room and admission endpoint.
 *
 * Both sides poll this. Identity is never taken from the request body:
 *   - the candidate proves themselves with the per-interview token
 *   - the interviewer proves themselves with their session
 * so a candidate cannot admit themselves, and cannot reach another candidate's
 * interview even with a valid token for their own.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/interview_lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$action = $_REQUEST['action'] ?? 'status';
$code   = trim($_REQUEST['code'] ?? '');
$token  = trim($_REQUEST['t'] ?? '');

if ($code === '') respond(['ok' => false, 'error' => 'Missing room.'], 400);

// --- Who is asking? -------------------------------------------------------
$staff = null;
if (!empty($_SESSION['user_id'])) {
    $s = db()->prepare('SELECT * FROM users WHERE id=? AND active=1 LIMIT 1');
    $s->execute([(int)$_SESSION['user_id']]);
    $u = $s->fetch() ?: null;
    if ($u && in_array($u['role'] ?? '', ['admin', 'recruiter', 'hiring_manager', 'super_admin'], true)) $staff = $u;
}

$row = null;
if ($staff) {
    $s = db()->prepare("SELECT i.*, c.id candidate_id, c.first_name, c.last_name, c.email, c.profile_image,
                               j.title, u.name interviewer_name
                        FROM interviews i
                        JOIN applications a ON a.id = i.application_id
                        JOIN candidates c ON c.id = a.candidate_id
                        JOIN jobs j ON j.id = a.job_id
                        LEFT JOIN users u ON u.id = i.interviewer_id
                        WHERE i.room_code = ? LIMIT 1");
    $s->execute([$code]);
    $row = $s->fetch() ?: null;
} elseif ($token !== '') {
    $row = interview_for_candidate_token($code, $token);
}

if (!$row) respond(['ok' => false, 'error' => 'Not authorised for this interview.'], 403);

$isStaff     = $staff !== null;
$interviewId = (int)$row['id'];

// Writing actions require a matching CSRF token, so a third-party page cannot
// drive them on the user's behalf.
$writing = in_array($action, ['admit', 'keep_waiting', 'request', 'cancel', 'heartbeat', 'leave'], true);
if ($writing && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST required.'], 405);
}
if ($writing && $isStaff && !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    respond(['ok' => false, 'error' => 'Invalid request token.'], 419);
}

// --- Actions --------------------------------------------------------------
switch ($action) {

    case 'heartbeat':
        // Marks whichever side is calling as present. The candidate's heartbeat
        // never marks the interviewer present and vice versa.
        touch_interview_presence($interviewId, $isStaff ? 'interviewer' : 'candidate');
        if ($isStaff) {
            // An interviewer in the room means the meeting has begun, but this
            // only ever moves the lifecycle forward.
            db()->prepare("UPDATE interviews SET room_status='live', room_last_ping=NOW(),
                               meeting_state='in_progress', started_at=COALESCE(started_at, NOW())
                           WHERE id=? AND meeting_state IN ('scheduled','ready')")
                ->execute([$interviewId]);
            db()->prepare("UPDATE interviews SET room_status='live', room_last_ping=NOW() WHERE id=?")
                ->execute([$interviewId]);
        }
        break;

    case 'request':
        if ($isStaff) respond(['ok' => false, 'error' => 'Only the candidate can request entry.'], 403);
        if (interview_is_over($row)) respond(['ok' => false, 'error' => 'This interview has ended.'], 409);
        [$allowed, , ] = candidate_join_state($row);
        if (!$allowed) respond(['ok' => false, 'error' => 'The interview is not open yet.'], 409);
        touch_interview_presence($interviewId, 'candidate');
        candidate_enter_waiting($row);
        break;

    case 'leave':
        // Clears only the caller's own presence. A candidate leaving must never
        // mark the room idle, or the interviewer would appear to have gone too.
        if ($isStaff) {
            db()->prepare("UPDATE interviews SET interviewer_last_seen=NULL, room_status='idle' WHERE id=?")
                ->execute([$interviewId]);
        } else {
            db()->prepare("UPDATE interviews SET candidate_last_seen=NULL WHERE id=?")
                ->execute([$interviewId]);
        }
        // Note: meeting_state is deliberately untouched. Leaving is not ending.
        break;

    case 'cancel':
        if ($isStaff) respond(['ok' => false, 'error' => 'Only the candidate can cancel their request.'], 403);
        db()->prepare("UPDATE interviews
                       SET candidate_request_state='none', candidate_requested_at=NULL
                       WHERE id=? AND candidate_request_state <> 'admitted'")
            ->execute([$interviewId]);
        break;

    case 'admit':
        if (!$isStaff) respond(['ok' => false, 'error' => 'Only the interviewer can admit a candidate.'], 403);
        admit_candidate($interviewId, (int)$staff['id']);
        audit('interview_candidate_admitted', 'interview', $interviewId, [
            'candidate' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        ]);
        break;

    case 'keep_waiting':
        if (!$isStaff) respond(['ok' => false, 'error' => 'Only the interviewer can do that.'], 403);
        // Deliberately not a rejection: the request stays open so the
        // interviewer can admit them a moment later.
        touch_interview_presence($interviewId, 'interviewer');
        break;

    case 'status':
    default:
        break;
}

// --- Re-read and report ---------------------------------------------------
$s = db()->prepare("SELECT i.*, c.first_name, c.last_name, c.email, c.profile_image, j.title, u.name interviewer_name
                    FROM interviews i
                    JOIN applications a ON a.id = i.application_id
                    JOIN candidates c ON c.id = a.candidate_id
                    JOIN jobs j ON j.id = a.job_id
                    LEFT JOIN users u ON u.id = i.interviewer_id
                    WHERE i.id = ? LIMIT 1");
$s->execute([$interviewId]);
$row = $s->fetch();

[$canJoin, $message, $state] = candidate_join_state($row);

$payload = [
    'ok'                   => true,
    'state'                => $state,
    'message'              => $message,
    'can_join'             => $canJoin,
    'request_state'        => $row['candidate_request_state'],
    'interviewer_present'  => interviewer_is_present($row),
    'ended'                => interview_is_over($row),
    'starts_at'            => date('c', strtotime((string)$row['starts_at'])),
    'server_time'          => date('c'),
];

// Candidate details go to the interviewer only. The candidate's own payload
// carries nothing internal — no notes, no score, no recruiter data.
if ($isStaff) {
    $pending = pending_candidate_request($row);
    $payload['pending_request'] = $pending;
    $payload['candidate_present'] = $row['candidate_last_seen']
        && (time() - strtotime((string)$row['candidate_last_seen'])) <= meeting_presence_window();
}

respond($payload);
