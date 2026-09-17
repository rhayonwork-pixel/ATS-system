Notification bell, candidate profile layout, and what I did not build
=====================================================================

SETUP
-----
Import database/migration-notifications-ui.sql (the eighth migration).

READ THIS FIRST — A FILE MISMATCH IN THE BRIEF
-----------------------------------------------
Section 1 describes a CANDIDATE profile: avatar with a stage pill, pipeline
stepper, scorecards, resume viewer, hiring team, "Move Stage", "Reject".

It names profile.php. In this ATS, profile.php is the STAFF self-service page —
the signed-in recruiter's own name, email, photo and password. It has no
candidate, no stage, no pipeline. Applying section 1 to it would produce a
pipeline stepper on a page about the recruiter themselves.

The candidate profile is candidate.php. I applied section 1 there, and left
profile.php as the staff account page. If you actually meant profile.php, tell
me and I will move it — but it would need a candidate id to be about anyone.

WHAT WAS BUILT
--------------

1. GLOBAL NOTIFICATION BELL  (sections 3)

   Trigger in the app header, present on every signed-in page. Badge shows the
   unread count, hides at zero, caps at 99+, and pulses once when the count
   rises. Flyout is 380px on desktop and a full-width bottom sheet under 768px,
   at z-index 1100 as specified.

   Contents: title, unread summary, "Mark all as read", category chips
   (All / Applications / Interviews / System), and items carrying a
   priority dot (urgent and high red, medium amber, low blue), category label,
   title, message, actor, relative time, and per-item actions.

   Closes on outside click and on Escape, returning focus to the trigger.

   SCHEMA: the brief proposed a new notifications table. This project already
   has one with call sites across the approval, seat, reactivation and password
   workflows, so it was EXTENDED instead:

       brief          here
       category   ->  category  (new, backfilled from the existing `type`)
       priority   ->  priority  (new, backfilled from `type`)
       message    ->  body      (existing)
       action_url ->  link      (existing)
       is_read    ->  read_at   (existing timestamp — it says whether AND when,
                                 so it was kept; is_read is derived in the API)

   TRANSPORT: short polling, not SSE. On XAMPP with mod_php every open
   EventSource holds an Apache worker for its entire lifetime, so a handful of
   signed-in recruiters would exhaust MaxRequestWorkers and the ATS would stop
   responding. Polling is the brief's own documented fallback and the right
   call for this stack: 20s while the tab is visible, 60s when hidden, paused
   while the flyout is open. The cheap action=count endpoint is what polls; the
   full feed loads only when the flyout opens. Moving to SSE later means
   changing one file.

   SECURITY: every response is scoped to the session user — no endpoint accepts
   a user id. Writes require POST plus the CSRF token, which is emitted on the
   flyout element because most pages have no form to borrow one from.

2. CANDIDATE PROFILE  (sections 1 and 2)

   Sticky snapshot strip: the identity card now sticks to the top of the scroll
   area, so the name, rating and quick actions stay reachable while the
   evaluation content scrolls. Made static under 768px, where a sticky strip
   would eat most of the screen.

   Pipeline stepper: Applied -> Screening -> Interview -> Offer -> Hired, driven
   by the existing applications.stage value. No new column and no second source
   of truth. Completed stages show a check, the current stage is highlighted and
   carries aria-current="step", and a rejected application dims the track and
   says so. Each step also has an .sr-only state so it is not colour-only.

   Two-column layout via container queries, so it reacts to the width left
   after the sidebar rather than the viewport:

       >= 1024px (container)   65 / 35
       >= 1440px (container)   68 / 32, capped at 1440px
       below                   single column in document order

   Long emails and URLs are the only things allowed overflow-wrap:anywhere;
   headings and names break on word boundaries.

WHAT I DID NOT BUILD, AND WHY
-----------------------------

3. THE FLOATING PiP MEETING ENGINE (section 4) — NOT IMPLEMENTED.

   Both strategies in the brief are large architectural commitments, and I did
   not want to half-do either inside a UI pass:

   Strategy B (Turbo/Swup PJAX shell) means converting a 53-page multi-page PHP
   app into an AJAX shell. Every page's inline scripts currently run on load;
   under Turbo they would need to become idempotent, re-bindable modules. The
   interview room alone has the state machine, the rail panels, pinning, the
   notes autosave and the admission poller. That is a project, not a step, and
   done carelessly it would break the room that now works.

   Strategy A (Document Picture-in-Picture) is Chromium-only and still behind a
   flag in some builds. It is a reasonable progressive enhancement but cannot
   be the mechanism.

   Worth knowing: a genuine WebRTC session cannot survive a full page load. A
   navigation tears down the JavaScript context, so the peer connection and the
   MediaStream go with it. Any "persistent meeting" in a multi-page app is
   either an SPA shell or a separate window — there is no third option, and a
   floating widget that merely looks live while the stream is dead would be
   worse than none.

   The existing room-active-overlay already detects a live room server-side on
   every page and offers "Back to meeting", which is the honest version of this
   for the current architecture.

   Tell me which way you want to go and I will do it properly as its own piece
   of work: (a) Document PiP as a Chromium-only enhancement, (b) a real Turbo
   shell migration, or (c) upgrade the existing overlay into a draggable
   mini-widget with saved position that reconnects on click.

VERIFIED
--------
  * php lexer: 53 files clean
  * branch-aware render: candidate.php and header.php balanced
  * node --check on app.js; sidebar flyout suite 17/17; pill suite 14/14
  * notification writes require POST + CSRF; reads are session-scoped
