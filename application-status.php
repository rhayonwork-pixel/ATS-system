<?php
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/interview_lib.php';
$pdo=db();
$result=null; $error=''; $matches=[]; $lookupEmail=''; $withdrawn=false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    check_csrf();
    $wId = (int)($_POST['application_id'] ?? 0);
    $wEmail = trim($_POST['email'] ?? '');
    if (withdraw_application($pdo, $wId, $wEmail)) { $withdrawn = true; }
    header('Location: application-status.php?id='.$wId.'&email='.urlencode($wEmail).'&withdrawn=1'); exit;
}

if (isset($_GET['email']) && trim($_GET['email']) !== '') {
    $lookupEmail = trim($_GET['email']);
    $idRaw = trim($_GET['id'] ?? '');
    if (!filter_var($lookupEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($idRaw !== '') {
        $result = application_status_payload($pdo, (int)$idRaw, $lookupEmail);
        if (!$result) $error = 'We could not find an application matching that ID and email.';
    } else {
        $matches = applications_for_email($pdo, $lookupEmail);
        if (!$matches) $error = 'We could not find any applications under that email.';
    }
}
$justApplied = isset($_GET['new']) && $result;
$pageTitle='Application status'; $minimal=true; include __DIR__.'/includes/header.php';
?>
<div class="application-status-page">
<div class="hero"><div class="eyebrow">Candidate portal</div><h1>Track your application.</h1><p>Enter the email you applied with. If you don't have your application ID handy, leave that field blank and we'll list everything under that email.</p></div>

<form class="card status-lookup" method="get">
  <div class="status-lookup-grid">
    <div class="field"><label>Email <span class="req">*</span></label><input type="email" required name="email" value="<?=e($_GET['email']??'')?>" placeholder="you@example.com"></div>
    <div class="field"><label>Application ID <span class="hint">optional</span></label><input name="id" value="<?=e($_GET['id']??'')?>" placeholder="e.g. 10245"></div>
    <button class="btn" type="submit">Check Application Status</button>
  </div>
</form>

<?php if ($justApplied): ?>
  <div class="card status-new-banner">
    <div class="eyebrow">Application submitted</div>
    <p class="meta" style="margin-top:6px">Your application has been received.</p>
    <div class="eyebrow" style="margin-top:16px">Application ID</div>
    <div class="status-id-big">#<?=e((string)$result['id'])?></div>
    <p class="meta">Keep this ID together with your email address — bookmark this page or write it down. You'll need both to check your status later.</p>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="notice error" style="margin-top:22px"><?=e($error)?> Or <a href="jobs.php">browse open positions</a>.</div>
<?php endif; ?>

<?php if ($matches): ?>
  <div class="card status-matches">
    <div class="eyebrow">Applications under</div>
    <p style="margin:4px 0 0;font-weight:700"><?=e($lookupEmail)?></p>
    <div class="status-match-grid">
      <?php foreach ($matches as $m): ?>
        <div class="status-match-card">
          <div><strong><?=e($m['title'])?></strong><div class="meta small" style="margin-top:4px">Applied <?=e(date('M j, Y', strtotime($m['applied_at'])))?> · Application #<?=e((string)$m['id'])?></div></div>
          <a class="btn small secondary" href="application-status.php?id=<?=$m['id']?>&email=<?=urlencode($lookupEmail)?>">View Application Status →</a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($result): ?>
    <?php
    // Interviews scheduled for this application. Ownership is already proven
    // by the id + email lookup above, so nothing extra is asked of the
    // candidate — the join link carries a per-interview token.
    $myInterviews = upcoming_interviews_for_application((int)$result['id']);
    foreach ($myInterviews as $iv):
      [$canJoin, $joinNote, $joinState] = candidate_join_state($iv);
      $ivState = interview_state($iv);
    ?>
    <div class="card upcoming-interview">
      <div class="ui-head">
        <div>
          <div class="eyebrow">Upcoming interview</div>
          <h3><?=e($iv['title'])?></h3>
        </div>
        <span class="istate istate-<?=e($ivState)?>"><span class="istate-dot"></span><?=e(interview_state_label($ivState))?></span>
      </div>
      <div class="ui-grid">
        <div><span class="label">Interview type</span><strong><?=e(ucfirst($iv['meeting_type']))?> · <?=e(ucfirst($iv['interview_type']))?></strong></div>
        <div><span class="label">Interviewer</span><strong><?=e($iv['interviewer_name'] ?? 'To be confirmed')?></strong></div>
        <div><span class="label">Date</span><strong><?=e(date('F j, Y', strtotime($iv['starts_at'])))?></strong></div>
        <div><span class="label">Time</span><strong><?=e(date('g:i A', strtotime($iv['starts_at'])))?></strong></div>
      </div>
      <?php if ($iv['room_code'] && $iv['candidate_token']):
        $headline = [
          'interviewer_ready' => 'Your interviewer is ready',
          'available'         => 'Interview is ready',
          'admitted'          => 'You have been admitted',
          'waiting'           => 'Waiting for your interviewer',
          'requested'         => 'Waiting for approval',
          'not_available'     => 'Interview starts at ' . date('g:i A', strtotime($iv['starts_at'])),
          'ended'             => 'Interview ended',
          'cancelled'         => 'Interview cancelled',
        ][$joinState] ?? 'Interview';
        $btnLabel = in_array($joinState, ['interviewer_ready','admitted'], true) ? 'Join now' : 'Join interview';
        $roomUrl  = 'interview-room.php?code=' . urlencode($iv['room_code']) . '&t=' . urlencode($iv['candidate_token']);
      ?>
        <div class="ui-join" data-join-block
             data-code="<?=e($iv['room_code'])?>" data-token="<?=e($iv['candidate_token'])?>"
             data-url="<?=e($roomUrl)?>">
          <div class="ui-join-status state-<?=e($joinState)?>" data-join-state>
            <span class="join-dot"></span>
            <div>
              <strong data-join-headline><?=e($headline)?></strong>
              <span class="meta small" data-join-message><?=e($joinNote)?></span>
            </div>
          </div>
          <div class="ui-actions">
            <?php if ($canJoin): ?>
              <!-- Genuinely joinable, so a genuine link: no overlay, no
                   aria-disabled, no handler swallowing the click. -->
              <a class="btn" data-join-btn href="<?=e($roomUrl)?>"><?=icon('video',16)?> <span data-join-label><?=e($btnLabel)?></span></a>
            <?php else: ?>
              <!-- Genuinely unavailable, so a genuinely disabled button. The
                   poller replaces it with the link above the moment it opens. -->
              <button class="btn secondary" type="button" disabled data-join-btn><?=icon('video',16)?> <span data-join-label>Join interview</span></button>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif ($iv['meeting_url']): ?>
        <div class="ui-actions"><a class="btn" target="_blank" rel="noopener" href="<?=e($iv['meeting_url'])?>"><?=icon('video',16)?> Join <?=e($iv['meeting_provider'] ?: 'meeting')?></a></div>
      <?php else: ?>
        <p class="meta small">Joining details will be shared by your recruiter closer to the time.</p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

  <div class="card status-result">
    <div class="status-result-head">
      <div class="eyebrow">Application #<?=e((string)$result['id'])?></div>
      <h2 style="margin:6px 0 4px"><?=e($result['title'])?></h2>
      <p class="meta">Hi <?=e($result['first_name'])?> — you applied on <?=e(date('F j, Y',strtotime($result['applied_at'])))?>.</p>
    </div>

    <div class="status-result-body">
      <div class="status-summary">
        <div class="stat-block">
          <strong>Current status</strong>
          <span class="status-stage-badge"><?= $result['withdrawn'] ? 'Withdrawn' : e($result['stage_label']) ?></span>
        </div>
      </div>

      <?php if ($result['withdrawn']): ?>
        <div class="status-rejected-panel">
          <div class="notice">You withdrew this application. It's no longer moving through our hiring process. If you change your mind, you're welcome to <a href="jobs.php">apply again</a> or apply to another open role.</div>
        </div>
      <?php elseif (!$result['rejected']): ?>
        <div class="status-timeline-panel">
          <div class="status-panel-title">Application Progress</div>
          <div class="status-steps">
            <?php foreach ($result['timeline'] as $step):
              if ($step['key'] === $result['stage']) { $cls = 'status-step current'; $dot = '●'; $state = 'Current stage'; }
              elseif ($step['reached']) { $cls = 'status-step done'; $dot = '✓'; $state = 'Completed'; }
              else { $cls = 'status-step'; $dot = '○'; $state = 'Upcoming'; }
            ?>
              <div class="<?=$cls?>"><span class="dot"><?=$dot?></span><div class="step-label"><?=e($step['label'])?></div><div class="step-state"><?=e($state)?></div></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="status-rejected-panel">
          <div class="notice">Thanks for your interest — we've decided not to move forward with this application. We appreciate the time you invested.</div>
          <?php if ($result['feedback'] && $result['feedback']['notes']): ?>
            <div class="card" style="margin-top:14px"><div class="label">Feedback from our team</div><p style="margin-top:8px"><?=nl2br(e($result['feedback']['notes']))?></p></div>
          <?php endif; ?>
          <?php if ($result['suggestion']): ?>
            <div class="card" style="margin-top:14px"><div class="label">You might also like</div><p style="margin-top:8px"><strong><?=e($result['suggestion']['title'])?></strong><?php if($result['suggestion']['note']): ?> — <?=nl2br(e($result['suggestion']['note']))?><?php endif; ?></p><a class="btn secondary small" style="margin-top:8px" href="jobs.php">Browse Open Positions</a></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$result['withdrawn'] && !$result['rejected'] && $result['stage'] !== 'hired'): ?>
      <div class="status-withdraw-row">
        <button type="button" class="btn ghost small" data-open-withdraw>Withdraw Application</button>
      </div>
    <?php endif; ?>
  </div>
  <p class="meta status-footer-note">We'll update this page as your application moves forward — check back anytime with your email and application ID.</p>

  <?php if (isset($_GET['withdrawn'])): ?>
    <div class="notice success" style="margin-top:16px">Your application has been withdrawn.</div>
  <?php endif; ?>

  <?php if (!$result['withdrawn'] && !$result['rejected'] && $result['stage'] !== 'hired'): ?>
  <div class="modal-overlay" data-modal-withdraw hidden>
    <div class="modal-box">
      <h2>Withdraw Application?</h2>
      <p class="meta" style="margin-top:8px">Are you sure you want to withdraw your application for <strong><?=e($result['title'])?></strong>?</p>
      <div class="modal-warning">After withdrawal, your application will no longer continue through the current hiring process. This cannot be undone from this page.</div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="withdraw">
        <input type="hidden" name="application_id" value="<?=$result['id']?>">
        <input type="hidden" name="email" value="<?=e($lookupEmail)?>">
        <div class="modal-actions">
          <button type="button" class="btn ghost" data-close-withdraw>Cancel</button>
          <button type="submit" class="btn danger">Withdraw Application</button>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function(){
    var overlay = document.querySelector('[data-modal-withdraw]');
    var openBtn = document.querySelector('[data-open-withdraw]');
    var closeBtn = document.querySelector('[data-close-withdraw]');
    if (!overlay || !openBtn) return;
    openBtn.addEventListener('click', function(){ overlay.hidden = false; });
    closeBtn.addEventListener('click', function(){ overlay.hidden = true; });
    overlay.addEventListener('click', function(e){ if (e.target === overlay) overlay.hidden = true; });
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
</div>
<script>
/* The candidate should never have to refresh to find out the room opened. Each
   scheduled interview polls its own availability and rebuilds its button. */
(function () {
  var HEADLINES = {
    interviewer_ready: 'Your interviewer is ready',
    available: 'Interview is ready',
    admitted: 'You have been admitted',
    waiting: 'Waiting for your interviewer',
    requested: 'Waiting for approval',
    not_available: 'Interview not available yet',
    ended: 'Interview ended',
    cancelled: 'Interview cancelled'
  };

  document.querySelectorAll('[data-join-block]').forEach(function (block) {
    var statusEl   = block.querySelector('[data-join-state]');
    var headlineEl = block.querySelector('[data-join-headline]');
    var messageEl  = block.querySelector('[data-join-message]');
    var actions    = block.querySelector('.ui-actions');
    if (!statusEl || !actions) return;

    function paint(data) {
      statusEl.className = 'ui-join-status state-' + data.state;
      headlineEl.textContent = HEADLINES[data.state] || 'Interview';
      messageEl.textContent = data.message || '';
      var over = data.state === 'ended' || data.state === 'cancelled';
      var label = (data.state === 'interviewer_ready' || data.state === 'admitted') ? 'Join now' : 'Join interview';

      // Swap the element itself rather than toggling attributes, so an enabled
      // control is always a real link and never a disabled button restyled.
      if (data.can_join && !over) {
        actions.innerHTML = '<a class="btn" data-join-btn href="' + block.dataset.url + '">' +
                            '<span data-join-label>' + label + '</span></a>';
      } else {
        actions.innerHTML = '<button class="btn secondary" type="button" disabled data-join-btn>' +
                            '<span data-join-label>Join interview</span></button>';
      }
      return over;
    }

    var timer = setInterval(function () {
      fetch('interview-status.php?code=' + encodeURIComponent(block.dataset.code) +
            '&t=' + encodeURIComponent(block.dataset.token))
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { if (data && data.ok && paint(data)) clearInterval(timer); })
        .catch(function () { /* keep the last known state */ });
    }, 8000);
  });
})();
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
