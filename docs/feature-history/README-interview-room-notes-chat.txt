Interview Room: layout, autosaving notes, and minimisable chat
==============================================================

NO MIGRATION NEEDED
-------------------
Presentation and interaction only. No schema changes. interviews.live_notes is
still where notes are stored, written by the same endpoint as before.

1. THE "SAVE NOTES ENDED THE MEETING" BUG — CAUSE AND FIX
---------------------------------------------------------
The Save Notes button submitted a real form to interviews.php, which saved the
notes and then REDIRECTED back to interview-room.php. That reload dropped the
interviewer out of the joined call and back to the device-check lobby, which
read exactly like the meeting had ended.

Fixed at the root, not papered over:
  * The Save Notes button is gone, and so is the <form> that wrapped the notes.
  * The notes textarea is now a bare textarea. There is nothing to submit.
  * The save_notes POST handler in interviews.php has been removed, so even a
    hand-crafted post cannot trigger that redirect.
  * Saving happens entirely through fetch() to interview-notes.php, which
    returns JSON and never navigates.

The only element inside the call that can submit anything is the chat form, and
it calls preventDefault(). The only thing that ends the meeting is the End
meeting control, which still shows its confirmation first.

2. AUTOSAVE
-----------
Typing pauses for 900ms, then one request goes out — one save per pause, not
per keystroke. If more is typed while a request is in flight, the next save is
queued rather than dropped. Blur and pagehide (via sendBeacon) are extra safety
nets on top.

Status shows inline in the panel header: "Unsaved changes" while typing,
"Saving…", then "Saved 2:14 PM". Failures say so instead of silently losing
work.

3. LAYOUT
---------
.room-call is a column: header, body, controls. The control bar is the last
child, so it is always beneath the camera area and cannot be covered by a panel
or pushed out of the viewport.

The body is a grid: the camera area on the left, and a right rail that only
takes a column when something is open. On desktop the rail is
clamp(260px, 26vw, 340px) — the camera gives up exactly that much width, so
panels sit beside the camera rather than over it. With both panels closed the
camera has the full width.

4. NOTES AND CHAT
-----------------
Both live in the right rail, Notes on top and Chat below it, each in its own
panel with its own [−] and [×].

  * Independent: opening, minimising or closing one never touches the other.
  * Hidden by default on a fresh join, so the camera starts at full width.
  * Minimising collapses the body only. The textarea and the chat log stay in
    the DOM with their contents — nothing is cleared.
  * The panel arrangement is remembered per room in that browser, so a
    returning interviewer gets their layout back.
  * Escape closes the topmost open panel rather than everything.
  * Participants is now a third rail panel with the same behaviour, replacing
    the old tabbed drawer that forced chat and people to share one slot.

Notes are still staff-only: the whole panel is inside an $isStaff branch, so a
candidate's page contains no notes markup, and interview-notes.php rejects
non-staff independently.

5. RESPONSIVE
-------------
Desktop (>=1000px): camera left, rail right, controls below both.
Tablet and phone (<1000px): the rail becomes a bottom sheet capped at 66vh with
its own scrolling, and the control bar is layered above it so no control is
ever hidden behind a panel.
Phone (<=640px): controls wrap, End meeting goes full width on its own row, the
Notes label collapses to its icon.

Overflow: the textarea and chat input are width:100% with min-width:0, the chat
log scrolls inside its panel, tile names truncate inside their tile, and the
rail scrolls rather than growing past the viewport.

6. ANIMATION
------------
Panels slide in over 180ms on desktop and 200ms from the bottom on mobile.
Short enough not to sit between the interviewer and the controls.

7. PRESERVED
------------
Camera, microphone, screen sharing and the presented-screen tile, participant
pinning (still per-viewer), candidate and interviewer joining, meeting
availability, recording toggle, layout toggle, meeting status, chat history,
interview completion, and post-meeting scoring and review. Scoring still
appears only after the meeting ends, with the saved notes shown above it.
