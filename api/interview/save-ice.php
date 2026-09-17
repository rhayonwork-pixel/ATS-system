<?php
/** Either side posts one ICE candidate as its browser discovers it. */
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') interview_api_respond(['ok'=>false,'error'=>'POST required.'], 405);

$candidate = trim($_POST['candidate'] ?? '');
if ($candidate === '') interview_api_respond(['ok'=>false,'error'=>'Missing ICE candidate.'], 400);
// Stored as opaque JSON — validated as JSON, never executed or interpreted.
json_decode($candidate);
if (json_last_error() !== JSON_ERROR_NONE) interview_api_respond(['ok'=>false,'error'=>'Malformed ICE candidate payload.'], 400);

InterviewSignalService::saveIce($interviewId, $myRole, $candidate);
interview_api_respond(['ok'=>true]);
