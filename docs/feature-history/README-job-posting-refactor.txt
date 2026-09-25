Job posting module refactor: DOCX, pasted text, sanitisation, status lights
===========================================================================

Builds on README-job-pdf-import.txt (the PDF importer). Read that one first.

MIGRATION 020 IS REQUIRED
-------------------------
database/migrations/020_job_company_name.sql adds jobs.company_name. Safe to
re-run. Migration 019 is still required for original_pdf_path, benefits and
application_deadline.

FILES
-----
  includes/text_sanitize.php         NEW — emoji stripping, bullet normalising
  includes/docx_job_text.php         NEW — DOCX -> job text, over the reader below
  includes/docx_extract.php          UNCHANGED — the existing resume-preview reader
  app/Services/JobDescriptionParser.php  DOCX + pasted text + sanitised output
  upload-parser.php                  accepts job_text and .docx
  job-post.php                       3-way entry, company field, lights, Clear form
  assets/js/job-post-import.js       sanitising, lights, clear+undo, validation
  assets/css/job-post-import.css     lights, spinner, responsive grid
  config/job_parse_rules.json        company label synonyms
  database/migrations/020_*.sql      NEW

THE FILE IS job-post.php
------------------------
The request names job-posting.php. There is no such file and never has been;
the module is job-post.php. Creating a second page would fork the routing, the
approval workflow and the permission checks, so everything below is in the file
that already exists.

DOCX: REUSING THE READER THAT WAS ALREADY HERE
----------------------------------------------
includes/docx_extract.php ALREADY read .docx files without the zip extension
(which is absent from this XAMPP build) for the resume preview that
document-preview.php serves. It walks the zip central directory by hand,
inflates word/document.xml with zlib, guards against zip bombs, refuses Zip64
and parses with DOMDocument.

That file is untouched. includes/docx_job_text.php is a thin layer over it:
it calls docx_zip_entry() for the XML and adds the two things a job
description needs that a resume preview does not —

  * TABLES AS LABEL/VALUE PAIRS. Every HR "job description form" is a
    two-column table. Read as a flat paragraph list, "Position Title" and
    "Accounting Assistant" become two unrelated lines and the pairing — the
    actual information — is lost.
  * LIST MARKERS. Word records that a paragraph is a list item (<w:numPr>) and
    draws the bullet itself, so no glyph is in the text. It is added back as
    "- " for jd_parse_fields() to see a list.

DOCX is the easy format and it never needs Python. A PDF has lost its structure
by the time it is written — headings, lists and tables are all just positioned
glyphs — which is why the PDF path infers them from font size and indentation.
A DOCX still says what everything is: <w:numPr> means "list item", <w:pStyle>
means "heading", <w:tbl> means "table". Those are read directly, and a two-cell
table row is emitted as "Label: value", which the existing parser already
understands. So the DOCX path is more accurate than the PDF path and runs
anywhere PHP does.

PASTED TEXT — BUILT, THEN REMOVED
---------------------------------
A "Paste description" panel shipped briefly and was then deprecated by request,
to push people towards either the structured fields or the document upload.
Removed with it: the panel and its textarea, the tab, the JavaScript handler,
job-post.php's `text_extract` POST branch, upload-parser.php's `job_text`
branch and JobDescriptionParser::parseText().

Both remaining sources — PDF and DOCX — still return the same contract from one
mapper (JobDescriptionParser::fromExtraction), so they cannot drift apart in
what they fill.

Anyone who used to paste a whole posting now either uploads the document or
pastes each section into its own field, which the per-field sanitiser cleans on
paste exactly as before. A stale form posting the old action is harmless: the
action matches no branch, and the page renders normally (verified).

SANITISATION
------------
includes/text_sanitize.php is the authoritative pass and runs inside
posted_job_fields(), so a POST that never met the browser (curl, a stale tab,
scripting off) is cleaned too. The JavaScript copy exists only so the person
sees the result immediately; it runs on paste, change and blur, never on every
keystroke, because rewriting a value mid-word moves the caret and feels broken.

What it removes: emoji and pictographs, dingbats, decorative arrows, enclosed
alphanumerics, variation selectors, ZWJ sequences, skin-tone modifiers, flag
pairs, private-use glyphs (where Wingdings bullets live), zero-width characters
and control characters.

What it keeps, deliberately: en and em dashes (salary ranges), smart quotes,
degree, plus-minus, multiplication, every currency symbol, and every script.
It must NEVER strip "non-ASCII" — that would corrupt "PHP 140,000 – 190,000",
"€", "₱", "Zoë", "Guðrún" and every non-English posting.

