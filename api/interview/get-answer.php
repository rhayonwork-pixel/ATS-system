<?php
/** Host polls for the candidate's SDP answer to complete the handshake. */
require_once __DIR__ . '/_bootstrap.php';

$sdp = InterviewSignalService::getAnswer($interviewId);
interview_api_respond(['ok'=>true, 'sdp'=>$sdp]);
