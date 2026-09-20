<?php
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/interview_lib.php';
$pdo=db();
$code=trim($_GET['code']??'');
$token=trim($_GET['t']??'');

// Staff are identified by their session. Candidates have no account, so they
// are identified by the per-interview token in their invite link — a room code
// on its own is no longer enough to get in.
$user=null;
if(!empty($_SESSION['user_id'])){
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? AND active=1 LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]); $user=$stmt->fetch() ?: null;
}
$isStaff = $user && in_array($user['role'] ?? '', ['admin','recruiter','hiring_manager','super_admin'], true);

$row=null; $isCandidate=false; $joinBlocked=''; $accessDenied=false; $candidateWaiting=false;

if($code!==''){
    if($isStaff){
        $stmt=$pdo->prepare("SELECT i.*,c.id candidate_id,c.first_name,c.last_name,c.email,c.profile_image,j.title,u.name interviewer_name
            FROM interviews i JOIN applications a ON a.id=i.application_id JOIN candidates c ON c.id=a.candidate_id
            JOIN jobs j ON j.id=a.job_id LEFT JOIN users u ON u.id=i.interviewer_id WHERE i.room_code=? LIMIT 1");
        $stmt->execute([$code]); $row=$stmt->fetch() ?: null;
    } elseif($token!==''){
        $row=interview_for_candidate_token($code,$token);
        if($row){
            $isCandidate=true;
            [$allowed,$joinBlocked]=candidate_can_join($row);
            if($allowed) $joinBlocked='';
            // A candidate stays in the waiting room until the interviewer
            // admits them. This is the backend gate, not a hidden button:
            // the meeting markup is simply not rendered for them before that.
            $candidateWaiting = ($row['candidate_request_state'] ?? 'none') !== 'admitted';
        } else {
            $accessDenied=true;
        }
    } else {
        // No session and no token: this link is not usable.
        $accessDenied=true;
    }
}

$pageTitle='Interview room'; $minimal=true; include __DIR__.'/includes/header.php';
?>
<?php if($accessDenied): ?>
<div class="hero"><div class="eyebrow">Acme Room</div><h1>This interview link is not valid.</h1>
<p>Interview links are personal to each candidate. Please use the link from your interview invitation, or check your application status to find it again.</p>
<div class="actions"><a class="btn secondary" href="application-status.php">Check application status</a></div></div>
<?php elseif(!$row): ?>
<div class="hero"><div class="eyebrow">Acme Room</div><h1>Room not found.</h1><p>This interview link is invalid or the interview has not been scheduled with a built-in room. Check the link and try again.</p></div>
<?php elseif($isCandidate && ($joinBlocked !== '' || $candidateWaiting)):
        // The candidate waits here until they are admitted. This covers being
        // early, the interviewer not having arrived, and a request that is with
        // the interviewer — the page moves itself along, no refreshing.
        [$wAllowed, $wMessage, $wState] = candidate_join_state($row);
        $headlines = [
            'not_available'     => 'Interview not available yet',
            'available'         => 'Interview is ready',
            'interviewer_ready' => 'Your interviewer is ready',
            'waiting'           => 'Waiting for your interviewer…',
            'requested'         => 'Waiting for approval…',
            'ended'             => 'Interview ended',
            'cancelled'         => 'Interview cancelled',
        ];
?>
<div class="card waiting-room" data-waiting
     data-code="<?=e($code)?>" data-token="<?=e($token)?>"
     data-state="<?=e($wState)?>">
  <div class="eyebrow">Interview waiting room</div>
  <h1 data-wr-headline><?=e($headlines[$wState] ?? 'Your interview')?></h1>
  <p class="meta"><?=e($row['title'])?></p>

  <div class="wr-meta">
    <div><span class="label">Interview type</span><strong><?=e(ucfirst($row['meeting_type']))?></strong></div>
    <div><span class="label">Interviewer</span><strong><?=e($row['interviewer_name'] ?? 'To be confirmed')?></strong></div>
    <div><span class="label">Date</span><strong><?=e(date('F j, Y', strtotime($row['starts_at'])))?></strong></div>
    <div><span class="label">Time</span><strong><?=e(date('g:i A', strtotime($row['starts_at'])))?></strong></div>
  </div>

  <div class="wr-status" data-wr-box>
    <span class="live-dot <?= in_array($wState,['waiting','requested'],true) ? '' : 'idle' ?>" data-wr-dot></span>
    <span data-wr-status><?=e($wMessage)?></span>
  </div>

  <div class="wr-actions" data-wr-actions>
    <?php if($wState === 'not_available'): ?>
      <button class="btn" type="button" disabled data-wr-join>Join interview</button>
      <p class="meta small">The room opens <?=e((string)interview_join_window())?> minutes before your start time. This page updates on its own.</p>
    <?php elseif($wState === 'requested'): ?>
      <p class="meta">Your interviewer has been notified. Please keep this page open.</p>
      <button class="btn secondary" type="button" data-wr-cancel>Cancel request</button>
    <?php elseif($wState === 'waiting'): ?>
      <p class="meta">You are in the waiting room. We will let you in as soon as your interviewer arrives.</p>
      <button class="btn secondary" type="button" data-wr-cancel>Leave waiting room</button>
    <?php else: ?>
      <button class="btn" type="button" data-wr-join>Join interview</button>
    <?php endif; ?>
  </div>

  <div class="actions"><a class="btn ghost" href="application-status.php">Back to my application</a></div>
</div>

<script>
/* The candidate never has to refresh. This heartbeats their presence, polls the
   room state, and reloads straight into the meeting the moment the interviewer
   admits them. */
(function () {
  var box    = document.querySelector('[data-waiting]');
  if (!box) return;
  var code   = box.dataset.code, token = box.dataset.token;
  var head   = box.querySelector('[data-wr-headline]');
  var status = box.querySelector('[data-wr-status]');
  var dot    = box.querySelector('[data-wr-dot]');
  var HEAD = {
    not_available: 'Interview not available yet',
    available: 'Interview is ready',
    interviewer_ready: 'Your interviewer is ready',
    waiting: 'Waiting for your interviewer\u2026',
    requested: 'Waiting for approval\u2026',
    ended: 'Interview ended',
    cancelled: 'Interview cancelled'
  };

  var busy = false;
  function call(action) {
    // One request at a time, so a double click cannot create two of anything.
    if (busy) return Promise.resolve(null);
    busy = true;
    var url = 'interview-access.php?code=' + encodeURIComponent(code) + '&t=' + encodeURIComponent(token) +
              '&action=' + encodeURIComponent(action);
    var opts = action === 'status' ? {} : { method: 'POST' };
    return fetch(url, opts)
      .then(function (r) { return r.json(); })
      .catch(function () { return null; })
      .finally(function () { busy = false; });
  }

  function render(data) {
    if (!data || !data.ok) return;
    if (data.request_state === 'admitted') { window.location.reload(); return; }
    box.dataset.state = data.state;
    if (head) head.textContent = HEAD[data.state] || 'Your interview';
    if (status) status.textContent = data.message || '';
    if (dot) dot.classList.toggle('idle', ['not_available','ended','cancelled'].indexOf(data.state) !== -1);
    if (data.state === 'ended' || data.state === 'cancelled') { clearInterval(poll); return; }
    // Once the room is open, take our place in the queue automatically.
    if (data.can_join && data.request_state === 'none') call('request').then(render);
  }

  document.querySelector('[data-wr-join]')?.addEventListener('click', function (ev) {
    ev.preventDefault();
    call('request').then(render);
  });
  document.querySelector('[data-wr-cancel]')?.addEventListener('click', function (ev) {
    ev.preventDefault();
    call('cancel').then(function () { window.location.href = 'application-status.php'; });
  });

  var poll = setInterval(function () {
    call('heartbeat').then(function (d) { return d || call('status'); }).then(render);
  }, 5000);
  call('heartbeat').then(render);
})();
</script>
<?php else:
    $saved = isset($_GET['saved']);
    // The room is exactly one screen at a time. Once the meeting has been
    // ended, the lobby and the live call are not rendered at all — reopening
    // the room goes straight to the review. Previously all three were siblings,
    // so an ended interview showed a "live" call with the completed review
    // stacked underneath it.
    $showReview = $isStaff && interview_accepts_review($row);
    $startsIso = date('c', strtotime($row['starts_at'])); $myName = $isStaff ? ($user['name'] ?? 'Interviewer') : ($row['first_name'].' '.$row['last_name']); $peerName = $isStaff ? ($row['first_name'].' '.$row['last_name']) : 'Interviewer'; ?>
<div class="room-wrap<?= $showReview ? ' is-review' : '' ?>" data-room data-interview-id="<?=$row['id']?>" data-room-code="<?=e($code)?>" data-starts-at="<?=e($startsIso)?>" data-title="<?=e($row['title'])?>" data-my-name="<?=e($myName)?>" data-is-host="<?=$isStaff?'1':'0'?>" data-token="<?=e($token)?>" data-csrf="<?=e(csrf_token())?>">
  <?php if(!$showReview): ?>
  <!-- STATE 1 — ENTRY. The only thing rendered on load. Neither the device
       check nor the meeting exists in the document yet; both live in <template>
       elements below and are cloned in when their state is reached. -->
  <section class="room-entry" data-screen="entry">
    <div class="lobby-head-row">
      <div class="eyebrow">Acme Room · <?=e($row['title'])?></div>
      <span class="countdown-chip" data-countdown hidden><?=icon('clock',13)?><span data-countdown-text>—</span></span>
    </div>
    <h1>Interview with <?=e($isStaff ? $row['first_name'].' '.$row['last_name'] : ($row['interviewer_name'] ?: 'your interviewer'))?></h1>
    <p class="meta"><?=icon('clock',14)?> <?=e(date('M j, Y · g:i A', strtotime($row['starts_at'])))?> · Room code <strong><?=e($code)?></strong></p>

    <div class="entry-grid">
      <div><span class="label">Position</span><strong><?=e($row['title'])?></strong></div>
      <div><span class="label">Interview type</span><strong><?=e(ucfirst($row['meeting_type']))?> · <?=e(ucfirst($row['interview_type']))?></strong></div>
      <div><span class="label">Interviewer</span><strong><?=e($row['interviewer_name'] ?? 'To be confirmed')?></strong></div>
      <div><span class="label">Status</span><strong><?=e(interview_state_label(interview_state($row)))?></strong></div>
    </div>

    <div class="actions">
      <button type="button" class="btn" data-enter-prejoin><?=icon('video',16)?> Join interview room</button>
      <a class="btn secondary" href="<?= $isStaff ? 'interviews.php' : 'application-status.php' ?>">Back</a>
    </div>
    <p class="meta small">You will check your camera and microphone before entering.</p>
  </section>

  <!-- STATE 2 — PRE-JOIN. Cloned in when "Join interview room" is pressed. -->
  <template data-prejoin-tpl>
  <div class="room-lobby" data-lobby>
    <div class="lobby-head-row">
      <div class="eyebrow">Acme Room · <?=e($row['title'])?></div>
      <span class="countdown-chip" data-countdown hidden><?=icon('clock',13)?><span data-countdown-text>—</span></span>
    </div>
    <h1>Interview with <?=e($row['first_name'].' '.$row['last_name'])?></h1>
    <p class="meta"><?=icon('clock',14)?> <?=e(date('M j, Y · g:i A', strtotime($row['starts_at'])))?> · Room code <strong><?=e($code)?></strong></p>
    <div class="device-check card">
      <div class="device-preview"><video data-local-preview autoplay muted playsinline></video><div class="device-preview-empty" data-preview-empty><?=icon('video',34)?><span>Camera preview</span></div></div>
      <div class="device-controls">
        <p><strong>Device check</strong><br><span class="meta">Grant camera and microphone access, then join when ready.</span></p>
        <button type="button" class="btn secondary small" data-enable-devices><?=icon('video',15)?> Enable camera &amp; mic</button>
        <div class="device-toggle-row">
          <button type="button" class="icon-toggle on" data-toggle-mic aria-pressed="true" title="Toggle microphone"><?=icon('mic',16)?></button>
          <button type="button" class="icon-toggle on" data-toggle-cam aria-pressed="true" title="Toggle camera"><?=icon('video',16)?></button>
        </div>
        <button type="button" class="btn wide" data-join-room><?=icon('check',16)?> Join interview room</button>
      </div>
    </div>
  </div>
  </template>

  <!-- STATE 3 — LIVE MEETING. Cloned in only after the device check is passed,
       and removed again on leave. Nothing inside this template is parsed as
       part of the page, so no video element, control, chat, notes panel, mini
       window or media stream exists before the user actually joins.

       Layout (assets/css/interview-room.css):
         .mr            fixed, full-viewport meeting shell
           .mr-topbar   identity + PiP / minimise / fullscreen
           .mr-body     [ .mr-stage (video grid) | .mr-panel (320px) ]
           .mr-bar      fixed 80px control bar: left info / centre / right
           .mr-mini     draggable floating window (minimised state)
           .mr-modal    settings
       Behaviour lives in assets/js/interview-room-ui.js. -->
  <template data-call-tpl>
  <div class="mr room-call" data-call data-view="grid" hidden>

    <header class="mr-topbar room-call-head">
      <div class="mr-topbar-left">
        <span class="mr-live"><span class="live-dot"></span>Live</span>
        <span class="mr-timer" data-timer>00:00</span>
        <span class="mr-rec-chip"><span class="mr-rec-dot"></span>Recording</span>
      </div>
      <div class="mr-topbar-mid">
        <strong><?=e($row['title'])?></strong>
        <span class="mr-room-code"><?=icon('link',12)?> <?=e($code)?></span>
      </div>
      <div class="mr-topbar-right">
        <button type="button" class="mr-icon-btn" data-toggle-pip title="Picture-in-picture" aria-label="Picture-in-picture"><?=icon('pip',16)?></button>
        <button type="button" class="mr-icon-btn" data-minimise title="Minimise to floating window" aria-label="Minimise to floating window"><?=icon('minimize',16)?></button>
        <button type="button" class="mr-icon-btn" data-toggle-fullscreen title="Fullscreen" aria-label="Fullscreen"><?=icon('expand',16)?></button>
      </div>
    </header>

    <div class="mr-body">
      <main class="mr-stage">
        <?php if($isStaff): ?>
        <!-- Admission prompt. Hidden until someone is actually waiting, and
             filled in by the poller so it appears without a refresh. -->
        <div class="admit-card mr-admit" data-admit-card hidden>
          <div class="admit-body">
            <span class="admit-avatar" data-admit-initials>?</span>
            <div class="admit-text">
              <strong data-admit-title>A candidate is requesting to join</strong>
              <span class="meta small" data-admit-sub></span>
            </div>
          </div>
          <div class="admit-actions">
            <a class="btn small ghost" data-admit-view href="candidate.php?id=<?=(int)$row['application_id']?>" target="_blank" rel="noopener">View candidate</a>
            <button type="button" class="btn small secondary" data-keep-waiting>Keep waiting</button>
            <button type="button" class="btn small" data-admit>Admit candidate</button>
          </div>
        </div>
        <?php endif; ?>

        <!-- Auto-fit grid: one row for two people, reflowing as tiles appear.
             data-stage / data-tile / data-pin are kept so the existing pin
             controller keeps working unchanged. -->
        <div class="mr-grid" data-stage data-grid data-pinned="" data-count="2">

          <div class="mr-tile" data-tile="self">
            <video data-local-preview-call autoplay muted playsinline></video>
            <div class="mr-tile-avatar" data-preview-empty-call>
              <span class="mr-avatar-initials"><?=e(strtoupper(mb_substr($myName,0,1)))?></span>
            </div>
            <div class="mr-tile-badges">
              <span class="mr-badge mr-badge-hand" data-hand-badge="self" hidden><?=icon('hand',13)?> Hand raised</span>
            </div>
            <span class="mr-tile-label">
              <span class="mr-mic" data-self-mic><?=icon('mic',12)?></span>
              <span class="mr-name tile-name">You<?php if($isStaff): ?> · Interviewer<?php endif; ?></span>
            </span>
            <button type="button" class="mr-pin pin-btn" data-pin="self" title="Pin yourself" aria-label="Pin yourself"><?=icon('expand',13)?></button>
          </div>

          <div class="mr-tile" data-tile="peer">
            <video data-remote-video autoplay playsinline hidden></video>
            <div class="mr-tile-avatar" data-peer-avatar>
              <?php if($isStaff && !empty($row['profile_image'])): ?>
                <img class="mr-avatar-img" src="<?=e($row['profile_image'])?>" alt="">
              <?php else: ?>
                <span class="mr-avatar-initials"><?=e(strtoupper(mb_substr($peerName,0,1)))?></span>
              <?php endif; ?>
            </div>
            <div class="mr-tile-badges">
              <span class="mr-badge mr-badge-hand" data-hand-badge="peer" hidden><?=icon('hand',13)?> Hand raised</span>
            </div>
            <span class="mr-tile-label">
              <span class="mr-mic" data-peer-mic><?=icon('mic',12)?></span>
              <span class="mr-name tile-name"><?=e($peerName)?></span>
            </span>
            <span class="mr-tile-status" data-peer-status>Waiting for the other participant to join…</span>
            <button type="button" class="mr-pin pin-btn" data-pin="peer" title="Pin <?=e($peerName)?>" aria-label="Pin participant"><?=icon('expand',13)?></button>
          </div>

          <div class="mr-tile mr-tile-screen" data-tile="screen" hidden>
            <video data-screen-video autoplay playsinline muted></video>
            <div class="mr-tile-avatar" data-screen-placeholder><?=icon('screen',34)?><span data-screen-label>Presented screen</span></div>
            <span class="mr-tile-label">
              <span class="mr-name tile-name" data-screen-owner>Presentation</span>
            </span>
            <button type="button" class="mr-pin pin-btn" data-pin="screen" title="Pin the presented screen" aria-label="Pin presentation"><?=icon('expand',13)?></button>
          </div>
        </div>

        <div class="mr-strip" data-strip hidden>
          <span class="meta small" data-strip-hint></span>
          <button type="button" class="btn small ghost" data-unpin><?=icon('grid',13)?> Grid view</button>
        </div>
      </main>

      <!-- Side panel: one 320px surface, tabbed, slides in from the right. -->
      <aside class="mr-panel" data-panel-root hidden aria-label="Meeting panel">
        <header class="mr-panel-head">
          <div class="mr-tabs" role="tablist" aria-label="Panel sections">
            <button type="button" class="mr-tab" role="tab" aria-selected="false" data-panel-tab="chat" id="mr-tab-chat" aria-controls="mr-pane-chat">
              Chat <span class="mr-unread" data-chat-unread hidden>0</span>
            </button>
            <button type="button" class="mr-tab" role="tab" aria-selected="false" data-panel-tab="people" id="mr-tab-people" aria-controls="mr-pane-people">People</button>
            <?php if($isStaff && !interview_accepts_review($row)): ?>
            <button type="button" class="mr-tab" role="tab" aria-selected="false" data-panel-tab="notes" id="mr-tab-notes" aria-controls="mr-pane-notes">Notes</button>
            <?php endif; ?>
            <button type="button" class="mr-tab" role="tab" aria-selected="false" data-panel-tab="info" id="mr-tab-info" aria-controls="mr-pane-info">Info</button>
          </div>
          <button type="button" class="mr-icon-btn" data-panel-close title="Close panel" aria-label="Close panel">&times;</button>
        </header>

        <section class="mr-pane" data-panel="chat" id="mr-pane-chat" role="tabpanel" aria-labelledby="mr-tab-chat" hidden>
          <div class="mr-chat-log" data-chat-log></div>
          <form class="mr-chat-form" data-chat-form>
            <input type="text" name="message" placeholder="Send a message to everyone…" autocomplete="off" maxlength="500" aria-label="Message">
            <button type="submit" aria-label="Send message"><?=icon('send',15)?></button>
          </form>
          <p class="mr-chat-note" data-chat-note>Messages are delivered directly to the other participant and are not stored.</p>
        </section>

        <section class="mr-pane" data-panel="people" id="mr-pane-people" role="tabpanel" aria-labelledby="mr-tab-people" hidden>
          <div class="mr-person">
            <span class="mr-person-avatar"><?=e(strtoupper(mb_substr($myName,0,1)))?></span>
            <div class="mr-person-text">
              <strong><?=e($myName)?> (you)</strong>
              <span class="meta small" data-people-status-you>Mic on · Cam on</span>
            </div>
            <span class="mr-person-icons"><span class="mr-mic" data-people-mic-you><?=icon('mic',12)?></span></span>
          </div>
          <div class="mr-person">
            <span class="mr-person-avatar"><?=e(strtoupper(mb_substr($peerName,0,1)))?></span>
            <div class="mr-person-text">
              <strong><?=e($peerName)?></strong>
              <span class="meta small" data-people-status-peer>Not joined yet</span>
            </div>
            <span class="mr-person-icons"><span class="mr-mic" data-people-mic-peer><?=icon('mic',12)?></span></span>
          </div>
        </section>

        <?php if($isStaff && !interview_accepts_review($row)): ?>
        <!-- No <form> and no save button: notes autosave as they are typed, so
             nothing in this panel can submit the page or end the meeting. -->
        <section class="mr-pane" data-panel="notes" id="mr-pane-notes" role="tabpanel" aria-labelledby="mr-tab-notes" hidden>
          <div class="mr-notes-head">
            <span class="meta small rail-status" data-notes-status><?= $row['notes_updated_at'] ? 'Saved '.e(time_ago($row['notes_updated_at'])) : 'Not saved yet' ?></span>
          </div>
          <textarea data-notes-field rows="12" placeholder="Candidate explained their approach to…"><?=e($row['live_notes'] ?? '')?></textarea>
          <p class="meta small">Only the hiring team sees this. Everything saves as you type.</p>
        </section>
        <?php endif; ?>

        <section class="mr-pane" data-panel="info" id="mr-pane-info" role="tabpanel" aria-labelledby="mr-tab-info" hidden>
          <dl class="mr-info">
            <div><dt>Position</dt><dd><?=e($row['title'])?></dd></div>
            <div><dt>Interview type</dt><dd><?=e(ucfirst($row['meeting_type']))?> · <?=e(ucfirst($row['interview_type']))?></dd></div>
            <div><dt>Interviewer</dt><dd><?=e($row['interviewer_name'] ?? 'To be confirmed')?></dd></div>
            <div><dt>Scheduled</dt><dd><?=e(date('M j, Y · g:i A', strtotime($row['starts_at'])))?></dd></div>
            <div><dt>Room code</dt><dd><?=e($code)?></dd></div>
          </dl>
          <?php if($isCandidate): ?>
          <p class="meta small">Use the controls below to mute, turn off your camera, or leave. If you get disconnected, open your interview link again.</p>
          <div class="actions"><a class="btn secondary small" href="application-status.php" data-external-ok>My application</a></div>
          <?php endif; ?>
        </section>
      </aside>
    </div>

    <!-- Fixed control bar. Centre cluster = the meeting controls, right cluster
         = the panels, per Meet/Zoom convention. -->
    <footer class="mr-bar">
      <div class="mr-bar-left">
        <span class="mr-bar-title"><?=e($row['title'])?></span>
        <span class="mr-bar-sub" data-bar-clock></span>
      </div>

      <div class="mr-bar-center">
        <button type="button" class="mr-ctl is-on" data-toggle-mic-call aria-pressed="true" title="Mute / unmute (M)" aria-label="Mute microphone">
          <span class="mr-ctl-on"><?=icon('mic',20)?></span><span class="mr-ctl-off"><?=icon('mic-off',20)?></span>
        </button>
        <button type="button" class="mr-ctl is-on" data-toggle-cam-call aria-pressed="true" title="Start / stop camera (V)" aria-label="Turn camera off">
          <span class="mr-ctl-on"><?=icon('video',20)?></span><span class="mr-ctl-off"><?=icon('cam-off',20)?></span>
        </button>
        <button type="button" class="mr-ctl" data-toggle-share aria-pressed="false" title="Present your screen" aria-label="Present your screen"><?=icon('screen',20)?></button>
        <button type="button" class="mr-ctl" data-toggle-hand aria-pressed="false" title="Raise hand" aria-label="Raise hand"><?=icon('hand',20)?></button>
        <?php if($isStaff && !interview_accepts_review($row)): ?>
          <button type="button" class="mr-ctl mr-ctl-leave" data-end-meeting title="End the meeting for everyone" aria-label="End meeting"><?=icon('hangup',20)?><span class="mr-ctl-text">End</span></button>
        <?php else: ?>
          <button type="button" class="mr-ctl mr-ctl-leave" data-leave-room title="Leave the meeting" aria-label="Leave meeting"><?=icon('hangup',20)?><span class="mr-ctl-text">Leave</span></button>
        <?php endif; ?>
      </div>

      <div class="mr-bar-right">
        <button type="button" class="mr-ctl mr-ctl-quiet" data-toggle-panel="people" aria-pressed="false" title="Participants" aria-label="Participants">
          <?=icon('users',18)?><span class="mr-ctl-count" data-people-count>1</span>
        </button>
        <button type="button" class="mr-ctl mr-ctl-quiet" data-toggle-panel="chat" aria-pressed="false" title="Chat" aria-label="Chat">
          <?=icon('chat',18)?><span class="mr-dot" data-chat-dot hidden></span>
        </button>
        <button type="button" class="mr-ctl mr-ctl-quiet" data-open-settings title="Meeting settings" aria-label="Meeting settings"><?=icon('settings',18)?></button>
      </div>
    </footer>

    <!-- Shown in the page while the meeting is minimised. In a multi-page app a
         normal link would tear down the call, so everything here opens in a new
         tab and the meeting keeps running in this one. -->
    <section class="mr-minimised-page" data-minimised-page hidden>
      <div class="eyebrow">Meeting in progress</div>
      <h2><?=e($row['title'])?></h2>
      <p class="meta">The interview is still connected in this tab. Use the floating window to talk, or bring the meeting back to full screen.</p>
      <div class="actions">
        <button type="button" class="btn" data-mini-expand><?=icon('expand',15)?> Back to the meeting</button>
        <?php if($isStaff): ?>
          <a class="btn secondary" href="candidate.php?id=<?=(int)$row['application_id']?>" target="_blank" rel="noopener" data-external-ok>Open candidate profile</a>
          <a class="btn ghost" href="interviews.php" target="_blank" rel="noopener" data-external-ok>Interviews</a>
        <?php else: ?>
          <a class="btn secondary" href="application-status.php" target="_blank" rel="noopener" data-external-ok>My application</a>
        <?php endif; ?>
      </div>
    </section>

    <!-- Floating window. Used when the meeting is minimised inside this page and
         as the fallback when the browser refuses native Picture-in-Picture.
         Its <video> shares the MediaStream with the grid and stays muted, so the
         audio path is never touched when the view changes. -->
    <div class="mr-mini" data-mini data-place="bottom-right" hidden role="dialog" aria-label="Meeting, minimised">
      <div class="mr-mini-head" data-mini-drag title="Drag to move">
        <span class="mr-mini-live"><span class="live-dot"></span><span data-mini-timer>00:00</span></span>
        <button type="button" class="mr-mini-btn" data-mini-expand title="Back to the meeting" aria-label="Back to the meeting"><?=icon('expand',13)?></button>
      </div>
      <div class="mr-mini-video">
        <video data-mini-video autoplay playsinline muted></video>
        <div class="mr-mini-avatar" data-mini-avatar><span data-mini-initials>?</span></div>
        <span class="mr-mini-name" data-mini-name>Connecting…</span>
      </div>
      <div class="mr-mini-controls">
        <button type="button" class="mr-mini-btn is-on" data-mini-mic aria-pressed="true" title="Mute / unmute" aria-label="Mute microphone">
          <span class="mr-ctl-on"><?=icon('mic',14)?></span><span class="mr-ctl-off"><?=icon('mic-off',14)?></span>
        </button>
        <button type="button" class="mr-mini-btn is-on" data-mini-cam aria-pressed="true" title="Start / stop camera" aria-label="Turn camera off">
          <span class="mr-ctl-on"><?=icon('video',14)?></span><span class="mr-ctl-off"><?=icon('cam-off',14)?></span>
        </button>
        <button type="button" class="mr-mini-btn mr-mini-leave" data-mini-expand title="Expand to full screen" aria-label="Expand to full screen"><?=icon('expand',14)?></button>
      </div>
    </div>

    <!-- Settings. Record and layout live here rather than in a hidden overflow
         menu; both keep their original hooks. -->
    <div class="mr-modal" data-settings-modal hidden>
      <div class="mr-modal-box" role="dialog" aria-modal="true" aria-labelledby="mr-settings-title">
        <header class="mr-modal-head">
          <h2 id="mr-settings-title"><?=icon('settings',17)?> Meeting settings</h2>
          <button type="button" class="mr-icon-btn" data-settings-close aria-label="Close settings">&times;</button>
        </header>

        <label class="mr-setting">
          <input type="checkbox" data-setting="autoPip" checked>
          <span>
            <strong>Auto Picture-in-Picture</strong>
            <span class="meta small">Pop the video out when you switch tabs or windows. Browsers may refuse this without a click — the floating window is used instead.</span>
          </span>
        </label>

        <label class="mr-setting">
          <input type="checkbox" data-setting="miniOnBlur">
          <span>
            <strong>Show the floating window when I leave this tab</strong>
            <span class="meta small">The minimised player is waiting for you when you come back.</span>
          </span>
        </label>

        <div class="mr-setting mr-setting-block">
          <strong>Floating window position</strong>
          <div class="mr-place-grid" role="radiogroup" aria-label="Floating window position">
            <button type="button" class="mr-place" data-place-choice="top-left" role="radio" aria-checked="false">Top left</button>
            <button type="button" class="mr-place" data-place-choice="top-right" role="radio" aria-checked="false">Top right</button>
            <button type="button" class="mr-place" data-place-choice="bottom-left" role="radio" aria-checked="false">Bottom left</button>
            <button type="button" class="mr-place" data-place-choice="bottom-right" role="radio" aria-checked="true">Bottom right</button>
          </div>
        </div>

        <label class="mr-setting">
          <input type="checkbox" data-setting="mirror" checked>
          <span><strong>Mirror my camera</strong><span class="meta small">Only affects how you see yourself.</span></span>
        </label>

        <div class="mr-modal-actions">
          <button type="button" class="btn secondary small" data-layout-toggle><?=icon('grid',15)?> Toggle layout</button>
          <button type="button" class="btn secondary small" data-record-toggle><?=icon('record',15)?> Record meeting</button>
          <button type="button" class="btn small" data-settings-close>Done</button>
        </div>
      </div>
    </div>
  </div>
  </template>

  <?php endif; /* !$showReview */ ?>

  <?php if($isStaff): ?>
  <?php if(!$showReview): ?>
  <!-- The meeting is running, so there is no scoring anywhere in this branch.
       Notes live in the right rail inside the call. The only thing left out
       here is the hidden form End meeting posts. -->
  <form method="post" action="interviews.php" class="end-meeting-form" data-end-form hidden>
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="end_meeting">
    <input type="hidden" name="interview_id" value="<?=$row['id']?>">
    <input type="hidden" name="room_code" value="<?=e($code)?>">
    <input type="hidden" name="live_notes" value="" data-end-notes>
  </form>

  <?php else: ?>
  <!-- The meeting is over, so the review is now the job in front of them. -->
  <aside class="card room-review" id="review">
    <div class="review-done-head">
      <div>
        <div class="eyebrow">Interview completed</div>
        <h2><?=e($row['first_name'].' '.$row['last_name'])?></h2>
        <p class="meta"><?=e($row['title'])?> · <?=e(ucfirst($row['meeting_type']))?><?php if($row['ended_at']): ?> · ended <?=e(time_ago($row['ended_at']))?><?php endif; ?></p>
      </div>
      <?= interview_state_badge($row) ?>
    </div>

    <?php if($saved): ?><div class="notice success"><?=icon('check',15)?> Interview review saved to the candidate profile.</div><?php endif; ?>

    <div class="review-notes-recall">
      <h3>Interview notes</h3>
      <?php if(trim((string)($row['live_notes'] ?? '')) !== ''): ?>
        <div class="recall-body"><?=nl2br(e($row['live_notes']))?></div>
        <p class="meta small">Recorded during the meeting<?php if($row['notes_updated_at']): ?> · last saved <?=e(date('M j, Y · g:i A',strtotime($row['notes_updated_at'])))?><?php endif; ?>. Kept separately from your final review.</p>
      <?php else: ?>
        <p class="meta">No notes were recorded during this meeting.</p>
      <?php endif; ?>
    </div>

    <form method="post" action="interviews.php" class="final-review-form">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="submit_review">
      <input type="hidden" name="interview_id" value="<?=$row['id']?>">
      <input type="hidden" name="room_code" value="<?=e($code)?>">
      <h3>Scoring &amp; review</h3>
      <div class="field score-field">
        <label>Overall score <span class="hint">1–100</span></label>
        <div class="score-row">
          <input type="range" min="1" max="100" name="score" value="<?=e((string)($row['score'] ?? 70))?>" data-score-range>
          <output class="score-output" data-score-out><?=e((string)($row['score'] ?? 70))?></output>
        </div>
      </div>
      <div class="field">
        <label>Review / feedback</label>
        <textarea name="review" rows="5" placeholder="Write your final interview review..."><?=e($row['feedback'] ?? '')?></textarea>
      </div>
      <div class="field">
        <label>Recommendation</label>
        <select name="recommendation">
          <option value="">Select recommendation…</option>
          <?php foreach(interview_recommendations() as $k=>$lbl): ?>
            <option value="<?=e($k)?>" <?= ($row['recommendation']??'')===$k?'selected':'' ?>><?=e($lbl)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn wide" type="submit"><?=icon('check',16)?> Submit interview review</button>
    </form>
  </aside>
  <?php endif; ?>
  <?php endif; ?>
</div>
<link rel="stylesheet" href="assets/css/interview-room.css?v=<?= @filemtime(__DIR__.'/assets/css/interview-room.css') ?: time() ?>">
<script>
(function(){

  /* ================= Interview Room state machine =================
     Three states, one mounted at a time:

        entry  -> prejoin -> live        (leave returns to entry)

     The pre-join and live screens live in <template> elements, so neither is
     part of the document until its state is entered, and the live meeting is
     removed from the DOM again when the user leaves. That is what stops a
     second set of video elements, controls, chat, notes and media streams
     existing underneath the pre-join screen — it is structural, not CSS.

     Because screens are mounted late, controls are bound by delegation rather
     than by querying for elements at load time. */
  // Modules that need the live meeting's elements register here and are run
  // after it is mounted, since those elements do not exist before that.
  window.ACME_ROOM = window.ACME_ROOM || { onMount: [], onUnmount: [] };
  function runHooks(list) { list.forEach(function (fn) { try { fn(); } catch (e) { console.error(e); } }); }

  /* Event bus. The meeting UI (assets/js/interview-room-ui.js) is a separate
     file, so it cannot see the locals in this closure. It subscribes here
     instead; late subscribers still receive the last value of a sticky event,
     because the UI module is mounted after the media is already running. */
  ACME_ROOM.events = ACME_ROOM.events || {};
  ACME_ROOM.last = ACME_ROOM.last || {};
  ACME_ROOM.on = function (name, fn) {
    (ACME_ROOM.events[name] = ACME_ROOM.events[name] || []).push(fn);
    if (name in ACME_ROOM.last) { try { fn(ACME_ROOM.last[name]); } catch (e) { console.error(e); } }
    return fn;
  };
  ACME_ROOM.emit = function (name, payload, sticky) {
    if (sticky !== false) ACME_ROOM.last[name] = payload;
    (ACME_ROOM.events[name] || []).forEach(function (fn) {
      try { fn(payload); } catch (e) { console.error(e); }
    });
  };

  const roomEl   = document.querySelector('[data-room]');
  const entryEl  = document.querySelector('[data-screen="entry"]');
  const preTpl   = document.querySelector('[data-prejoin-tpl]');
  const callTpl  = document.querySelector('[data-call-tpl]');
  let   screenState = 'entry';

  function onClick(selector, handler) {
    document.addEventListener('click', function (ev) {
      const el = ev.target.closest ? ev.target.closest(selector) : null;
      if (el) handler.call(el, ev, el);
    });
  }

  function mount(tpl) {
    if (!tpl || !roomEl) return null;
    const node = tpl.content.firstElementChild.cloneNode(true);
    roomEl.appendChild(node);
    return node;
  }

  function unmount(selector) {
    const el = roomEl && roomEl.querySelector(selector);
    if (el) el.remove();
  }

  function setScreen(next) {
    if (next === screenState) return;

    if (next === 'prejoin') {
      if (entryEl) entryEl.hidden = true;
      unmount('[data-call]');            // never two meetings
      unmount('[data-lobby]');
      const node = mount(preTpl);
      if (node) node.hidden = false;
      roomEl.classList.remove('is-live');
      document.body.classList.remove('in-meeting');

    } else if (next === 'live') {
      unmount('[data-lobby]');           // the device check is gone, not hidden
      const node = mount(callTpl);
      if (node) node.hidden = false;
      roomEl.classList.add('is-live');
      document.body.classList.add('in-meeting');
      runHooks(window.ACME_ROOM.onMount);

    } else {                              // entry
      runHooks(window.ACME_ROOM.onUnmount);
      unmount('[data-call]');
      unmount('[data-lobby]');
      unmount('.candidate-panel');
      if (entryEl) entryEl.hidden = false;
      roomEl.classList.remove('is-live');
      document.body.classList.remove('in-meeting');
    }
    screenState = next;
    roomEl.dataset.screenState = next;
  }

  onClick('[data-enter-prejoin]', function () { setScreen('prejoin'); });

  // Whenever the meeting is unmounted, release the devices. Leaving the page
  // entirely is covered by the browser, but returning to the entry screen is
  // not, so the camera light must not stay on.
  window.ACME_ROOM.onUnmount.push(function () {
    try {
      if (typeof stream !== 'undefined' && stream) stream.getTracks().forEach(function (t) { t.stop(); });
      if (typeof screenStream !== 'undefined' && screenStream) screenStream.getTracks().forEach(function (t) { t.stop(); });
    } catch (e) { /* already released */ }
  });
  const room = document.querySelector('[data-room]'); if(!room) return;
  const code = room.dataset.roomCode, title = room.dataset.title, myName = room.dataset.myName;
  let stream = null, screenStream = null, sharing = false;
  // NOTE: the preview <video> elements live inside <template> until their
  // screen is mounted, so they are looked up at use time, never at load time.

  /* ---------- countdown until interview start ---------- */
  const startsAt = new Date(room.dataset.startsAt).getTime();
  const chip = document.querySelector('[data-countdown]'), chipText = document.querySelector('[data-countdown-text]');
  function renderCountdown(){
    const diff = startsAt - Date.now();
    if (!chip) return;
    if (diff > 0) {
      chip.hidden = false; chip.classList.remove('live');
      const h = Math.floor(diff/3600000), m = Math.floor((diff%3600000)/60000), s = Math.floor((diff%60000)/1000);
      chipText.textContent = 'Starts in ' + (h>0?String(h).padStart(2,'0')+':':'') + String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
    } else if (diff > -3600000) {
      chip.hidden = false; chip.classList.add('live'); chipText.textContent = 'Starting now';
    } else { chip.hidden = true; }
  }
  renderCountdown(); setInterval(renderCountdown, 1000);

  async function enableDevices(){
    try {
      stream = await navigator.mediaDevices.getUserMedia({video:true,audio:true});
      ACME_ROOM.stream = stream;
      document.querySelectorAll('[data-local-preview],[data-local-preview-call]')
        .forEach(function (v) { v.srcObject = stream; });
      ACME_ROOM.emit('local-stream', stream);
    } catch(err){
      const t = document.querySelector('#toast'); if(t){t.textContent='Camera/mic permission was not granted — continuing in preview-only mode.'; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),3200);}
    }
  }
  onClick('[data-enable-devices]', enableDevices);
  /* Mic, camera, screen share, raise hand, chat and the panels are owned by
     assets/js/interview-room-ui.js, which binds them by delegation after the
     meeting is mounted. They used to be bound here with querySelector at load
     time, against elements that were still inside <template>, so the bindings
     silently matched nothing. */

  /* ---------- presence: tell the database this room is live, for the HR-app "meeting ongoing" banner ---------- */
  let heartbeatInterval = null;
  function pingActive(action){
    const payload = JSON.stringify({code, action});
    // sendBeacon survives the tab actually closing; fetch is used while the page is alive
    // since it lets us confirm the request went out (beacon has no response).
    if (action==='leave' && navigator.sendBeacon) {
      navigator.sendBeacon('room-presence.php', new Blob([payload], {type:'text/plain'}));
      return;
    }
    fetch('room-presence.php', {method:'POST', headers:{'Content-Type':'text/plain'}, body: payload, keepalive: true}).catch(()=>{});
  }
  function clearActive(){ pingActive('leave'); }

  let timerInterval=null, seconds=0;

  /* ---------- real WebRTC peer connection ----------
     Two browsers, no video SDK, no WebSocket (InfinityFree does not support
     them) — signaling is AJAX polling against api/interview/*.php, which
     store the SDP offer/answer and ICE candidates in MySQL
     (interview_signals / interview_ice_candidates). Google's public STUN
     server resolves each side's public address; no TURN server is
     configured, matching the "no paid video SDK, no TURN initially" brief —
     calls between two peers behind symmetric NATs may still fail to connect
     directly, which is a known limit of STUN-only signaling, not a bug here. */
  const isHost   = room.dataset.isHost === '1';
  const roomToken = room.dataset.token || '';
  const roomCsrf  = room.dataset.csrf || '';
  let pc = null, iceTimer = null, sdpTimer = null, statusTimer = null, lastIceId = 0, everConnected = false;

  function signalUrl(file, extra) {
    const params = new URLSearchParams({ code: code });
    if (!isHost && roomToken) params.set('t', roomToken);
    if (extra) Object.entries(extra).forEach(([k,v]) => params.set(k, v));
    return 'api/interview/' + file + '?' + params.toString();
  }
  function signalPost(file, fields) {
    const body = new FormData();
    if (isHost) body.append('csrf', roomCsrf);
    Object.entries(fields || {}).forEach(([k,v]) => body.append(k, v));
    return fetch(signalUrl(file), { method: 'POST', body }).then(r => r.json()).catch(() => ({ok:false}));
  }
  function signalGet(file, extra) {
    return fetch(signalUrl(file, extra)).then(r => r.json()).catch(() => ({ok:false}));
  }

  function setPeerConnected(connected) {
    const video = document.querySelector('[data-remote-video]');
    const p = document.querySelector('[data-peer-status]');
    const ps = document.querySelector('[data-people-status-peer]');
    if (connected) {
      if (video) video.hidden = false;
      if (p) p.textContent = 'Connected';
      if (ps) ps.textContent = 'Mic on · Cam on';
      everConnected = true;
    } else if (!everConnected) {
      if (p) p.textContent = 'Waiting for the other participant to join…';
    }
  }

  function startWebRTC() {
    pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
    ACME_ROOM.pc = pc;
    ACME_ROOM.emit('pc', pc);

    if (stream) stream.getTracks().forEach(t => pc.addTrack(t, stream));

    /* One data channel carries chat and participant state (mic, camera, raised
       hand, speaking). The offerer creates it; the answerer receives it. */
    if (isHost) {
      bindChannel(pc.createDataChannel('acme-room', { ordered: true }));
    } else {
      pc.ondatachannel = function (ev) { bindChannel(ev.channel); };
    }

    pc.ontrack = function (ev) {
      const video = document.querySelector('[data-remote-video]');
      if (video && ev.streams[0]) video.srcObject = ev.streams[0];
      if (ev.streams[0]) ACME_ROOM.emit('remote-stream', ev.streams[0]);
      setPeerConnected(true);
    };
    pc.onconnectionstatechange = function () {
      if (pc.connectionState === 'connected') setPeerConnected(true);
      if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') setPeerConnected(false);
    };
    pc.onicecandidate = function (ev) {
      if (ev.candidate) signalPost('save-ice.php', { candidate: JSON.stringify(ev.candidate.toJSON()) });
    };

    if (isHost) {
      pc.createOffer().then(offer => pc.setLocalDescription(offer)).then(() => {
        return signalPost('save-offer.php', { sdp: pc.localDescription.sdp });
      }).then(() => pollForAnswer());
    } else {
      pollForOffer();
    }

    iceTimer = setInterval(pollForIce, 2000);
  }

  function bindChannel(ch) {
    ACME_ROOM.channel = ch;
    ch.onopen = function () { ACME_ROOM.emit('channel-open', ch); };
    ch.onclose = function () { ACME_ROOM.emit('channel-closed', ch, false); };
    ch.onmessage = function (ev) {
      let msg = null;
      try { msg = JSON.parse(ev.data); } catch (e) { return; }
      if (msg && msg.type) ACME_ROOM.emit('peer:' + msg.type, msg, false);
    };
    ACME_ROOM.emit('channel', ch);
  }

  function pollForAnswer() {
    sdpTimer = setInterval(() => {
      signalGet('get-answer.php').then(res => {
        if (res && res.ok && res.sdp && pc && !pc.currentRemoteDescription) {
          clearInterval(sdpTimer);
          pc.setRemoteDescription({ type: 'answer', sdp: res.sdp }).catch(() => {});
        }
      });
    }, 1500);
  }

  function pollForOffer() {
    sdpTimer = setInterval(() => {
      signalGet('get-offer.php').then(res => {
        if (res && res.ok && res.sdp && pc && !pc.currentRemoteDescription) {
          clearInterval(sdpTimer);
          pc.setRemoteDescription({ type: 'offer', sdp: res.sdp })
            .then(() => pc.createAnswer())
            .then(answer => pc.setLocalDescription(answer))
            .then(() => signalPost('save-answer.php', { sdp: pc.localDescription.sdp }))
            .catch(() => {});
        }
      });
    }, 1500);
  }

  function pollForIce() {
    signalGet('get-ice.php', { since: String(lastIceId) }).then(res => {
      if (!res || !res.ok) return;
      lastIceId = res.last_id || lastIceId;
      (res.candidates || []).forEach(c => { if (pc && c) pc.addIceCandidate(c).catch(() => {}); });
    });
  }

  function stopWebRTC() {
    ACME_ROOM.emit('teardown', null, false);
    ACME_ROOM.channel = null;
    if (iceTimer) clearInterval(iceTimer);
    if (sdpTimer) clearInterval(sdpTimer);
    if (statusTimer) clearInterval(statusTimer);
    if (pc) { try { pc.close(); } catch (e) {} pc = null; }
    signalPost('end.php', {});
  }
  window.ACME_ROOM.onUnmount.push(stopWebRTC);

  onClick('[data-join-room]', function(){
    // Pre-join -> live. The device check is removed from the document and the
    // meeting is mounted; the two never coexist.
    setScreen('live');
    // Reuse the stream the device check already opened rather than calling
    // getUserMedia a second time.
    const callVideo = document.querySelector('[data-local-preview-call]');
    if (callVideo && stream) {
      callVideo.srcObject = stream;
      const empty = document.querySelector('[data-preview-empty-call]');
      if (empty) empty.hidden = stream.getVideoTracks().some(function (t) { return t.enabled; });
    }
    pingActive('join');
    // Keep re-pinging while the call is actually open, so the HR app can tell
    // a live room apart from one that was simply opened once and abandoned.
    heartbeatInterval = setInterval(()=>pingActive('ping'), 4000);
    timerInterval = setInterval(()=>{seconds++;const m=String(Math.floor(seconds/60)).padStart(2,'0');const s=String(seconds%60).padStart(2,'0');const el=document.querySelector('[data-timer]');if(el)el.textContent=`${m}:${s}`;},1000);
    setPeerConnected(false);
    startWebRTC();
    ACME_ROOM.emit('joined', { stream: stream });
  });
  onClick('[data-leave-room]', function(ev, btnEl){
    const btn = btnEl;
    if (btn.dataset.busy === '1') return;          // one leave, not two
    if (!confirm('Leave this meeting? The interview stays open for everyone else.')) return;
    btn.dataset.busy = '1';
    btn.classList.add('is-busy');

    if(timerInterval) clearInterval(timerInterval);
    if(heartbeatInterval) clearInterval(heartbeatInterval);
    stopWebRTC();
    if(stream) stream.getTracks().forEach(t=>t.stop());
    if(screenStream) screenStream.getTracks().forEach(t=>t.stop());

    // Clears this participant's own presence only. Leaving never ends the
    // meeting and never changes meeting_state — only End meeting does that.
    const params = new URLSearchParams({ code: room.dataset.roomCode || '', action: 'leave' });
    <?php if($isCandidate): ?>params.set('t', <?=json_encode($token)?>);<?php endif; ?>
    const body = new FormData();
    body.append('csrf', <?=json_encode($_SESSION['csrf'] ?? '')?>);

    fetch('interview-access.php?' + params.toString(), { method: 'POST', body: body, keepalive: true })
      .catch(function () { /* leaving anyway */ })
      .finally(function () {
        // Tear the meeting down before navigating, so no stream or timer
        // survives the departure.
        setScreen('entry');
        window.location.href = <?= $isCandidate ? "'application-status.php'" : "'interviews.php'" ?>;
      });
  });
  window.addEventListener('beforeunload', clearActive);
  window.addEventListener('pagehide', clearActive);
})();
</script>
<?php endif; ?>

