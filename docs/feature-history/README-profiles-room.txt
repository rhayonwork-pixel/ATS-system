Profiles, password resets, and the redesigned Interview Room
============================================================

SETUP
-----
Import in this order (each is idempotent and safe to re-run):
  1. database/schema.sql
  2. database/attendance-payroll.sql
  3. database/migration-rbac-approvals.sql
  4. database/migration-seats-interviews.sql
  5. database/migration-candidate-interviews.sql
  6. database/migration-profiles-password-reset.sql      <-- this change

NOTE ON THE VISUAL REFERENCE
----------------------------
The spec refers to an uploaded meeting screenshot. That upload arrived as a
0-byte file, so it was never readable. The Interview Room was built from the
written layout description in the prompt instead: dark meeting surface, large
participant tiles with names and mic state, pinning, a participant strip, and a
bottom control bar with Notes sitting next to End meeting. If you re-upload the
screenshot the layout can be tuned to match it more closely.

1. URGENT HIRING
----------------
The fire emoji is gone from every page (index, jobs, job-detail, admin,
my-jobs, job-post). The feature is untouched — is_urgent still drives the
urgent section on the careers site and the checkbox in both job editors. It now
renders as a text badge ("Urgent" / "Urgent Hiring") using a new .badge.urgent
style with light and dark variants.

2. STAFF PROFILES
-----------------
profile.php, linked from the sidebar for every signed-in role.

Editable: full name, email, job title, profile picture, password.
Not editable, and not writable from this page at all: role, permissions, seat
limit, account status. Only the fields in editable_profile_fields() are ever
put into an UPDATE, so extra POST keys reach nothing. The role field is shown
disabled purely as information.

users.profile_image mirrors candidates.profile_image and reuses the same upload
rules, including a getimagesize() content check rather than trusting the file
extension.

3. CHANGE PASSWORD
------------------
The current password is verified with password_verify() against the stored hash
before anything changes — holding a valid session is not sufficient. The new
password must differ from the old one, be at least 8 characters, contain a
letter and a number, and match its confirmation. Hashing uses the existing
password_hash(). The session id is regenerated on success.

The audit trail records that a password changed. It never records the password.

4. FORGOT PASSWORD — SUPER ADMIN APPROVED
-----------------------------------------
    Forgot password -> request -> Super Admin decides -> approved -> new password

forgot-password.php submits a request. It never reveals whether an address
exists: the confirmation message is identical either way, so the page cannot be
used to enumerate staff accounts. Nothing about the existing password is shown
or changed.

password-resets.php (Super Admin only) is the decision queue. On approval a
64-character token is generated, stored against the request, and given an
expiry (settings.password_reset_hours, default 24). Because this prototype has
no mail transport, the one-time link is displayed to the Super Admin once, to
hand over through a channel they trust — it disappears on reload.

reset-password.php consumes the token. It is single use: completing a reset
sets status='used' and clears the token, so the same link cannot be replayed.
Expired tokens are marked 'expired' on sight.

Rejection leaves the password unchanged, records the reason, and notifies the
requester. Approve, reject and completion are all in the audit trail.

5. CANDIDATE CAN JOIN AS SOON AS IT IS AVAILABLE
------------------------------------------------
candidate_join_state() returns one of:
    not_available | available | interviewer_ready | ended | cancelled

The candidate gets in on EITHER condition:
  A. the scheduled join window has opened (interview_join_window_minutes), or
  B. the interviewer is already in the room — room_status='live' with a ping in
     the last 12 seconds, the same freshness rule room-presence.php uses.

interview-status.php is a candidate-safe poll. It returns only the join state
and the start time — no notes, no score, no recruiter data — and still requires
the correct per-interview token.

The applicant portal card and the waiting room both poll it (10s and 8s), so
the button changes to "Join now" on its own when the interviewer arrives. No
manual refreshing.

6. INTERVIEW ROOM
-----------------
Participant grid: tiles are laid out by how many are actually visible
(data-count), 1-2 large, 3-6 in a grid, collapsing to a single column under
760px. Names truncate with ellipsis inside the tile rather than stretching it.

Pinning: every tile has a pin control. Pinning promotes that tile to full width
with the others in a smaller strip beneath, plus a "Grid view" button to
return.

PINNING IS PER-VIEWER. The choice is kept in that browser's localStorage and is
never sent to the server, so the candidate pinning the interviewer does not
move anything on the interviewer's screen, and vice versa.

Presented screens are a tile too: starting a share reveals the screen tile,
which either side can pin like a person. Stopping the share hides it and the
pin falls back to grid view.

Notes as a meeting control:
  * A Notes button sits in the control bar beside End meeting, with the icon
    system's notes glyph — no emoji.
  * The panel is HIDDEN by default so the meeting has the full width.
  * The button toggles: click opens, click again minimises, click again
    reopens. The panel header also has minimise and close buttons, and Escape
    closes it.
  * Minimising or closing only hides the panel. The textarea stays in the DOM
    with its contents, autosave keeps running, and nothing is cleared.
  * The button shows an active state while the panel is open.
  * Desktop: a right-hand drawer; the meeting column gives up exactly that
    width so nothing is covered. Tablet and phone: a bottom sheet capped at
    72vh with its own scrolling.
  * Only the interviewer gets it. The button, the panel and the textarea are
    all inside `if ($isStaff)`, so a candidate's page contains no notes markup
    at all, and interview-notes.php independently rejects non-staff.

End meeting moved into the control bar and is styled distinctly. It carries
whatever is in the notes box through with it, whether the panel is open or not.

Scoring and review remain hidden until the meeting ends, exactly as before, and
the saved notes are shown above the review form.

7. WHAT EACH SIDE SEES
----------------------
Interviewer: meeting, candidate details, notes control and panel, participants,
screen share, pinning, End meeting, and scoring only after ending.

Candidate: meeting, their own interview information, mic/camera, participants,
pinning, screen viewing, Leave meeting.

Verified structurally: every notes, score, review and recommendation element is
nested inside an $isStaff branch; the candidate panel is inside $isCandidate.

8. AUDIT TRAIL
--------------
New entries: profile_updated (with what changed), password_changed,
password_reset_requested, password_reset_approved, password_reset_rejected,
password_reset_completed. These join the existing interview_ended,
interview_notes_saved and interview_review_submitted records. The audit trail
remains its own page — nothing was moved into Job Management.

9. FILES
--------
Added:
  includes/account_lib.php     profile fields, password rules, reset tokens
  profile.php                  staff profile and password change
  forgot-password.php          reset request
  password-resets.php          Super Admin decision queue
  reset-password.php           single-use token consumption
  interview-status.php         candidate-safe availability poll
  database/migration-profiles-password-reset.sql

Changed:
  includes/interview_lib.php   join states, live-room detection
  includes/auth.php            loads account_lib
  includes/sidebar.php         profile link; password resets for Super Admin
  interview-room.php           participant grid, pinning, notes drawer,
                               control bar, waiting-room polling
  application-status.php       live join states with polling
  login.php                    forgot-password link
  index.php jobs.php job-detail.php admin.php my-jobs.php job-post.php
                               fire emoji replaced with a badge
  assets/styles.css            all of the above, light and dark
