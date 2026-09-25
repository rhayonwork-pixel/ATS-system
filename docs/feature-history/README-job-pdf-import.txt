PDF job description -> job posting
==================================

MIGRATION 019 IS REQUIRED
-------------------------
database/migrations/019_job_pdf_import.sql adds three columns to `jobs`:
original_pdf_path, benefits, application_deadline. Safe to re-run.

FILES
-----
  config/job_parse_rules.json        the shared vocabulary — read by BOTH engines
  tools/extract_job_data.py          NEW — Python parser (pdfplumber/PyMuPDF/pypdf)
  tools/requirements.txt, README.md  NEW — how to set it up, and how to tune it
  app/Services/JobDescriptionParser.php  NEW — validation, storage, engine choice
  upload-parser.php                  NEW — the AJAX endpoint
  job-attachment.php                 NEW — staff-only delivery of a stored PDF
  job-post.php                       dual entry, review step, two new fields
  assets/js/job-post-import.js       NEW — dropzone, upload, auto-fill, toasts
  assets/css/job-post-import.css     NEW
  includes/pdf_extract.php           rules now shared; letter-spacing repair (below)
  storage/job_attachments/           NEW — where uploaded PDFs live

THE TABLE IS `jobs`, NOT `job_posts`
------------------------------------
The request asked for `original_pdf_path` on a `job_posts` table. No such table
exists and never has — postings live in `jobs`, and every page, query and
foreign key points there. A second table for the same rows would be a bug, so
the column went on `jobs`.

`jobs.source_pdf` already existed and held a PUBLIC path under
assets/uploads/job-descriptions/, which the web server serves to anyone who
guesses the filename — a draft job description, with its salary band, was one
URL away. New imports write `original_pdf_path` instead, which is a bare
filename inside storage/job_attachments/ (not web-servable), delivered by
job-attachment.php after a permission check. `source_pdf` is kept, backfilled
with a "legacy:" prefix, and is now read-only legacy data — the same treatment
candidates.resume_path got in migration 004.

TWO ENGINES, ONE VOCABULARY
---------------------------
Preferred: tools/extract_job_data.py, run as a child process. It sees font
size, weight, indentation and table cells.

Fallback: includes/pdf_extract.php + jd_parse_fields(), which runs anywhere PHP
does. This is not a theoretical path — InfinityFree has no Python and usually
disables proc_open, so shared hosting will use it.

Both read their synonyms from config/job_parse_rules.json. Teach a heading
there and both engines learn it; teaching only one is a bug. The PHP maps keep
their old hardcoded lists as a safety net for an install where the JSON is
missing, so parsing degrades instead of failing.

The field map between parser output and form inputs is also defined once, as
JOB_FIELD_MAP in job-post.php, and handed to the browser in the page's config
block — so the AJAX import and the no-JavaScript import cannot fill different
boxes from the same PDF. They were verified to fill the same 11 fields.

WHAT THE PARSER ACTUALLY HAD TO SOLVE
-------------------------------------
  1. BULLETS ARE USUALLY NOT CHARACTERS. Chrome's Print to PDF, Word and
     InDesign draw list markers as vector art or in a separate font run, so an
     exported job description contains indented text and no bullet glyph to
     find. Lists are detected from indentation past the DOCUMENT's body margin
     (not the section's: where every line of a section is a bullet, they share
     a margin and none looks indented).
  2. WRAPPED LINES ARE NOT NEW ITEMS. A long bullet breaks across lines with no
     marker and the same indentation. Lines starting in lower case, or
     following a line that ran the full width of the column, are joined back
     on. Ragged right margins mean "full width" has to tolerate about 8% of the
     column, roughly four characters.
  3. HR FORM TEMPLATES ARE TABLES. "Position Title | Accounting Assistant" in a
     table is the most reliable signal in the document, and also the one that
     breaks a line-based reader: grouping words by y position glues the label
     to the value and to whatever sits beside them. Tables are read first with
     pdfplumber's table finder, their words are then excluded from the line
     flow, and the label/value pairs win over anything in the running text.
  4. DATES THAT MEAN TWO THINGS. 30/11/2026 can only be day-first and
     11/30/2026 can only be month-first, so one number over 12 settles it.
     03/04/2026 is genuinely ambiguous and is NOT guessed: the raw phrase is
     shown next to the date field and the recruiter sets it. A confident wrong
     date is worse than a blank one.
  5. "Deadline" APPEARS IN SENTENCES. An earlier version searched the whole
     document for the word and matched "able to meet deadlines" in a
     qualifications bullet, filing the next few words as the closing date. The
     search is now anchored to a line that STARTS with a deadline word.
  6. TITLES CARRY ADVERTISING. "We're hiring a Product Designer" becomes
     "Product Designer". The stopword list rejects a line it essentially IS
     ("Job Description"), not any line containing a stopword — matching
     anywhere threw away the real title.

A BUG FIXED IN THE BUNDLED PHP READER
-------------------------------------
On PDFs that position every glyph individually, pdf_extract_text() returned
"S e n i o r B a c k e n d E n g i n e e r" — and reported quality "good",
because its only check was for too FEW spaces. The recruiter got silent
garbage in every field.

