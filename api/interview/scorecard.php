<?php
/** Saves the interviewer's score + feedback. Mirrors interviews.php's
 * "only from review_pending onward" rule using interview_accepts_review(). */
require_once __DIR__ . '/_bootstrap.php';

if ($myRole !== 'host') interview_api_respond(['ok'=>false,'error'=>'Only the interviewer can submit a scorecard.'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') interview_api_respond(['ok'=>false,'error'=>'POST required.'], 405);
if (!interview_accepts_review($interviewRow)) interview_api_respond(['ok'=>false,'error'=>'This interview cannot be scored yet.'], 409);

$score = isset($_POST['score']) && $_POST['score'] !== '' ? max(0, min(5, (int) $_POST['score'])) : null;
$feedback = trim($_POST['feedback'] ?? '');

db()->prepare('UPDATE interviews SET score=?, feedback=?, status=IF(status IN ("cancelled"),status,"completed") WHERE id=?')
    ->execute([$score, $feedback, $interviewId]);
audit('interview_feedback', 'interview', $interviewId);
interview_api_respond(['ok'=>true]);
