<?php
/**
 * Candidate portal — application status.
 *
 * Layout is strictly top-down: page header, then the full-width horizontal
 * progress stepper for the application in focus, then "My Applications". The
 * old split view (a vertical status rail beside the timeline) is gone; there
 * is now one column and one reading order at every breakpoint.
 *
 * Two lookup modes still exist, but they no longer produce two different
 * pages. With an id, that application is the one in focus; with only an email,
 * the most recent still-open application is. Either way the full history is
 * listed below the stepper.
 */
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/documents.php';
require_once __DIR__.'/includes/interview_lib.php';
$pdo=db();
$result=null; $error=''; $apps=[]; $lookupEmail=''; $focusId=0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    check_csrf();
    $wId = (int)($_POST['application_id'] ?? 0);
    $wEmail = trim($_POST['email'] ?? '');
    withdraw_application($pdo, $wId, $wEmail);
    header('Location: application-status.php?id='.$wId.'&email='.urlencode($wEmail).'&withdrawn=1'); exit;
}

/* The accordion fetches one panel at a time from here. It returns a fragment,
   not a page: same markup as the server-rendered fallback below, so the two
   can never drift. Ownership is re-checked inside the payload function. */
if (($_GET['action'] ?? '') === 'detail') {
    $dEmail = strtolower(trim($_GET['email'] ?? ''));
    $detail = filter_var($dEmail, FILTER_VALIDATE_EMAIL)
        ? application_submission_payload($pdo, (int)($_GET['id'] ?? 0), $dEmail)
        : null;
    if (!$detail) { http_response_code(404); exit('<p class="cs-empty-line">We could not load this application.</p>'); }
    $lookupEmail = $dEmail;
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__.'/includes/application-detail.php';
    exit;
}

