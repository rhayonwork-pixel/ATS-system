<?php
/**
 * Text preview of a Word resume, as JSON, for the viewer on the candidate
 * profile. Same access rule and same storage checks as download.php.
 *
 * Why not an embedded third-party viewer (Google Docs Viewer, Office Online):
 * those fetch the file themselves from a public URL. Resumes are deliberately
 * not reachable without a staff session, and handing a candidate's CV to a
 * third party is a data-protection decision this page should not make on its
 * own. The text is extracted here instead and never leaves the app.
 *
 * .doc (the pre-2007 binary format) has no preview: download only.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/includes/docx_extract.php';

require_login(['admin', 'recruiter', 'hiring_manager']);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');   // see download.php: revalidated on every use
header('Vary: Cookie');

$found = document_resolve((int)($_GET['file_id'] ?? 0));
if (!$found) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'That document does not exist.']); exit; }
['doc' => $doc, 'path' => $path] = $found;
session_write_close();

if ($doc['extension'] !== 'docx') {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'A text preview is only available for DOCX files.']);
    exit;
}

if (document_not_modified(document_etag($doc, $path, 'text-v1'), (int)filemtime($path))) exit;

$blocks = docx_extract_blocks($path);
if ($blocks === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'This file could not be read as a Word document.']);
    exit;
}

audit('document_downloaded', 'candidate', (int)$doc['candidate_id'], [
    'file' => $doc['original_name'],
    'mode' => 'previewed',
]);

echo json_encode(['ok' => true, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
