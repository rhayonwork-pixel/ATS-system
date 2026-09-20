Interview room: Meet/Zoom-style meeting UI, PiP and floating window
===================================================================

NO MIGRATION NEEDED — but 018 must be imported
----------------------------------------------
No new schema. The room needs migration 018_webrtc_interview_signals.sql
(interview_signals + interview_ice_candidates). It was missing from the local
database, so every call failed silently before this work; it is imported now.

FILES
-----
  interview-room.php                    live-meeting markup replaced; inline JS
                                        keeps the state machine, media and
                                        signalling and now publishes an event bus
  assets/css/interview-room.css         NEW — the whole meeting surface
  assets/js/interview-room-ui.js        NEW — controls, panel, chat, indicators,
                                        PiP, floating window, settings
  app/Services/InterviewSignalService.php  SDP terminator fix (see BUGS 3)
  includes/config.php                   four new icons: pip, minimize, info, hangup

THE ONE THING TO READ FIRST: THIS IS NOT AN SPA
-----------------------------------------------
The brief asks for a floating window that survives navigation "within the ATS
SPA". This app is ~45 separate PHP pages. Following any in-app link unloads the
document, and the RTCPeerConnection dies with it. No amount of CSS or JS keeps a
call alive across a full page load — only an SPA shell, or a call that lives in
its own window, can do that.

What is implemented instead, in order of how well it holds the call:

  1. Native Picture-in-Picture. The meeting keeps running in its tab; the video
     floats above every other window, including other applications. This is the
     real answer to "I switched tabs".
  2. The in-page floating window. A draggable mini player for when the person is
     still on the room page but wants the page back (it reveals a panel with
     links out).
  3. In-app links open in a NEW TAB while a call is live, with a toast saying
     so, and beforeunload warns if they try to leave the room tab anyway. The
     meeting survives because its tab is never unloaded.

If a call that follows the user from page to page is a hard requirement, that is
an architecture decision (SPA shell for the authenticated app, or open the room
in a popup window), not a styling task.

PICTURE-IN-PICTURE: WHAT BROWSERS ACTUALLY ALLOW
------------------------------------------------
requestPictureInPicture() requires transient user activation. Switching tabs is
not activation, so an automatic call CAN be refused with NotAllowedError. Safari
and installed PWAs honour the autoPictureInPicture hint (set on the active
video); most browsers do not. The implementation therefore:

  * sets video.autoPictureInPicture = true, so browsers that support it pop out
    by themselves;
  * tries requestPictureInPicture() on visibilitychange when the setting is on;
  * treats refusal as normal and falls back to the in-page floating window;
  * always offers a manual PiP button, which is a real click and always works.

Settings (persisted per browser in localStorage, key acme-room-settings):
auto-PiP on/off, "show the floating window when I leave this tab", floating
window corner, and mirror-my-camera.

AUDIO CONTINUITY — THE RULE
---------------------------
Minimising, PiP and panel changes are layout changes only. No track is stopped,
no srcObject is cleared, and the <video> carrying the remote audio is never
removed from the document. The floating window has its own <video> bound to the
SAME MediaStream and permanently muted, so there is exactly one audio path.
Native PiP re-uses the element that already carries the audio, so the stream is
never re-attached. The test suite asserts all of this.

LAYOUT
------
  .mr            fixed, full-viewport shell (dark in both app themes, like Meet)
    .mr-topbar   live dot, timer, title, room code; PiP / minimise / fullscreen
    .mr-body     .mr-stage (video grid) + .mr-panel (320px, slides in)
    .mr-bar      fixed bottom bar, 80px: left info / centre controls / right
    .mr-mini     draggable floating window, z-index 9999
    .mr-modal    settings, z-index 10000

  The grid is `repeat(auto-fit, minmax(min(100%, 340px), 1fr))`: one tile fills
  the row, two sit side by side, a third (a shared screen) reflows — no JS.
  Pinning switches the same container to a spotlight + strip.
  Centre cluster: mic, camera, present, raise hand, and a red End/Leave pill.
  Right cluster: participants (with a count), chat (with an unread dot),
  settings. Phone: single column, 76px bar, panel becomes a sheet.

INDICATORS
----------
  Talking      the active speaker's tile gets a green inset ring and the mic
               icon pulses. The local level comes from a WebAudio analyser; the
               REMOTE level comes from RTCRtpReceiver.getSynchronizationSources()
               audioLevel, because an analyser tap on a remote MediaStream reads
               silence in Chrome. A muted participant never lights up.
  Raised hand  an amber badge on the tile, mirrored to the other side.
  Unread chat  a count on the Chat tab and a red dot on the chat control, while
               the panel is closed, the tab is hidden, or the call is minimised.
  Mic state    per-tile and per-participant, driven by the peer's own state.

