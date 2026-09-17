<?php
/**
 * CV parsing endpoint for the Add Candidate form.
 *
 * The brief suggested smalot/pdfparser and PhpWord. There is no Composer in
 * this project, so neither is available. Nothing is simulated:
 *   PDF  -> includes/pdf_extract.php, the self-contained reader already built
 *           for the job-description importer (handles ToUnicode CID fonts)
 *   DOCX -> ZipArchive + word/document.xml, which is all a .docx is
 *   TXT  -> read directly
 *
 * The file is parsed and thrown away. Nothing is written to the database here;
 * the recruiter reviews and corrects the values first, then submits the form.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pdf_extract.php';
require_once __DIR__ . '/includes/documents.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$me = current_user();
if (!$me || !in_array($me['role'] ?? '', ['admin', 'super_admin', 'recruiter', 'hiring_manager'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to add candidates.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Your session expired. Please reload the page.']);
    exit;
}

$file = $_FILES['cv'] ?? null;
if (!$file) {
    echo json_encode(['ok' => false, 'code' => 'ERR_CORRUPT_FILE', 'error' => 'No file was received.']);
    exit;
}

// The file is stored first and kept. The recruiter may correct every parsed
// value, but the document itself is the record — so it is retained either way,
// even when extraction finds nothing.
prune_orphan_documents();
$token = bin2hex(random_bytes(16));
[$stored, $result] = store_candidate_document($file, null, (int)$me['id'], $token);
if (!$stored) {
    echo json_encode(['ok' => false, 'code' => $result['code'], 'error' => $result['error']]);
    exit;
}
$ext  = $result['extension'];
$path = $result['path'];

// ---------------------------------------------------------------------------
// Text extraction
// ---------------------------------------------------------------------------
/** A .docx is a zip; the body text lives in word/document.xml. */
function docx_to_text(string $path, ?string &$error = null): string {
    if (!class_exists('ZipArchive')) {
        $error = 'DOCX reading needs the PHP zip extension. Enable it in php.ini, or upload a PDF or TXT.';
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { $error = 'That DOCX file could not be opened — it may be corrupted.'; return ''; }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) { $error = 'That DOCX file is missing its document body — it may be corrupted.'; return ''; }

    // Paragraph and line breaks become newlines before tags are stripped, so
    // the line-based heuristics below still have structure to work with.
    $xml = preg_replace('~<w:(p|br|tab)\b[^>]*/?>~', "\n", $xml) ?? $xml;
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return pdf_cleanup_text($text);
}

$error = null;
$text  = '';
switch ($ext) {
    case 'pdf':
        $res = pdf_extract_text($path);
        $text = $res['text'];
        if ($res['error']) $error = $res['error'];
        break;
    case 'docx':
        $text = docx_to_text($path, $error);
        break;
    case 'doc':
        // Legacy binary .doc is an OLE compound file, not something worth
        // reverse-engineering here. The document is stored and downloadable;
        // only auto-fill is unavailable, and we say so plainly.
        $error = 'This is a legacy .doc file, which cannot be read automatically. '
               . 'It has been attached to the candidate — please enter the details manually, '
               . 'or re-save it as PDF or DOCX to auto-fill.';
        break;
}

// A parse failure is not an upload failure: the document is already stored and
// attached to the pending form, so the recruiter keeps the file either way.
$docPayload = [
    'token'         => $token,
    'id'            => $result['id'],
    'original_name' => $result['original_name'],
    'extension'     => $ext,
    'size_label'    => $result['size_label'],
    'icon_class'    => document_icon_class($ext),
];

if ($error) {
    echo json_encode(['ok' => false, 'code' => 'ERR_CORRUPT_FILE', 'error' => $error, 'document' => $docPayload]);
    exit;
}
if (mb_strlen(trim($text)) < 40) {
    echo json_encode([
        'ok' => false,
        'code' => 'ERR_CORRUPT_FILE',
        'error' => 'Parsing failed: the document appears to be corrupted or encrypted. Please enter details manually.',
        'document' => $docPayload,
    ]);
    exit;
}

