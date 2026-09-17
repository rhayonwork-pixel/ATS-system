Candidate profile: layered architecture, and a real duplication bug fixed
==========================================================================

NO MIGRATION NEEDED
--------------------
Two new include files, edits to candidate.php, assets/styles.css and
assets/theme-tokens.css. No schema change.

READ THIS FIRST — A REAL BUG, NOT A STALE COMPLAINT
-----------------------------------------------------
Before touching architecture, I checked whether "duplicate candidate profile
data and redundant resume/CV sections" was still true. It was, and it was
worse than cosmetic:

The page was rendering the ENTIRE header card twice in a row -- avatar, name,
rating form, stage badge, stepper, application number, all of it, byte-for-
byte identical, back to back. Sitting between the two copies was a raw
"Resume / CV" bar reading directly from candidates.resume_path with an
UNAUTHENTICATED link straight at the stored path -- bypassing download.php and
the candidate_documents table entirely, the system built specifically to be
the single source of truth for documents. That bar was ALSO duplicated. A
stray extra <div class="page-container candidate-profile-page"> opened a
second, nested copy of the page's own size-container.

This was mine -- almost certainly a copy/duplicate artifact from one of the
large scripted string-replacements I ran on this file across earlier turns of
this conversation, the same class of mistake (imprecise boundaries in a
find-and-replace over a huge PHP+HTML file) that caused the parse error I
fixed just before this request. Sorry it was still there.

Fixed: exactly one header card remains. The raw, unauthenticated resume bar is
gone -- not hidden, deleted -- and the one real Resume/CV component (the
authenticated, versioned one built earlier: download.php + candidate_documents
+ inline PDF viewer) is now the single place a resume is offered anywhere on
this page. A candidate whose resume predates that table (resume_path set,
no candidate_documents row) gets a "Legacy upload" fallback INSIDE that same
component, not a second one.

ARCHITECTURE
------------
Three layers, as asked, mapped onto this project's actual conventions (a flat
includes/ directory of function libraries, page files as controllers+views --
every one of the other 58 pages in this app follows that shape, so a one-off
MVC framework for just this page would be the inconsistency, not the fix):

    includes/candidate_dal.php         Data Access Layer
      Every query for this page, and nothing else. Each function runs one
      parameterized statement and returns raw data (array, null, or bool for
      writes). No RBAC, no derived values, no HTML.

    includes/candidate_view_model.php  Presentation logic
      build_candidate_view_model($pdo, $applicationId) is the ONE function
      that assembles a request's worth of data: calls the DAL, applies
      business rules (screeningDone/interviewDone, stage-rank), computes
      presentation constants, and returns either ['ok'=>true, ...] or
      ['ok'=>false,'error'=>'<friendly message>']. Never lets an exception
      escape to the page.

    candidate.php                      Controller + View
      POST handler: unchanged behaviour, but every branch now calls a DAL
      write function instead of inline SQL.
      GET path: calls build_candidate_view_model() exactly ONCE, checks
      ['ok'], then renders. The 600-line markup body itself is UNCHANGED from
      before this refactor -- deliberately. It was already tested, and I was
      not going to rewrite working, verified markup in the same pass as a data-
      layer change on the file that broke once already this conversation. The
      bridge between the two is one explicit block assigning $vm's keys to the
      same variable names the markup already used ($candidate, $documents,
      $screeningDone, ...) -- verifiable by inspection, and I verified it: a
      static scan confirmed every variable the view references is either
      assigned in that bridge or bound by its own foreach loop; none are
      undefined.

    Confirmed by direct count: zero pdo->prepare / pdo->query calls remain in
    candidate.php. Every query lives in the DAL.

SECURITY
--------
  * require_login(['admin','recruiter','hiring_manager']) -- unchanged, still
    the first thing that runs.
  * check_csrf() -- unchanged, still the first line of the POST handler.
  * 100% prepared statements -- already true before this refactor (verified:
    every query in the original file used ->prepare()->execute()); the one
    static, zero-parameter SELECT (departments) now also goes through
    prepare()->execute() for consistency, though it carried no injection risk
    either way.
  * Friendly errors -- the primary fetch was already wrapped for a missing-
    column fallback; that is now inside candidate_dal_fetch() with a second
    layer of try/catch in the view-model, so ANY database failure -- primary
    record or a secondary panel -- produces "Unable to load candidate data.
    Please try again later." on the page, never a raw PDO message or a stack
    trace. A secondary-panel failure (say, the activity log query) degrades
    that one section to empty rather than failing the whole page.

CANDIDATE STATUS BADGES
------------------------
The brief asks for New/Reviewing/Interviewed/Hired/Rejected with specific
colour families. This app's pipeline already has ONE badge function --
stage_badge() in includes/config.php -- used on this page, candidates.php,
pipeline.php and analytics.php, built on the stage values that are also the
dropdown options, the analytics filters, and the stage_reviews.stage_type
enum: new/screening/interview/offer/hired/rejected.

