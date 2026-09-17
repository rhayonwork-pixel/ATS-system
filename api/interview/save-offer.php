<?php
/** HR (host) posts its SDP offer once it starts the call. */
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') interview_api_respond(['ok'=>false,'error'=>'POST required.'], 405);
if ($myRole !== 'host') interview_api_respond(['ok'=>false,'error'=>'Only the interviewer can send the initial offer.'], 403);

$sdp = trim($_POST['sdp'] ?? '');
if ($sdp === '') interview_api_respond(['ok'=>false,'error'=>'Missing SDP offer.'], 400);

InterviewSignalService::saveOffer($interviewId, $sdp);
interview_api_respond(['ok'=>true]);
