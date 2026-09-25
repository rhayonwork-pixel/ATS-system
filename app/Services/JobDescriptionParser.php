<?php
/**
 * JobDescriptionParser — validate an uploaded job description PDF, store it
 * safely, and turn it into job-posting fields.
 *
 * TWO ENGINES, ONE VOCABULARY
 * ---------------------------
 * Preferred: tools/extract_job_data.py, run as a short-lived child process.
 * Python sees what the text alone does not — font size, weight, indentation
 * and table cells — which is what separates a heading from a sentence and a
 * bullet from a paragraph. Most PDFs exported from Word, Chrome or InDesign
 * carry their bullet glyphs as vector art rather than characters, so
 * indentation is frequently the ONLY evidence that a line is a list item.
 *
 * Fallback: includes/pdf_extract.php + jd_parse_fields(), the reader this
 * project already ships. It runs anywhere PHP does. The shared-hosting target
 * (InfinityFree) has no Python and usually disables proc_open, so this path is
 * not hypothetical — it is what production will use until the app is hosted
 * somewhere that can run a subprocess.
 *
 * Both engines read their synonyms from config/job_parse_rules.json, so a new
 * section heading is taught once. Anything else would drift.
 *
 * WHAT THIS CLASS DOES NOT DO
 * ---------------------------
 * It never writes to the database and never decides anything is correct. Every
 * field it returns is a suggestion that a human confirms on the form.
 */

require_once __DIR__ . '/../../includes/pdf_extract.php';
require_once __DIR__ . '/../../includes/docx_job_text.php';
require_once __DIR__ . '/../../includes/text_sanitize.php';

