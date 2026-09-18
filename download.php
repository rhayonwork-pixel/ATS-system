<?php
/**
 * Authenticated document delivery.
 *
 * Files live under /storage, which the web server refuses to serve. This is the
 * only route to them, and it checks the session before reading a byte.
 * ?disposition=inline is used by the resume viewer on the candidate profile;
 * anything else downloads.
 *
 * Built for progressive viewing:
 *   - Byte ranges (Accept-Ranges / 206). PDF.js asks for the chunks page 1
 *     needs first instead of waiting for the whole file.
 *   - ETag / Last-Modified with 304. A stored file never changes, so a repeat
 *     view costs a tiny revalidation instead of the whole document again.
 *   - The session is released before streaming, so a large file never holds
 *     the session lock that the rest of the page's requests are waiting on.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';

// Only hiring staff may read candidate documents. Candidates and applicants
// have no route here at all.
require_login(['admin', 'recruiter', 'hiring_manager']);

$id = (int)($_GET['file_id'] ?? 0);
if ($id < 1) { http_response_code(400); exit('Missing file id.'); }

$found = document_resolve($id);
if (!$found) { http_response_code(404); exit('That document does not exist or is no longer on the server.'); }
['doc' => $doc, 'path' => $path] = $found;

$mime = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
][$doc['extension']] ?? 'application/octet-stream';

// Only a PDF is ever shown inline; a Word file always downloads.
$inline = (($_GET['disposition'] ?? '') === 'inline') && $doc['extension'] === 'pdf';
$size   = (int)filesize($path);
$mtime  = (int)filemtime($path);
$etag   = document_etag($doc, $path);

// Everything this request needs from the session has been read.
session_write_close();

// private: never stored by a shared cache (proxy, Cloudflare) -- this is a
// candidate's personal data. no-cache: the browser may keep a copy but must
// revalidate before every use, so each view still passes the login check
// above; a logged-out user on a shared machine cannot reopen it from cache.
header('Cache-Control: private, no-cache');
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');   // opened directly, a PDF cannot run scripts
header('Vary: Cookie');

if (document_not_modified($etag, $mtime)) exit;

// One audit entry per view or download. A ranged load makes many requests for
// one view; only the one that starts at byte 0 (or has no range) is logged.
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
$ifRange = trim((string)($_SERVER['HTTP_IF_RANGE'] ?? ''));
if ($ifRange !== '' && $ifRange !== $etag) $range = '';   // stale partial copy: send it all

$start = 0; $end = $size - 1; $partial = false;
if ($range !== '') {
    // One range only (bytes=a-b, bytes=a-, bytes=-n) -- all a PDF viewer asks
    // for. Anything else (multi-range, other units, garbage) is ignored and
    // the whole file is sent, as RFC 9110 allows; only an out-of-bounds range is a 416.
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) || ($m[1] === '' && $m[2] === '')) {
        $m = null;
    }
}
if ($range !== '' && $m) {
    if ($m[1] === '') {                    // suffix: the last n bytes
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416); header('Content-Range: bytes */' . $size); exit;
    }
    $partial = true;
}

if ($start === 0) {
    audit('document_downloaded', 'candidate', (int)$doc['candidate_id'], [
        'file' => $doc['original_name'],
        'mode' => $inline ? 'viewed' : 'downloaded',
    ]);
}

// A quoted ASCII fallback plus RFC 5987 UTF-8, so accented filenames survive.
$safe = preg_replace('/[^\w.\- ]+/u', '_', $doc['original_name']) ?: ('resume.' . $doc['extension']);
$length = $end - $start + 1;

if ($partial) {
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . $length);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
     . '; filename="' . $safe . '"'
     . "; filename*=UTF-8''" . rawurlencode($doc['original_name']));

// Stream in chunks straight to the client, never through an output buffer.
while (ob_get_level() > 0) ob_end_clean();
$fh = fopen($path, 'rb');
if ($fh === false) exit;
fseek($fh, $start);
$left = $length;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $chunk = fread($fh, (int)min(65536, $left));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    flush();
    $left -= strlen($chunk);
}
fclose($fh);
