Application status: top-down layout, horizontal stepper, My Applications
========================================================================

NO MIGRATION NEEDED
-------------------
No schema change. Everything shown was already stored: applications.cover_letter
and why_us, candidates.phone / portfolio_url / source, and candidate_documents
(migration 004). An install without 004 falls back to candidates.resume_path,
the same fallback candidate.php uses.

FILES
-----
  application-status.php              rewritten; also hosts ?action=detail
  includes/application-detail.php     NEW — the "what you submitted" panel,
                                      shared by the fetch and the no-JS path
  my-document.php                     NEW — candidate-facing file download
  includes/config.php                 3 new functions (see DATA below)
  assets/css/application-status.css   NEW — the whole page
  assets/js/application-status.js     NEW — accordion, skeletons, modal, and
                                      the interview-join poll moved out of
                                      the page's inline <script>
  routes/web.php                      documents the new routes

WHAT CHANGED IN THE LAYOUT
--------------------------
The page used to render two different things depending on how you looked
yourself up: with an id you got a status card with a vertical timeline beside a
"Current status" block; with only an email you got a flat list of matches and
nothing else. Both are gone.

There is now one page, in one order, at every width:

  header  ->  horizontal progress stepper  ->  My Applications

The stepper always describes ONE application — the one "in focus". With an id
in the URL that is the application asked for; with only an email it is the most
recent application that is still moving (not withdrawn, not rejected), falling
back to the most recent of any kind. Every other application is a card below,
and each card that is not in focus has a "View progress" link that re-focuses
the stepper on it.

Upcoming interviews stay INSIDE the progress section rather than becoming a
third band, because they belong to the application the stepper is describing.
That keeps the top-down rule literally true.

DATA
----
Three functions in includes/config.php, beside the ones that were already
there:

  candidate_applications_for_email()  the history list: job, department,
                                      location, stage, status, dates
  candidate_status_tone()             stage + status -> the label and tone a
                                      candidate is shown. new = Pending review
                                      (amber), screening/interview/offer =
                                      Under review / Interviewing / Offer stage
                                      (blue), hired = Accepted (green),
                                      rejected = Not selected (red), withdrawn
                                      = neutral. Recruiter vocabulary never
                                      reaches the candidate.
  application_submission_payload()    the answers and documents for ONE
                                      application, re-checking id + email
                                      itself because it is also reachable as
                                      its own request.

Answers come back as an ordered list, not fixed fields, so a new question on
the apply form only has to be added in one place to appear here. Empty answers
are dropped by the view rather than rendered as blanks.

DOCUMENT ACCESS — WHY A SECOND DOWNLOAD ROUTE EXISTS
----------------------------------------------------
download.php includes auth.php, which calls require_login() at the bottom of
the file, so merely including it forces a login. An applicant has no account,
so a candidate could not reach their own resume through it. my-document.php is
the candidate's route: it takes file_id + application id + email, and one query
proves in a single step that the application belongs to that email and the file
belongs to that same candidate. A file id that belongs to someone else returns
the same 404 as a file id that does not exist, so it never confirms that
another candidate's document exists.

