Add Candidate module, CV parsing, and light-mode refresh
========================================================

SETUP
-----
Import database/migration-add-candidate.sql (the ninth migration).

THREE PLACES THE BRIEF DID NOT MATCH THIS CODEBASE
--------------------------------------------------
1. POST-SUBMIT ROUTING. The brief routes to "the candidate's profile.php". In
   this ATS profile.php is the signed-in STAFF member's own account page. The
   candidate profile is candidate.php?id=<application_id>, so it routes there —
   or to candidates.php when no target position was chosen, since without an
   application there is no candidate profile to open.

2. $_SESSION['role']. This app has no such key; the role lives on the user row
   and is read through current_user(). That is also the safer source: a
   session-held role would keep working after an Admin revoked it, until the
   person happened to sign out.

3. audit_logs "create/update". It already exists with a richer shape than the
   brief specifies — id, user_id, action, entity_type, entity_id, details as
   JSON, ip_address, created_at. Left untouched. The submission logs
   'candidate_added_manually' with details {name, source, cv_parsed, added_by},
   which is the brief's 'CV Parsed: Yes' in structured form.

CV PARSING — REAL, NOT SIMULATED
--------------------------------
The brief suggested smalot/pdfparser and PhpWord. There is no Composer here, so
neither exists. Nothing is faked:

    PDF   includes/pdf_extract.php — the self-contained reader built earlier for
          the job-description importer, including ToUnicode CID font handling
    DOCX  ZipArchive + word/document.xml, which is all a .docx is. Paragraph and
          break tags become newlines before stripping, so the line-based
          heuristics still have structure to work with.
    TXT   read directly, with a Windows-1252 fallback for non-UTF-8 files

Heuristics extract: full name, email, phone, current role, skills, education,
and experience level (inferred from a stated number of years).

TESTED, NOT ASSUMED. I ported the regexes and ran them against a realistic CV.
All seven fields parsed correctly:

    full_name         Maria Santos
    email             maria.santos@example.com
    phone             +63 917 555 0142
    current_title     Senior Backend Engineer
    experience_level  senior          (from "7 years of experience")
    skills            php, laravel, mysql, redis, docker, aws, rest, git
    education         Bachelor of Science in Computer Science, ...

Skills come from a known vocabulary plus anything listed under a Skills
heading, so domain terms the vocabulary does not know still get picked up.
The name heuristic requires 2-4 Title-Case words in the first eight lines, and
skips headings and anything containing digits or an @.

The file is parsed and discarded. Nothing is written to the database until the
recruiter reviews the values and submits.

AUTO-FILL REVIEW
----------------
Every auto-filled field gets a tinted background, a matching border and a
"✨ Auto-filled" chip beside its label. The highlight clears the moment that
field is edited — it is a prompt to check the value, not a permanent badge.

Errors are specific rather than generic: unsupported format, corrupted DOCX,
missing zip extension, file too large, and "Could not extract data — please
enter the details manually" for a scanned CV with no text layer.

THE FORM
--------
Three sections in a two-column grid (single column under 720px of CONTAINER
width, so it reacts to the sidebar state rather than the viewport):

    Personal      full name*, email*, phone
    Professional  current role, experience level, skills, education
    Application   source, target position, recruiter notes

Skills is a tokenised chip input. The chips are a view over a hidden input,
which is what actually posts — so with JavaScript off the field still works.

Inline validation runs on blur, then live once a field has been corrected —
not on every keystroke of a half-typed address. Email and phone have their own
patterns, and the same rules are enforced again server-side.

Sticky bottom action bar: Cancel, Save as draft, Submit candidate. A draft only
needs a name and stores record_status='draft'; a submission honours the
configured required fields.

DUPLICATE HANDLING (not in the brief, but necessary)
----------------------------------------------------
candidates.email is indexed and the same person is often considered for several
roles. Submitting an email that already exists UPDATES that candidate rather
than creating a second record, and the application is only created if one does
not already exist for that job. Without this the module would quietly fill the
pipeline with duplicates.

ADMIN FIELD SETTINGS
--------------------
Admins get a "Field settings" panel to mark optional fields mandatory. Full
name and email are always required and cannot be switched off. Stored as JSON
in the existing settings table under candidate_required_fields, and the change
is audited. The requirement is enforced server-side, not just by the asterisk.

LIGHT-MODE REFRESH
------------------
Adopted from the brief: --bg-canvas #f8fafc, --bg-surface #ffffff,
--text-primary #0f172a, --text-secondary #475569, --border-default #e2e8f0,
and layered card elevation (--elevation-card) so surfaces lift off the canvas.

NOT adopted: --accent-primary #2563eb. This is the third brief to specify blue,
so to be explicit — ACME's identity is green and the accent drives buttons,
links, focus rings and active states across all 55 pages. Changing it is a
rebrand, not a polish pass, and it is one line if you want it:

    assets/theme-tokens.css, :root { --accent-primary: #2563eb; --accent-hover: #1d4ed8; }
    (and in the dark blocks, a lighter blue such as #60a5fa)

Say the word and I will switch it properly, including re-running the contrast
audit against every surface.

CONTRAST RE-VERIFIED after the refresh, by computing WCAG luminance:

    on white     text-primary 17.85  secondary 7.58  muted 4.65
                 placeholder 4.55    accent 6.29
    on canvas    text-primary 17.06  secondary 7.24
    input border 3.23:1 (UI components need 3:1)

All pass.

VERIFIED
--------
  * php lexer: 55 files clean
  * branch-aware render: add_candidate.php and candidates.php balanced
  * node --check on the page's inline script and on app.js
  * parsing heuristics tested against a sample CV: 7/7 fields correct
  * contrast computed, not asserted
  * migration avoids `current_role`, which is reserved in MySQL 8 — the column
    is current_title
