<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/analytics_lib.php';
require_login(['admin','recruiter','hiring_manager']);
$pdo = db();
$me  = current_user();
$myId = (int)$me['id'];
$isAdmin = is_admin_level($me);

// ---------------------------------------------------------------------------
// Whose analytics are we allowed to show?
//
// A recruiter only ever sees themselves. An Admin may look at the HR/Recruiters
// they provisioned. This is decided here, in the backend — the ?user= parameter
// is checked against that list rather than trusted.
// ---------------------------------------------------------------------------
$requested = (int)($_GET['user'] ?? 0);
$team = $isAdmin ? analytics_team_members($myId, is_super_admin($me)) : [];
$teamIds = array_map(static fn($m) => (int)$m['id'], $team);

$subjectId = $myId;
$subject   = $me;
$viewingOther = false;

if ($requested && $requested !== $myId) {
    if (!$isAdmin || !in_array($requested, $teamIds, true)) {
        deny_403('You can only view analytics for HR/Recruiters you manage.');
    }
    foreach ($team as $m) if ((int)$m['id'] === $requested) $subject = $m;
    $subjectId = $requested;
    $viewingOther = true;
}

// ---------------------------------------------------------------------------
// Reporting period
// ---------------------------------------------------------------------------
$rangeKey = $_GET['range'] ?? 'month';
if (!array_key_exists($rangeKey, analytics_ranges())) $rangeKey = 'month';
[$from, $to, $rangeLabel] = analytics_resolve_range($rangeKey, $_GET['from'] ?? '', $_GET['to'] ?? '');

// Admins land on the team view unless they asked for a person.
$mode = $isAdmin && !$viewingOther ? 'team' : 'personal';
if ($isAdmin && ($_GET['view'] ?? '') === 'me') { $mode = 'personal'; }

$qs = static function (array $extra = []) use ($rangeKey, $from, $to, $requested) {
    $base = array_filter([
        'range' => $rangeKey,
        'from'  => $rangeKey === 'custom' ? $from : null,
        'to'    => $rangeKey === 'custom' ? $to : null,
        'user'  => $requested ?: null,
    ], static fn($v) => $v !== null && $v !== '');
    return http_build_query(array_merge($base, $extra));
};

if ($mode === 'personal') {
    $cand      = analytics_candidate_metrics($subjectId, $from, $to);
    $funnel    = analytics_funnel($subjectId, $from, $to);
    $inter     = analytics_interview_metrics($subjectId, $from, $to);
    $outcomes  = analytics_interview_outcomes($subjectId, $from, $to);
    $timeline  = analytics_interview_timeline($subjectId, $from, $to);
    $activity  = analytics_activity_timeline($subjectId, $from, $to);
    $timeToHire = analytics_time_to_hire($subjectId, $from, $to);
    $offerRate  = analytics_offer_acceptance($subjectId, $from, $to);
    $hasData   = $cand['assigned'] > 0 || $inter['total'] > 0;
} else {
    $summary   = analytics_team_summary($team, $from, $to);
    $teamTotals = ['candidates'=>0,'processing'=>0,'interviews'=>0,'completed'=>0,'hired'=>0,'rejected'=>0];
    foreach ($summary as $r) foreach ($teamTotals as $k => $_) $teamTotals[$k] += (int)$r[$k];
    $activeCount = $suspendedCount = 0;
    foreach ($team as $m) {
        if (($m['account_status'] ?? '') === 'active') $activeCount++;
        elseif (in_array($m['account_status'] ?? '', ['suspended','disabled','pending_reactivation'], true)) $suspendedCount++;
    }
    $hasData = (bool)$team;
}

$pageTitle = $mode === 'team' ? 'HR / Recruiter performance' : 'My analytics';
include __DIR__.'/includes/header.php';

/**
 * One KPI card. role="group" with an aria-label so the number and its label are
 * announced together; aria-hidden on the icon keeps it out of the reading order.
 */
function metric_tile(string $label, $value, string $iconName = 'overview', ?string $hint = null): void {
    $spoken = $label . ': ' . $value . ($hint ? '. ' . $hint : '');
?>
  <article class="analytics-card" role="group" aria-label="<?= e($spoken) ?>">
    <span class="analytics-card-icon" aria-hidden="true"><?= icon($iconName) ?></span>
    <strong class="analytics-card-value"><?= e((string)$value) ?></strong>
    <span class="analytics-card-label"><?= e($label) ?></span>
    <?php if ($hint): ?><small class="analytics-card-hint"><?= e($hint) ?></small><?php endif; ?>
  </article>
<?php }
?>
<!-- The analytics page is a CSS size container, so its widget breakpoints
     measure the space left after the sidebar rather than the viewport. -->