It is deliberately simpler than download.php: no byte ranges (there is no
progressive PDF viewer here) and no audit entry (a candidate re-reading their
own attachment is not a recruiter opening a candidate's file).

LOADING STATES — WHAT IS ACTUALLY ASYNC
---------------------------------------
Skeletons are only shown where something is genuinely being waited for:

  * A details panel is fetched the first time it is opened, from
    ?action=detail. The skeleton covers that request and the content is kept
    afterwards, so re-opening is instant and the server is asked once per
    application.
  * Submitting the lookup form replaces the results region with skeletons for
    the duration of the navigation it just started.

The first paint of a server-rendered page is NOT given a skeleton, because
there is nothing to wait for — the HTML already has the answer. A skeleton
there would be an animation pretending to be a load.

PROGRESSIVE ENHANCEMENT
-----------------------
With JavaScript off the page still works. "View details" is a link to
?detail=<id>, which server-renders that panel open from the same partial the
fetch returns, so the two cannot drift. The stepper is a plain <ol> in a
scroller. Only the withdraw modal needs JS, and the button that opens it is the
only thing that stops working.

ACCESSIBILITY
-------------
  * Colour never carries meaning alone: every status chip states its status in
    words and its dot is aria-hidden.
  * The current stage carries aria-current="step".
  * Every control on the page is at least 44x44. This needed `html .cs-page
    .btn` rather than `.cs-page .btn`, to match the specificity of
    light-theme-v3.css's `html[data-theme="light"] .btn { min-height: 42px }`,
    which otherwise wins under 768px. The extra `html` is specificity only —
    the rule is not theme-scoped.
  * Focus is visible on everything (2px outline, 2px offset), including the
    stepper scroller, which is focusable so it can be scrolled with the
    keyboard.
  * The accordion, the lookup and the join-state poll all announce through ONE
    aria-live region, so a screen reader is never interrupted by three.
  * The withdraw modal takes focus on open, closes on Escape and on a backdrop
    click, and returns focus to whatever opened it.
  * prefers-reduced-motion switches off the shimmer, the accordion slide and
    the card lift.

NAMESPACE — READ THIS BEFORE ADDING CLASSES
-------------------------------------------
The page uses the `cs-` prefix. It was written with `cp-` first, and `.cp-page`
already existed in assets/styles.css as the candidate-preview PDF page — a
white background that painted a white block behind this page in dark mode.
`cp-` belongs to the candidate profile; do not reuse it here.

THEMES
------
This stylesheet contains no dark selector and no palette literals. Everything
resolves through the semantic tokens that theme-tokens.css and
light-theme-tokens.css define per theme, so the page follows the active theme
without either theme's rules being touched.

WHAT WAS VERIFIED
-----------------
Headless Chrome over CDP against the local PHP server and MySQL, 30 of 30
assertions, plus a separate interaction pass:

  * top-down order: header above the stepper above My Applications, and no
    element of the old split view left in the DOM
  * desktop 1440: one horizontal stepper row spanning the card, no scrolling,
    cards 2-3 per row, no horizontal page scroll
  * tablet 900: 2 columns
  * mobile 390 (device emulation): cards in one column, stepper overflows and
    snaps with scroll-snap-type "x mandatory", the current step is scrolled
    into view on load, no horizontal PAGE scroll, targets still >= 44px
  * every visible control >= 44px tall in both light and mobile layouts
  * text contrast >= 4.5:1 in light AND dark (measured with alpha layers
    composited, which is what the browser paints)
  * accordion: skeleton during the fetch, then the real answers and a
    my-document.php link; aria-expanded flips, the label becomes "Hide
    details", the live region announces, and it closes again
  * lookup: skeletons swap in the moment the form submits
  * withdraw: modal opens with focus, Escape closes it, and the real POST
    withdraws the application, redirects, and renders the withdrawn state
    (the test row was restored afterwards)
  * my-document.php: own file streams with the right type and filename;
    another candidate's file and a wrong email both 404
  * ?action=detail with the wrong email returns 404
  * empty state renders for an email with no applications
  * no-JS: ?detail=<id> returns the panel in the server response
  * keyboard: Tab lands a visible 2px outline

NOT VERIFIED HERE
-----------------
Real screen readers (the ARIA is correct by inspection, not by NVDA/VoiceOver);
Safari and Firefox; real touch devices — the 390px run is Chrome device
emulation, which is layout-accurate but not a finger; printing.

KNOWN DEAD CODE LEFT ALONE
--------------------------
includes/status-result-body.php and status-live.php were written for an earlier
version of this page. Nothing includes or calls them now (the old page inlined
its own copy of that markup, so the partial was already dead before this work).
They are untouched rather than deleted, but they are not part of this page and
should not be updated to match it.

QA CHECKLIST
------------
[ ] Look up by email only: the stepper shows the most recent OPEN application,
    and the card for it is the one flagged "Shown in the progress bar above".
[ ] Look up with an ID: that application is in focus.
[ ] Wrong ID, right email: the banner explains it and the history still lists.
[ ] Open each card's details: the answers match what was actually submitted,
    and every uploaded file opens or downloads.
[ ] Copy a details link and change the id to someone else's: 404.
[ ] Real phone in portrait: swipe the stepper, controls reachable with a thumb,
    no sideways page scroll.
[ ] Rejected application: feedback and the suggested role still appear.
[ ] Withdraw: the modal warns, the POST works, and the page comes back showing
    Withdrawn with no Withdraw button.
[ ] Both themes, and a reduced-motion setting.