<script>
/* ---------- Interview notes: autosave only, no form, no save button ----------
   There is deliberately no <form> around the notes. Nothing here submits or
   navigates, so typing, autosaving, opening or closing notes can never end the
   meeting — only the End meeting control does that. */
// Registered rather than run now: these elements only exist once the live
// meeting has been mounted.
(window.ACME_ROOM = window.ACME_ROOM || { onMount: [], onUnmount: [] }).onMount.push(function () {

  const field = document.querySelector('[data-notes-field]');
  if (!field) return;

  const room     = document.querySelector('[data-room]');
  const status   = document.querySelector('[data-notes-status]');
  const endNotes = document.querySelector('[data-end-notes]');
  const code     = room?.dataset.roomCode || '';
  const csrf     = <?=json_encode($_SESSION['csrf'] ?? '')?>;

  let timer = null, lastSent = field.value, saving = false, queued = false;

  function setStatus(text, cls) {
    if (!status) return;
    status.textContent = text;
    status.className = 'meta small rail-status' + (cls ? ' ' + cls : '');
  }

  async function save() {
    if (saving) { queued = true; return; }
    if (field.value === lastSent) return;
    saving = true;
    const sending = field.value;
    setStatus('Saving…');
    try {
      const res = await fetch('interview-notes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf: csrf, code: code, live_notes: sending }),
        keepalive: true
      });
      const data = await res.json();
      if (data.ok) { lastSent = sending; setStatus('Saved ' + data.saved_at, 'is-saved'); }
      else setStatus('Not saved — check your connection', 'is-error');
    } catch (e) {
      setStatus('Offline — will retry', 'is-error');
    } finally {
      saving = false;
      // Anything typed while the last request was in flight goes next.
      if (queued) { queued = false; save(); }
    }
  }

  field.addEventListener('input', function () {
    setStatus('Unsaved changes');
    clearTimeout(timer);
    timer = setTimeout(save, 900);   // debounce: one request per pause, not per keystroke
  });

  // Blur and page-hide are extra safety nets, not the primary mechanism.
  field.addEventListener('blur', save);
  window.addEventListener('pagehide', function () {
    if (field.value !== lastSent && navigator.sendBeacon) {
      navigator.sendBeacon('interview-notes.php', new Blob(
        [JSON.stringify({ csrf: csrf, code: code, live_notes: field.value })],
        { type: 'application/json' }
      ));
    }
  });

  // End meeting carries the latest text with it, open panel or not.
  window.__collectNotes = function () { if (endNotes) endNotes.value = field.value; };
});

