Pipeline: approval-gated moves, responsive board, invisible sidebar scrollbar
===========================================================================

NO MIGRATION NEEDED
-------------------
Markup, CSS and JavaScript, plus one read-only JSON branch in pipeline.php.
The applications table, the move_stage POST contract and the audit log entry
are unchanged, except that move_stage now also accepts an optional `from`.

FILES
-----
  pipeline.php               page shell, skeleton columns, templates, JSON + POST handlers
  assets/pipeline.js         PipelineBoard, CandidateCard, ApprovalModal (rewritten)
  assets/css/pipeline.css    board, card, modal, skeleton and breakpoint styles (new)
  assets/app.js              stray global drop handler removed (see THE CANCEL BUG)
  assets/styles.css          .sidebar hidden scrollbar + inset focus rings
  includes/sidebar.php       <aside tabindex="-1">
  routes/web.php             board_data and move_stage documented

THE CANCEL BUG
--------------
The first line of assets/app.js still carried a generic drag-and-drop snippet
from the original prototype: every [draggable] element and [data-drop] column
got a `drop` handler that did `col.append(card)` and toasted "Candidate stage
updated". It ran alongside pipeline.js, so every drop moved the card straight
away. Cancel then only threw away pipeline.js's pending object. The card stayed
in the new column, which looked exactly like a committed move until the next
reload. The database was never written, but the screen said it was.

The snippet is deleted. Nothing else in the project used [draggable] or
[data-drop], and the new board uses neither: HTML5 drag-and-drop is replaced by
Pointer Events and the board calls preventDefault on dragstart.

SERVER CHANGES (pipeline.php)
-----------------------------
  GET  ?action=board_data[&job&department&q]
       -> { ok, candidates: Candidate[] }. Same query and filters as before,
          same candidate_documents fallback. Read-only; login still required.
  POST action=move_stage, csrf, id, stage, override, from (new, optional)
       -> 409 { error: 'stale' } when `from` no longer matches the database,
          so a board that is out of date cannot silently overwrite a colleague's
          move. The skip rule and audit log are unchanged.
  Skip override now uses is_admin_level(): Super Admin can override like Admin
  (previously only the literal 'admin' role could).

COMPONENTS (assets/pipeline.js)
-------------------------------
No framework, so "props" are plain objects passed to functions.

Candidate (one record from board_data)
  id:number            application id, the board's key
  stage:string         COMMITTED stage. Changes only after a successful save
  name, initials, title, updatedAgo:string
  aiScore:?number  resume:?string  profileImage:?string
  noteCount:number  latestNote:?string  screeningScore, interviewScore:?number|string

PendingMove
  id:number
  original_stage_id:string   where the card is committed
  proposed_stage_id:string   where it was dropped
  override:boolean           admin skip override, sent with the commit

PipelineBoard  (the IIFE; mounts on [data-pipeline-board])
  Reads from the DOM:  data-csrf, data-can-override ("1"/"0"), data-query,
                       window.PIPELINE_STAGE_LABELS, window.PIPELINE_TRANSITION_MESSAGES
  State:
    phase     loading | idle | dragging | lifted | pending | committing |
              reverting | settling | error
    byStage   { [stage]: Candidate[] }  display order per column
    byId      Map<id, Candidate>
    drag      pointer session { pointerId, pointerType, id, originStage,
              active, overlay, baseRect, targetStage, ... }
    lift      keyboard pick-up { id, originStage, targetStage }
    pending   PendingMove or null
    arriving  { id, stage }  card kept invisible while a clone flies onto it
  While a move is pending, the SAME Candidate object is in both columns:
  in the proposed column it renders as .is-pending, in the original column as
  .is-ghost. Counts ignore the ghost.
  Event handlers:
    pointerdown / pointermove / pointerup / pointercancel   pointer sensor
    touchmove (non-passive, board only)   blocks page scroll ONLY while a
                                          touch drag is active
    keydown (board)   keyboard sensor
    click (board)     opens the candidate preview when idle
    scroll (each column, passive)   re-windows virtual columns
    resize, matchMedia(max-width:480px) change, pagehide, pageshow