<div class="page-container analytics-page">

<div class="dashboard-head">
  <div>
    <div class="eyebrow"><?= $mode === 'team' ? 'Team monitoring' : ($viewingOther ? 'HR / Recruiter' : 'My performance') ?></div>
    <h1><?= $mode === 'team' ? 'HR / Recruiter performance' : ($viewingOther ? e($subject['name']) : 'My analytics') ?></h1>
    <p class="meta">
      <?php if ($mode === 'team'): ?>
        Work and results for the HR/Recruiters you manage · <?=e($rangeLabel)?>
      <?php elseif ($viewingOther): ?>
        <?=e(role_label($subject['role']))?> · <?=e($subject['email'])?> · <?=e($rangeLabel)?>
      <?php else: ?>
        Your own candidates, interviews and hiring activity · <?=e($rangeLabel)?>
      <?php endif; ?>
    </p>
  </div>
  <div class="actions" style="margin-top:0">
    <?php if ($viewingOther): ?>
      <a class="btn secondary" href="analytics.php?<?=e($qs(['user'=>null]))?>">← All HR / Recruiters</a>
    <?php elseif ($isAdmin && $mode === 'team'): ?>
      <a class="btn secondary" href="analytics.php?<?=e($qs())?>&view=me"><?=icon('candidates',16)?> My own analytics</a>
    <?php elseif ($isAdmin): ?>
      <a class="btn secondary" href="analytics.php?<?=e($qs())?>">← Team performance</a>
    <?php endif; ?>
  </div>
</div>

<form method="get" class="filters analytics-filters" role="search" aria-label="Analytics reporting period">
  <?php if ($requested): ?><input type="hidden" name="user" value="<?=$requested?>"><?php endif; ?>
  <?php if ($isAdmin && $mode === 'personal' && !$viewingOther): ?><input type="hidden" name="view" value="me"><?php endif; ?>
  <label class="sr-only" for="range-select">Reporting period</label>
  <select id="range-select" name="range" data-range-select>
    <?php foreach (analytics_ranges() as $key => $label): ?>
      <option value="<?=e($key)?>" <?=$rangeKey===$key?'selected':''?>><?=e($label)?></option>
    <?php endforeach; ?>
  </select>
  <div class="custom-range" data-custom-range <?= $rangeKey==='custom' ? '' : 'hidden' ?>>
    <label class="sr-only" for="range-from">From date</label>
    <input id="range-from" type="date" name="from" value="<?=e($from ?? '')?>">
    <label class="sr-only" for="range-to">To date</label>
    <input id="range-to" type="date" name="to" value="<?=e($to ?? '')?>">
  </div>
  <button class="btn small" type="submit">Apply</button>
</form>

