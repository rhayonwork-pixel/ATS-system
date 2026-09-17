Interview joining, availability, and candidate admission
========================================================

SETUP
-----
Import database/migration-waiting-room.sql (the seventh migration). It is
idempotent and safe to re-run.

THEN CHECK ONE SETTING
----------------------
includes/config.php now has:

    const APP_TIMEZONE = 'Asia/Manila';

Set this to your office's timezone. It is the fix for the bug below, so it
matters more than it looks.

1. WHY THE JOIN BUTTON COULD NOT BE CLICKED
-------------------------------------------
It was a timezone mismatch, not a UI problem, and not a missing endpoint.

Interview times are captured with <input type="datetime-local">, which submits
the recruiter's local wall clock ("2026-09-05T14:00"), and that string was
stored raw. PHP had no date_default_timezone_set() anywhere in the codebase, so
it fell back to php.ini — UTC on a stock XAMPP.

The availability check was:

    time() >= strtotime($row['starts_at']) - $window

strtotime("2026-09-05 14:00") read that as 14:00 UTC, while time() returned the
real instant. In a UTC+8 office at 2:00 PM local, that comparison was:

    06:00 UTC  >=  14:00 UTC     ->  false

So the button stayed disabled and only unlocked eight hours later. The same
mismatch broke "the interviewer is already here", because room_last_ping came
from MySQL NOW() (server local) and was compared against PHP's time().

THE FIX
  * date_default_timezone_set(APP_TIMEZONE) at bootstrap.
  * The PDO connection runs SET time_zone to the matching numeric offset, so
    NOW(), CURDATE() and time() all agree. The numeric offset is used rather
    than a named zone because MySQL's timezone tables are not populated on a
    stock XAMPP.

No data migration is needed: existing rows already hold local wall-clock times,
which is exactly how they are now read.

The button markup was also rebuilt. Previously an unavailable button was a link
with aria-disabled and a click handler that swallowed the event. Now an
available control is a real <a>, and an unavailable one is a real
<button disabled> — the poller swaps the element rather than restyling it, so
there is never an enabled-looking control with a blocked click.

2. WHEN A CANDIDATE CAN JOIN
----------------------------
    now >= starts_at - joinWindow    AND    interview not ended

Nothing closes the door because the start time has passed. Verified against the
spec's acceptance tests (16 cases, all passing): exactly on time, +5 minutes,
+30 minutes, +3 hours, ended, reviewed, cancelled, and stale presence.

The interviewer does NOT have to arrive first. Either condition opens the room:
the scheduled time arriving, or the interviewer already being present. Neither
one closes it once open.

3. WAITING ROOM AND ADMISSION
-----------------------------
    Candidate clicks Join
            |
            +-- interviewer not present -> "Waiting for your interviewer..."
            |                               (auto-advances when they arrive)
            |
            +-- interviewer present     -> request sent
                                            |
                                     interviewer sees a prompt
                                            |
                              [ Admit candidate ] [ Keep waiting ]
                                            |
                                   candidate enters automatically

"Keep waiting" is not a rejection: the request stays open so the interviewer
can admit them a moment later.

The candidate never refreshes. The waiting room heartbeats and polls every five
seconds and reloads straight into the meeting the instant they are admitted.
The interviewer's prompt appears the same way.

4. NO DUPLICATES
----------------
There is one candidate per interview, so the waiting state lives on the
interview row as candidate_request_state rather than in a requests table. A
second click UPDATEs the same row — it cannot INSERT a second request. The
client also serialises its calls, so a rapid double click issues one request.

Reconnecting, refreshing or reopening the page all land on the same single
state; an admitted candidate is never pushed back to waiting.

5. STATES
---------
Meeting lifecycle (interviews.meeting_state), unchanged:
    scheduled -> ready -> in_progress -> ended -> review_pending -> reviewed

Candidate admission (interviews.candidate_request_state), new:
    none -> waiting | requested -> admitted

Join states reported to the candidate UI:
    not_available | available | interviewer_ready | waiting | requested
    | admitted | ended | cancelled

6. SECURITY
-----------
interview-access.php never takes identity from the request body:
  * the candidate proves themselves with the per-interview token (hash_equals)
  * the interviewer proves themselves with their session
  * writes require POST, and staff writes require a matching CSRF token

Enforced server-side, not by hiding buttons:
  * a candidate cannot admit themselves (admit is staff-only, 403 otherwise)
  * a candidate cannot request entry for another interview (wrong token, 403)
  * a candidate cannot enter before the room is open (the request action
    re-checks candidate_join_state and returns 409)
  * a candidate stays in the waiting room until admitted — the meeting markup
    is not rendered for them at all before that, so there is nothing to unhide
  * the candidate's JSON payload contains only join state and timing: no notes,
    no score, no review, no recruiter data

7. NOTHING NEW ENDS THE MEETING
-------------------------------
Only the End meeting control ends the interview. Joining, admitting, keeping
someone waiting, heartbeating and polling all use fetch and never navigate or
submit a form. This was checked structurally: the only form inside the call
block is the chat form, which calls preventDefault().

8. PRESERVED
------------
Mic, camera, screen sharing, participant pinning, notes with autosave,
minimisable notes and chat, participants panel, recording toggle, candidate and
interviewer joining, meeting status, interview completion, and post-meeting
scoring and review. Scoring still appears only after the meeting ends.

9. FILES
--------
Added:
  interview-access.php                  waiting room + admission endpoint
  database/migration-waiting-room.sql

Changed:
  includes/config.php        APP_TIMEZONE, PHP + MySQL alignment  <-- the fix
  includes/interview_lib.php join rule, presence, admission helpers
  interview-room.php         candidate waiting room, admission prompt, heartbeat
  application-status.php     real enabled/disabled join control, polling
  interview-status.php       reports the new states
  assets/styles.css          waiting room and admission prompt, light + dark