CandidateCard
  create(candidate) -> HTMLElement   from <template id="pb-card-template">
  update(node, candidate, flags)     flags = { ghost, pending, source, lifted, arriving }
  Every value goes in via textContent or an href/src attribute, never innerHTML.
  Card classes: .is-pending (striped + glowing, "Pending approval" tag),
  .is-ghost (dashed, 40% opacity, inert), .is-drag-source (35% opacity,
  touch-action:none), .is-lifted (keyboard), .is-arriving (opacity 0),
  .is-pressing (long-press ring).

ApprovalModal
  open(props)
    props = { id, name, from, to, warning, tone: 'info'|'blocked',
              blocked, requireAck, confirmLabel,
              onConfirm(), onCancel(reason: 'cancel'|'backdrop'|'escape') }
  close()            hides the modal. Does NOT call onCancel; the board owns state
  setBusy(bool)      while saving: Confirm shows "Saving…", and Cancel, backdrop
                     and Escape are all ignored
  showError(message, fatal)   fatal disables Confirm (stale, skip_blocked, 400/404/419)
  isOpen() -> bool
  Backdrop rule: a click cancels only when BOTH the press and the release land
  on the overlay itself (e.target === e.currentTarget), so dragging a text
  selection out of the box never cancels. Escape is caught in the capture phase
  and stopped, so the sidebar/notification Escape handlers do not also fire.
  Focus goes into the dialog on open, Tab is trapped, and focus returns to the
  card after revert or commit.

The two-step "Move candidate?" then "Confirm stage change + checkbox" flow is
now one modal with one explicit Confirm. The checkbox survives only where it
means something: an admin overriding a stage skip must tick "I understand this
skips required stages" before Confirm enables. A non-admin skip shows the
blocked warning with Confirm disabled.

STATE MACHINE (drag lifecycle)
------------------------------
  [Loading] --board_data ok--> [Idle]
  [Loading] --fetch fails----> [Error] --Retry--> [Loading]

  Pointer (mouse: 5px travel; touch/pen: 350ms hold within 8px):
  [Idle] --pointerdown--> (press) --threshold met--> [Dragging]
         (press) --finger moves >8px first--> [Idle]   (it was a scroll)
  [Dragging] --pointerup over another column--> [Pending + Modal open]
  [Dragging] --pointerup over own column / outside board--> [Settling] --300ms--> [Idle]
  [Dragging] --Escape / pointercancel--> [Settling] --300ms--> [Idle]

  Keyboard (focused card):
  [Idle] --Space--> [Lifted] --arrows--> [Lifted: target=X]
  [Lifted] --Space/Enter on another stage--> [Pending + Modal open]
  [Lifted] --Space on own stage / Escape / Tab--> [Idle]

  Approval:
  [Pending + Modal open] --Confirm--> [Committing] --POST move_stage-->
        ok      --> [Idle]  (ghost removed, card committed, toast)
        failure --> [Pending + Modal open + error]  (retry, or Cancel)
  [Pending + Modal open] --Cancel | backdrop | Escape--> [Reverting]
        --transform 300ms cubic-bezier(0.2, 0.8, 0.2, 1)--> [Idle]
  [Committing] --Cancel | backdrop | Escape--> ignored until the request settles

  The API is called from exactly one place: commitPending(), which is wired
  only to the modal's Confirm button.