<?php if ($mode === 'personal'): ?>
  <?php if (!$hasData): ?>
    <div class="card empty-panel">
      <h2><?=icon('analytics',20)?> No activity in this period</h2>
      <p class="meta"><?= $viewingOther ? e($subject['name']).' has' : 'You have' ?> no candidates or interviews recorded for <?=e(strtolower($rangeLabel))?>. Try a wider date range.</p>
    </div>
  <?php else: ?>

  <div class="section-head" style="margin-top:8px"><div><h2 id="kpi-heading"><?= $viewingOther ? 'Candidate workload' : 'My candidates' ?></h2></div></div>
  <section class="analytics-grid" role="region" aria-labelledby="kpi-heading">
    <?php
      metric_tile('Candidates assigned', $cand['assigned'], 'candidates');
      metric_tile('Currently processing', $cand['processing'], 'pipeline');
      metric_tile('Shortlisted', $cand['shortlisted'], 'check');
      metric_tile('In interview', $cand['in_interview'], 'interviews');
      metric_tile('Offers out', $cand['offer'], 'star');
      metric_tile('Hired', $cand['hired'], 'employees');
      metric_tile('Rejected', $cand['rejected'], 'more');
      metric_tile('Withdrawn', $cand['withdrawn'], 'signout');
      metric_tile('Time to hire', $timeToHire === null ? '—' : $timeToHire . ' days', 'clock',
                  $timeToHire === null ? 'No hires in this period' : 'Average, application to hire');
      metric_tile('Offer acceptance', $offerRate === null ? '—' : $offerRate['rate'] . '%', 'check',
                  $offerRate === null ? 'No offers decided yet' : $offerRate['hired'] . ' accepted · ' . $offerRate['declined'] . ' declined');
    ?>
  </section>

  <div class="analytics-two">
    <section class="card">
      <div class="section-head" style="margin:0 0 16px"><div><h2>Candidate processing</h2></div><span class="meta">Cumulative</span></div>
      <?php
        $maxFunnel = max(1, ...array_values($funnel));
        $funnelSpoken = [];
        foreach ($funnel as $l => $v) $funnelSpoken[] = $l . ' ' . $v;
      ?>
      <div class="funnel" role="img" aria-label="Candidate processing funnel: <?=e(implode(', ', $funnelSpoken))?>">
        <?php foreach ($funnel as $label => $value): $w = (int)round($value / $maxFunnel * 100); ?>
          <div class="funnel-row">
            <span class="funnel-label"><?=e($label)?></span>
            <div class="funnel-track"><span class="funnel-fill" style="width:<?=max($value?4:0,$w)?>%"></span></div>
            <strong class="funnel-value"><?=$value?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <div class="section-head" style="margin:0 0 16px"><div><h2><?= $viewingOther ? 'Interview activity' : 'My interviews' ?></h2></div></div>
      <div class="metric-row"><span>Total handled</span><strong><?=$inter['total']?></strong></div>
      <div class="metric-row"><span>Upcoming</span><strong><?=$inter['upcoming']?></strong></div>
      <div class="metric-row"><span>In progress</span><strong><?=$inter['in_progress']?></strong></div>
      <div class="metric-row"><span>Completed</span><strong><?=$inter['completed']?></strong></div>
      <div class="metric-row"><span>Awaiting review</span><strong><?=$inter['awaiting_review']?></strong></div>
      <div class="metric-row"><span>Cancelled / no show</span><strong><?=$inter['cancelled']?></strong></div>
      <div class="metric-row"><span>Average score</span><strong><?= $inter['avg_score'] === null ? '—' : $inter['avg_score'].' / 100' ?></strong></div>
      <div class="metric-row"><span>Moved forward after interview</span><strong><?=$outcomes['progressed']?></strong></div>
      <div class="metric-row"><span>Not moved forward</span><strong><?=$outcomes['not_progressed']?></strong></div>
    </section>
  </div>

  <section class="card">
    <div class="section-head" style="margin:0 0 16px"><div><h2>Interviews completed over time</h2></div><span class="meta"><?=e($rangeLabel)?></span></div>
    <?php $maxBar = max(1, ...array_map(static fn($b) => $b['total'], $timeline ?: [['total'=>0]])); ?>
    <?php if (array_sum(array_column($timeline, 'total')) === 0): ?>
      <p class="meta">No completed interviews during this period.</p>
    <?php else: ?>
      <?php
        $barSpoken = [];
        foreach ($timeline as $b) $barSpoken[] = $b['label'] . ': ' . $b['total'];
      ?>
      <div class="bar-chart" role="img" aria-label="Interviews completed per period. <?=e(implode('; ', $barSpoken))?>">
        <?php foreach ($timeline as $b): $h = (int)round($b['total'] / $maxBar * 100); ?>
          <div class="bar-col">
            <div class="bar-track"><span class="bar-fill" style="height:<?=max($b['total']?6:0,$h)?>%" title="<?=$b['total']?>"></span></div>
            <span class="bar-value"><?=$b['total']?></span>
            <span class="bar-label"><?=e($b['label'])?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="section-head" style="margin:0 0 14px"><div><h2>Activity timeline</h2></div><span class="meta">From the audit trail</span></div>
    <?php if (!$activity): ?>
      <p class="meta">No HR/Recruiter activity found for this period.</p>
    <?php else: ?>
      <div class="activity-stack">
        <?php foreach ($activity as $row): ?>
          <div class="activity-row">
            <span class="activity-time"><?=e(date('M j — g:i A', strtotime($row['created_at'])))?></span>
            <span class="activity-text"><?=e(analytics_activity_text($row))?><?php if($row['job_title']): ?> <span class="meta">· <?=e($row['job_title'])?></span><?php endif; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php endif; ?>

