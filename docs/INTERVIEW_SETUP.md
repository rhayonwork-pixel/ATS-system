# Interview System Setup

## What's real now

Before this reorganization, `interview-room.php` showed a camera/mic
preview and a waiting room, but the "Connected" status you'd see after
joining was a hardcoded `setTimeout` — no video ever actually crossed
between HR and the candidate. That's now real:

- `RTCPeerConnection` with Google's public STUN server
  (`stun:stun.l.google.com:19302`), no TURN server, no paid SDK.
- Signaling (SDP offer/answer + ICE candidates) goes over AJAX polling
  against `api/interview/{save-offer,get-offer,save-answer,get-answer,
  save-ice,get-ice}.php`, backed by the new `interview_signals` /
  `interview_ice_candidates` tables — InfinityFree has no WebSocket
  support, so polling is the actual transport, by design, not a
  workaround.
- `api/interview/status.php` reports offer/answer/connected/ended state;
  `api/interview/end.php` marks a call ended so the other side's poller
  can stop.

## Full flow

```
HR schedules (interviews.php, or api/interview/schedule.php)
  → room_code + candidate_token generated, interview row saved
  → invite email sent (EmailService — see RESEND_SETUP.md)
  → candidate opens interview-room.php?code=...&t=...
  → waiting room: camera/mic preview, presence heartbeat
    (interview-access.php — unchanged)
  → HR admits candidate (interview-access.php action=admit — unchanged)
  → both sides click "Join" → startWebRTC() runs:
      HR (host):      createOffer → save-offer.php → poll get-answer.php
      Candidate:       poll get-offer.php → createAnswer → save-answer.php
      both sides:      onicecandidate → save-ice.php
                       poll get-ice.php every 2s for the other side's candidates
  → pc.ontrack fires → remote <video> shown, status → "Connected"
  → Notes (api/interview/notes.php) autosave to interviews.notes
  → Scorecard (api/interview/scorecard.php) → interviews.score/feedback,
    gated by the same interview_accepts_review() rule interviews.php uses
  → Leave/End → api/interview/end.php marks interview_signals.ended_at
```

## Known limitation — please test this specifically

STUN alone (no TURN) resolves each browser's public address and works
for most home/office networks, but two peers both behind **symmetric**
NATs (common on some corporate and mobile networks) may never find a
direct path and the call will get stuck at "Connecting…". This is an
inherent limit of STUN-only signaling, not a bug in this implementation
— the spec explicitly asked for STUN-only with "no TURN initially," so
that trade-off was made as instructed. If real-world testing shows this
happening often, the fix is adding a TURN server (e.g. Twilio's, or a
self-hosted coturn) to the `iceServers` list in `interview-room.php`.

## What to test yourself

Nothing here has been run against a live browser or a live database in
this environment — please verify, in order:
1. Schedule an interview between two staff/candidate browser sessions.
2. Both join, confirm camera/mic permission prompts appear and preview
   works (unchanged from before).
3. Confirm the remote video tile actually shows the other person, not
   just a "Connected" label.
4. Leave/End from each side and confirm the other side's poller stops
   (check `storage/logs/` for PHP errors if it doesn't).