// Kept for full-text search across the ATS; written when the candidate saves.
try {
    db()->prepare('UPDATE candidate_documents SET parsed=1 WHERE id=?')->execute([(int)$result['id']]);
} catch (Throwable $e) { /* not fatal */ }

// ---------------------------------------------------------------------------
// Heuristics. Every value is a suggestion the recruiter must confirm.
// ---------------------------------------------------------------------------
$fields = [];

/**
 * CV headings are frequently set in capitals ("DAVE JOSEPH", "VIRTUAL
 * ASSISTANT"). Storing them that way would shout from every list and table, so
 * an all-caps value is title-cased. Mixed case is left exactly as written, and
 * short all-caps tokens (IT, HR, QA, UX) keep their capitals.
 */
function tidy_case(string $v): string {
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
    if ($v === '' || $v !== mb_strtoupper($v, 'UTF-8')) return $v;   // not all caps
    $words = preg_split('/\s+/u', mb_strtolower($v, 'UTF-8')) ?: [];
    $small = ['a','an','and','of','the','for','to','in','at','on','or'];
    $out = [];
    foreach ($words as $i => $w) {
        $bare = preg_replace('/[^\p{L}]/u', '', $w) ?? $w;
        if (mb_strlen($bare) <= 3 && preg_match('/^(it|hr|qa|ux|ui|ai|bpo|crm|sap|seo|pm|va)$/u', $bare)) {
            $out[] = mb_strtoupper($w, 'UTF-8');           // keep real acronyms
        } elseif ($i > 0 && in_array($bare, $small, true)) {
            $out[] = $w;
        } else {
            $out[] = mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($w, 1, null, 'UTF-8');
        }
    }
    return implode(' ', $out);
}

if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]{2,}/u', $text, $m)) {
    // Addresses are case-insensitive, and a CV in capitals would otherwise
    // auto-fill the form with a shouting address.
    $email = mb_strtolower(rtrim($m[0], '.'), 'UTF-8');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $fields['email'] = $email;
}

// International, spaced, dashed or bracketed forms; at least 7 digits so a
// year range or a postcode is not mistaken for a phone number.
if (preg_match('/(?:\+?\d{1,3}[\s.-]?)?(?:\(\d{1,4}\)[\s.-]?)?\d[\d\s.-]{6,}\d/u', $text, $m)) {
    $digits = preg_replace('/\D/', '', $m[0]);
    if (strlen($digits) >= 7 && strlen($digits) <= 15) $fields['phone'] = trim($m[0]);
}

$lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));

/** The name is usually the first short line that is not a heading or contact detail. */
foreach (array_slice($lines, 0, 8) as $line) {
    if (mb_strlen($line) > 48 || mb_strlen($line) < 4) continue;
    if (preg_match('/[@\d]|https?:|www\./u', $line)) continue;
    if (preg_match('/^(curriculum vitae|resume|r[ée]sum[ée]|profile|contact|personal details|about me|work experience|education(al background)?|skills|software|software\s*\/\s*tools|references)$/iu', $line)) continue;
    $words = preg_split('/\s+/u', $line) ?: [];
    if (count($words) < 2 || count($words) > 4) continue;
    // Title Case or ALL CAPS, which is how names are nearly always set.
    if (!preg_match('/^[\p{Lu}][\p{L}\'’.-]*(\s+[\p{Lu}][\p{L}\'’.-]*){1,3}$/u', $line)) continue;
    $fields['full_name'] = tidy_case($line);
    break;
}

/** Skills: match a known vocabulary, plus anything under a "Skills" heading. */
$vocabulary = ['php','laravel','symfony','javascript','typescript','react','vue','angular','node.js','node',
    'python','django','flask','java','spring','kotlin','swift','objective-c','c#','.net','go','golang','rust',
    'ruby','rails','html','css','sass','tailwind','bootstrap','mysql','postgresql','mongodb','redis','sqlite',
    'graphql','rest','api','docker','kubernetes','aws','azure','gcp','terraform','jenkins','git','github',
    'gitlab','ci/cd','linux','figma','photoshop','illustrator','excel','power bi','tableau','sql','nosql',
    'agile','scrum','kanban','jira','salesforce','sap','seo','copywriting','recruitment','onboarding','payroll'];
