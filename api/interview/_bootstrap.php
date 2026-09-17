<?php
/**
 * Shared bootstrap for api/interview/*.php signaling endpoints.
 * Not used by the older interview-access.php / room-presence.php (those keep
 * working exactly as before) — this is plumbing for the new offer/answer/ICE
 * endpoints only.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../app/Services/InterviewSignalService.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function interview_api_respond(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$roomCode = trim($_REQUEST['code'] ?? '');
$token    = trim($_REQUEST['t'] ?? '');

$resolved = InterviewSignalService::resolve($roomCode, $token);
if (!$resolved) {
    interview_api_respond(['ok' => false, 'error' => 'Not authorised for this interview.'], 403);
}

$interviewRow = $resolved['row'];
$interviewId  = (int) $interviewRow['id'];
$myRole       = $resolved['role']; // 'host' | 'candidate'

// Anything that writes requires POST, and a staff caller must also present a
// valid CSRF token — mirrors interview-access.php's rule exactly.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($myRole === 'host' && !hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        interview_api_respond(['ok' => false, 'error' => 'Invalid request token.'], 419);
    }
}
