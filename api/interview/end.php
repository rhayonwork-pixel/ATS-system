<?php
/** Either side can flag the call as ended (Leave / End Interview button)
 * so the other poller stops trying to (re)connect. */
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') interview_api_respond(['ok'=>false,'error'=>'POST required.'], 405);

InterviewSignalService::markEnded($interviewId);
interview_api_respond(['ok'=>true]);