<?php else: /* ------------------------- team monitoring ------------------------- */ ?>

  <?php if (!$team): ?>
    <div class="card empty-panel">
      <h2><?=icon('candidates',20)?> No HR/Recruiters yet</h2>
      <p class="meta">You have not created any HR/Recruiter accounts, so there is no team activity to monitor. <a href="users.php">Create an account →</a></p>
    </div>
  <?php else: ?>

  <section class="analytics-grid" role="region" aria-label="Team totals">
    <?php
      metric_tile('HR / Recruiters', count($team), 'candidates');
      metric_tile('Active', $activeCount, 'check');
      metric_tile('Suspended / disabled', $suspendedCount, 'signout');
      metric_tile('Applicants handled', $teamTotals['candidates'], 'overview');
      metric_tile('Being processed', $teamTotals['processing'], 'pipeline');
      metric_tile('Interviews', $teamTotals['interviews'], 'interviews');
      metric_tile('Interviews completed', $teamTotals['completed'], 'star');
      metric_tile('Candidates hired', $teamTotals['hired'], 'employees');
    ?>
  </section>

  <section class="card">
    <div class="section-head" style="margin:0 0 14px"><div><h2>HR / Recruiter performance</h2></div><span class="meta"><?=e($rangeLabel)?></span></div>
    <div class="table-wrap"><table class="table perf-table">
      <tr><th>Name</th><th>Candidates</th><th>Processing</th><th>Interviews</th><th>Hired</th><th>Avg. score</th><th></th></tr>
      <?php foreach ($summary as $r): $u = $r['user']; ?>
      <tr>
        <td data-label="HR / Recruiter">
          <strong><?=e($u['name'])?></strong>
          <div class="meta small"><?=e($u['email'])?></div>
          <span class="pill status-<?=e($u['account_status'])?>"><?=e(account_status_label($u['account_status']))?></span>
        </td>
        <td data-label="Candidates" class="num"><strong><?=$r['candidates']?></strong></td>
        <td data-label="Processing" class="num"><?=$r['processing']?></td>
        <td data-label="Interviews" class="num"><?=$r['interviews']?><div class="meta small"><?=$r['completed']?> completed</div></td>
        <td data-label="Hired" class="num"><strong><?=$r['hired']?></strong><div class="meta small"><?=$r['rejected']?> rejected</div></td>
        <td data-label="Avg. score" class="num"><?= $r['avg_score'] === null ? '<span class="meta">—</span>' : '<span class="score-pill">'.$r['avg_score'].'</span>' ?></td>
        <td data-label=""><a class="btn small secondary" href="analytics.php?<?=e($qs(['user'=>(int)$u['id']]))?>">View analytics</a></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  </section>

  <section class="card">
    <div class="section-head" style="margin:0 0 14px"><div><h2>Comparison</h2></div>
      <a class="btn small secondary" href="analytics-report.php?<?=e($qs())?>"><?=icon('analytics',14)?> View report</a></div>
    <?php
      $maxCand = max(1, ...array_map(static fn($r) => (int)$r['candidates'], $summary));
      $maxInt  = max(1, ...array_map(static fn($r) => (int)$r['interviews'], $summary));
    ?>
    <div class="compare-grid" role="list">
      <?php foreach ($summary as $r): ?>
        <div class="compare-col" role="listitem">
          <strong><?=e($r['user']['name'])?></strong>
          <div class="compare-bar"><span class="label">Candidates</span><div class="funnel-track"><span class="funnel-fill" style="width:<?=max($r['candidates']?4:0,(int)round($r['candidates']/$maxCand*100))?>%"></span></div><b><?=$r['candidates']?></b></div>
          <div class="compare-bar"><span class="label">Interviews</span><div class="funnel-track"><span class="funnel-fill alt" style="width:<?=max($r['interviews']?4:0,(int)round($r['interviews']/$maxInt*100))?>%"></span></div><b><?=$r['interviews']?></b></div>
          <div class="compare-bar"><span class="label">Hired</span><div class="funnel-track"><span class="funnel-fill hire" style="width:<?=max($r['hired']?4:0,(int)round($r['hired']/max(1,$maxCand)*100))?>%"></span></div><b><?=$r['hired']?></b></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php endif; ?>
<?php endif; ?>

<script>
(function () {
  // Custom dates only make sense when the custom range is selected.
  var select = document.querySelector('[data-range-select]');
  var custom = document.querySelector('[data-custom-range]');
  if (!select || !custom) return;
  select.addEventListener('change', function () {
    custom.hidden = select.value !== 'custom';
    if (select.value !== 'custom') select.form.submit();
  });
})();
</script>

</div><!-- /.analytics-page -->

<?php include __DIR__.'/includes/footer.php'; ?>
