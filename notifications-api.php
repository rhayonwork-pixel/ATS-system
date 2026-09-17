<?php
/**
 * Notification feed and read-state endpoint for the header bell.
 *
 * Transport note: the brief recommended Server-Sent Events. This runs on
 * XAMPP/Apache with mod_php, where every open SSE connection holds a worker
 * process for its whole lifetime — a handful of signed-in recruiters would
 * exhaust MaxRequestWorkers and the whole ATS would stop responding. Short
 * polling is used instead (20s visible / 60s hidden, throttled client-side),
 * which is the documented fallback in the brief and the correct choice for this
 * stack. Swapping to SSE later only means replacing this file's read path.
 *
 * Every response is scoped to the signed-in user; nothing accepts a user id.
 */
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}
$uid = (int)$me['id'];
$pdo = db();

$action = $_REQUEST['action'] ?? 'feed';

// ---------------------------------------------------------------------------
// Writes require POST and a CSRF token, so a third-party page cannot mark a
// recruiter's notifications read on their behalf.
// ---------------------------------------------------------------------------
if (in_array($action, ['mark_read', 'mark_all'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required.']);
        exit;
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Invalid request token.']);
        exit;
    }
    try {
        if ($action === 'mark_all') {
            $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([$uid]);
        } else {
            // Scoped by user_id as well as id, so an id from elsewhere does nothing.
            $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?')
                ->execute([(int)($_POST['id'] ?? 0), $uid]);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not update notifications.']);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Unread count only — the cheap call the poller makes most often.
// ---------------------------------------------------------------------------
if ($action === 'count') {
    echo json_encode(['ok' => true, 'unread' => unread_notification_count($uid)]);
    exit;
}

// ---------------------------------------------------------------------------
// Feed, optionally filtered by category.
// ---------------------------------------------------------------------------
$categories = ['application', 'interview', 'message', 'system'];
$filter = $_REQUEST['category'] ?? '';
$where  = 'n.user_id = ?';
$params = [$uid];
if (in_array($filter, $categories, true)) {
    $where .= ' AND n.category = ?';
    $params[] = $filter;
}

try {
    $stmt = $pdo->prepare("SELECT n.*, a.name actor_name
                           FROM notifications n
                           LEFT JOIN users a ON a.id = n.actor_id
                           WHERE $where
                           ORDER BY n.read_at IS NOT NULL, n.created_at DESC
                           LIMIT 20");
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load notifications.']);
    exit;
}

$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id'        => (int)$r['id'],
        'category'  => $r['category'] ?? 'system',
        'priority'  => $r['priority'] ?? 'medium',
        'title'     => $r['title'],
        // `body` is the brief's `message`; `link` is its `action_url`.
        'message'   => $r['body'],
        'action_url'=> $r['link'],
        'action_label' => $r['action_label'] ?: 'Open',
        'actor'     => $r['actor_name'],
        // read_at is kept as the source of truth; is_read is derived for the UI.
        'is_read'   => $r['read_at'] !== null,
        'ago'       => time_ago($r['created_at']),
        'created_at'=> date('c', strtotime($r['created_at'])),
    ];
}

echo json_encode([
    'ok'     => true,
    'unread' => unread_notification_count($uid),
    'items'  => $items,
]);
