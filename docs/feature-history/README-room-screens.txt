Interview Room screens: open room -> lobby -> live meeting
==========================================================

NO MIGRATION NEEDED
-------------------
Presentation and structure only.

WHAT THE SCREENSHOTS SHOWED
---------------------------
Screenshot 2 was the real problem: a live call ("Live 00:00", "Waiting for the
other participant to join...") with an INTERVIEW COMPLETED review panel stacked
underneath it, and a control bar with no Notes button and a "Leave" button
instead of "End meeting".

CAUSE
-----
The lobby, the live call and the review panel were all rendered as SIBLINGS
inside .room-wrap. Only their visibility differed. So once an interview had been
ended, meeting_state stayed at review_pending, and reopening the room produced
all three at once:

    <div class="room-wrap">
      <div class="room-lobby">      <- always rendered
      <div class="room-call" hidden> <- always rendered
      <aside class="room-review">    <- rendered whenever the meeting was over

The control bar was correct for that state, which is why it showed "Leave" and
no Notes: interview_accepts_review() was true, so the meeting-only controls were
deliberately suppressed. The bug was that the call was being shown at all.

FIX
---
The room now decides up front which single screen it is:

    $showReview = $isStaff && interview_accepts_review($row);

  * Meeting still open  -> lobby (device check) -> live call. Notes and
    End meeting are in the control bar.
  * Meeting ended       -> ONLY the review screen. The lobby and the call are
    not output at all, so there is nothing to reveal.

THE FLOW YOU ASKED FOR
----------------------
    Open room  ->  device check lobby (screenshot 1)
                     Enable camera & mic, mic/cam toggles,
                     [ Join interview room ]
                          |
                          v
    Join       ->  live meeting (screenshot 2, minus the review panel)
                     participant grid, Live timer, room code,
                     controls below the camera:
                     mic, camera, present, raise hand, chat,
                     participants, more, End meeting

Joining now also adds body.in-meeting, which hides the page heading so the
meeting fills the screen the way a real meeting client does. Leaving restores
it. This is a class toggle, not a navigation — the call is not reloaded.

ALSO FIXED
----------
  * The "Grid view" strip is hidden unless a participant is actually pinned.
    It was appearing with nothing pinned.
  * attendance-admin.php had a <form> spanning two <td> cells
    (<td><form>...</td><td><button>Save</button></form></td>). Browsers
    relocate a form opened inside a table cell, which could detach the Save
    button from its form. The form now sits in one cell and the button uses
    form="att-{id}" to submit it. Pre-existing, found while validating markup.

HOW THIS WAS VERIFIED
---------------------
Since there is no PHP runtime here, I wrote a branch-aware template renderer
(htmlcheck.py, not shipped) that picks one arm per <?php if: ?> / else / endif
and balances the resulting HTML. A plain regex counts both arms of every branch
at once and reports imbalances that never happen at runtime, which is why the
earlier checks kept producing false alarms.

interview-room.php: all 512 sampled render-path combinations produce balanced
markup. Every other page was run through the same check.

The tool counts server-side ifs as well as template ifs, so a small number of
combinations it reports are impossible in practice. Two such cases remain in
application-status.php; its static tag counts balance at 57/57 and each arm was
inspected by hand.

UNCHANGED
---------
Notes autosave with no save button, minimisable notes and chat, participant
pinning, screen sharing, the waiting room and admission flow, the timezone fix,
and post-meeting scoring and review. Only End meeting ends the interview.
