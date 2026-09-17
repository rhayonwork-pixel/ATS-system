Unified resume storage across every intake route, and the list-view bug
=========================================================================

NO NEW MIGRATION -- database/migration-resume-storage.sql (already provided
in an earlier round) must be run if it has not been. Nothing in this change
requires a NEW migration; it makes the EXISTING candidate_documents table the
single place every route writes to.

WHAT I VERIFIED BEFORE CHANGING ANYTHING
------------------------------------------
Given this conversation's history, I read the actual current code rather than
assume anything, for every claim in the brief:

  1. "Resumes are not syncing" -- TRUE, and worse than described. apply.php
     (external applicants) and add_candidate.php (internal HR/Admin, from an
     earlier round) were using two ENTIRELY SEPARATE storage systems:

         apply.php          save_resume_upload()   candidates.resume_path
                             writes into assets/uploads/resumes/ --
                             INSIDE the public web root, no authentication,
                             "security" was purely an unguessable filename.

         add_candidate.php   store_candidate_document()   candidate_documents
                              writes into storage/resumes/ -- outside any
                              directly-servable path, every read gated by
                              download.php's require_login().

     These were not just "inconsistent"; the older path was a real security
     gap (public, unauthenticated file storage) that the newer one was
     already built to fix for one route only.

  2. "candidates.php shows None even when a CV was uploaded" -- TRUE, and for
     exactly the reason implied: the Resume column checked ONLY resume_path,
     which add_candidate.php's uploads never populate (they go into
     candidate_documents instead). Confirmed by reading the actual query and
     table cell, not assumed.

  3. "Candidate profile needs a strict 7-row layout" -- ALREADY TRUE. This
     exact structure (header, Resume/CV strip, 3-column info row, AI
     Analysis, 2-column Feedback/Suggest, Interview History, Activity) was
     built and verified in an earlier round of this same conversation. I
     re-verified the render order programmatically before writing anything
     rather than rebuild a page that was already correct -- doing so again
     would only have reintroduced risk to a file that has already broken
     once this conversation from large scripted edits. Nothing in the layout
     needed to change.

WHAT WAS ACTUALLY CHANGED
---------------------------

1. includes/documents.php
   store_candidate_document()'s $userId parameter widened from `int` to
   `?int = null`. Every existing caller (parse-cv.php, add_candidate.php)
   already passes a real signed-in staff id, so their behaviour is identical.
   This only ENABLES a new legitimate caller: apply.php, where there is no
   signed-in user -- the uploader is the applicant themself. The database
   column (uploaded_by) was already nullable; only the PHP type hint was
   overly strict.

2. apply.php
   The resume upload no longer goes through save_resume_upload(). It is
   validated for presence, and everything else about it (size, type, MIME
   sniffed from the actual bytes, storage, and the candidate_documents row)
   is now handled by the exact same store_candidate_document() function
   add_candidate.php already used -- one function, one table, one storage
   location, regardless of route.

   This required reordering the request: store_candidate_document() needs a
   candidate_id, which does not exist until the candidate/application rows
   are written. The document is now stored AFTER those inserts succeed,
   inside the same database transaction (db() is a singleton connection, so
   the document's own INSERT participates in that transaction and rolls back
   with everything else if anything after it fails). If the resume storage
   step itself fails, the whole transaction rolls back -- matching the
   original guarantee that a failed resume upload never leaves an orphan
   candidate or application row behind.

   resume_path is no longer written by apply.php at all, in either the
   INSERT or UPDATE branch. It is untouched going forward and remains only as
   read-only legacy data for candidates who predate this change (exactly the
   "Legacy upload" fallback candidate.php's Resume/CV section already shows
   when there is no primary document).

   A real mistake I caught before finishing, not after: store_candidate_
   document() catches its own database errors and returns a message string
   rather than throwing -- which meant a missing candidate_documents table
   (migration not yet run) would have surfaced its raw SQLSTATE error text
   directly to a public, unauthenticated applicant, and would have blocked
   EVERY submission outright rather than degrading gracefully. Fixed: that
   specific failure is now detected (checked against the real MySQL "table
   doesn't exist" message format) and replaced with the same kind of
   administrator-facing message this file already uses elsewhere for a
   missing migration, never a raw database string.

3. candidates.php
   The list query gained one correlated subquery -- the same pattern already
   used elsewhere in this codebase for per-row lookups, not a per-row PHP
   loop, so there is no N+1 query cost for a list of any size:

       (SELECT cd.id FROM candidate_documents cd
        WHERE cd.candidate_id=c.id AND cd.is_primary=1 LIMIT 1) primary_doc_id

   The Resume cell now checks that first (linking to download.php, the
   authenticated route), and falls back to the legacy resume_path link only
   when there is no primary document -- never both, never neither when a
   file genuinely exists.

   Defensive fallback: if candidate_documents does not exist yet on this
   install, the subquery throws, and the query is transparently re-run
   without it rather than showing a raw SQL error on every visit to this
   page. The existing display logic already handles a missing
   primary_doc_id correctly (falls through to the resume_path check), so no
   further change was needed there.

4. pipeline.php (found while investigating, not named in the brief)
   The Kanban board's candidate-preview modal had the EXACT SAME bug --
   `'resume' => $r['resume_path']` only, feeding a JS `data.resume` value that
   is used directly as a link href (confirmed by reading assets/pipeline.js).
   Fixing candidates.php while leaving this page with the identical bug would
   have relocated the inconsistency rather than removed it, directly against
   the brief's own stated goal ("appears consistently across the entire
   platform"). Same subquery, same fallback pattern, same preference order
   applied here. No JavaScript changes were needed -- the field was already
   rendered as a plain href, so a download.php URL works exactly like the
   legacy raw path did.

WHAT I DELIBERATELY DID NOT TOUCH
------------------------------------
includes/config.php's generate_ai_analysis() -- a clearly-labelled SIMULATED/
prototype heuristic -- reads resume_path as one signal among several to bump
a fake experience score. After this change, a candidate whose resume came in
through the new system will not trigger that particular nudge. This is a
minor, prototype-only quirk (the "AI analysis" was never a real model call to
begin with), and rewriting that function's internals was not part of this
task and carries its own regression risk for a feature the brief did not
mention. Flagging it here rather than silently leaving it or silently
fixing it without saying so.

save_resume_upload() (includes/config.php) is no longer called from
anywhere -- confirmed by a project-wide search. It is left in place rather
than deleted, since removing a function is a slightly higher-risk edit than
leaving an unused one, and deleting it was not asked for.

VERIFIED
--------
  * PHP lexer across all 59 project files
  * an independent, paren-aware if/endif walker on every changed file
  * the branch-aware HTML renderer on every changed page
  * app.js, pipeline.js, and every inline <script> in candidate.php parsed
    with node --check
  * a project-wide grep confirms zero remaining writes to resume_path
    anywhere, and zero remaining callers of save_resume_upload()
  * the "table doesn't exist" detection was checked against MySQL's actual
    error message format, not assumed
  * the two unrelated regression suites built earlier in this project
    (sidebar flyout, nav pill) re-run clean, confirming nothing else moved
