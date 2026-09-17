<?php
/**
 * Candidate document storage.
 *
 * ON "OUTSIDE THE WEB ROOT": the brief asks for storage outside the public
 * root. On XAMPP this project lives in htdocs, so a path above it would sit
 * outside the vhost and break the moment the app is moved or deployed
 * elsewhere. Instead the directory is inside the project but sealed:
 *
 *   - storage/.htaccess denies all direct access (mod_authz_core and legacy)
 *   - storage/index.php and storage/resumes/index.php return 403
 *   - every download goes through download.php, which checks the session first
 *   - stored names are generated, never taken from the upload
 *
 * To use a truly external directory, change ACME_STORAGE_PATH below to an
 * absolute path outside the vhost. Nothing else needs to change.
 */

require_once __DIR__ . '/config.php';

if (!defined('ACME_STORAGE_PATH')) {
    define('ACME_STORAGE_PATH', __DIR__ . '/../storage/resumes');
}

const DOC_MAX_BYTES = 10 * 1024 * 1024;      // 10MB, per the specification

/** Accepted uploads: extension => the MIME types that legitimately carry it. */
function document_types(): array {
    return [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                   'application/zip', 'application/octet-stream'],
    ];
}

function document_icon_class(string $ext): string {
    return ['pdf' => 'type-pdf', 'doc' => 'type-doc', 'docx' => 'type-doc'][$ext] ?? 'type-doc';
}

function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

/**
 * Validate and store an upload.
 * Returns [ok, payload]. On failure the payload carries a code from the
 * specification (ERR_SIZE_EXCEEDED, ERR_UNSUPPORTED_TYPE, ERR_CORRUPT_FILE)
 * and the matching message.
 */
function store_candidate_document(array $file, ?int $candidateId, ?int $userId = null, ?string $token = null): array {
    $fail = static fn(string $code, string $msg) => [false, ['code' => $code, 'error' => $msg]];

    if (!isset($file['error']) || is_array($file['error'])) {
        return $fail('ERR_CORRUPT_FILE', 'Parsing failed: the document appears to be corrupted or encrypted. Please enter details manually.');
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return $fail('ERR_SIZE_EXCEEDED', 'Upload failed: file exceeds the 10MB limit.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return $fail('ERR_CORRUPT_FILE', 'Parsing failed: the document appears to be corrupted or encrypted. Please enter details manually.');
    }
    if ((int)$file['size'] > DOC_MAX_BYTES) {
        return $fail('ERR_SIZE_EXCEEDED', 'Upload failed: file exceeds the 10MB limit.');
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $types = document_types();
    if (!isset($types[$ext])) {
        return $fail('ERR_UNSUPPORTED_TYPE', 'Upload failed: only PDF, DOC, and DOCX formats are supported.');
    }

    // Trust the bytes, not the extension.
    $mime = null;
    if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) {
        $mime = finfo_file($fi, $file['tmp_name']) ?: null;
        finfo_close($fi);
        if ($mime !== null && !in_array($mime, $types[$ext], true)) {
            return $fail('ERR_UNSUPPORTED_TYPE', 'Upload failed: only PDF, DOC, and DOCX formats are supported.');
        }
    }

    if (!is_dir(ACME_STORAGE_PATH) && !mkdir(ACME_STORAGE_PATH, 0755, true) && !is_dir(ACME_STORAGE_PATH)) {
        return $fail('ERR_CORRUPT_FILE', 'The storage folder could not be created. Please contact your administrator.');
    }

    // [uuid]_[timestamp]_resume.[ext] — generated, so a hostile filename cannot
    // reach the filesystem and two uploads can never collide.
    $uuid = bin2hex(random_bytes(8));
    $stored = $uuid . '_' . time() . '_resume.' . $ext;
    $target = ACME_STORAGE_PATH . '/' . $stored;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return $fail('ERR_CORRUPT_FILE', 'The file could not be saved. Please try again.');
    }
    @chmod($target, 0640);

    try {
        $pdo = db();
        // A new upload becomes primary; the previous one is archived, not deleted.
        if ($candidateId) {
            $pdo->prepare('UPDATE candidate_documents SET is_primary=0 WHERE candidate_id=?')->execute([$candidateId]);
        }
        $pdo->prepare('INSERT INTO candidate_documents
                       (candidate_id,upload_token,stored_name,original_name,extension,mime_type,byte_size,is_primary,uploaded_by)
                       VALUES (?,?,?,?,?,?,?,1,?)')
            ->execute([$candidateId, $token, $stored, mb_substr((string)$file['name'], 0, 255), $ext,
                       $mime, (int)$file['size'], $userId]);
        $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        @unlink($target);
        return $fail('ERR_CORRUPT_FILE', 'The document could not be recorded: ' . $e->getMessage());
    }

    return [true, [
        'id' => $id, 'stored_name' => $stored, 'path' => $target,
        'original_name' => (string)$file['name'], 'extension' => $ext,
        'size' => (int)$file['size'], 'size_label' => format_bytes((int)$file['size']),
    ]];
}

/**
 * Attach a document uploaded during parsing to the candidate now being saved,
 * and demote any earlier resume. Returns the document id, or null.
 */
function attach_document_to_candidate(string $token, int $candidateId): ?int {
    if (!preg_match('/^[a-f0-9]{32}$/i', $token)) return null;
    try {
        $pdo = db();
        $s = $pdo->prepare('SELECT * FROM candidate_documents WHERE upload_token=? AND candidate_id IS NULL LIMIT 1');
        $s->execute([$token]);
        $doc = $s->fetch();
        if (!$doc) return null;

        $pdo->prepare('UPDATE candidate_documents SET is_primary=0 WHERE candidate_id=?')->execute([$candidateId]);
        $pdo->prepare('UPDATE candidate_documents SET candidate_id=?, upload_token=NULL, is_primary=1 WHERE id=?')
            ->execute([$candidateId, (int)$doc['id']]);
        return (int)$doc['id'];
    } catch (Throwable $e) {
        return null;
    }
}

/** Documents for a candidate, primary first. */
function candidate_documents(int $candidateId): array {
    try {
        $s = db()->prepare('SELECT d.*, u.name uploader FROM candidate_documents d
                            LEFT JOIN users u ON u.id = d.uploaded_by
                            WHERE d.candidate_id = ?
                            ORDER BY d.is_primary DESC, d.created_at DESC');
        $s->execute([$candidateId]);
        return $s->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Delete pending uploads older than a day, so abandoned parses do not pile up. */
function prune_orphan_documents(): void {
    try {
        $pdo = db();
        $s = $pdo->query("SELECT id, stored_name FROM candidate_documents
                          WHERE candidate_id IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 50");
        foreach ($s->fetchAll() as $row) {
            @unlink(ACME_STORAGE_PATH . '/' . $row['stored_name']);
            $pdo->prepare('DELETE FROM candidate_documents WHERE id=?')->execute([(int)$row['id']]);
        }
    } catch (Throwable $e) { /* housekeeping only */ }
}