/* ---------- Interviewer presence and candidate admission ----------
   Heartbeating tells the candidate's waiting room that the interviewer has
   arrived. Polling surfaces a waiting candidate. Neither one ends the meeting:
   nothing here submits a form or navigates. */
// Registered rather than run now: these elements only exist once the live
// meeting has been mounted.
(window.ACME_ROOM = window.ACME_ROOM || { onMount: [], onUnmount: [] }).onMount.push(function () {

  const room = document.querySelector('[data-room]');
  const card = document.querySelector('[data-admit-card]');
  if (!room) return;

  const code = room.dataset.roomCode || '';
  const csrf = <?=json_encode($_SESSION['csrf'] ?? '')?>;
  // Reaching this hook means the meeting is mounted, so we are in the call.
  let inCall = true, busy = false;

  function call(action) {
    if (busy) return Promise.resolve(null);
    busy = true;
    const body = new FormData();
    body.append('csrf', csrf);
    return fetch('interview-access.php?code=' + encodeURIComponent(code) + '&action=' + action,
                 { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .catch(function () { return null; })
      .finally(function () { busy = false; });
  }

  function render(data) {
    if (!data || !data.ok || !card) return;
    const req = data.pending_request;
    if (!req) { card.hidden = true; return; }
    const name = req.name || 'The candidate';
    card.querySelector('[data-admit-title]').textContent = name + ' is requesting to join';
    card.querySelector('[data-admit-initials]').textContent = (name.trim()[0] || '?').toUpperCase();
    card.querySelector('[data-admit-sub]').textContent =
      (req.email ? req.email + ' · ' : '') +
      (req.state === 'waiting' ? 'In the waiting room' : 'Waiting for your approval');
    card.hidden = false;
  }

  document.querySelector('[data-admit]')?.addEventListener('click', function () {
    call('admit').then(function (d) { if (card) card.hidden = true; render(d); });
  });
  document.querySelector('[data-keep-waiting]')?.addEventListener('click', function () {
    // Not a rejection — the request stays open so they can be admitted later.
    if (card) card.hidden = true;
    call('keep_waiting');
  });

  // One timer per mount: clear any left over from a previous join.
  if (window.ACME_ROOM.admitTimer) clearInterval(window.ACME_ROOM.admitTimer);
  window.ACME_ROOM.admitTimer = setInterval(function () {
    if (!inCall) return;
    call('heartbeat').then(render);
  }, 5000);
  window.ACME_ROOM.onUnmount.push(function () {
    clearInterval(window.ACME_ROOM.admitTimer);
    window.ACME_ROOM.admitTimer = null;
    inCall = false;
  });
});

/* The side panel (Chat, People, Notes, Info) is owned by
   assets/js/interview-room-ui.js. The old stacked "rail" controller that lived
   here was removed with the markup it drove. */

/* ---------- End meeting from the control bar ---------- */
(window.ACME_ROOM = window.ACME_ROOM || { onMount: [], onUnmount: [] }).onMount.push(function () {

  const btn  = document.querySelector('[data-end-meeting]');
  const form = document.querySelector('[data-end-form]');
  if (!btn || !form) return;
  btn.addEventListener('click', function () {
    if (btn.dataset.busy === '1') return;        // one submission, not two
    if (typeof window.__collectNotes === 'function') window.__collectNotes();
    if (!confirm('End the meeting now? This ends the interview for everyone. You will then be able to score and review it.')) return;
    btn.dataset.busy = '1';
    btn.classList.add('is-busy');
    form.submit();
  });
});

/* ---------- Pinning: this viewer's layout only ---------- */
// Registered rather than run now: these elements only exist once the live
// meeting has been mounted.
(window.ACME_ROOM = window.ACME_ROOM || { onMount: [], onUnmount: [] }).onMount.push(function () {

  const room  = document.querySelector('[data-room]');
  const stage = document.querySelector('[data-stage]');
  if (!room || !stage) return;

  const strip = document.querySelector('[data-strip]');
  const hint  = document.querySelector('[data-strip-hint]');
  const KEY   = 'acme-pin:' + (room.dataset.roomCode || '');

  function nameFor(key) {
    const tile = stage.querySelector('[data-tile="' + key + '"]');
    return tile?.querySelector('.tile-name')?.textContent?.trim() || 'participant';
  }

  function apply(pinned) {
    // A hidden tile (nobody is presenting) cannot be the pinned one.
    const target = pinned ? stage.querySelector('[data-tile="' + pinned + '"]') : null;
    if (pinned && (!target || target.hidden)) pinned = '';

    stage.dataset.pinned = pinned || '';
    stage.querySelectorAll('[data-tile]').forEach(function (tile) {
      tile.classList.toggle('is-pinned', !!pinned && tile.dataset.tile === pinned);
    });
    stage.querySelectorAll('[data-pin]').forEach(function (b) {
      const on = !!pinned && b.dataset.pin === pinned;
      b.classList.toggle('on', on);
      b.title = on ? 'Unpin' : 'Pin ' + nameFor(b.dataset.pin);
    });
    if (strip) {
      strip.hidden = !pinned;
      if (pinned && hint) hint.textContent = nameFor(pinned) + ' is pinned for you only.';
    }
    try { localStorage.setItem(KEY, pinned || ''); } catch (e) {}
  }

  stage.addEventListener('click', function (ev) {
    const btn = ev.target.closest('[data-pin]');
    if (!btn) return;
    apply(stage.dataset.pinned === btn.dataset.pin ? '' : btn.dataset.pin);
  });

  document.querySelector('[data-unpin]')?.addEventListener('click', function () { apply(''); });

  // Keep the grid density in step with how many tiles are actually showing.
  function recount() {
    const visible = [...stage.querySelectorAll('[data-tile]')].filter(function (t) { return !t.hidden; });
    stage.dataset.count = String(visible.length);
    if (stage.dataset.pinned) apply(stage.dataset.pinned);
  }
  new MutationObserver(recount).observe(stage, { attributes: true, attributeFilter: ['hidden'], subtree: true });

  let saved = '';
  try { saved = localStorage.getItem(KEY) || ''; } catch (e) {}
  recount();
  apply(saved);
});

/* ---------- Score slider readout ---------- */
(function () {
  const range = document.querySelector('[data-score-range]');
  const out   = document.querySelector('[data-score-out]');
  if (!range || !out) return;
  const sync = function () { out.textContent = range.value; };
  range.addEventListener('input', sync);
  sync();
})();

/* ---------- Jump straight to the review when arriving from the queue ---------- */
(function () {
  if (window.location.hash === '#review') {
    document.getElementById('review')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
})();
</script>

<script src="assets/js/interview-room-ui.js?v=<?= @filemtime(__DIR__.'/assets/js/interview-room-ui.js') ?: time() ?>"></script>
<?php include __DIR__.'/includes/footer.php'; ?>