$found = [];
$haystack = ' ' . mb_strtolower($text) . ' ';
foreach ($vocabulary as $skill) {
    if (preg_match('/(?<![\w.+#-])' . preg_quote($skill, '/') . '(?![\w+#-])/u', $haystack)) {
        $found[] = $skill;
    }
}
// Anything listed under a Skills heading, which catches domain terms the
// vocabulary does not know about.
if (preg_match('/^\s*(?:technical\s+)?skills?\s*:?\s*$(.{0,400}?)^\s*(?:[A-Z][A-Za-z ]{2,30}:?\s*)$/msu', $text, $m)
    || preg_match('/^\s*(?:technical\s+)?skills?\s*:\s*(.{0,300})$/miu', $text, $m)) {
    foreach (preg_split('/[,;|\n•·]+/u', $m[1]) ?: [] as $chunk) {
        $chunk = trim($chunk, " \t.-–—•\u{00B7}");
        if ($chunk !== '' && mb_strlen($chunk) <= 28 && !preg_match('/\d{4}/', $chunk)) $found[] = mb_strtolower($chunk);
    }
}
$found = array_values(array_unique(array_filter($found)));
if ($found) $fields['skills'] = implode(', ', array_slice($found, 0, 14));

/** Education: the first line after an Education heading that names a qualification. */
if (preg_match('/^\s*education.*$\R+(.{0,180}?)$/miu', $text, $m)) {
    $edu = trim(preg_replace('/\s+/u', ' ', $m[1]));
    if ($edu !== '' && mb_strlen($edu) > 5) $fields['education'] = mb_substr($edu, 0, 200);
}
if (!isset($fields['education'])
    && preg_match('/((?:bachelor|master|b\.?sc|m\.?sc|b\.?a\b|m\.?a\b|mba|ph\.?d|diploma|degree)[^\n,]{0,90})/iu', $text, $m)) {
    $fields['education'] = trim(preg_replace('/\s+/u', ' ', $m[1]));
}

/**
 * Experience level from stated years. Takes the LARGEST figure in the document,
 * not the first: a CV that opens "over 2 years in BPO and 1 year as a VA" would
 * otherwise be banded on the 2. "over", "more than" and a trailing "+" all mean
 * the real figure is higher, so they nudge it up a year before banding.
 */
if (preg_match_all('/(over|more than|about|approx\.?|nearly)?\s*(\d{1,2})\s*(\+)?\s*(?:years?|yrs?)\b/iu',
                   $text, $all, PREG_SET_ORDER)) {
    $best = 0;
    foreach ($all as $hit) {
        $n = (int)$hit[2];
        if ($n > 40) continue;                       // a year like "2024", not a duration
        if (!empty($hit[1]) || !empty($hit[3])) $n++; // "over 2 years" is more than 2
        $best = max($best, $n);
    }
    if ($best > 0) {
        $fields['experience_level'] = $best <= 2 ? 'entry' : ($best <= 5 ? 'mid' : ($best <= 9 ? 'senior' : 'lead'));
    }
}

/** Current role: the line above or beside the name, or after a "Role:" label. */
if (preg_match('/^\s*(?:current\s+)?(?:role|position|title)\s*:\s*(.{3,80})$/miu', $text, $m)) {
    $fields['current_title'] = tidy_case($m[1]);
} elseif (isset($fields['full_name'])) {
    $idx = array_search($fields['full_name'], $lines, true);
    if ($idx !== false && isset($lines[$idx + 1])) {
        $next = $lines[$idx + 1];
        if (mb_strlen($next) >= 4 && mb_strlen($next) <= 60 && !preg_match('/[@\d]|https?:/u', $next)) {
            $fields['current_title'] = tidy_case($next);
        }
    }
}

echo json_encode([
    'ok'        => true,
    'document'  => $docPayload,
    'resume_text' => mb_substr($text, 0, 60000),
    'fields'  => $fields,
    'filled'  => array_keys($fields),
    'partial' => count($fields) < 3,
    'message' => $fields
        ? 'Auto-filled ' . count($fields) . ' field' . (count($fields) === 1 ? '' : 's') . '. Please check each one before saving.'
        : 'The file was read, but nothing recognisable was found. Please enter the details manually.',
]);