BULLETS ARE STRIPPED, STRUCTURE IS KEPT
---------------------------------------
strip_list_markers() removes the marker and keeps everything that carries
meaning. Markers: glyphs, hyphens, asterisks, pipes, and numbered/lettered/
roman lists (1.  2)  a.  (iv]). A marker only counts at the START of a line and
must be followed by whitespace, so "e-commerce", "1.5 million", "Full-time" and
"24/7" are never touched. Stacked markers are removed together.

What survives: the line break between items, and the INDENT as a nesting level.
Ragged source indentation is snapped to two spaces per level, so a sub-point
still reads as a sub-point without copying whatever the document contained.
A glyph in the MIDDLE of a line is a separator, not a marker, and becomes a
space ("Java • Python" -> "Java Python"); pipes left over from a table row
become commas.

Nesting comes from three places, one per source: <w:ilvl> in a DOCX, the x
position of the line in a PDF (the Python engine), and the leading spaces in
pasted or already-extracted text.

jd_parse_fields() had to change to make that work: it trimmed every line, which
flattened every nested list. It now keeps two forms of each line -- trimmed for
heading detection, original for section content.

BUGS FOUND AND FIXED WHILE BUILDING THIS
----------------------------------------
  1. Emoji stripping ran BEFORE bullet conversion, and most bullet glyphs
     (square, circle, check) sit inside the emoji ranges — so lists were
     silently flattened into unlabelled lines. Order reversed.
  2. U+00F0 was listed as a Wingdings bullet. It is the Icelandic letter eth:
     "Guðrún" came out as "Gu rún". Removed; the Wingdings bullets are in the
     private use area (U+F0B7 and friends).
  3. A DOCX reader was written from scratch here before noticing that
     includes/docx_extract.php already did the job, better -- and the new file
     replaced it, which would have broken the resume preview in
     document-preview.php. Restored, and the job-specific part moved into
     includes/docx_job_text.php as a layer over it. (Two bugs in that
     discarded reader are worth recording anyway, because both are easy to
     repeat: strrpos() with a NEGATIVE offset ENDS the search that many bytes
     from the end rather than starting there, so it found no central
     directory; and the central-directory field offsets were six bytes out, so
     it read the uncompressed size as the compressed one.)
  4. Required-field validation never ran: the browser's own `required`
     validation aborts submission BEFORE a submit event fires, so the handler
     that paints the red lights was never called. The form is now noValidate in
     JS (the attributes stay for the no-JavaScript path).
  5. focus() on an invalid field did nothing when the form panel was hidden
     behind another entry tab. The panel is revealed first.
  6. Clear All left eight orphaned "Auto-filled" badges: it stripped .is-auto
     from every field and THEN called clearMarks(), which looks for .is-auto to
     decide what to remove. clearMarks() now finds badges by their own class.
  7. A deadline read from a DOCX table row ("Application Deadline | December 5,
     2026") stayed raw text where the same date in a PDF became 2026-12-05. The
     PHP path now normalises it the way the Python path does.

REVIEW MODE — REMOVED, THEN REBUILT
-----------------------------------
The history matters, because it is easy to re-litigate:

  1. shipped with a "✨ Auto-filled" badge and a blue tint
  2. both removed on request -- the green status light was deemed enough and
     the sparkle was an emoji in a module whose job is stripping emoji
  3. rebuilt on request as REVIEW MODE, the current behaviour

Review mode today: a parsed field gets a 2px #3B82F6 border, a pale blue wash
and an "Auto-filled" badge in its label. Empty fields stay in the default
state, which is the entire point -- the contrast is what shows the reviewer
what the system did and what is still theirs to do.

The badge carries an inline SVG tick, NOT an emoji. That keeps the earlier
decision intact: emoji are stripped from posting content, so putting one in the
chrome announcing that cleanup would be odd. The badge is aria-hidden and a
visually hidden sentence ("Auto-filled from your document, please verify")
carries the state, so it never depends on seeing a colour or an icon.

The highlight clears when the reviewer puts the cursor in the field -- at that
moment they are verifying it, so the prompt has done its job. Clearing is
DELEGATED on the form (focusin + pointerdown) rather than bound per control,
because the no-JavaScript import renders its highlighted fields in PHP and
those never pass through the JS that would have bound a listener; a per-control
listener left them highlighted forever. focus does not bubble, focusin does.

The CSS needs !important, which is not a preference. The base input skin is
written as `input:not([type="checkbox"]):not([type="radio"])...` -- six
attribute selectors, specificity (0,6,1) -- and no class selector can outrank
it. components-light-fixes.css already overrides that same rule the same way.

The status light and the review highlight now coexist: the light says "this
field has content", the highlight says "the system wrote it and you have not
looked yet". The light survives; the highlight does not.

COMPANY NAME
------------
This ATS is single-tenant: the company is one row in `settings` and is the same
for every posting, which is why "company name" had nowhere to go. The new
column is NULLable and means "this posting is for a company other than us" — an
agency's client, a subsidiary, a trading name. NULL keeps every page showing
settings.company_name exactly as before, so nothing changes for a single-company
install. The field's placeholder is the org's own name and its hint says so.

STATUS LIGHTS
-------------
Grey #CBD5E1 empty, green #22C55E filled, red #EF4444 invalid — the brief's
colours. Each dot is aria-hidden and followed by a visually hidden word
("empty", "filled", "needs attention"), so the state never depends on seeing a
colour. Rendered server-side with the correct starting state, then updated by
JS on input (debounced 120ms), change and blur.

A field never collapses and never gains placeholder text when emptied: it stays
where it is and its light goes grey. Collapsing makes the form jump under the
cursor, and placeholder text that looks like a value is how a blank field gets
mistaken for a filled one.

The employment-type select is lit on an empty form. That is correct — it has a
real default (Full-time) and will be saved.

CLEAR FORM
----------
Confirmation modal (focus-trapped, Escape and backdrop close, focus returns to
the trigger), then a ten-second Undo toast. The undo is the real protection; a
confirm dialog mostly trains people to click through. Clearing an already-empty
form skips the dialog.

Cleared: every editable input, select and checkbox, all auto-fill badges and
tints, all field hints, the review banner, the attachment token, the dropzone
filename and the paste box. Not cleared: the CSRF token, job_id when editing,
and anything already saved — a saved posting and its attached file are untouched.

WHAT WAS VERIFIED
-----------------
Headless Chrome over CDP against the local PHP server and MySQL: 40 of 40
assertions, no console errors.

  * three entry options; DOCX and PDF both advertised and accepted
  * 16 status lights; grey on load (bar the select with a default), green on
    typing, grey again on delete, red after a failed submit, all grey together
    after Clear All; the hidden state word tracks the colour
  * emoji stripped on change; bullets normalised to "- "; en dash, ± and
    currency preserved
  * DOCX fills the form in 313ms; PDF in 808ms (budget: 3s)
  * an auto-filled field is computed-style identical to a typed one: no badge,
    no tint, no star anywhere in the page HTML
  * Clear: modal focus-trapped, Escape cancels without clearing, confirm empties
    everything, undo restores it
  * failed submit marks required fields red and focuses the first one
  * 320px: no horizontal overflow, single column, every target >= 44px
  * 900px: two columns for short inputs, textareas full width
  * keyboard focus paints a visible 2px ring

Separately, outside the browser:
  * the no-JavaScript paste import fills 7 fields with 7 badges and 7 lit lights
  * a PHP/MySQL round trip keeps "–", "±", "ø", "ä", "î" intact and removes
    every emoji (verified by reading the stored bytes back as hex)

NOT VERIFIED
------------
Real Word documents — the DOCX fixture is generated, so real-world Word output
(styles, numbering definitions, content controls, tracked changes) has not been
exercised. Nor have: .doc (the old binary format, explicitly refused), Google
Docs exports, Pages, scanned PDFs (reported, not read), screen readers, Safari
and Firefox. The sample ZIP mentioned in earlier requests has still not arrived.

QA CHECKLIST
------------
[ ] Import a real Word file saved from Word, Google Docs and LibreOffice.
[ ] Import a .doc and confirm the message tells you to save it as .docx or PDF.
[ ] Copy a section from a LinkedIn posting, emoji and all, into one field.
[ ] Type an emoji into every field and tab away; check the database row.
[ ] Empty each field in turn and watch its light go grey.
[ ] Clear the form, undo, then clear again and let the undo expire.
[ ] Submit with no title: the light goes red and the cursor lands in the field.
[ ] Fill the form on a phone in portrait; check nothing overflows sideways.
[ ] Save a posting with a company name and confirm the public page still reads
    correctly (job-detail.php does not show company_name yet — decide whether
    it should).
