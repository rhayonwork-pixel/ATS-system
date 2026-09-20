<?php
/**
 * Candidate-facing document delivery.
 *
 * download.php is staff-only: it includes auth.php, so it forces a login, and
 * an applicant has no account to log in with. Candidates still have to be able
 * to reopen the resume they attached, so this is the one route that serves a
 * stored file to someone without a session.
 *
 * Ownership is proven exactly the way it is proven everywhere else on the
 * public status page: the application id plus the email it was filed under.
 * The requested file must belong to the candidate who owns that application —
 * changing file_id in the URL cannot reach anyone else's resume.
 *
 * Deliberately simpler than download.php: no byte ranges and no audit entry.
 * A candidate re-reading their own attachment is not a recruiter viewing a
 * candidate's file, and there is no progressive PDF viewer on this page.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/documents.php';

$fileId = (int)($_GET['file_id'] ?? 0);
$appId  = (int)($_GET['id'] ?? 0);
$email  = strtolower(trim($_GET['email'] ?? ''));

if ($fileId < 1 || $appId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); exit('Missing or invalid request.');
}

// The application must exist under this email, and the file must belong to the
// same candidate. One query, both facts.
$stmt = db()->prepare(
    'SELECT d.id FROM candidate_documents d
     JOIN applications a ON a.candidate_id = d.candidate_id
     JOIN candidates  c ON c.id = a.candidate_id
     WHERE d.id = ? AND a.id = ? AND c.email = ? LIMIT 1'
);
try {
    $stmt->execute([$fileId, $appId, $email]);
    $owned = (bool)$stmt->fetchColumn();
} catch (Throwable $e) {
    $owned = false;                       // migration 004 not imported
}
// The same 404 for "no such file" and "not yours", so this never confirms that
// someone else's document id exists.
if (!$owned) { http_response_code(404); exit('That document is not available.'); }

$found = document_resolve($fileId);
if (!$found) { http_response_code(404); exit('That document is no longer on the server.'); }
['doc' => $doc, 'path' => $path] = $found;

$mime = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
][$doc['extension']] ?? 'application/octet-stream';

// Only a PDF is ever shown inline; a Word file always downloads.
$inline = (($_GET['disposition'] ?? '') === 'inline') && $doc['extension'] === 'pdf';
$mtime  = (int)filemtime($path);
$etag   = document_etag($doc, $path, 'candidate');

session_write_close();

header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');   // a PDF opened directly cannot run scripts
header('Vary: Cookie');

if (document_not_modified($etag, $mtime)) exit;

$safe = preg_replace('/[^\w.\- ]+/u', '_', $doc['original_name']) ?: ('resume.' . $doc['extension']);
header('Content-Type: ' . $mime);
header('Content-Length: ' . (int)filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
     . '; filename="' . $safe . '"'
     . "; filename*=UTF-8''" . rawurlencode($doc['original_name']));

while (ob_get_level() > 0) ob_end_clean();
readfile($path);
