<?php
/**
 * job-attachment.php — serve the PDF a job posting was imported from.
 *
 * Files live in storage/job_attachments, which the web server refuses to
 * serve (storage/.htaccess). This is the only route to them, and it checks the
 * session and the job permissions first. Job descriptions are drafts of unannounced
 * roles: salary bands, headcount and reorganisations are all in them.
 *
 * Two ways in:
 *   ?job=<id>    the PDF attached to a saved posting
 *   ?file=<name> a PDF just uploaded but not yet attached to anything, which
 *                is what the review step on job-post.php links to
 *
 * Rows migrated from the old public folder carry a "legacy:" prefix (migration
 * 019) and are read from assets/uploads/job-descriptions instead.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/JobDescriptionParser.php';

require_login();
if (!has_permission(PERM_JOB_POSTING) && !has_permission(PERM_JOB_MANAGEMENT)) {
    require_permission(PERM_JOB_POSTING);
}

$path = null;
$downloadName = 'job-description.pdf';

$jobId = (int)($_GET['job'] ?? 0);
$file  = trim((string)($_GET['file'] ?? ''));

if ($jobId > 0) {
    $stmt = db()->prepare('SELECT title, original_pdf_path, source_pdf FROM jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    if ($job) {
        $downloadName = (preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$job['title']) ?: 'job') . '.pdf';
        $stored = (string)($job['original_pdf_path'] ?? '');
        if (strncmp($stored, 'legacy:', 7) === 0) {
            // The legacy column only ever held this one relative path shape;
            // anything else in it is ignored rather than turned into an href.
            $legacy = substr($stored, 7);
            if (preg_match('#^assets/uploads/job-descriptions/[A-Za-z0-9._-]+\.pdf$#', $legacy)) {
                $candidate = realpath(__DIR__ . '/' . $legacy);
                $root = realpath(__DIR__ . '/assets/uploads/job-descriptions');
                if ($candidate && $root && strncmp($candidate, $root, strlen($root)) === 0) $path = $candidate;
            }
        } elseif ($stored !== '') {
            $path = JobDescriptionParser::resolve($stored);
        }
    }
} elseif ($file !== '') {
    $path = JobDescriptionParser::resolve($file);
}

if ($path === null) {
    http_response_code(404);
    exit('That job description is not available.');
}

session_write_close();

header('Content-Type: application/pdf');
header('Content-Length: ' . (int)filesize($path));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Vary: Cookie');

while (ob_get_level() > 0) ob_end_clean();
readfile($path);