Renaming the stage NAMES on just this page ("Reviewing" for screening,
"Interviewed" for interview) would create a second vocabulary for the same
underlying value -- the exact kind of duplication this refactor exists to
remove, and it would desync this page from every other one. The names are
kept; only the COLOURS changed, and only in the one shared place, so every
page that calls stage_badge() gets the same result:

    new         blue/purple family  (--status-info tokens)
    screening   amber               (--status-warning tokens)  ["Reviewing"]
    interview   teal (new token)    (--status-teal tokens)     ["Interviewed"]
    offer       purple              (kept distinct; not named in the brief)
    hired       green               (--status-success tokens, unchanged)
    rejected    red/crimson         (--status-danger tokens, unchanged)

Teal did not exist in the token set (success/warning/danger/info), so it was
added once, to all three theme blocks (light, explicit dark, OS-preference
dark) in theme-tokens.css -- not as a one-off hex value on the badge rule.

The old dark-mode treatment was a blanket filter:brightness/saturate hack on
every badge; with five of six colours now real per-theme tokens (offer gets
its own explicit dark override), that hack was removed rather than left
active on top of colours it would now distort.

Contrast measured, not assumed: the new teal badge's text-on-tinted-background
composites to 4.64:1 in light mode and 5.84:1 in dark, both above the 4.5:1
requirement. The other four families reuse tokens already verified earlier in
this project.

IN-PAGE NAVIGATION
-------------------
The brief asks for "tabs or smooth-scroll sections." A tab system would hide
every section but one behind a click -- which directly reverses explicit
direction given earlier in this same conversation (two wireframes, signed off
across several turns, specifically asking for full natural scroll with every
section always visible and no sticky positioning). I implemented the other
half of the brief's own "or": a slim anchor-link strip -- Profile & Contact,
Resume/CV, AI Analysis, Interview History, Activity -- that smooth-scrolls to
each section. Every section stays visible and reachable regardless of which
link was used; clicking one only moves the viewport. It is NOT sticky: it
scrolls away with the page like the rest of the header, matching the layout
this page was already built to.

The brief's six-item list (Profile Summary / Contact / Skills / Experience /
Education / Resume Viewer) does not match this app's actual information
architecture, which groups Personal/Application/Recruiter-Notes into three
columns and has real features the generic list omits entirely (AI analysis,
interview history, stage reviews, activity log, role suggestions). The
quick-nav links to the sections that actually exist rather than inventing
four new subsections that would just re-slice fields already shown in
Personal Info.

ASYNCHRONOUS UX / LOADING STATE
---------------------------------
This page is server-rendered; nearly everything on it is present at load,
with no client-side fetch to show a skeleton FOR. Fabricating loading states
for content that is not actually loading asynchronously would be dishonest UI
-- a skeleton that never reflects a real wait teaches people to ignore it.

The one genuinely async operation on the page is the resume iframe, which
loads on demand (View press) rather than on page load, specifically so a
profile that is only skimmed never fetches the PDF. That load now shows a
shimmering skeleton, removed on the iframe's own `load` event -- not a fixed
timer -- so it is shown for exactly as long as the fetch actually takes.
Respects prefers-reduced-motion.

DEPENDENCIES
-------------
None added. The brief lists PDF.js and Office/Google Docs Viewer as options;
neither was adopted, consistent with the decision made earlier in this same
project:

  * The inline viewer is a plain <iframe> pointed at download.php with an
    inline Content-Disposition, using the browser's own native PDF renderer.
    It already renders text, formatting and layout correctly for every
    mainstream browser, needs no library, and stays inside the authenticated
    download path rather than handing a file to a third-party viewer.
  * PDF.js would add a non-trivial JS dependency to a project that otherwise
    has zero (no Composer, no npm build step, no CDN framework).
  * Microsoft Office Web Viewer / Google Docs Viewer both require uploading
    the document's URL to a third-party service to render it -- which is
    incompatible with keeping resumes behind authentication; either service
    would need public, unauthenticated access to the file to preview it.

VERIFIED
--------
Given this exact file broke once already this conversation, verification here
was deliberately heavier than usual -- seven independent methods, all passing
against the FINAL file state:

  1. PHP lexer across all 59 project files (braces/strings/heredocs)
  2. An independent, paren-aware if/endif walker (the tool that caught the
     earlier orphaned-endif bug) -- zero problems, nothing unclosed
  3. The branch-aware HTML renderer, sampled across 131,072 real combinations
     of this file's conditionals -- every one balanced
  4. Static tag counts cross-checked against the pre-refactor file to confirm
     any remaining discrepancy is an existing if/else-arm artifact (verified
     by tracing it to its exact line), not a new one
  5. CSS brace-balance on both stylesheets
  6. Every inline <script> block in candidate.php, and app.js, parsed with
     `node --check`
  7. The unrelated sidebar flyout and nav-pill regression suites built earlier
     in this project re-run to confirm the CSS/token changes did not disturb
     them -- 17/17 and 14/14

Also confirmed directly rather than assumed: zero raw SQL remains in
candidate.php; RBAC and CSRF are byte-identical to before; stage_badge()'s
function body and every call site are unchanged.