The cause: the reader estimates each glyph's advance as a fixed fraction of the
em (it does not read per-character /Widths), so a wide letter advances further
than the estimate and the leftover looks like a word break. It now measures the
share of one-character tokens and, when that is high, re-reads the same content
streams at wider word-break thresholds, keeping the result with the fewest
loose letters that still has a believable number of spaces. On the sample set
that turns 94% single-letter tokens into 3%, and the fallback goes from
unusable to correct for the standard layout. A file that read correctly the
first time never enters the retry. The quality flag now catches both failure
modes.

VALIDATION AND SECURITY
-----------------------
  * extension .pdf, size <= 5MB, and the MIME type re-read from the BYTES with
    finfo — the browser's declared type is not trusted
  * %PDF- header, %%EOF trailer, and at least one object or an xref: rejects a
    renamed executable, a truncated download, and an HTML error page saved as
    .pdf
  * active content (/JavaScript, /JS, /Launch, /EmbeddedFile, /OpenAction) is
    refused. A job description has no use for any of it, and a reader opening
    the file later might
  * stored under a generated name; the name is validated and the resolved path
    must stay inside storage/job_attachments, so a tampered value cannot escape
  * the child process is started with an ARRAY argv, so no argument passes
    through a shell, and it is killed after 25 seconds
  * the endpoint requires a login, the "Job posting" permission and a CSRF
    token, and it writes nothing to the database

This is structural validation, not virus scanning, and it does not pretend to
be. It rejects the shapes this app can be attacked with through this field.

UI
--
A two-card chooser at the top of job-post.php: "Upload PDF description" or
"Fill in manually". The tab bar is hidden in the markup and revealed by
JavaScript, so it never appears as a dead control when scripting is off.

The existing manual form is untouched and is the review step. A banner says how
many fields were filled. (The per-field badge and tint this originally shipped
with were removed later — see README-job-posting-refactor.txt; a parsed field
is now visually identical to a typed one and the status light is the only
per-field signal.) Editing a field clears its
badge, so what is left marked is what has not been checked yet. Three error
codes get three distinct toasts, because "something went wrong" does not tell a
recruiter whether to try another file, retype the posting, or call IT.

Nothing is saved by uploading. The PDF is attached to a posting only when a
save button is pressed, and the file is deleted if it could not be parsed.

WHAT WAS VERIFIED
-----------------
Headless Chrome against the local PHP server and MySQL, with three PDFs built
to the three shapes in the brief (standard labelled sections, narrative prose,
HR form template) plus a text-layer-free "scan" and a text file renamed .pdf.
21 of 21 assertions:

  * tabs revealed, manual form intact, benefits + deadline fields present
  * standard PDF: 11 fields filled, correct title, department matched to a real
    row, 4 responsibilities as 4 lines, required vs preferred split, benefits,
    deadline as 2026-10-31, and the salary's en dash intact (the subprocess
    boundary is UTF-8, which Windows Python gets wrong by default)
  * 11 fields badged and highlighted; editing one clears its badge
  * the stored token reaches the form, the draft saves, and the saved page
    links to the PDF
  * scan -> ERR_UNREADABLE, text file -> ERR_FILE_FORMAT, narrative ->
    ERR_MISSING_FIELDS with the fields it did find still filled
  * no console errors

Separately, by request:
  * the no-JavaScript path fills the same 11 fields with the same badges
  * job-attachment.php serves a stored PDF to staff, 302s to login when signed
    out, and 404s a traversal attempt or a made-up filename
  * with tools/extract_job_data.py removed, the PHP fallback still imports the
    standard PDF correctly (title, department, type, salary, responsibilities)

NOT VERIFIED
------------
The sample ZIP the request mentions had not arrived, so the corpus is three
PDFs written here, all produced by Chrome. Real-world job descriptions from
Word, Google Docs, Canva and scanners will exercise paths these do not, and the
rules file is where most of that tuning belongs. Also untested: PyMuPDF and
pypdf engines (only pdfplumber is installed), OCR of scans (not implemented —
a scan is reported, not read), multi-column layouts, and non-English documents.

Note that `php -S` does not honour .htaccess, so storage/ appears readable on
the dev server. Under Apache (XAMPP, InfinityFree) storage/.htaccess denies it.
That is pre-existing and identical for storage/resumes.

QA CHECKLIST
------------
[ ] Import a Word-exported and a Google-Docs-exported job description.
[ ] Import a two-page description; check nothing from page 2 is lost.
[ ] A scanned PDF gives the "no text layer" toast and nothing is stored.
[ ] Upload a 6MB PDF: refused before anything is parsed.
[ ] Switch to "Fill in manually" and save a posting with no PDF at all.
[ ] Import, edit two fields, save a draft, reopen: the edits persist and the
    attached PDF opens from the review banner link.
[ ] Submit an imported posting for approval; the approval queue is unchanged.
[ ] Turn JavaScript off and import again: same fields, same badges.
[ ] On the shared host (no Python): the importer still works and says so in
    storage/logs/job-parser.log.
[ ] Check the deadline field on a PDF that writes dates as 03/04/2026 — it
    should stay empty with the raw phrase shown beside it.
