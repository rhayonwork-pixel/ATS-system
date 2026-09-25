<?php
/**
 * upload-parser.php — the endpoint behind the "Upload PDF description" panel
 * on job-post.php.
 *
 * Takes one PDF or Word (.docx) file, validates it, stores it in
 * storage/job_attachments, parses it,
 * and answers with JSON the form can fill itself from. It writes NOTHING to the
 * jobs table: the posting is only created when the recruiter reviews the fields
 * and presses a save button, and the stored file is only attached to a job at
 * that point (job-post.php does that, from the token below).
 *
 * Response, always JSON, always HTTP 200 unless the request itself is wrong:
 *
 *   { "ok": true,
 *     "token": "<stored name>",          // handed back on save to attach the file
 *     "engine": "python:pdfplumber",
 *     "fields": { "title": "...", ... },  // suggestions, never authoritative
 *     "fields_html": { ... },
 *     "confidence": { "title": 0.85, ... },
 *     "department_id": 3 | null,          // matched against real departments
 *     "warnings": [ ... ],
 *     "notice": "..." | null }            // ERR_MISSING_FIELDS is a notice, not a failure
 *
 *   { "ok": false, "error_code": "ERR_UNREADABLE", "message": "..." }
 *
 * Error codes are the contract with the front end:
 *   ERR_FILE_FORMAT     not a PDF, too large, or structurally broken
 *   ERR_UNREADABLE      a real PDF with no text layer (a scan)
 *   ERR_MISSING_FIELDS  text came through but few fields were recognised
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/JobDescriptionParser.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** One exit for every answer, so the shape never varies. */
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail_out(string $code, string $message, int $status = 200): void
{
    respond(['ok' => false, 'error_code' => $code, 'message' => $message], $status);
}

// Creating postings is what this is for, so it takes the posting permission --
// the same one job-post.php checks before it will accept a new job. The page
// gate is not relied on: this endpoint is reachable on its own.
require_login();
require_permission(PERM_JOB_POSTING, 'Creating job postings requires the "Job posting" permission.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_out('ERR_FILE_FORMAT', 'Send the PDF as a POST request.', 405);
}
check_csrf();

$file = $_FILES['job_pdf'] ?? [];
[$valid, $code, $message] = JobDescriptionParser::validate($file);
if (!$valid) {
    fail_out($code, $message);
}

$stored = JobDescriptionParser::store($file['tmp_name'], (string)($file['name'] ?? 'jd.pdf'));
$path = $stored ? JobDescriptionParser::resolve($stored) : null;
if ($path === null) {
    fail_out('ERR_FILE_FORMAT', 'We could not save that file — please try again.');
}

$result = JobDescriptionParser::parse($path);

if (!$result['ok']) {
    // An unreadable file is not worth keeping: nothing will ever attach to it.
    @unlink($path);
    fail_out($result['error_code'] ?: 'ERR_UNREADABLE',
        $result['message'] ?: 'The PDF could not be read or contains scanned images without text.');
}

// Match the extracted department name to a real one. An unmatched name is
// returned as text so the form can say what the PDF claimed and ask for a
// choice, rather than silently filing the job under the wrong department.
$departmentId = null;
$departmentText = trim((string)($result['fields']['department'] ?? ''));
if ($departmentText !== '') {
    foreach (db()->query('SELECT id, name FROM departments')->fetchAll() as $dept) {
        if (mb_strtolower($dept['name']) === mb_strtolower($departmentText)
            || mb_stripos($departmentText, $dept['name']) !== false) {
            $departmentId = (int)$dept['id'];
            break;
        }
    }
}

$notice = null;
if (($result['error_code'] ?? null) === 'ERR_MISSING_FIELDS') {
    $notice = "We extracted the text, but couldn't identify specific fields. Please fill the remaining fields manually.";
}

audit('job_pdf_import', 'job', null, [
    'file'   => $stored,
    'engine' => $result['engine'],
    'fields' => implode(',', array_keys($result['fields'])),
    'title'  => $result['fields']['title'] ?? '(not detected)',
]);

respond([
    'ok'              => true,
    'token'           => $stored,
    'engine'          => $result['engine'],
    'fields'          => $result['fields'],
    'fields_html'     => $result['fields_html'],
    'confidence'      => $result['confidence'],
    'department_id'   => $departmentId,
    'department_text' => $departmentText,
    'warnings'        => $result['warnings'],
    'notice'          => $notice,
    'error_code'      => $result['error_code'] ?? null,
]);
