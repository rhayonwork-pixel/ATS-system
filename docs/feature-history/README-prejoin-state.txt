Interview Room: entry -> pre-join -> live meeting
=================================================

NO MIGRATION NEEDED
-------------------
Structure and state only.

THE PROBLEM
-----------
The device check and the live meeting were both written into the page and the
live one carried a `hidden` attribute:

    <div class="room-wrap">
      <div class="room-lobby">        <- device check, always in the document
      <div class="room-call" hidden>  <- full meeting, always in the document

So the meeting existed underneath the pre-join screen from the moment the page
loaded: a second video element, a second set of controls, the chat, the notes
panel and the rail were all parsed and present. Hiding is not absence.

THE FIX — THREE STATES, ONE MOUNTED AT A TIME
---------------------------------------------
    entry  ->  prejoin  ->  live          (leave returns to entry)

The pre-join and live screens now live in <template> elements. Content inside a
<template> is inert: the browser does not render it, does not run it, and does
not create its elements. Each screen is cloned into the page only when its
state is entered, and removed again when it is left.

    setScreen('prejoin')  clones the device check, removes any meeting node
    setScreen('live')     removes the device check, clones the meeting
    setScreen('entry')    removes both

This is structural. No display:none, visibility, opacity, z-index or covering
element is used to solve it.

VERIFIED AT SOURCE LEVEL
------------------------
Each of these appears exactly once in the file, and every one of them is inside
a <template>:

    live meeting container, device check, control bar, call video element,
    chat form, notes textarea, side rail, participant grid, admission prompt,
    candidate info panel

The entry screen and its "Join interview room" button are the only things in
the document on load.

WHAT THE USER SEES
------------------
    Interview Room page
        position, interview type, interviewer, status
        [ Join interview room ]  [ Back ]
            |
            v
    Pre-join / device check
        camera preview, Enable camera & mic, mic and camera toggles
        [ Join interview room ]
            |
            v
    Live meeting
        participant grid, controls below the camera, chat, notes,
        participants, End meeting / Leave

Candidates keep the existing waiting-room and approval flow: the waiting screen
is a separate server-rendered branch and is reached before any of this, so
admission logic is untouched.

CAMERA AND MICROPHONE
---------------------
getUserMedia is called in exactly one place — the device check. When the
meeting mounts, the existing MediaStream is attached to the meeting's video
element rather than a second stream being opened.

Tracks are stopped when the meeting is unmounted, which covers both leaving
and returning to the entry screen, so the camera indicator does not stay lit.

LATE BINDING
------------
Because the meeting is mounted after page load, controls that used
    document.querySelector('[data-x]').addEventListener(...)
would have bound to nothing. Two changes handle this:

  * Controls use delegation through a small onClick(selector, handler) helper,
    so they work no matter when the element appears.
  * Modules that need the meeting's elements (notes autosave, the rail panels,
    pinning, the admission poller, End meeting) register on
    window.ACME_ROOM.onMount and run after the meeting is mounted.

Timers and document-level listeners are guarded so rejoining does not stack a
second interval or a second Escape handler.

NO DUPLICATES
-------------
  * setScreen removes the other screen before mounting one, so two meeting
    interfaces cannot coexist
  * one getUserMedia call, one MediaStream
  * one set of controls, one chat, one notes panel, one participant grid
  * the admission poller clears its previous interval on each mount

PRESERVED
---------
Everything in the live meeting is the same markup as before: video tiles,
pinning, mic, camera, present, raise hand, chat, notes with autosave, the
participants panel, more options, record and layout toggles, minimise/maximise
(fullscreen), Leave, End meeting, the candidate admission prompt, the waiting
room, meeting status, dark mode and the responsive rules.

The only change is when the pre-join and meeting interfaces come into
existence.