class JobDescriptionParser
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Every field the parser may return, in the order the form shows them. */
    public const FIELDS = [
        'title', 'company', 'department', 'location', 'employment_type', 'salary',
        'description', 'responsibilities', 'qualifications', 'skills',
        'preferred_skills', 'experience', 'education', 'benefits', 'deadline',
    ];

    /** Where accepted files are kept. Outside the web root's reach; see storage/.htaccess. */
    public static function storageDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/job_attachments';
    }

    // ------------------------------------------------------------------
    // 1. Validation
    // ------------------------------------------------------------------
    /**
     * Check an entry from $_FILES. Returns [ok, errorCode, message].
     *
     * The extension is checked, but it is the weakest signal of the three: the
     * declared MIME type from the browser is not trusted at all, the type is
     * re-read from the bytes with finfo, and the file must actually open as a
     * PDF. A .exe renamed to .pdf fails the second and third checks.
     */
    public static function validate(array $file): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return [false, 'ERR_FILE_FORMAT', 'No file was received.'];
        }
        switch ($file['error']) {
            case UPLOAD_ERR_OK: break;
            case UPLOAD_ERR_NO_FILE:
                return [false, 'ERR_FILE_FORMAT', 'Please choose a PDF to upload.'];
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return [false, 'ERR_FILE_FORMAT', 'That file is larger than the server accepts (5MB).'];
            default:
                return [false, 'ERR_FILE_FORMAT', 'We could not receive that file — please try again.'];
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            return [false, 'ERR_FILE_FORMAT', 'We could not receive that file — please try again.'];
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return [false, 'ERR_FILE_FORMAT', 'The PDF must be under 5MB.'];
        }
        if (($file['size'] ?? 0) < 32) {
            return [false, 'ERR_FILE_FORMAT', 'That file is empty.'];
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'docx'], true)) {
            return [false, 'ERR_FILE_FORMAT', 'Invalid file format. Please upload a PDF or Word (.docx) file.'];
        }

        $mime = self::sniffMime($file['tmp_name']);
        // A .docx is a ZIP, and finfo reports it as one whenever the archive
        // does not begin with the mimetype member Office usually writes first.
        // Both answers are accepted for a .docx and neither is accepted for a
        // .pdf, so the extension can never widen what the bytes are allowed
        // to be.
        $allowed = $ext === 'pdf'
            ? ['application/pdf']
            : ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
               'application/zip', 'application/octet-stream'];
        if (!in_array($mime, $allowed, true)) {
            return [false, 'ERR_FILE_FORMAT', 'Invalid file format. Please upload a PDF or Word (.docx) file.'];
        }

        [$structureOk, $structureMsg] = $ext === 'pdf'
            ? self::looksLikePdf($file['tmp_name'])
            : self::looksLikeDocx($file['tmp_name']);
        if (!$structureOk) {
            return [false, 'ERR_FILE_FORMAT', $structureMsg];
        }
        return [true, null, ''];
    }

    private static function sniffMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string)finfo_file($fi, $path);
                finfo_close($fi);
                return strtolower(trim($mime));
            }
        }
        // finfo is compiled in by default, but if it is missing the header
        // check below still has to pass, so this is not the only guard.
        return strncmp((string)@file_get_contents($path, false, null, 0, 5), '%PDF-', 5) === 0
            ? 'application/pdf' : 'application/octet-stream';
    }

    /**
     * Structural sanity, not a virus scan — and it does not pretend to be one.
     *
     * A PDF starts with %PDF- and ends with %%EOF, and a real one has at least
     * one object and a cross-reference section. Those three facts reject a
     * renamed executable, a truncated download and an HTML error page saved as
     * .pdf. Active content (/JavaScript, /Launch, an embedded file) is also
     * refused: this app has no use for any of it in a job description, and a
     * reader that opens the file later might.
     */
    private static function looksLikePdf(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) return [false, 'We could not read that file.'];

        $head = (string)fread($fh, 1024);
        fseek($fh, max(0, filesize($path) - 2048));
        $tail = (string)fread($fh, 2048);
        fclose($fh);

        if (strncmp($head, '%PDF-', 5) !== 0) {
            return [false, 'Invalid file format. Please upload a PDF.'];
        }
        if (strpos($tail, '%%EOF') === false) {
            return [false, 'That PDF looks incomplete — try re-saving or re-downloading it.'];
        }

        $bytes = (string)@file_get_contents($path, false, null, 0, 512 * 1024);
        if (!preg_match('/\d+\s+\d+\s+obj/', $bytes) && stripos($bytes, 'xref') === false) {
            return [false, 'That file is not a readable PDF.'];
        }
        foreach (['/JavaScript', '/JS ', '/Launch', '/EmbeddedFile', '/OpenAction'] as $marker) {
            if (stripos($bytes, $marker) !== false) {
                return [false, 'That PDF contains active content (scripts or embedded files), so it was not accepted. Please export a plain PDF.'];
            }
        }
        return [true, ''];
    }

    /**
     * Structural check for a Word file: a ZIP whose central directory actually
     * contains word/document.xml. A renamed .zip of holiday photos fails here,
     * as does a .doc (the old binary format, which this cannot read).
     */
    private static function looksLikeDocx(string $path): array
    {
        $head = (string)@file_get_contents($path, false, null, 0, 4);
        if (strncmp($head, "PK", 4) !== 0) {
            return [false, 'That file is not a Word (.docx) document. If it is an older .doc, save it as .docx or PDF first.'];
        }
        if (docx_zip_entry($path, 'word/document.xml') === null) {
            return [false, 'That Word file could not be opened — please re-save it and try again.'];
        }
        return [true, ''];
    }

    // ------------------------------------------------------------------
    // 2. Storage
    // ------------------------------------------------------------------
    /**
     * Move an accepted upload into storage/job_attachments and return the
     * stored name only — never a path the caller could have chosen. Nothing
     * under storage/ is web-servable; job-attachment.php serves it after a
     * permission check.
     */
    public static function store(string $tmpPath, string $originalName): ?string
    {
        $dir = self::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) === 'docx' ? 'docx' : 'pdf';
        $stored = bin2hex(random_bytes(8)) . '_' . time() . '_jd.' . $ext;
        $target = $dir . '/' . $stored;
        $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $target) : rename($tmpPath, $target);
        if (!$moved) return null;
        @chmod($target, 0644);
        return $stored;
    }

    /** Resolve a stored name to a real path, refusing anything that escapes the folder. */
    public static function resolve(string $storedName): ?string
    {
        if (!preg_match('/^[a-f0-9]{16}_\d+_jd\.(pdf|docx)$/', $storedName)) return null;
        $path = realpath(self::storageDir() . '/' . $storedName);
        $root = realpath(self::storageDir());
        if ($path === false || $root === false) return null;
        if (strncmp($path, $root, strlen($root)) !== 0 || !is_file($path)) return null;
        return $path;
    }

    // ------------------------------------------------------------------
    // 3. Parsing
    // ------------------------------------------------------------------
    /**
     * Parse a stored PDF. Always returns:
     *
     *   [ 'ok' => bool, 'engine' => 'python:pdfplumber'|'php', 'error_code' => ?string,
     *     'message' => string, 'fields' => [...], 'fields_html' => [...],
     *     'confidence' => [...], 'warnings' => [...] ]
     */
    public static function parse(string $path): array
    {
        // A .docx keeps its structure -- <w:numPr> says "list item", <w:tbl>
        // says "table" -- so it is read in PHP and never needs Python. Only a
        // PDF, where that structure has been flattened into positioned glyphs,
        // benefits from the layout-aware engine.
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'docx') {
            return self::fromExtraction(docx_job_text($path), 'php:docx');
        }

        $viaPython = self::runPython($path);
        if ($viaPython !== null && ($viaPython['ok'] ?? false)) {
            return $viaPython;
        }
        // A Python run that failed for a reason the user must see (a scan, a
        // corrupt file) is returned as-is. ERR_NO_ENGINE and an unusable child
        // process are internal, so the PHP reader gets its turn.
        if ($viaPython !== null
            && in_array($viaPython['error_code'] ?? '', ['ERR_UNREADABLE', 'ERR_FILE_FORMAT'], true)) {
            return $viaPython;
        }
        return self::runPhp($path);
    }

    /** The bundled PDF reader. Same contract, fewer signals. */
    private static function runPhp(string $path): array
    {
        return self::fromExtraction(pdf_extract_text($path), 'php');
    }

    /**
     * Shared mapper: an extraction result (from the PDF reader, the DOCX reader
     * or pasted text) becomes the response contract. One implementation, so the
     * three sources cannot drift apart in what they return.
     */
    private static function fromExtraction(array $extracted, string $engine): array
    {
        if (!empty($extracted['error'])) {
            return self::failure('ERR_UNREADABLE', $extracted['error']);
        }
        $text = (string)($extracted['text'] ?? '');
        if (mb_strlen(trim($text)) < 120) {
            return self::failure('ERR_UNREADABLE',
                'The file could not be read or contains scanned images without text.');
        }

        $parsed = jd_parse_fields($text);
        $fields = [];
        foreach (self::FIELDS as $key) {
            $value = trim((string)($parsed[$key] ?? ''));
            if ($value !== '') $fields[$key] = $value;
        }
        if (!isset($fields['deadline'])) {
            $deadline = self::findDeadline($text);
            if ($deadline !== null) $fields['deadline'] = $deadline;
        }
        // A deadline read from a label ("Application Deadline: December 5, 2026")
        // is still raw text. Put it through the same conversion a deadline found
        // in running text gets, so the form receives one format from every
        // source. An ambiguous numeric date stays raw on purpose -- see toIso().
        if (isset($fields['deadline']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['deadline'])) {
            foreach (jd_rules()['date_patterns'] ?? [] as $pattern) {
                if (preg_match('/' . str_replace('/', '\/', $pattern) . '/iu', $fields['deadline'], $m)) {
                    $iso = self::toIso($m);
                    if ($iso !== null) { $fields['deadline'] = $iso; }
                    break;
                }
            }
        }

        // One "Qualifications" list often mixes both kinds: "5+ years required"
        // beside "AWS a plus". The Python engine splits them; do the same here
        // so the two engines fill the same boxes. A PDF that already had its own
        // preferred section is left alone.
        if (!empty($fields['qualifications']) && empty($fields['preferred_skills'])) {
            $markers = jd_rules()['preferred_markers'] ?? [];
            $required = $preferred = [];
            foreach (preg_split('/\R/u', $fields['qualifications']) ?: [] as $line) {
                $low = mb_strtolower($line);
                $isPreferred = false;
                foreach ($markers as $marker) {
                    if ($marker !== '' && mb_strpos($low, $marker) !== false) { $isPreferred = true; break; }
                }
                if ($isPreferred) { $preferred[] = $line; } else { $required[] = $line; }
            }
            if ($preferred && $required) {
                $fields['qualifications'] = implode("
", $required);
                $fields['preferred_skills'] = implode("
", $preferred);
            }
        }

        $fields = self::sanitizeFields($fields);

        $warnings = [];
        if (($extracted['quality'] ?? '') === 'poor') {
            $warnings[] = 'The text layer in this file came through poorly, so some fields may run together. Please read them carefully.';
        }

        $core = array_filter(['title', 'responsibilities', 'qualifications'],
                             static fn($k) => !empty($fields[$k]));
        $errorCode = count($core) < 2 ? 'ERR_MISSING_FIELDS' : null;

        return [
            'ok'          => true,
            'engine'      => $engine,
            'error_code'  => $errorCode,
            'message'     => '',
            'fields'      => $fields,
            'fields_html' => self::htmlFromFields($fields),
            'confidence'  => array_fill_keys(array_keys($fields), 0.6),
            'warnings'    => $warnings,
        ];
    }

    /**
     * Every value the parser returns is sanitised before it leaves this class.
     *
     * The form is not the only consumer -- upload-parser.php answers with JSON
     * that other code could use -- so emoji are removed here rather than in the
     * browser, and the single-line fields are additionally flattened and cut to
     * their column length.
     */
    private static function sanitizeFields(array $fields): array
    {
        $lineLimits = [
            'title' => 180, 'company' => 160, 'department' => 120, 'location' => 160,
            'salary' => 255, 'experience' => 255, 'education' => 255, 'deadline' => 120,
        ];
        foreach ($fields as $key => $value) {
            $fields[$key] = isset($lineLimits[$key])
                ? sanitize_job_line($value, $lineLimits[$key])
                : sanitize_job_text($value);
            if ($fields[$key] === '') unset($fields[$key]);
        }
        return $fields;
    }

    /** Dates, using the patterns both engines share. */
    private static function findDeadline(string $text): ?string
    {
        $rules = jd_rules();
        $labels = array_merge($rules['labels']['deadline'] ?? [], $rules['sections']['deadline'] ?? []);
        if (!$labels) return null;
        usort($labels, static fn($a, $b) => strlen($b) <=> strlen($a));

        foreach (preg_split('/\R/u', $text) ?: [] as $i => $line) {
            $key = mb_strtolower(trim($line));
            $hit = null;
            foreach ($labels as $label) {
                if (strncmp($key, $label, strlen($label)) === 0) { $hit = $label; break; }
            }
            if ($hit === null) continue;

            $tail = trim(mb_substr(trim($line), mb_strlen($hit)));
            foreach ($rules['date_patterns'] ?? [] as $pattern) {
                if (preg_match('/' . str_replace('/', '\/', $pattern) . '/i', $tail, $m)) {
                    return self::toIso($m) ?? trim($m[0]);
                }
            }
            $tail = trim($tail, " :\u{2013}\u{2014}-");
            if ($tail !== '') return mb_substr($tail, 0, 120);
        }
        return null;
    }

    /** Mirror of the Python to_iso(): refuse to guess an ambiguous numeric date. */
    private static function toIso(array $m): ?string
    {
        $g = array_values(array_filter(array_slice($m, 1), static fn($v) => $v !== '' && $v !== null));
        if (count($g) !== 3) return null;
        $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
        [$a, $b, $c] = $g;

        if (ctype_digit($a) && strlen($a) === 4) { [$y, $mo, $d] = [(int)$a, (int)$b, (int)$c]; }
        elseif (ctype_alpha($a)) { $mo = $months[strtolower(substr($a, 0, 3))] ?? 0; $d = (int)$b; $y = (int)$c; }
        elseif (ctype_alpha($b)) { $d = (int)$a; $mo = $months[strtolower(substr($b, 0, 3))] ?? 0; $y = (int)$c; }
        else {
            $first = (int)$a; $second = (int)$b; $y = (int)$c;
            if ($first > 12 && $second <= 12)      { $d = $first; $mo = $second; }
            elseif ($second > 12 && $first <= 12)  { $mo = $first; $d = $second; }
            else return null;                       // 03/04/2026 means two different days
        }
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
        if ($y < 100 && strlen((string)$c) <= 2) $y += 2000;
        if ($y < 1000) return null;
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** Line-per-item text -> a <ul>, prose -> <p>. The Python engine does better. */
    private static function htmlFromFields(array $fields): array
    {
        $html = [];
        foreach (['responsibilities', 'qualifications', 'skills', 'preferred_skills', 'benefits'] as $key) {
            if (empty($fields[$key])) continue;
            $items = array_values(array_filter(array_map('trim', preg_split('/\R/u', $fields[$key]) ?: [])));
            if (!$items) continue;
            $html[$key] = '<ul>' . implode('', array_map(
                static fn($i) => '<li>' . htmlspecialchars($i, ENT_QUOTES, 'UTF-8') . '</li>', $items)) . '</ul>';
        }
        if (!empty($fields['description'])) {
            $paras = array_values(array_filter(array_map('trim', preg_split('/\R{2,}|\R/u', $fields['description']) ?: [])));
            $html['description'] = implode('', array_map(
                static fn($p) => '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>', $paras));
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Python bridge
    // ------------------------------------------------------------------
    /** Returns the decoded JSON, or null when Python could not be used at all. */
    private static function runPython(string $path): ?array
    {
        if (!function_exists('proc_open')) return null;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) return null;

        $binary = self::pythonBinary();
        if ($binary === null) return null;

        $script = dirname(__DIR__, 2) . '/tools/extract_job_data.py';
        if (!is_file($script)) return null;

        [$stdout, $stderr, $code] = self::run([$binary, $script, $path], 25);
        if ($stdout === '') {
            self::log('python produced no output (exit ' . $code . '): ' . substr($stderr, 0, 400));
            return null;
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            self::log('python output was not JSON: ' . substr($stdout, 0, 300));
            return null;
        }
        if (!empty($decoded['error_code']) && $decoded['error_code'] === 'ERR_NO_ENGINE') {
            return null;                              // no PDF library there; PHP takes over
        }

        $fields = [];
        foreach (self::FIELDS as $key) {
            $value = trim((string)($decoded['fields'][$key] ?? ''));
            if ($value !== '') $fields[$key] = $value;
        }
        $fields = self::sanitizeFields($fields);
        return [
            'ok'          => (bool)($decoded['ok'] ?? false),
            'engine'      => 'python:' . ($decoded['engine'] ?? 'unknown'),
            'error_code'  => $decoded['error_code'] ?? null,
            'message'     => (string)($decoded['message'] ?? ''),
            'fields'      => $fields,
            'fields_html' => is_array($decoded['fields_html'] ?? null) ? $decoded['fields_html'] : [],
            'confidence'  => is_array($decoded['confidence'] ?? null) ? $decoded['confidence'] : [],
            'warnings'    => is_array($decoded['warnings'] ?? null) ? $decoded['warnings'] : [],
        ];
    }

    /**
     * Find an interpreter. JD_PYTHON_BIN wins, then the project venv (which is
     * where the PDF libraries are installed — see tools/README.md), then
     * whatever is on PATH. The result is cached for the request.
     */
    private static function pythonBinary(): ?string
    {
        static $found = false, $cached = null;
        if ($found) return $cached;
        $found = true;

        $root = dirname(__DIR__, 2);
        $candidates = array_filter([
            env('JD_PYTHON_BIN', ''),
            $root . '/tools/.venv/Scripts/python.exe',   // Windows venv
            $root . '/tools/.venv/bin/python',           // POSIX venv
        ]);
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) { $cached = $candidate; return $cached; }
        }
        foreach (['python3', 'python', 'py'] as $name) {
            [$out, , $code] = self::run([$name, '-c', 'print(1)'], 6);
            if ($code === 0 && trim($out) === '1') { $cached = $name; return $cached; }
        }
        $cached = null;
        return null;
    }

    /**
     * Run a child process with a wall-clock limit and no shell.
     *
     * proc_open() with an ARRAY argv means the arguments never pass through a
     * shell, so a filename cannot become part of a command. Nothing here is
     * user-supplied anyway — the path is one this class generated — but a
     * parser bolted onto a web upload is exactly where that stops being true
     * later.
     */
    private static function run(array $argv, int $timeoutSeconds): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($argv, $descriptors, $pipes, dirname(__DIR__, 2));
        if (!is_resource($proc)) return ['', 'could not start ' . $argv[0], -1];

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) break;
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                self::log('python timed out after ' . $timeoutSeconds . 's');
                break;
            }
            usleep(25000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) { if (is_resource($pipe)) fclose($pipe); }
        $exit = proc_close($proc);

        return [trim($stdout), trim($stderr), $exit];
    }

    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'engine' => 'php', 'error_code' => $code, 'message' => $message,
                'fields' => [], 'fields_html' => [], 'confidence' => [], 'warnings' => []];
    }

    /** Diagnostics go beside the mail log; nothing here reaches the browser. */
    private static function log(string $line): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;
        @file_put_contents($dir . '/job-parser.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }
}
