<?php
/** Lightweight connection-state poll used by the room UI to show
 * "Connecting…" vs "Connected" vs "Call ended" without re-fetching SDP. */
require_once __DIR__ . '/_bootstrap.php';

$s = db()->prepare('SELECT offer_sdp IS NOT NULL AS has_offer, answer_sdp IS NOT NULL AS has_answer, connected_at, ended_at FROM interview_signals WHERE interview_id=?');
$s->execute([$interviewId]);
$row = $s->fetch() ?: ['has_offer'=>0,'has_answer'=>0,'connected_at'=>null,'ended_at'=>null];

interview_api_respond([
    'ok' => true,
    'has_offer' => (bool)$row['has_offer'],
    'has_answer' => (bool)$row['has_answer'],
    'connected' => (bool)$row['connected_at'],
    'ended' => (bool)$row['ended_at'],
]);
