# Feature Verification Report

## What changed since the first pass

The first version of this reorganization was checked with `php -l`
(syntax only) — no live database, no running server. Real testing since
then caught **three actual bugs** that syntax checking couldn't have
found:

1. **Migration order was wrong.** `notifications` and `jobs.created_by`
   are created in migration 012 (RBAC/approvals), but migrations that
   depend on them were numbered to run before it. A fresh install would
   have failed partway through. Fixed by renumbering to the order that
   was actually run and verified clean, start to finish, against a real
   database (`database/migrations/001`–`018`, see `database/README.txt`).
2. **Migration 004 (profile photo) failed on a fresh install** —
   `schema.sql`'s `candidates` table already has `profile_image` inline,
   so the `ALTER TABLE ... ADD COLUMN` collided. Fixed with the same
   information-schema idempotency guard the project's other migrations
   already use.
3. **The interview-invite email was silently failing**, in all three
   places it's sent from (`interviews.php`, `api/interview/schedule.php`,
   `InterviewController.php`): the query selected `c.name`, but
   `candidates` has `first_name`/`last_name`, not `name`. It threw, and
   the surrounding `catch` block swallowed it with no logging at all.
   Fixed the query in all three places, **and** added real error logging
   to those catch blocks so a failure like this can't go silent again.

## How it was actually tested this time

A real MySQL 8.0 server and the project's own PHP built-in web server
were both stood up and exercised directly — not just read for syntax:

- `schema.sql` + `migrations/001`–`018` run from scratch on a throwaway
  database — clean, no errors, tables and demo data present.
- Logged in as the seeded admin user through the real `login.php` (not
  mocked) and confirmed the session-based auth path works.
- Scheduled a real interview through the real `interviews.php` form —
  got back a genuine `room_code` and `candidate_token` from the database.
- Confirmed the invite email actually logs correctly to
  `storage/logs/mail.log` (this is where bug #3 above was caught).
- Loaded `interview-room.php` as the candidate (token-based) before
  admission — correctly shows the waiting room, not the call UI.
- Drove the real admission flow through `interview-access.php`:
  candidate `request` → HR `admit` — both real HTTP calls against the
  real database, both returned the correct state.
- Reloaded the candidate's room page post-admission — now correctly
  renders the live-call markup (`data-is-host="0"`, the remote `<video>`
  element) instead of the waiting room.
- Drove the **entire signaling exchange** through the real
  `api/interview/*.php` endpoints exactly as the browser JS calls them:
  host posts an SDP offer → candidate polls and receives it → candidate
  posts an SDP answer → host polls and receives it → both sides post ICE
  candidates → each side polls and receives *only the other side's*
  candidates (confirmed the role-based filtering is correct) →
  `status.php` correctly reports `has_offer`/`has_answer`/`connected` →
  `end.php` marks the call ended and `status.php` reflects it.
- Tested `notes.php` (saved correctly to `interviews.notes`) and
  `scorecard.php` (correctly rejected with 409 because the interview
  hadn't reached `review_pending` state yet — confirmed this is the
  existing, intentional gating rule in `interview_accepts_review()`,
  not a bug).

## What is still NOT verified — the one thing that genuinely can't be

**Actual browser-to-browser WebRTC media.** Everything above proves the
*signaling* — the mechanism that gets two browsers' SDP/ICE data to each
other — is correct end to end. What it cannot prove is the browser-side
`RTCPeerConnection` actually negotiating a real audio/video stream
between two real devices on real networks, because that requires an
actual browser with camera/mic hardware, which does not exist in this
environment. This is the one item I'd genuinely ask you to check before
the meeting: open the room from two separate browsers/devices and
confirm you see and hear each other, not just that the status says
"Connected." If it doesn't connect, the signaling logs (now real,
working, and easy to inspect via `storage/logs/`) plus the browser's own
console/`chrome://webrtc-internals` are the right next place to look —
and the known STUN-only/no-TURN limitation in `docs/INTERVIEW_SETUP.md`
is the most likely cause if two peers are both behind restrictive NATs.

## Everything else (unchanged from the original app)

| Area | Status |
|---|---|
| HR / Candidate / Staff login | Unchanged, confirmed working via a real login in this pass |
| Dashboard, jobs, candidates, pipeline, settings, notifications | Unchanged, not touched |
| Resume upload/parsing | Unchanged, not touched |
| Waiting room / admission | Unchanged, confirmed working via real requests in this pass |

## Recommended test order before the meeting

1. Open the interview room as HR in one browser, as the candidate
   (using their link) in another — ideally two different devices.
2. Confirm camera/mic permission prompts and previews work on both.
3. Join on both sides, confirm you actually see/hear the other person.
4. If it hangs at "Connecting…" — check `storage/logs/` for any PHP
   errors first (should be none, per the testing above), then check
   each browser's WebRTC internals for ICE connection failures, which
   would point to the STUN-only/symmetric-NAT limitation.
