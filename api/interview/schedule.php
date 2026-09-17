<?php
/**
 * JSON API mirror of interviews.php?action=create — same validation, same
 * table, same audit trail. The HR screen still posts to interviews.php
 * directly (unchanged); this exists for the documented api/interview/ tree
 * and for any future JS-driven scheduling UI to call without a full page
 * reload.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/interview_lib.php';
require_once __DIR__ . '/../../app/Services/EmailService.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user || !in_array($user['role'], ['admin','recruiter','hiring_manager','super_admin'], true)) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Not authorised.']); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Invalid request token.']); exit; }

$pdo = db();
$application   = (int) ($_POST['application_id'] ?? 0);
$start         = $_POST['starts_at'] ?? '';
$end           = $_POST['ends_at'] ?? null;
$type          = $_POST['interview_type'] ?? 'video';
$meetingType   = ($_POST['meeting_type'] ?? '') === 'screening' ? 'screening' : 'interview';
$interviewerId = (int) ($_POST['interviewer_id'] ?? $user['id']);
$useRoom       = ($_POST['meeting_mode'] ?? 'builtin') === 'builtin';
$roomCode      = $useRoom ? strtoupper(bin2hex(random_bytes(4))) : null;
$meetingUrl    = $useRoom ? '' : trim($_POST['meeting_url'] ?? '');
$provider      = $useRoom ? 'Acme Room' : trim($_POST['meeting_provider'] ?? 'Zoom');

if (!$application || !$start || !in_array($type, ['phone','video','onsite','panel'], true)) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Missing application, start time, or interview type.']); exit;
}

$conflict = $pdo->prepare("SELECT COUNT(*) FROM interviews WHERE interviewer_id=? AND status NOT IN ('cancelled') AND starts_at=?");
$conflict->execute([$interviewerId, $start]);
if ((int) $conflict->fetchColumn() > 0) {
    http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Interviewer already booked at that time.']); exit;
}

$candidateToken = generate_candidate_token();
$s = $pdo->prepare('INSERT INTO interviews(application_id,interviewer_id,meeting_type,starts_at,ends_at,interview_type,meeting_url,meeting_provider,room_code,candidate_token,location,notes,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
$s->execute([$application,$interviewerId,$meetingType,$start,$end,$type,$meetingUrl,$provider,$roomCode,$candidateToken,trim($_POST['location'] ?? ''),trim($_POST['notes'] ?? ''),'scheduled']);
$interviewId = (int) $pdo->lastInsertId();

$pdo->prepare('UPDATE applications SET assigned_to=? WHERE id=? AND assigned_to IS NULL')->execute([$interviewerId, $application]);
$newStage = $meetingType === 'screening' ? 'screening' : 'interview';
$pdo->prepare("UPDATE applications SET stage=? WHERE id=? AND stage NOT IN ('hired','rejected')")->execute([$newStage, $application]);
audit('interview_create', 'interview', $interviewId);

try {
    $ctx = $pdo->prepare('SELECT CONCAT(c.first_name,\' \',c.last_name) AS name, c.email, j.title FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id WHERE a.id=?');
    $ctx->execute([$application]);
    if ($row = $ctx->fetch()) {
        EmailService::sendInterviewEmail(
            ['room_code'=>$roomCode,'candidate_token'=>$candidateToken,'meeting_url'=>$meetingUrl,'starts_at'=>$start],
            ['name'=>$row['name'],'email'=>$row['email']],
            ['title'=>$row['title']]
        );
    }
} catch (Throwable $e) { @file_put_contents(__DIR__ . "/../../storage/logs/mail.log", "[" . date("Y-m-d H:i:s") . "] interview invite email failed: " . $e->getMessage() . PHP_EOL, FILE_APPEND); }

echo json_encode(['ok'=>true, 'interview_id'=>$interviewId, 'room_code'=>$roomCode]);
