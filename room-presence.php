<?php
/**
 * Tracks whether an interview room is actually live, in the database —
 * not in the browser's localStorage. A room only counts as "live" while
 * its tab is actively pinging this endpoint; if the tab dies without
 * saying goodbye, the row goes stale on its own and the HR app stops
 * reporting a meeting in progress. There is no client-side flag that can
 * get stuck pointing at an interview that was never created or already ended.
 */
require_once __DIR__.'/includes/config.php';
$pdo = db();

// A live room is only "fresh" if it pinged within this window. Anything
// older is treated as dead, whether or not the room ever sent a "leave".
const ROOM_LIVE_WINDOW_SECONDS = 12;

header('Content-Type: application/json');

// Garbage-collect any room whose last ping is too old — keeps the table
// honest even if a browser tab was killed instead of closed cleanly.
$pdo->exec('UPDATE interviews SET room_status="idle" WHERE room_status="live" AND (room_last_ping IS NULL OR room_last_ping < NOW() - INTERVAL '.((int)ROOM_LIVE_WINDOW_SECONDS).' SECOND)');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($_GET['action'] ?? '') === 'check') {
    // Used by the HR app's "meeting is ongoing" banner. Staff only —
    // candidates don't see this overlay, so they don't need this endpoint.
    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id=? AND active=1 LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    if (!$user) { echo json_encode(['active' => null]); exit; }

    $stmt = $pdo->prepare("SELECT i.room_code, j.title FROM interviews i
        JOIN applications a ON a.id=i.application_id JOIN jobs j ON j.id=a.job_id
        WHERE i.room_status='live' AND i.room_last_ping IS NOT NULL
          AND i.room_last_ping >= NOW() - INTERVAL ".((int)ROOM_LIVE_WINDOW_SECONDS)." SECOND
        ORDER BY i.room_last_ping DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    echo json_encode(['active' => $row ? ['code' => $row['room_code'], 'title' => $row['title']] : null]);
    exit;
}

if ($method === 'POST') {
    // sendBeacon posts as text/plain, so read the body manually rather than relying on $_POST.
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: $_POST;
    $code = trim($body['code'] ?? '');
    $action = $body['action'] ?? '';
    if (!$code || !in_array($action, ['join', 'ping', 'leave'], true)) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

    // The room code itself is the access control here, same as interview-room.php —
    // anyone with the link (staff or candidate) can update presence for that room.
    $stmt = $pdo->prepare('SELECT id, meeting_state FROM interviews WHERE room_code=? LIMIT 1');
    $stmt->execute([$code]);
    $interview = $stmt->fetch();
    if (!$interview) { http_response_code(404); echo json_encode(['ok' => false]); exit; }

    if ($action === 'leave') {
        $upd = $pdo->prepare("UPDATE interviews SET room_status='idle' WHERE id=?");
        $upd->execute([$interview['id']]);
    } else {
        $upd = $pdo->prepare("UPDATE interviews SET room_status='live', room_last_ping=NOW() WHERE id=?");
        $upd->execute([$interview['id']]);
        // Someone is actually in the room, so the meeting has begun. This only
        // moves forward from the pre-meeting states — it never rewinds an
        // interview that has already ended or been reviewed.
        $pdo->prepare("UPDATE interviews SET meeting_state='in_progress', started_at=COALESCE(started_at, NOW())
                       WHERE id=? AND meeting_state IN ('scheduled','ready')")
            ->execute([$interview['id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false]);
