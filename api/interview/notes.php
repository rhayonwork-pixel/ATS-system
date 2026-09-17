<?php
/** Autosave endpoint for the interviewer's live notes textarea (interviews.notes). */
require_once __DIR__ . '/_bootstrap.php';

if ($myRole !== 'host') interview_api_respond(['ok'=>false,'error'=>'Only the interviewer can save notes.'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') interview_api_respond(['ok'=>false,'error'=>'POST required.'], 405);

$notes = trim($_POST['notes'] ?? '');
db()->prepare('UPDATE interviews SET notes=? WHERE id=?')->execute([$notes, $interviewId]);
interview_api_respond(['ok'=>true]);
