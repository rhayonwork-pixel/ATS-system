<?php
/** Candidate polls for the host's SDP offer once admitted to the room. */
require_once __DIR__ . '/_bootstrap.php';

$sdp = InterviewSignalService::getOffer($interviewId);
interview_api_respond(['ok'=>true, 'sdp'=>$sdp]);
