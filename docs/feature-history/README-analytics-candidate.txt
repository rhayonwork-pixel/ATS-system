Personal analytics, Admin monitoring, and candidate interview access
====================================================================

SETUP
-----
Import in this order (each is idempotent and safe to re-run):
  1. database/schema.sql
  2. database/attendance-payroll.sql
  3. database/migration-rbac-approvals.sql
  4. database/migration-seats-interviews.sql
  5. database/migration-candidate-interviews.sql      <-- this change

1. WHOSE CANDIDATES ARE WHOSE
-----------------------------
Personal analytics needs an owner per application. The prototype had no such
column, so applications.assigned_to was added and BACKFILLED from the job's
author (jobs.created_by, falling back to jobs.owner_id) — the relationship the
prototype already used to imply ownership. Nothing was invented.

From now on, scheduling an interview also claims an unassigned application for
that interviewer, so the numbers stay meaningful without manual admin.

"Mine" resolves to:
    applications.assigned_to = me
    OR (unassigned AND the job was created/owned by me)
    interviews.interviewer_id = me   (for interview metrics)

The predicate lives in analytics_owned_applications_clause() and is applied in
SQL, so a recruiter cannot widen it from the browser.

2. ANALYTICS BY ROLE
--------------------
HR / Recruiter -> personal only. analytics.php resolves the subject to the
signed-in user; ?user= pointing at anyone else returns a 403.

Admin -> lands on team monitoring: totals across the HR/Recruiters they
provisioned, a performance table, and a comparison view. "View analytics" opens
that person's full personal dashboard. The ?user= value is checked against the
list of people that Admin actually manages, in the backend.

Super Admin -> unchanged. No operational analytics were added to their panel,
and Analytics is still absent from their navigation, per the previous change.

Reporting periods: Today / This week / This month / Last 3 months / All time /
Custom range. Selecting a preset submits immediately; custom reveals the two
date inputs.

3. REPORTS
----------
analytics-report.php (Admins only) gives a printable per-person report with
reporting period, candidate workload, interview workload, hiring results,
pipeline activity and a totals row, plus CSV export. Exports are recorded in
the audit trail as analytics_report_export.

The report deliberately covers ATS work only — candidates handled, interviews
run, outcomes. There is no attendance, timing or behavioural monitoring in it.

4. CANDIDATE INTERVIEW ACCESS
-----------------------------
Previously anyone holding a room code could open interview-room.php. Codes get
forwarded, so that was not real access control.

Each interview now carries its own unguessable candidate_token:
    interview-room.php?code=<room>&t=<token>

  * Staff are identified by their session, as before.
  * Candidates are identified by the token, compared with hash_equals.
  * No session and no token -> the link is rejected outright.
  * A token that does not match that room -> rejected.

The token, not the candidate's email, goes in the URL, so no personal data is
ever placed in a query string.

The candidate reaches the link from the Applicant Portal: application-status.php
already proves ownership through the application ID + email lookup, and now
renders an "Upcoming interview" card with position, type, interviewer, date,
time, status and a Join button.

WAITING ROOM: entering early is blocked. The window is a setting
(interview_join_window_minutes, default 15). Before it opens the candidate sees
their interview details and:
    "The interview room is not available yet. Please return when your
     scheduled interview is about to begin."
The page re-checks itself every 30 seconds.

5. WHAT EACH SIDE SEES
----------------------
Interviewer: meeting, candidate details, interview notes, meeting controls,
and — only after ending the meeting — scoring and review.

Candidate: meeting, their own interview information, meeting controls.

Interview notes, scores, recommendations and every internal control are inside
`if ($isStaff)` branches, so they are never rendered into the candidate's page
in any form. interview-notes.php (the autosave endpoint) independently rejects
anyone who is not signed-in staff, so notes are not reachable through the API
either.

6. RESPONSIVE + OVERFLOW FIXES
------------------------------
Structural fixes rather than shrinking type:
  * global box-sizing:border-box; inputs, textareas and selects inside cards
    are width:100% with min-width:0
  * min-width:0 on flex/grid children so long content can shrink instead of
    forcing horizontal scroll
  * overflow-wrap:anywhere on headings, table cells and names, so a long
    candidate name wraps instead of breaking the card
  * .table-wrap scrolls its table rather than the page
  * modals capped at min(90vh,720px) with internal scrolling
  * body{overflow-x:hidden} as a backstop

Interview room layout:
  Desktop (>=1000px, once joined) — meeting left, notes / candidate panel right
  as a sticky column, controls inside the meeting column.
  Tablet and below — the sections stack: meeting, information, notes, controls.
  Phone (<=640px) — controls wrap, Leave goes full width, the side panel and
  device check go full width.

7. EMPTY STATES
---------------
"No activity in this period", "No completed interviews during this period",
"No HR/Recruiter activity found", "No HR/Recruiters yet", "Nothing to report".
Charts are not drawn when their totals are zero — the empty state replaces them
rather than showing a misleading flat chart.

8. FILES
--------
Added:
  includes/analytics_lib.php     scoping and metric queries
  analytics-report.php           Admin report + CSV export
  database/migration-candidate-interviews.sql

Changed:
  analytics.php            personal / team / drill-down, date ranges
  includes/interview_lib.php  candidate tokens, join window, waiting-room rules
  interview-room.php       token access control, waiting room, candidate panel,
                           two-column desktop layout
  interviews.php           token generation, ownership claim on scheduling
  application-status.php   upcoming interview card with Join button
  assets/styles.css        containment fixes, analytics, candidate access, room

9. PRESERVED
------------
Authentication, the Super Admin / Admin / HR hierarchy, seat system, job
approval workflow, audit trail, applicant portal, pipelines, hiring stages,
interview scheduling, interview notes and reviews, candidate records and
profile photos. No candidate or application records are duplicated — the
interview history and analytics both read through the existing
candidate -> application -> interview relationships.
