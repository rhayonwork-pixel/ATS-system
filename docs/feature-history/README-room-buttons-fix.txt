Interview Room: why the buttons were dead, and what was fixed
=============================================================

NO MIGRATION NEEDED
-------------------
Bug fixes and structure only.

THREE SEPARATE FAULTS, ALL REAL
-------------------------------

FAULT 1 — interviews.php: an unclosed <script> tag
--------------------------------------------------
    <script>document.querySelectorAll('input[name=meeting_mode]')...
    document.querySelectorAll('[data-open-room]')...
    <?php include __DIR__.'/includes/footer.php'; ?>      <-- no </script>

The block was never closed, so the browser swallowed the footer markup
(</div></body></html> and the <script src="app.js"> tag) as JavaScript. That is
a syntax error, so the whole block failed to parse: no handlers attached, and
app.js was never loaded at all.

Fixed: the tag is closed and the footer is emitted as markup again.

FAULT 2 — interviews.php: the Join button used a blocked popup
--------------------------------------------------------------
    a.addEventListener('click', e => {
      e.preventDefault();
      window.open(a.href, 'acme-room-' + code, 'noopener');
    });

preventDefault() cancelled the normal navigation, then window.open() was
stopped by the popup blocker. The result was a button that did nothing at all —
exactly "not clickable".

Fixed: opening a room is ordinary navigation now. The only handler left is a
short busy lock so a double click cannot start two joins.

FAULT 3 — interview-room.php: a ReferenceError killed every handler below it
----------------------------------------------------------------------------
This one was mine, introduced when the old tabbed side drawer was removed in an
earlier change. The cleanup deleted chatKey, loadChat() and renderChat() but
left their callers:

    ...
    input.value = ''; renderChat();      <- still called
    window.addEventListener('storage', ...renderChat());
    renderChat();                        <- runs on load -> ReferenceError

renderChat() threw immediately on load, which aborted the rest of that IIFE.
Everything defined after that point never ran — including the Leave handler,
the presence heartbeat and the unload cleanup. That is why Leave/End Meeting
appeared unclickable inside the room.

Fixed: the three definitions are restored, rendering into [data-chat-log], with
message text set via textContent so a message cannot inject markup.

To stop this class of bug recurring I ran a scan for functions called in inline
scripts but never defined in the same block, across every page. Only a false
positive (the `async` keyword) remains.

OVERLAYS AND Z-INDEX
--------------------
The Acme Assist orb is position:fixed at the bottom-right, roughly 230x93px,
z-index:30. In the meeting it overlapped the right-hand end of the control bar,
where End meeting and Leave sit, so those clicks landed on the orb.

  * .room-controls now sits at z-index 45, above it
  * the orb fades back and drops out of the way while body.in-meeting is set,
    and returns on hover or when opened
  * on tablet and phone the rail bottom sheet is z-index 40 and the control bar
    46, so the sheet can never bury the controls

LEAVE IS NOT END MEETING
------------------------
Previously Leave called the shared room-presence "leave", which set
room_status='idle' for the whole interview. A candidate leaving therefore made
the interviewer look absent to everyone else.

Now interview-access.php has a 'leave' action that clears ONLY the caller's own
presence — interviewer_last_seen for staff, candidate_last_seen for the
candidate — and deliberately does not touch meeting_state. Leaving is not
ending.

  * Candidate: confirm -> clear own presence -> back to application-status.php
  * Interviewer: confirm -> clear own presence -> back to interviews.php
  * End meeting: separate control, separate confirmation, posts the end form,
    updates the interview status, and is the only thing that ends the interview

Both controls carry a busy lock, so a double click cannot leave twice or submit
the end form twice.

ROUTING
-------
The two states were already separate pages; the broken script made them feel
like one screen because the Join button did nothing and the page never
progressed.

    Sidebar "Interviews"        ->  interviews.php   (the Interview Room page:
                                    scheduled interviews, who they are with,
                                    status, and the join action)
    "Join interview room"       ->  interview-room.php  (device-check lobby)
    "Join interview room" again ->  the live meeting

The action buttons are now labelled "Join interview room" and "Open interview
room" so the path reads the way it behaves. The sidebar entry is still
"Interviews" because that page also handles scheduling.

CONTROL AUDIT
-------------
Every data-* control hook in the room was checked against the JavaScript for a
bound handler. All buttons are bound: join, leave, end meeting, mic, camera,
present, raise hand, chat, participants, notes, more, record, layout,
fullscreen, pin, unpin, panel minimise, panel close, admit, keep waiting,
waiting-room join and cancel. The few unbound hooks are labels and containers
(data-chat-log, data-screen-label, data-wr-box) or a plain link
(data-admit-view), not controls.

VERIFIED
--------
  * php lexer: 52 files balanced
  * branch-aware template render: interview-room.php balanced across all 512
    sampled render paths; interviews.php likewise
  * inline-script reference scan: no undefined functions remain

UNCHANGED
---------
Meet-style layout, participant grid and pinning, notes with autosave and no
save button, minimisable notes and chat, participants, screen sharing,
minimise/maximise, waiting room and admission, the timezone fix, dark mode,
responsive behaviour, and post-meeting scoring and review.