if (isset($_GET['email']) && trim($_GET['email']) !== '') {
    $lookupEmail = trim($_GET['email']);
    $idRaw = trim($_GET['id'] ?? '');
    if (!filter_var($lookupEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $apps = candidate_applications_for_email($pdo, $lookupEmail);
        if ($idRaw !== '') {
            $result = application_status_payload($pdo, (int)$idRaw, $lookupEmail);
            if (!$result) {
                $error = $apps
                    ? 'We could not find an application with that ID under this email — here is everything we do have.'
                    : '';   // no applications at all: the empty state says so
            }
        }
        if (!$result && $apps) {
            // No id (or a bad one): focus the most recent application that is
            // still moving, falling back to the most recent of any kind.
            $open = array_values(array_filter($apps, fn($a) => $a['status'] !== 'withdrawn' && $a['stage'] !== 'rejected'));
            $focus = $open[0] ?? $apps[0];
            $result = application_status_payload($pdo, (int)$focus['id'], $lookupEmail);
        }
        // "Nothing under this email" is not an error banner — it is the empty
        // state below, which explains it and offers somewhere to go next.
    }
}
$focusId = $result ? (int)$result['id'] : 0;
$justApplied = isset($_GET['new']) && $result;
$openDetail = (int)($_GET['detail'] ?? 0);      // no-JS fallback: render one panel open
$companyName = setting('company_name', 'Acme');

$pageTitle='Application status'; $minimal=true; include __DIR__.'/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/application-status.css?v=<?= @filemtime(__DIR__.'/assets/css/application-status.css') ?: time() ?>">

<div class="cs-page">

  <!-- 1. PAGE HEADER ------------------------------------------------------->
  <header class="cs-head">
    <p class="cs-eyebrow">Candidate portal</p>
    <h1>Track your application.</h1>
    <p class="cs-lede">Enter the email you applied with. If you don't have your application ID handy, leave that
      field blank — we'll show everything filed under that email.</p>
  </header>

  <form class="cs-lookup" method="get" data-lookup>
    <div class="field">
      <label for="cs-email">Email <span class="req" aria-hidden="true">*</span></label>
      <input id="cs-email" type="email" required name="email" autocomplete="email"
             value="<?=e($_GET['email']??'')?>" placeholder="you@example.com">
    </div>
    <div class="field">
      <label for="cs-id">Application ID <span class="hint">optional</span></label>
      <input id="cs-id" name="id" inputmode="numeric" value="<?=e($_GET['id']??'')?>" placeholder="e.g. 10245">
    </div>
    <button class="btn" type="submit">Check status</button>
  </form>

  <?php if ($justApplied): ?>
    <div class="cs-banner" role="status">
      <p class="cs-eyebrow">Application submitted</p>
      <p class="cs-banner-id">#<?=e((string)$result['id'])?></p>
      <p class="cs-lede">Keep this ID with your email address — you'll need both to check back later.</p>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['withdrawn'])): ?>
    <div class="cs-notice cs-notice-ok" role="status">Your application has been withdrawn.</div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="cs-notice cs-notice-err" role="alert"><?=e($error)?> Or <a href="jobs.php">browse open positions</a>.</div>
  <?php endif; ?>

  <!-- The whole results region is replaced by skeletons while a lookup is in
       flight (see assets/js/application-status.js). -->
  <div id="cs-results" data-results>

  <?php if ($result):
    $tone = candidate_status_tone($result['stage'], $result['status']);
    $closed = $result['withdrawn'] || $result['rejected'];
    $focusApp = null;
    foreach ($apps as $a) { if ((int)$a['id'] === $focusId) { $focusApp = $a; break; } }
  ?>

  <!-- 2. HORIZONTAL PROGRESS ----------------------------------------------->
  <section class="cs-progress" aria-labelledby="cs-progress-title">
    <div class="cs-progress-head">
      <div>
        <h2 id="cs-progress-title">Application progress</h2>
        <p class="cs-progress-sub">
          <?=e($result['title'])?> · Application #<?=e((string)$result['id'])?>
          <?php if ($focusApp && $focusApp['department']): ?> · <?=e($focusApp['department'])?><?php endif; ?>
        </p>
      </div>
      <span class="cs-chip tone-<?=e($tone['tone'])?>"><span class="cs-chip-dot" aria-hidden="true"></span><?=e($tone['label'])?></span>
    </div>

    <!-- Mobile: this scroller is the horizontal, snapping track. Desktop: it
         simply stops scrolling because the steps fit. One element, one DOM. -->
    <div class="cs-stepper-scroll" tabindex="0" role="group"
         aria-label="Progress through the hiring stages. Scroll sideways on a small screen.">
      <ol class="cs-stepper"<?= $closed ? ' data-closed="1"' : '' ?>>
        <?php foreach ($result['timeline'] as $i => $step):
          $isCurrent = !$closed && $step['key'] === $result['stage'];
          $isDone    = $step['reached'] && !$isCurrent;
          $state = $isCurrent ? 'current' : ($isDone ? 'done' : 'upcoming');
          $stateLabel = $isCurrent ? 'Current stage' : ($isDone ? 'Completed' : 'Upcoming');
        ?>
          <li class="cs-step is-<?=$state?>"<?= $isCurrent ? ' aria-current="step"' : '' ?>>
            <span class="cs-step-marker" aria-hidden="true"><?= $isDone ? '&#10003;' : ($i + 1) ?></span>
            <span class="cs-step-text">
              <span class="cs-step-label"><?=e($step['label'])?></span>
              <span class="cs-step-state"><?=e($stateLabel)?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>

    <?php if ($result['withdrawn']): ?>
      <div class="cs-outcome tone-neutral">
        <p>You withdrew this application, so it is no longer moving through the hiring process.
          You're welcome to <a href="jobs.php">apply again</a> or apply to another open role.</p>
      </div>
    <?php elseif ($result['rejected']): ?>
      <div class="cs-outcome tone-rejected">
        <p>Thanks for your interest — we've decided not to move forward with this application.
          We appreciate the time you invested.</p>
        <?php if ($result['feedback'] && $result['feedback']['notes']): ?>
          <div class="cs-outcome-note"><h3>Feedback from our team</h3><p><?=nl2br(e($result['feedback']['notes']))?></p></div>
        <?php endif; ?>
        <?php if ($result['suggestion']): ?>
          <div class="cs-outcome-note">
            <h3>You might also like</h3>
            <p><strong><?=e($result['suggestion']['title'])?></strong><?php if($result['suggestion']['note']): ?> — <?=nl2br(e($result['suggestion']['note']))?><?php endif; ?></p>
            <a class="btn small secondary" href="jobs.php">Browse open positions</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php
    /* Upcoming interviews belong to the application in focus, so they stay
       inside this section rather than becoming a third band between the
       stepper and the history. Ownership was proven by the id + email lookup;
       the join link carries its own per-interview token. */
    foreach (upcoming_interviews_for_application($focusId) as $iv):
      [$canJoin, $joinNote, $joinState] = candidate_join_state($iv);
      $ivState = interview_state($iv);
    ?>
      <div class="cs-interview">
        <div class="cs-interview-head">
          <div>
            <p class="cs-eyebrow">Upcoming interview</p>
            <h3><?=e($iv['title'])?></h3>
          </div>
          <span class="istate istate-<?=e($ivState)?>"><span class="istate-dot"></span><?=e(interview_state_label($ivState))?></span>
        </div>
        <div class="cs-interview-grid">
          <div><span class="cs-k">Type</span><strong><?=e(ucfirst($iv['meeting_type']))?> · <?=e(ucfirst($iv['interview_type']))?></strong></div>
          <div><span class="cs-k">Interviewer</span><strong><?=e($iv['interviewer_name'] ?? 'To be confirmed')?></strong></div>
          <div><span class="cs-k">Date</span><strong><?=e(date('F j, Y', strtotime($iv['starts_at'])))?></strong></div>
          <div><span class="cs-k">Time</span><strong><?=e(date('g:i A', strtotime($iv['starts_at'])))?></strong></div>
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
          <div class="cs-join" data-join-block
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
                <a class="btn" data-join-btn href="<?=e($roomUrl)?>"><?=icon('video',16)?> <span data-join-label><?=e($btnLabel)?></span></a>
              <?php else: ?>
                <button class="btn secondary" type="button" disabled data-join-btn><?=icon('video',16)?> <span data-join-label>Join interview</span></button>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif ($iv['meeting_url']): ?>
          <div class="ui-actions"><a class="btn" target="_blank" rel="noopener" href="<?=e($iv['meeting_url'])?>"><?=icon('video',16)?> Join <?=e($iv['meeting_provider'] ?: 'meeting')?></a></div>
        <?php else: ?>
          <p class="cs-empty-line">Joining details will be shared by your recruiter closer to the time.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if (!$closed && $result['stage'] !== 'hired'): ?>
      <div class="cs-progress-foot">
        <button type="button" class="btn ghost small" data-open-withdraw>Withdraw application</button>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- 3. MY APPLICATIONS ---------------------------------------------------->
  <?php /* Rendered for any valid lookup: with results it is the history grid,
           without them it is the empty state, which is the answer to "nothing
           found" rather than an error banner. */ ?>
  <?php if ($apps || ($lookupEmail && filter_var($lookupEmail, FILTER_VALIDATE_EMAIL))): ?>
  <section class="cs-apps" aria-labelledby="cs-apps-title">
    <div class="cs-apps-head">
      <h2 id="cs-apps-title">My applications</h2>
      <?php if ($apps): ?><p class="cs-apps-count"><?=count($apps)?> application<?=count($apps)===1?'':'s'?> under <?=e($lookupEmail)?></p><?php endif; ?>
    </div>

    <?php if ($apps): ?>
      <ul class="cs-grid" role="list">
        <?php foreach ($apps as $app):
          $aTone = candidate_status_tone($app['stage'], $app['status']);
          $aid = (int)$app['id'];
          $isFocus = $aid === $focusId;
          $isOpen = $openDetail === $aid;
          $detailUrl = 'application-status.php?action=detail&id='.$aid.'&email='.urlencode($lookupEmail);
        ?>
          <li class="cs-card<?= $isFocus ? ' is-focus' : '' ?>">
            <div class="cs-card-top">
              <p class="cs-card-org"><?=e($companyName)?><?php if ($app['department']): ?> · <?=e($app['department'])?><?php endif; ?></p>
              <span class="cs-chip tone-<?=e($aTone['tone'])?>"><span class="cs-chip-dot" aria-hidden="true"></span><?=e($aTone['label'])?></span>
            </div>
            <h3 class="cs-card-title"><?=e($app['title'])?></h3>
            <p class="cs-card-meta">
              Applied <time datetime="<?=e(date('c', strtotime($app['applied_at'])))?>"><?=e(date('M j, Y', strtotime($app['applied_at'])))?></time>
              · #<?=$aid?><?php if ($app['location']): ?> · <?=e($app['location'])?><?php endif; ?>
            </p>
            <?php if ($isFocus): ?><p class="cs-card-flag">Shown in the progress bar above</p><?php endif; ?>

            <div class="cs-card-actions">
              <?php if (!$isFocus): ?>
                <a class="btn small secondary" href="application-status.php?id=<?=$aid?>&email=<?=urlencode($lookupEmail)?>">View progress</a>
              <?php endif; ?>
              <button type="button" class="btn small ghost cs-toggle"
                      aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                      aria-controls="cs-detail-<?=$aid?>"
                      data-detail-url="<?=e($detailUrl)?>"
                      data-fallback="application-status.php?email=<?=urlencode($lookupEmail)?>&id=<?=$focusId?:$aid?>&detail=<?=$aid?>#cs-detail-<?=$aid?>">
                <span class="cs-toggle-label">View details</span>
                <span class="cs-toggle-caret" aria-hidden="true"></span>
              </button>
            </div>

            <div class="cs-detail" id="cs-detail-<?=$aid?>" <?= $isOpen ? '' : 'hidden' ?>>
              <?php if ($isOpen):
                $detail = application_submission_payload($pdo, $aid, $lookupEmail);
                if ($detail) include __DIR__.'/includes/application-detail.php';
              endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="cs-empty">
        <!-- Illustration: inline SVG, because this project has no image
             pipeline and an empty state should not cost a network request. -->
        <svg class="cs-empty-art" viewBox="0 0 160 120" role="img" aria-label="An empty folder">
          <rect x="24" y="30" width="112" height="72" rx="10" class="cs-art-back"/>
          <path d="M24 44a10 10 0 0 1 10-10h30l10 12h52a10 10 0 0 1 10 10v46a10 10 0 0 1-10 10H34a10 10 0 0 1-10-10z" class="cs-art-front"/>
          <rect x="52" y="66" width="56" height="6" rx="3" class="cs-art-line"/>
          <rect x="52" y="80" width="34" height="6" rx="3" class="cs-art-line"/>
        </svg>
        <h3>No applications yet</h3>
        <p>Nothing has been filed under this email address. When you apply, every submission shows up here
          with the answers and files you sent.</p>
        <a class="btn" href="jobs.php">Browse open jobs</a>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  </div><!-- /#cs-results -->

  <?php if ($result): ?>
    <p class="cs-foot-note">We update this page as your application moves forward — check back anytime with your
      email and application ID.</p>
  <?php endif; ?>

  <?php if ($result && !$result['withdrawn'] && !$result['rejected'] && $result['stage'] !== 'hired'): ?>
  <div class="modal-overlay cs-modal" data-modal-withdraw hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="cs-withdraw-title">
      <h2 id="cs-withdraw-title">Withdraw application?</h2>
      <p class="meta" style="margin-top:8px">Are you sure you want to withdraw your application for
        <strong><?=e($result['title'])?></strong>?</p>
      <div class="modal-warning">After withdrawal, your application will no longer continue through the current
        hiring process. This cannot be undone from this page.</div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="withdraw">
        <input type="hidden" name="application_id" value="<?=$focusId?>">
        <input type="hidden" name="email" value="<?=e($lookupEmail)?>">
        <div class="modal-actions">
          <button type="button" class="btn ghost" data-close-withdraw>Cancel</button>
          <button type="submit" class="btn danger">Withdraw application</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Skeletons. Cloned by the JS while a lookup or a detail panel is loading;
       never shown on a fully server-rendered paint, because there is nothing
       to wait for then. -->
  <template id="cs-skeleton-results">
    <section class="cs-progress cs-skel" aria-hidden="true">
      <span class="cs-sk cs-sk-line" style="width:32%"></span>
      <span class="cs-sk cs-sk-line cs-sk-sm" style="width:52%"></span>
      <div class="cs-skel-steps"><span class="cs-sk cs-sk-step"></span><span class="cs-sk cs-sk-step"></span><span class="cs-sk cs-sk-step"></span><span class="cs-sk cs-sk-step"></span><span class="cs-sk cs-sk-step"></span></div>
    </section>
    <section class="cs-apps cs-skel" aria-hidden="true">
      <span class="cs-sk cs-sk-line" style="width:24%"></span>
      <div class="cs-grid">
        <div class="cs-card"><span class="cs-sk cs-sk-line cs-sk-sm" style="width:40%"></span><span class="cs-sk cs-sk-line" style="width:70%"></span><span class="cs-sk cs-sk-line cs-sk-sm" style="width:55%"></span><span class="cs-sk cs-sk-btn"></span></div>
        <div class="cs-card"><span class="cs-sk cs-sk-line cs-sk-sm" style="width:46%"></span><span class="cs-sk cs-sk-line" style="width:62%"></span><span class="cs-sk cs-sk-line cs-sk-sm" style="width:50%"></span><span class="cs-sk cs-sk-btn"></span></div>
        <div class="cs-card"><span class="cs-sk cs-sk-line cs-sk-sm" style="width:38%"></span><span class="cs-sk cs-sk-line" style="width:74%"></span><span class="cs-sk cs-sk-line cs-sk-sm" style="width:44%"></span><span class="cs-sk cs-sk-btn"></span></div>
      </div>
    </section>
  </template>

  <template id="cs-skeleton-detail">
    <div class="cs-detail-inner cs-skel" aria-hidden="true">
      <div class="cs-detail-block"><span class="cs-sk cs-sk-line cs-sk-sm" style="width:30%"></span><span class="cs-sk cs-sk-line" style="width:90%"></span><span class="cs-sk cs-sk-line" style="width:76%"></span><span class="cs-sk cs-sk-line" style="width:84%"></span></div>
      <div class="cs-detail-block"><span class="cs-sk cs-sk-line cs-sk-sm" style="width:26%"></span><span class="cs-sk cs-sk-file"></span></div>
    </div>
  </template>

  <!-- Single live region: one announcement channel for panel opening, lookup
       progress and join-state changes, so a screen reader is never interrupted
       by three competing regions. -->
  <p class="sr-only" role="status" aria-live="polite" data-cs-live></p>
</div>

<script src="assets/js/application-status.js?v=<?= @filemtime(__DIR__.'/assets/js/application-status.js') ?: time() ?>" defer></script>

<?php include __DIR__.'/includes/footer.php'; ?>