ANIMATION AND PERFORMANCE
-------------------------
  * The dragged card is a position:fixed clone on <body> (z-index 100), moved
    with translate3d in one requestAnimationFrame loop per drag. The loop also
    does the hit test (elementFromPoint; the clone is pointer-events:none) and
    edge auto-scroll for the board, the column under the pointer, and the page.
  * The drop and revert flights animate the same kind of clone from its
    current rect to the target slot, with transform and opacity only. A clone
    is used because a card inside a column would be clipped by that column's
    overflow as it crossed into another one. If the target slot is not visible
    (collapsed accordion column, scrolled out of view, or off the board
    sideways), the clone shrinks into the column header and fades.
  * prefers-reduced-motion: flights resolve instantly.
  * Virtualization: a column with more than 50 cards becomes a full-height
    spacer with only the visible rows (plus 6 overscan rows) mounted,
    positioned with transform. Cards are a fixed height (every line is single
    line with an ellipsis), so one measurement gives every row. The dragged,
    lifted, focused, pending and arriving cards stay mounted even when scrolled
    away. DOM order follows visual order and aria-posinset/aria-setsize are
    set, so Tab order and screen readers stay correct.
  * Skeletons: three per column, server-rendered, animation pb-pulse 1.5s
    infinite (named pb-pulse to stay out of the global keyframe namespace).

