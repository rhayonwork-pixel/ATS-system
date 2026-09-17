<?php
/**
 * routes/api.php — the api/ tree, which IS how the new endpoints are
 * actually reached (these are new in this reorganization, so — unlike
 * web.php above — this file's structure matches real Apache-served paths
 * with no risk of breaking an existing route).
 */

return [
    'POST /api/interview/schedule.php'    => 'Create an interview (staff, session auth)',
    'POST /api/interview/save-offer.php'  => 'Host posts its SDP offer',
    'GET  /api/interview/get-offer.php'   => 'Candidate polls for the SDP offer',
    'POST /api/interview/save-answer.php' => 'Candidate posts its SDP answer',
    'GET  /api/interview/get-answer.php'  => 'Host polls for the SDP answer',
    'POST /api/interview/save-ice.php'    => 'Either side posts one ICE candidate',
    'GET  /api/interview/get-ice.php'     => 'Poll for the other side\'s new ICE candidates',
    'GET  /api/interview/status.php'      => 'Poll connection state (offer/answer/connected/ended)',
    'POST /api/interview/end.php'         => 'Mark the call ended',
    'POST /api/interview/notes.php'       => 'Autosave interviewer notes',
    'POST /api/interview/scorecard.php'   => 'Save score + feedback',
];
