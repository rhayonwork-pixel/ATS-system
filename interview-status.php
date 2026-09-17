<?php
/**
 * Candidate-safe availability poll.
 *
 * Returns only whether the room can be entered. No notes, no scores, no
 * recruiter data — a candidate polling this learns nothing about the internal
 * record. Access still requires the correct per-interview token.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/interview_lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$code  = trim($_GET['code'] ?? '');
$token = trim($_GET['t'] ?? '');

$row = interview_for_candidate_token($code, $token);
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

[$allowed, $message, $state] = candidate_join_state($row);

echo json_encode([
    'ok'                  => true,
    'can_join'            => $allowed,
    'state'               => $state,
    'message'             => $message,
    'request_state'       => $row['candidate_request_state'] ?? 'none',
    'interviewer_present' => interviewer_is_present($row),
    'ended'               => interview_is_over($row),
    'starts_at'           => date('c', strtotime((string)$row['starts_at'])),
    'server_time'         => date('c'),
]);
