<?php
/** Expects $result (from application_status_payload()) in scope. Included by
 * application-status.php for the initial server-rendered paint; status-live.php
 * + the inline JS in application-status.php re-render this same structure on
 * every poll, so keep both in sync if you change this markup. */
?>
<div class="status-summary">
  <div class="stat-block">
    <strong>Current status</strong>
    <span class="status-stage-badge" id="status-pill"><?=e($result['stage_label'])?></span>
    <div class="status-live-badge" id="status-live-indicator"><span class="status-live-dot"></span>Live · Updated <span id="status-updated-ago">just now</span></div>
  </div>
</div>

<?php if (!$result['rejected']): ?>
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