CHAT AND PARTICIPANT STATE NOW ACTUALLY REACH THE OTHER SIDE
------------------------------------------------------------
Chat used to be written to localStorage, so two participants in two browsers
never saw each other's messages. Chat, mute/camera state, raised hand and
"started presenting" now travel over an RTCDataChannel created with the offer.
Nothing is stored server-side, which the panel says out loud. Screen share also
replaces the outgoing video track (replaceTrack), so the other side sees the
presentation instead of only a local preview swap.

BUGS FOUND AND FIXED ALONG THE WAY
----------------------------------
  1. Mute and camera did nothing. The buttons were looked up with
     querySelector at load time, but they live inside <template> until the
     meeting is mounted, so the listeners bound to nothing. Same for the
     pre-join preview: the device check opened the camera and never showed it.
     Controls are now bound by delegation.
  2. Every call failed: interview_signals / interview_ice_candidates did not
     exist locally. Migration 018 imported.
  3. Every answer was rejected: "Failed to parse SessionDescription.
     a=max-message-size:262144 Invalid SDP line." The signalling endpoints
     trim() the SDP, which strips the final CRLF that RFC 4566 requires. It went
     unnoticed while the last line was an a=ssrc attribute Chrome tolerated;
     adding a data channel moved a=max-message-size to the end and broke every
     connection. InterviewSignalService now normalises the terminator on both
     save and read, which also repairs rows stored earlier.
  4. The "full viewport" meeting was not full viewport. .room-wrap carries a
     fadeInUp animation, and a transformed ancestor becomes the containing block
     for position:fixed descendants — so the shell, the control bar and the
     floating window were all sized to a 1273x20 box. Neutralised while
     body.in-meeting is set.
  5. Screen share and raise hand rendered red (the "your mic is off" colour)
     when idle, because the rule matched every aria-pressed="false" control.

WHAT WAS VERIFIED
-----------------
Two real Chrome browsers (separate profiles: one logged-in interviewer, one
candidate on a token link), the local PHP server, MySQL, and fake camera/mic
devices — a genuine two-peer WebRTC call over the polling signalling. 35 of 35
assertions pass:

  * both peers reach connectionState=connected; remote video attached
  * data channel opens; a chat message crosses; the unread badge appears while
    the panel is closed and clears when it opens
  * mute disables the real track and the other side sees the muted mic
  * raised hand appears on the peer's tile
  * the active speaker highlight appears for a talking participant and never for
    a muted one
  * control bar fixed, full width, 80px; panel 320px; two tiles in one row;
    the grid reflows to three tiles
  * PiP never throws; it either pops out or falls back to the floating window,
    and the remote stream stays attached either way
  * floating window: fixed at z-index 9999, shares the peer stream, stays muted
    while the main element keeps the audio, drags with pointer events, clamps
    inside the viewport, its mic button drives the real track and syncs the main
    bar, and expanding restores the meeting with the stream intact
  * tab switch with auto-PiP off falls back to the floating window
  * settings persist to localStorage
  * leaving unmounts the meeting, releases the devices and returns the candidate
    to their application; the other participant's room keeps working
  * 390px (device emulation): tiles stack, 76px bar, panel as a sheet, no
    sideways scroll

NOT VERIFIED HERE
-----------------
Real cameras and microphones; Safari and Firefox (PiP and autoPictureInPicture
behave differently there); actual PiP window rendering, which headless Chrome
refuses; iOS, where PiP for getUserMedia streams is restricted; screen sharing
against a real display picker; TURN-less connectivity between two symmetric
NATs, which is a pre-existing limit of the STUN-only setup.

QA CHECKLIST
------------
[ ] Two machines, two networks: both see and hear each other.
[ ] Mute, camera, present, raise hand: the state shows on both sides.
[ ] Chat crosses between the two participants; the unread dot appears when the
    panel is closed and clears when it opens.
[ ] The speaking ring follows whoever is talking and never a muted person.
[ ] Switch tabs: PiP pops out (or the floating window is waiting on return) and
    the audio never breaks mid-sentence.
[ ] Drag the floating window to each corner and off each edge: it stays on
    screen and comes back where it was left.
[ ] The floating window's mic/camera buttons match the main bar both ways.
[ ] Settings survive a reload; turning auto-PiP off stops the pop-out.
[ ] Click an in-app link during a call: it opens in a new tab and the call
    continues; closing the room tab warns first.
[ ] End meeting (staff) still records notes and opens the review; Leave
    (candidate) returns to the application status page.
[ ] Phone in portrait: tiles stack, controls reachable with a thumb, the panel
    covers the stage rather than squeezing it.
[ ] Screen reader: the control bar buttons announce their pressed state; the
    panel tabs announce selection.
