Seats, account reactivation approvals, and the interview workflow
=================================================================

SETUP
-----
Import in this order (each is idempotent and safe to re-run):
  1. database/schema.sql
  2. database/attendance-payroll.sql
  3. database/migration-rbac-approvals.sql
  4. database/migration-seats-interviews.sql      <-- this change

1. ACCOUNT LIFECYCLE
--------------------
    Active -> Suspended / Disabled -> Reactivation request
           -> Super Admin approval -> Active

Suspending or disabling an HR/Recruiter account writes a notification to every
Super Admin containing the account name and email, the action, the Admin who
did it, the timestamp, and the reason if one was given. The account is never
silently reactivated and never auto-deleted.

Enabling an account is a REQUEST, not a toggle:
  * The Admin presses "Request reactivation".
  * The account moves to 'pending_reactivation' with active=0 — it still cannot
    sign in.
  * A row lands in account_requests with status='pending'.
  * Only a Super Admin can decide it. If approved the account returns to Active;
    if rejected it stays disabled, the reason is recorded, and the requesting
    Admin is notified.

The enforcement is in the POST handler in users.php, not in the template: the
"Activate" button is not rendered for an Admin, AND the handler refuses the
transition even if the request is hand-crafted (users.php, action=hr_status).

2. SEATS (replaces "allowance")
-------------------------------
The word "allowance" no longer appears anywhere in the UI. The column keeps its
original name (users.hr_account_limit) because renaming a live column buys
nothing, but every label, helper and message says seats:

    seat_limit($adminId)       how many seats the Super Admin granted
    seats_used($adminId)       how many are occupied right now
    seats_available($adminId)  what is left

Seat rules, applied consistently:
  * Creating an HR/Recruiter account consumes a seat.
  * Pending, active, suspended, disabled and pending-reactivation accounts all
    HOLD their seat — each of those can come back.
  * Rejected accounts never held one.
  * Deleting an account frees the seat immediately.
  * A Super Admin can explicitly release the seat of a suspended or disabled
    account without deleting it (users.seat_released).
  * Reactivation reuses the existing seat and never creates a second one.
  * Seat limits are enforced server-side; an Admin cannot raise their own.
  * A Super Admin cannot lower a seat limit below the seats already in use.

At the limit an Admin sees, and the backend independently returns:
    "No available HR/Recruiter seats. Please contact the Super Admin to
     increase your seat limit."

Deleting an account detaches rather than destroys: jobs, notes, audit records
and interviews they touched are kept, with their user reference set to NULL.

3. SIMPLIFIED SUPER ADMIN PANEL
-------------------------------
    ADMINISTRATION            NOTIFICATIONS
    - Admins                  - Notifications
    - HR / Recruiters
    - Applicant Portal
    - Audit Trail
    - Settings

Dashboard, Jobs, Candidates, Pipeline, Interviews, Employees, Attendance and
Analytics are removed from the SUPER ADMIN NAVIGATION ONLY. Admin, HR and
Recruiter users still see and use all of them — nothing was deleted from the
ATS. Signing in as a Super Admin now lands on super-admin.php instead of the
recruiting dashboard.

4. SUPER ADMIN NOTIFICATIONS
----------------------------
notifications now records actor_id, entity_type, entity_id and action_label, so
each item renders type, title, description, related account, who did it, when,
read/unread state, and an action button.

Events generated: account suspended, account disabled, reactivation requested,
reactivation approved, reactivation rejected, new account awaiting approval,
account deleted, job submitted for approval, job approved, job rejected,
changes requested, permission changed, and seat limit changed.

The panel filters by All / Unread / Accounts / Jobs / Permissions & seats.

5. INTERVIEW WORKFLOW
---------------------
    scheduled -> ready -> in_progress -> ended -> review_pending -> reviewed

interviews.status is UNCHANGED (dashboard.php and pipeline.php read it). The
lifecycle lives in the new interviews.meeting_state column, and 'ready' is
derived from the start time so no cron job is needed.

During the meeting the interviewer sees INTERVIEW NOTES and nothing else — the
scoring box is not rendered at all. Notes autosave about a second after typing
stops, save again on pagehide, and there is a manual "Save notes" button as a
fallback, so notes survive refresh, minimise/maximise, panel switches and
ending the meeting.

"End meeting & start review" carries whatever is in the notes box with it,
moves the interview to review_pending, and only then does SCORING & REVIEW
appear: score 1-100, final feedback, and a recommendation.

Notes and the final review are kept as separate fields (interviews.live_notes
vs interviews.feedback) and shown separately everywhere, so it is always clear
what was captured live and what was submitted as the evaluation.

The gate is server-side: interviews.php refuses action=submit_review unless
interview_accepts_review() is true, so posting the form early does nothing.

6. CANDIDATE PROFILE
--------------------
candidate.php gained an Interview History section reached through the existing
candidate -> application -> interview relationship. No new candidate records,
no duplication. It shows date, interviewer, type, state, notes, score, final
review and recommendation for every interview across all of that candidate's
applications.

7. RESPONSIVE + MODALS
----------------------
Interviews use a two-column layout that collapses at 1100px, cards that go
single-column at 850px, and stacked meta plus full-width buttons at 560px. The
interview room notes and review panels reflow the same way. All new components
have light and dark rules.

The confirmation dialog closes on the X, the Cancel button, a backdrop click
and the Escape key, so it can never leave the page stuck.

8. FILES
--------
Added:
  includes/interview_lib.php          interview lifecycle, states, history
  interview-notes.php                 staff-only autosave endpoint for live notes
  database/migration-seats-interviews.sql

Changed:
  includes/permissions.php  seats, reactivation requests, richer notify()
  includes/sidebar.php      minimal Super Admin panel
  users.php                 suspension notices, reactivation flow, seats, delete
  super-admin.php           seat wording, seat stats, decisions banner, modal
  notifications.php         typed panel with actor, filters and actions
  interviews.php            lifecycle handlers, responsive grouped cards
  interview-room.php        notes during, scoring only after, autosave
  room-presence.php         marks a joined room as in_progress
  candidate.php             interview history section
  login.php                 Super Admins land on super-admin.php
  assets/styles.css         seats, notifications, interview UI, light + dark

9. AUDIT TRAIL
--------------
New actions recorded with user, role, target and details: seat_limit_changed,
seat_released, hr_account_create, hr_account_status, hr_account_delete,
reactivation_requested, reactivation_approved, reactivation_rejected,
admin_permissions_update, hr_permissions_update, job_submit_for_approval,
job_approve, job_reject, job_request_changes, interview_ended,
interview_notes_saved, interview_review_submitted.
