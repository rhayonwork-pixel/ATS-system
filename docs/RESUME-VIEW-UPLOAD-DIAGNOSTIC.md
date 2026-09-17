# ACME ATS — Resume View & Upload: Diagnostic Report and Remediation Guide

**Scope:** `candidates.php` (Issue 1 — view/404/forced-download) and `apply.php` → `candidate.php` (Issue 2 — uploads not appearing).

**Method:** Every claim below was checked against the actual current code, not written as a generic template. Two real, confirmed findings came out of this; one described symptom did not reproduce anywhere I could find, and that is stated plainly rather than papered over.

---

## Executive summary

| Issue | Status | Root cause |
|---|---|---|
| **1 — forced download instead of inline view** | **Confirmed and fixed** | Two "View" links (`candidates.php` list, `pipeline.php`'s Kanban modal) called the shared file controller without the query parameter that tells it to render inline. Every other "View" control in the app already had it; these two didn't. |
| **1 — 404 on view** | **Not reproduced** | No code path currently produces a 404 for a document that was successfully stored. See Phase 3 below for exactly what was checked. |
| **2 — uploads don't appear in the profile** | **Not reproduced** | The write path (`apply.php` → `store_candidate_document()`) and the read path (`candidate.php` → `candidate_documents()`) were each re-traced independently and both check out. A plausible explanation for the *symptom* is given at the end of Phase 4 — it may be the same root cause as Issue 1, misread as "missing" rather than "downloaded instead of shown." |

---

## 1. Systematic debugging checklist (prioritized)

### Phase 1 — File ingestion (Issue 2)

Checked in `includes/documents.php`, `store_candidate_document()`:

```php
if (!isset($file['error']) || is_array($file['error'])) { return $fail('ERR_CORRUPT_FILE', ...); }
if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) { return $fail('ERR_SIZE_EXCEEDED', ...); }
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { return $fail('ERR_CORRUPT_FILE', ...); }
if ((int)$file['size'] > DOC_MAX_BYTES) { return $fail('ERR_SIZE_EXCEEDED', ...); }
```

`is_uploaded_file()` confirms the temp file genuinely came from a real HTTP upload (not a manufactured path), and every `UPLOAD_ERR_*` case is handled explicitly rather than assumed. `move_uploaded_file()` is the actual move to persistent storage:

```php
if (!move_uploaded_file($file['tmp_name'], $target)) {
    return $fail('ERR_CORRUPT_FILE', 'The file could not be saved. Please try again.');
}
```

This fails loudly (a returned error, not a silent no-op) if the destination directory isn't writable — so a permissions problem would surface as an on-screen error to the applicant, not a silent "success" with a missing file. If you are seeing a *silent* miss, this is the first place to add temporary logging (see Section 6) to confirm whether `move_uploaded_file()` is even being reached, versus failing before it.

**Verified:** `ACME_STORAGE_PATH` (`includes/documents.php`) resolves to `storage/resumes/`, created with `mkdir($dir, 0755, true)` if absent, and every stored file is `chmod`'d to `0640` after the move.

### Phase 2 — Data persistence (Issue 2)

Checked in `apply.php`. The resume is stored **after** the candidate and application rows exist, inside the same transaction:

```php
$ins = $pdo->prepare('INSERT INTO applications(candidate_id,job_id,cover_letter,why_us) VALUES(?,?,?,?)');
$ins->execute([$candidateId, $job['id'], $coverLetter, $whyUs]);
$applicationId = (int)$pdo->lastInsertId();

[$docOk, $docResult] = store_candidate_document($_FILES['resume'], $candidateId, null);
if (!$docOk) {
    $pdo->rollBack();
    $error = /* friendly message, see Phase 2 finding below */;
} else {
    $pdo->commit();
    header('Location: application-status.php?id='.$applicationId.'&email='.urlencode($email).'&new=1'); exit;
}
```

`db()` (`includes/config.php`) is a `static` singleton connection, so `store_candidate_document()`'s own internal `INSERT INTO candidate_documents` runs on the **same** connection and **inside** this same transaction — if anything after it fails, the document row rolls back with everything else. There is no separate/orphaned insert.

The read side, `candidate_documents()`:

```php
function candidate_documents(int $candidateId): array {
    $s = db()->prepare('SELECT d.*, u.name uploader FROM candidate_documents d
                        LEFT JOIN users u ON u.id = d.uploaded_by
                        WHERE d.candidate_id = ?
                        ORDER BY d.is_primary DESC, d.created_at DESC');
    $s->execute([$candidateId]);
    return $s->fetchAll() ?: [];
}
```

`$candidateId` is the same integer at write and read time; `is_primary` is hardcoded to `1` on insert and the previous primary is explicitly demoted (`UPDATE candidate_documents SET is_primary=0 WHERE candidate_id=?`) before the new row is written, so there is exactly one primary row per candidate at any time. **This round-trip was traced twice, independently, and no break in the chain was found.**

**A separate, real bug was found and fixed here** — not the reported symptom, but a genuine defect in the same code path: `store_candidate_document()`'s internal error catch returns the *raw* database exception text (`'The document could not be recorded: ' . $e->getMessage()`). If `candidate_documents` doesn't exist yet on a given install (migration not run), that raw SQLSTATE string would have reached a public, unauthenticated applicant, and blocked every single submission outright. Fixed with a specific check against MySQL's actual error format:

```php
$error = stripos($docResult['error'], 'candidate_documents') !== false
       || stripos($docResult['error'], "doesn't exist") !== false
       || stripos($docResult['error'], 'Base table') !== false
    ? 'The database is missing recent updates. An administrator needs to run database/migration-resume-storage.sql against the acme_ats database.'
    : $docResult['error'];
```

If your test environment has **not** run `database/migration-resume-storage.sql`, this is almost certainly what you are actually seeing, and it would look exactly like "the upload doesn't appear" — because with the fix above in place, the *whole submission* is rejected with an explicit message, and without it (on an older copy of this file), it would previously have shown a raw SQL error. Either way: **run that migration first**, before investigating further.

### Phase 3 — Routing & retrieval (Issue 1, the 404 claim)

Traced `download.php` top to bottom. Every `http_response_code()` call and its exact trigger:

| Code | Trigger | Reachable when a document was actually stored successfully? |
|---|---|---|
| `400` | `file_id` missing/zero | No — every real link passes a real id |
| `404` | `SELECT * FROM candidate_documents WHERE id=?` returns no row | No — only if the id is fabricated or the row was deleted |
| `400` | stored filename fails `^[a-f0-9]{16}_\d+_resume\.(pdf|doc|docx)$` | No — checked the generator: `bin2hex(random_bytes(8))` always produces exactly 16 hex chars, `time()` always produces digits, format matches by construction |
| `404` | `realpath()` fails or the file isn't on disk | Only if the file was moved/deleted outside the app after upload |

**No path from a normal, successful upload to a 404 was found.** If you are genuinely seeing a 404 (not a downloaded file, an actual 404 page), the most likely causes are:
- the migration issue above (no row was ever created, so there's nothing to 404 *from* — the applicant's error would have told you this if the Phase 2 fix is in place)
- a document row whose file was manually deleted from `storage/resumes/` without deleting the DB row
- testing against a stale `file_id` from before a database reset

None of these are a code defect in the current files; they're operational/data conditions. Confirm which one you're hitting by opening `storage/resumes/` directly (or querying `candidate_documents` for the `file_id` in your browser's address bar) before assuming a routing bug.

### Phase 4 — Header delivery (Issue 1, the forced-download claim — **confirmed and fixed**)

This is the real bug. `download.php`'s inline/attachment decision:

```php
$inline = (($_GET['disposition'] ?? '') === 'inline') && $doc['extension'] === 'pdf';
...
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"' ...);
```

It defaults to `attachment` unless the caller explicitly asks for `inline`. Auditing **every** link to this controller in the project (not a sample — every single one):

| File | Label | Had `&disposition=inline`? |
|---|---|---|
| `candidate.php` (PDF viewer iframe) | *(embedded, no label)* | Yes — correct |
| `candidate.php` (Download button) | "Download" | No — correct, download is intended |
| `candidate.php` (Word-doc fallback) | "Download {filename}" | No — correct |
| `candidate.php` (version list) | "Download" (icon) | No — correct |
| **`candidates.php` (list view)** | **"View"** | **No — bug** |
| **`pipeline.php`** (feeds the Kanban preview modal's "View resume" link) | **"View resume"** | **No — bug** |

Two controls explicitly labeled "View" were silently forcing a download, because they were missing the one query parameter that changes the behavior. Every "Download"-labeled control was already correct. **Fixed** — both now pass `&disposition=inline`:

```php
// candidates.php
<a class="btn small secondary" href="download.php?file_id=<?=(int)$c['primary_doc_id']?>&disposition=inline" target="_blank" rel="noopener">View</a>

// pipeline.php
'resume' => !empty($r['primary_doc_id']) ? ('download.php?file_id='.(int)$r['primary_doc_id'].'&disposition=inline') : ($r['resume_path'] ?: null),
```

The extension gate is untouched: `disposition=inline` is a no-op for a `.doc`/`.docx` file (`$doc['extension'] === 'pdf'` is still required), so Word documents correctly continue to force a download — browsers cannot render them regardless of the header, and that fallback UI already tells the person so explicitly.

**A plausible unification of Issues 1 and 2:** a PDF that downloads instead of opening inline, in a browser configured to save downloads automatically, produces a file sitting in the Downloads folder with no visible change on the page the recruiter is looking at. From that recruiter's point of view, "I clicked View and nothing showed up" is indistinguishable from "the document isn't there." If Issue 2 was actually reported by *staff* looking at `candidates.php` rather than by tracing an applicant's submission, this fix may resolve both reports from a single cause. Worth confirming after deploying this fix before spending more time chasing a second, distinct upload-linkage bug that this investigation could not find.

---

## 2. PHP code review points

### File upload logic

The project's actual validation order (`includes/documents.php`), which is the pattern to follow anywhere else a file is accepted:

```php
if (!isset($file['error']) || is_array($file['error'])) { /* malformed $_FILES entry */ }
if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) { /* too large, per php.ini or the form */ }
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { /* anything else, incl. spoofed path */ }
if ((int)$file['size'] > DOC_MAX_BYTES) { /* app-level cap, independent of php.ini */ }
```

Check `$file['error']` **before** touching `$file['tmp_name']` in any way — a failed upload can leave `tmp_name` empty or unset, and calling `is_uploaded_file('')` or `move_uploaded_file('', ...)` on it is the classic way this kind of check gets it backwards. `is_uploaded_file()` must run before `move_uploaded_file()`, not after — it's what confirms the path genuinely came from the upload machinery rather than being attacker-supplied.

### Database operations

The mapping query, parameterized, never string-concatenated:

```php
$pdo->prepare('INSERT INTO candidate_documents
               (candidate_id,upload_token,stored_name,original_name,extension,mime_type,byte_size,is_primary,uploaded_by)
               VALUES (?,?,?,?,?,?,?,1,?)')
    ->execute([$candidateId, $token, $stored, mb_substr((string)$file['name'], 0, 255), $ext,
               $mime, (int)$file['size'], $userId]);
```

`$candidateId` is the foreign key that makes the whole system work; it is looked up or created **before** this call, never guessed or trusted from client input. `mb_substr(..., 0, 255)` truncates to the column width rather than letting a long filename throw a DB-level truncation error.

### Display routing — serving a PDF inline

The exact working code, from `download.php`:

```php
$mime = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
         'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][$doc['extension']]
        ?? 'application/octet-stream';

$inline = (($_GET['disposition'] ?? '') === 'inline') && $doc['extension'] === 'pdf';

$safe = preg_replace('/[^\w.\- ]+/u', '_', $doc['original_name']) ?: ('resume.' . $doc['extension']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
     . '; filename="' . $safe . '"'
     . "; filename*=UTF-8''" . rawurlencode($doc['original_name']));
header('X-Content-Type-Options: nosniff');
readfile($path);
```

The two things that actually decide inline-vs-download are `Content-Type` and `Content-Disposition`, in that combination — `Content-Type: application/pdf` alone is not enough; without `Content-Disposition: inline` many browsers still prompt to save. The `filename` parameter is a quoted ASCII fallback (for older clients); `filename*=UTF-8''...` is the RFC 5987 form so accented filenames survive in modern ones. `X-Content-Type-Options: nosniff` stops a browser from second-guessing the declared type. `readfile()` streams the file directly rather than reading it into a PHP string first, which matters for large PDFs and `memory_limit` (see Section 3).

---

## 3. Server configuration checks

### php.ini

| Setting | What to check | Where it bites in this codebase |
|---|---|---|
| `file_uploads` | Must be `On` | If off, `$_FILES` is empty and Phase 1's own error handling (`UPLOAD_ERR_NO_FILE`) reports it correctly rather than failing silently |
| `upload_max_filesize` | Must be ≥ the app's own cap | `DOC_MAX_BYTES` in `includes/documents.php` is 10MB. If `upload_max_filesize` is lower (PHP's classic default is 2M), the upload is truncated by PHP **before** this code ever runs, and `$file['error']` will be `UPLOAD_ERR_INI_SIZE` — which is already handled, but only if this ini value is at least 10M does a 9MB resume even have a chance |
| `post_max_size` | Must be **larger** than `upload_max_filesize`, not equal | The whole POST body (all fields + the file) must fit; if the file alone is 10M and this is also 10M, the request is rejected before PHP parses anything, `$_FILES` may not even be populated |
| `memory_limit` | Comfortable headroom over the max file size | `readfile()` in `download.php` streams and does not load the whole file into memory, so this matters far more for the *upload* side (multipart parsing) than for serving |
| `sys_temp_dir` | Writable by the PHP process user | `move_uploaded_file()`'s source is always here first; if this directory isn't writable, uploads fail before `includes/documents.php` is ever reached |

### Directory permissions

```bash
ls -la storage/resumes/
```

The app creates this with `mkdir($dir, 0755, true)` and `chmod`s each file to `0640` after moving it. `0755` on the directory (owner: read/write/execute, group and others: read/execute) is correct for a directory the web server process owns and needs to list/traverse; `0640` on each file (owner read/write, group read, others nothing) is intentionally tighter than the directory, since files never need to be executed and should not be world-readable. Confirm the directory's **owner** matches the user PHP actually runs as (`www-data` on a stock Apache/Debian install, sometimes `apache` on RHEL-family, or your XAMPP service account on Windows) — a permissions number that looks right but is owned by the wrong user fails identically to a wrong number.

### Routing rules

`download.php` is a plain file at the project root — there is no router or `.htaccess` rewrite rule involved in reaching it, and no framework front-controller to misroute around it. If you are seeing a 404 specifically at the URL `download.php?file_id=...` (as opposed to the "That document does not exist" text the app itself emits on a missing row), check for:
- an `.htaccess` rule elsewhere in the vhost that blanket-denies query strings or specific extensions
- a reverse proxy / Nginx `location` block in front of Apache that doesn't pass `.php` requests with query strings through correctly

Neither exists in this project's own files; if present, it would be at the web-server config layer, outside this codebase.

---

## 4. Database schema validation

Current, actual schema (`candidate_documents`, from `database/migration-resume-storage.sql`):

```sql
CREATE TABLE candidate_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT UNSIGNED NULL,          -- NULL while still a pending upload
  upload_token CHAR(32) NULL,              -- links a parse to its later save
  stored_name VARCHAR(255) NOT NULL,       -- name on disk, never user-supplied
  original_name VARCHAR(255) NOT NULL,     -- what the recruiter/applicant saw
  extension VARCHAR(8) NOT NULL,
  mime_type VARCHAR(100) NULL,
  byte_size INT UNSIGNED NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 1,
  parsed TINYINT(1) NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY stored_name_unique (stored_name),
  INDEX doc_candidate (candidate_id, is_primary, created_at),
  INDEX doc_token (upload_token),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

This already covers every column the brief asks for, under different but equivalent names: `original_name` (original filename), `mime_type`, and `extension` in place of a combined "resume_mime_type." `resume_path` is not this table at all — it's a **separate**, older column directly on `candidates`, kept only as read-only legacy data for records created before this table existed.

**Verifying the foreign-key relationship** ("matching the applicant_id from the frontend session to the candidate_id in the backend"): there is no separate applicant/session identity to reconcile in this schema — the same `candidates.id` is used end-to-end, established either by an existing email match or a fresh `INSERT`, *before* `candidate_documents.candidate_id` is ever written:

```sql
-- Confirm a specific candidate's documents, primary first:
SELECT id, candidate_id, original_name, is_primary, created_at
FROM candidate_documents
WHERE candidate_id = <id>
ORDER BY is_primary DESC, created_at DESC;

-- Confirm there is at most one primary per candidate (should return nothing):
SELECT candidate_id, COUNT(*) FROM candidate_documents
WHERE is_primary = 1 GROUP BY candidate_id HAVING COUNT(*) > 1;
```

If that second query ever returns a row, that's a genuine data-integrity bug worth reporting — the application code always demotes the previous primary before inserting a new one, so it should be structurally impossible, but it's a fast, cheap thing to check directly if something still looks wrong after the fixes above.

---

## 5. Security & vulnerability prevention

### MIME type validation

`$_FILES[...]['type']` is exactly what the **browser** claims the file is, read from the client-supplied `Content-Type` of the multipart part — trivially spoofable by anyone editing the request by hand, and never checked anywhere in this codebase. The actual check, in `store_candidate_document()`:

```php
if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) {
    $mime = finfo_file($fi, $file['tmp_name']) ?: null;
    finfo_close($fi);
    if ($mime !== null && !in_array($mime, $types[$ext], true)) {
        return $fail('ERR_UNSUPPORTED_TYPE', ...);
    }
}
```

`finfo_file()` inspects the file's actual bytes (magic numbers / signature) rather than trusting anything the client sent, and the result is cross-checked against the extension the filename claims — a `.pdf` whose real content is something else is rejected.

### Path traversal prevention

The filename ultimately written to disk is never derived from user input at all:

```php
$uuid = bin2hex(random_bytes(8));
$stored = $uuid . '_' . time() . '_resume.' . $ext;
```

There is no `basename()` call because there is nothing to sanitize — the original filename is preserved only as a *display* value (`original_name`) in the database, never used to construct a filesystem path. `download.php` re-validates this generated pattern again on the way **out**, before touching the filesystem:

```php
if (!preg_match('/^[a-f0-9]{16}_\d+_resume\.(pdf|doc|docx)$/', $doc['stored_name'])) {
    http_response_code(400); exit('That document reference is not valid.');
}
$path = realpath(ACME_STORAGE_PATH . '/' . $doc['stored_name']);
$root = realpath(ACME_STORAGE_PATH);
if ($path === false || $root === false || strncmp($path, $root, strlen($root)) !== 0 || !is_file($path)) {
    http_response_code(404); ...
}
```

The `realpath()` + prefix comparison is the actual traversal defense: even if a stored row were somehow tampered with, resolving the real filesystem path and confirming it still sits inside the storage root closes off `../../` tricks structurally, not just by pattern-matching the string.

### Storage isolation

`storage/resumes/` sits inside the project directory (not a path outside the vhost — see the note in `includes/documents.php` about why: on a typical XAMPP layout the project already lives in `htdocs`, so a path *above* that would fall outside the vhost and break on any other hosting setup). Isolation instead comes from denying the directory outright:

```
# storage/.htaccess
Require all denied
<IfModule !mod_authz_core.c>
  Order deny,allow
  Deny from all
</IfModule>
```

Backed up by `storage/index.php` and `storage/resumes/index.php`, each a one-line `http_response_code(403); exit;` — so even a misconfigured or missing `.htaccess` (Nginx doesn't read them at all) still can't be browsed to an index. `download.php` is the **only** route to any file in this tree, and it requires a signed-in staff session (`require_login(['admin','recruiter','hiring_manager'])`) before reading a single byte.

If moving to a directory genuinely outside the web root, the only change needed is one constant:

```php
define('ACME_STORAGE_PATH', '/absolute/path/outside/the/vhost');
```

---

## 6. Recommended logging implementation

Nothing in the current code logs upload/view attempts beyond the audit trail's own successful-only `document_downloaded` event. A dedicated logger for **failures** specifically (which the audit trail is not designed for — it's an audit log of confirmed actions, not a debug log):

```php
<?php
/**
 * includes/doc_debug_log.php
 * Temporary diagnostic logging for the upload/view pipeline. Not a
 * replacement for the audit trail — this is for tracing a specific reported
 * bug and should be removed (or left permanently at a coarser level) once
 * the issue is confirmed resolved.
 */
function doc_debug_log(string $action, string $status, ?int $subjectId, string $detail): void {
    $line = sprintf(
        "[%s] [%s] [Action: %s] [%s] %s\n",
        date('Y-m-d H:i:s'),
        $subjectId !== null ? "id={$subjectId}" : 'id=unknown',
        strtoupper($action),   // UPLOAD or VIEW
        strtoupper($status),   // OK or FAIL
        $detail
    );
    // A dedicated file, not error_log(), so this can be grepped in isolation
    // and deleted cleanly once the investigation is done.
    @file_put_contents(__DIR__ . '/../storage/doc-debug.log', $line, FILE_APPEND | LOCK_EX);
}
```

Call sites to add temporarily while diagnosing:

```php
// includes/documents.php, right after the move_uploaded_file() check:
if (!move_uploaded_file($file['tmp_name'], $target)) {
    doc_debug_log('upload', 'fail', $candidateId, "move_uploaded_file failed for {$target}");
    return $fail('ERR_CORRUPT_FILE', 'The file could not be saved. Please try again.');
}
doc_debug_log('upload', 'ok', $candidateId, "stored as {$stored}");

// download.php, at each http_response_code() call:
if (!$doc) {
    doc_debug_log('view', 'fail', $id, 'no matching candidate_documents row');
    http_response_code(404); exit('That document does not exist.');
}
```

This directly produces the `[Timestamp] [ID] [Action] [Status] [Detail]` format requested, writes to `storage/` (already access-denied to the outside world, so the log itself isn't newly exposed), and is written as its own small file specifically so it's easy to delete once the investigation above is closed out — it is not intended to become permanent, unlike the existing `audit()` mechanism.

---

## 7. Testing protocol

### File type matrix

| Type | Expected behavior after this fix | How to confirm |
|---|---|---|
| `.pdf` | Inline render, in every entry point | Click "View" from `candidates.php`, from the Kanban preview in `pipeline.php`, and from `candidate.php`'s own viewer — all three now pass `&disposition=inline` |
| `.docx` | Forced download, everywhere | The extension gate in `download.php` (`$doc['extension'] === 'pdf'`) means `&disposition=inline` is a no-op for this type by design — confirm the download prompt appears and the file opens correctly in Word once downloaded |
| `.doc` | Same as `.docx` | Same check |

No third-party viewer integration exists in this project for Word documents (a deliberate decision from an earlier round: browsers can't render `.docx` natively, and neither PDF.js nor a hosted Office/Google viewer was adopted, since the latter would require exposing the file's URL to a third party — incompatible with keeping resumes behind authentication). The current UI already tells the person this plainly rather than pretending a preview exists.

### Environment matrix

`Content-Disposition: inline` combined with `Content-Type: application/pdf` is standard, RFC 6266 / RFC 2183 behavior honored consistently by Chrome, Firefox, and Safari's built-in PDF renderers — there is no browser-specific branching in `download.php` and none should be needed. The one thing worth checking per-browser is whether the user has configured "always download PDFs" as a personal browser setting, which overrides *any* server header — that's a client preference, not a server bug, and worth ruling out if a specific person still sees a download after this fix while others don't.

### End-to-end flow

1. **Upload as applicant** — submit `apply.php` with a real PDF attached.
2. **Verify physical disk write** — confirm a new file matching `[16 hex chars]_[timestamp]_resume.pdf` appears under `storage/resumes/`.
3. **Verify DB row** —
   ```sql
   SELECT * FROM candidate_documents ORDER BY id DESC LIMIT 1;
   ```
   confirm `candidate_id` matches the candidate just created and `is_primary = 1`.
4. **Log in as Admin/Recruiter/Hiring Manager** (`candidates.php` is gated to exactly these three roles).
5. **Click View** from the candidates list.
6. **Confirm inline render** — the PDF should open in a new tab (`target="_blank"`) and display in the browser's native viewer, not prompt to save.

If step 3 fails (no row, or `candidate_id` is `NULL`), that is Issue 2 as originally described, and would be a genuine bug distinct from anything found in this investigation — the next step would be adding the Section 6 logging around `store_candidate_document()`'s own internal try/catch to see exactly which line it fails on. If step 3 succeeds but step 6 still downloads instead of rendering, confirm you are testing against the files in this delivery (the two-link fix above) rather than a previous copy.

---

## Files changed in this pass

- `candidates.php` — View link now requests inline disposition, opens in a new tab.
- `pipeline.php` — same fix, for the Kanban preview modal's resume link.
- `apply.php` — a raw database error was prevented from reaching a public applicant on an unmigrated install (found during Phase 2 re-verification; not the originally reported symptom, but a real defect in the same code path).

## What this pass could not confirm

A distinct "upload succeeds but the row never links to the candidate" bug, separate from the download-vs-view issue above. Both the write and read sides of that path were re-traced independently and neither shows a defect. If the symptom persists after deploying this fix and confirming `database/migration-resume-storage.sql` has been run, the Section 6 logging is the concrete next step — it will show, for a specific real submission, exactly which line (if any) failed, rather than continuing to reason about it in the abstract.
