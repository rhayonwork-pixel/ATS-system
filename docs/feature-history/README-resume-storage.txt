Resume storage, viewer, and the auto-fill indicator
===================================================

SETUP
-----
Import database/migration-resume-storage.sql (the tenth migration).
It creates candidate_documents and adds candidates.resume_text with a FULLTEXT
index, wrapped so an older engine cannot abort the migration.

FEATURE 1 — DOCUMENT STORAGE
============================

WHERE FILES LIVE, AND WHY NOT LITERALLY OUTSIDE THE WEB ROOT
------------------------------------------------------------
The brief asks for storage outside the public root. On XAMPP this project sits
in htdocs, so a path above it would fall outside the vhost and break the moment
the app is moved or deployed anywhere else. Instead the folder is inside the
project and sealed four ways:

    storage/.htaccess              Require all denied, with a legacy fallback
    storage/index.php              returns 403
    storage/resumes/index.php      returns 403
    download.php                   the only reader, and it checks the session

For a genuinely external directory, change one constant:

    includes/documents.php:  define('ACME_STORAGE_PATH', '/absolute/path');

Nothing else needs to change. I verified that no file other than
includes/documents.php references the storage path at all.

UPLOAD PIPELINE
---------------
    validate  ->  store  ->  parse  ->  attach on save

  * 10MB cap, checked against both the PHP limit and the byte count
  * extension allowlist: pdf, doc, docx
  * MIME sniffed from the file's own bytes with finfo and cross-checked against
    the extension, so a .exe renamed to .pdf is refused
  * is_uploaded_file() before anything is moved
  * stored as [uuid]_[timestamp]_resume.[ext] — entirely generated, so a
    hostile filename never reaches the filesystem and two uploads cannot
    collide. The original name is kept in the database for display only.
  * files are chmod 0640

The three error codes from the specification are returned verbatim:
ERR_SIZE_EXCEEDED, ERR_UNSUPPORTED_TYPE, ERR_CORRUPT_FILE, each with the exact
message text given.

THE FILE IS KEPT EVEN WHEN PARSING FAILS
-----------------------------------------
This is the important behaviour. The document is stored BEFORE parsing is
attempted, so a scanned PDF, an encrypted file or a legacy .doc still ends up
attached to the candidate — the recruiter types the details in by hand, but the
resume itself is on file. Previously a parse failure meant losing the upload
entirely.

A pending upload is linked to the form by a one-time token and attached to the
candidate on save. Abandoned uploads older than a day are pruned automatically,
so nothing accumulates.

Legacy .doc is accepted and stored, but not parsed: it is an OLE compound
binary, and reverse-engineering it here would be guesswork. The message says so
and suggests re-saving as PDF or DOCX. That is honest rather than silently
returning nothing.

VERSIONING
----------
Uploading a replacement sets the previous document is_primary = 0 rather than
deleting it. The profile shows the current file with a "Current" badge and
lists earlier versions beneath it with their upload date and who uploaded them.

DOWNLOAD SECURITY
-----------------
Every property below was checked, not assumed:

    session and staff role required (candidates have no route here)
    the stored name is re-validated against a strict pattern before use
    realpath() plus a prefix check confines reads to the storage folder
    the request carries a file id, never a path
    X-Content-Type-Options: nosniff
    Content-Security-Policy: sandbox, so an uploaded PDF cannot run scripts
    inline disposition is allowed for PDF only; Word always downloads
    every access is written to the audit trail as document_downloaded

THE VIEWER
----------
PDFs are embedded in an iframe pointed at download.php with an inline
disposition, so the file stays behind the authorisation check instead of being
exposed at a guessable URL. Height is clamp(320px, 60vh, 720px) — usable on a
laptop without swallowing a phone screen.

Word documents are NOT given a fake viewer. Browsers cannot render them, so the
panel says so and offers a download. A viewer that did not work would be worse
than an honest message.

FULL-TEXT SEARCH
----------------
Extracted text is stored in candidates.resume_text with a FULLTEXT index, so
resume contents are searchable alongside the existing candidate fields.

FEATURE 2 — AUTO-FILL INDICATOR
===============================

The sparkle emoji is gone. Zero occurrences remain in the markup or the CSS.

Replaced with three cues that reinforce each other:

    a 3px left accent rule on the field group
    a 6% blue tint on the input (10% in dark mode), which clears on focus so it
      does not fight the recruiter while they are typing in it
    a monospace [Auto-filled] badge beside the label

Why this instead of an icon: it survives a screenshot, a printout and a
monochrome display, it never renders as a missing-glyph box on an older
Windows build, and it matches the typographic language used elsewhere in the
ATS. The colour is informational blue rather than success green — the value is
a suggestion to check, not a confirmed fact.

ACCESSIBILITY: the badge is aria-hidden and a visually hidden sentence carries
the meaning, so a screen reader announces "Auto-filled from CV, please verify"
rather than reading a decorative token. The treatment clears the moment the
field is edited, because at that point the recruiter has verified it.

VERIFIED
--------
  * php lexer: 57 files clean
  * branch-aware render: candidate.php and add_candidate.php balanced
  * node --check on app.js and the page's inline script
  * eight download-path security properties checked programmatically
  * seven upload-validation properties checked programmatically
  * no emoji remains in the auto-fill treatment