Z-INDEX (pipeline page)
-----------------------
  1     .pb-card
  10    .pb-col-head (sticky inside each column's scroll box)
  100   .pb-drag-overlay (on <body>, above every column and header)
  1000  .pb-modal and the candidate preview overlay

BREAKPOINTS (viewport width)
----------------------------
  <=480       stacked accordion. Each header is a toggle (aria-expanded).
              The first non-empty stage starts open. An open column scrolls
              within 70dvh. You can drop onto a collapsed header, and the target
              column opens to show the pending card. The modal becomes a
              bottom sheet.
  481-1024    horizontal swipe board: overflow-x:auto, scroll-snap-type:x
              mandatory, columns min(300px, 80%). Snap is switched off while a
              drag is active so edge auto-scroll can move freely.
  1025-1440   kanban, columns flex:1 1 0, min 200px (the board still scrolls
              sideways if the expanded sidebar leaves too little room).
  >=1441      same, 18px gaps, min 240px columns.
  Touch drags need the long press at EVERY width, not only on phones: on a
  tablet a plain swipe scrolls the board sideways.

SIDEBAR SCROLLBAR
-----------------
  .sidebar keeps overflow-y:auto and adds scrollbar-width:none (Firefox),
  -ms-overflow-style:none (IE/legacy Edge), ::-webkit-scrollbar{display:none}
  (Chrome/Safari/Edge) and overscroll-behavior:contain (no scroll chaining into
  the page or out of the mobile drawer). No pointer-events or margin tricks.
  <aside tabindex="-1">: clicking blank sidebar space focuses the scroller, so
  arrows, Page Up/Down, Home and End scroll it. It is not a Tab stop, and
  .sidebar:focus has no ring. overflow-x:hidden clips outer outlines, so every
  focus ring inside the sidebar is drawn inset (outline-offset:-2px).

WHAT WAS VERIFIED
-----------------
php -l on pipeline.php and includes/sidebar.php, and node --check on
pipeline.js and app.js. A headless Chrome harness rendered the real
pipeline.php markup (PHP helpers stubbed) with a mocked fetch holding 136
candidates (130 in Applied, so that column is virtualized). It drove real
PointerEvents and KeyboardEvents in real time over the DevTools protocol:

  1300px  35/35   800px  35/35   320px (true 320px iframe)  11/11

Covered: skeleton to data. Virtualization (11 of 130 cards mounted, re-windowed
on scroll). Sticky header z10, card z1, drag clone z100, modal z1000. Drop opens
the modal and makes no request. Pending card and ghost appear and counts
update. Cancel, Escape and backdrop each revert with a transform 300ms
cubic-bezier(0.2, 0.8, 0.2, 1) flight and make no request. A press inside the
box released on the backdrop does not cancel. Skip is blocked for non-admins.
A drop on the card's own column settles back. Confirm sends exactly one POST
with id, stage and from. Keyboard lift, target, drop and Escape work, and focus
returns to the card. Edge auto-scroll works on the tablet board. No page
horizontal overflow at 320, 800 or 1300. At 320px the accordion defaults are
right, the toggle works, a touch that moves before 350ms does not drag, a long
press does, and pointercancel springs back.
Sidebar in Chrome: 0px scrollbar gutter, still scrollable, focus scrolls it,
and the aside is click-focusable but not a Tab stop.

NOT VERIFIED HERE (needs manual QA)
-----------------------------------
Real touch hardware (iOS Safari long press, callout suppression, touchmove
blocking). Firefox and Safari. Screen reader output. The real PHP endpoints
against MySQL (the harness mocked fetch). Visual review of both themes.

QA CHECKLIST
------------
Sidebar
[ ] No visible scrollbar in Chrome, Edge, Safari and Firefox, in the expanded,
    collapsed and mobile-drawer states.
[ ] Mouse wheel and trackpad scroll the sidebar. At the top or bottom limit,
    the page behind does NOT scroll.
[ ] Click blank sidebar space, then Up/Down, Page Up/Down, Home and End scroll it.
[ ] Tab through every nav item. Each shows a full, unclipped focus ring and
    scrolls into view.
[ ] The mobile drawer scrolls on touch without dragging the page behind it.

Approval and cancel
[ ] Drag a card to another stage. The modal opens, the card shows striped,
    glowing and "Pending approval" in the new column, and a dashed ghost is
    left in the old one.
[ ] Network tab: NO move_stage request until Confirm is clicked.
[ ] Cancel: the modal closes and the card glides back (about 300ms). Reload:
    the stage is unchanged.
[ ] Escape: the same.
[ ] Click the grey backdrop: the same. Press inside the box, drag out and
    release on the backdrop: the modal stays open.
[ ] Confirm: one request, a toast, the card is committed, and a reload shows
    the new stage and an audit_logs row.
[ ] Escape during a drag (before release): the card glides home, no modal.
[ ] Drop on the card's own column or outside the board: glides home, no modal.
[ ] Non-admin skip (Applied to Offer): blocked warning, Confirm disabled.
[ ] Admin or Super Admin skip: Confirm enables only after the acknowledgement.
[ ] Stale board: in tab A, move a candidate. In tab B (not reloaded), move the
    same one and Confirm. You get "Someone else moved…", Confirm is disabled,
    and Cancel reverts.
[ ] Offline, then Confirm: an error shows, nothing moves. Back online: Confirm
    works.
[ ] While "Saving…" is shown, Cancel, Escape and backdrop do nothing.

Keyboard and screen reader
[ ] Tab to a card, then Space, Right, Right, Space: the modal opens. Escape
    reverts and focus returns to the card.
[ ] Up/Down/Home/End move between cards, including in a 50+ column. Left/Right
    move between columns.
[ ] Enter opens the preview. Escape closes it and focus returns.
[ ] Announcements are heard for pick-up, target change, cancel and success.

Responsive and performance
[ ] 320px phone: no horizontal page scroll, stages stacked, headers toggle.
[ ] Phone: a normal vertical swipe on a card scrolls the page. A 350ms hold
    starts the drag. Dropping on a collapsed header works.
[ ] Tablet (481-1024): the board swipes sideways and snaps per column. Dragging
    to the edge auto-scrolls.
[ ] Desktop and ultra-wide: columns fill the width. Sticky headers stay visible
    while a column scrolls.
[ ] Z-index: the dragged card is never hidden behind headers or other cards,
    and the modal is above everything.
[ ] 100+ candidates in one stage: smooth scroll, fewer than 30 card nodes in
    that column (DevTools Elements), 60fps while dragging (Performance panel,
    no layout in the drag frames).
[ ] Skeletons pulse while loading. With an expired session: "Couldn't load…"
    plus a working Retry.
[ ] prefers-reduced-motion: no flights, and moves still work.
[ ] Light and dark themes: columns, pending stripes, ghost and modal are all
    legible.
