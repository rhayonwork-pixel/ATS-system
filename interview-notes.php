<?php
/**
 * Autosave endpoint for notes taken while an interview is running.
 *
 * Staff only — the candidate shares the room link but must never be able to
 * read or write the hiring team's notes. Identity comes from the session, not
 * from anything the caller sends.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/interview_lib.php';

header('Content-Type: application/json');

$user = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id=? AND active=1 LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
}
if (!$user || !in_array($user['role'] ?? '', ['admin', 'recruiter', 'hiring_manager', 'super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorised.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

// The autosave uses fetch with a JSON body, so read the raw input.
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;

if (!hash_equals($_SESSION['csrf'] ?? '', (string)($body['csrf'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Invalid request token.']);
    exit;
}

$code  = trim((string)($body['code'] ?? ''));
$notes = (string)($body['live_notes'] ?? '');
if ($code === '') {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$stmt = db()->prepare('SELECT id, meeting_state, status FROM interviews WHERE room_code=? LIMIT 1');
$stmt->execute([$code]);
$interview = $stmt->fetch();
if (!$interview) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

// Once the review has been submitted the notes are part of the record and are
// no longer edited from the room.
if (($interview['meeting_state'] ?? '') === 'reviewed') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'This interview has already been reviewed.']);
    exit;
}

db()->prepare('UPDATE interviews SET live_notes=?, notes_updated_at=NOW(), notes_author_id=? WHERE id=?')
   ->execute([$notes !== '' ? $notes : null, (int)$user['id'], (int)$interview['id']]);

echo json_encode(['ok' => true, 'saved_at' => date('g:i A')]);
