<?php
/** Poll for ICE candidates from the other peer, newer than ?since=<id>.
 * Stop polling once the client has received an empty batch and the peer
 * connection reports "connected" — see interview-room.php. */
require_once __DIR__ . '/_bootstrap.php';

$since = (int) ($_GET['since'] ?? 0);
$rows  = InterviewSignalService::getIce($interviewId, $myRole, $since);
$lastId = $rows ? (int) end($rows)['id'] : $since;

interview_api_respond([
    'ok' => true,
    'candidates' => array_map(fn($r) => json_decode($r['candidate'], true), $rows),
    'last_id' => $lastId,
]);
