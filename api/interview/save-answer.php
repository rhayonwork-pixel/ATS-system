<?php
/** Candidate posts its SDP answer after accepting the host's offer. */
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') interview_api_respond(['ok'=>false,'error'=>'POST required.'], 405);
if ($myRole !== 'candidate') interview_api_respond(['ok'=>false,'error'=>'Only the candidate answers the offer.'], 403);

$sdp = trim($_POST['sdp'] ?? '');
if ($sdp === '') interview_api_respond(['ok'=>false,'error'=>'Missing SDP answer.'], 400);

InterviewSignalService::saveAnswer($interviewId, $sdp);
interview_api_respond(['ok'=>true]);
