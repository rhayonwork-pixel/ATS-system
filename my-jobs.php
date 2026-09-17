<?php
require_once __DIR__.'/includes/auth.php';
require_permission(PERM_JOB_MANAGEMENT);
$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $jobId = (int)($_POST['job_id'] ?? 0);
    try {
        $s = $pdo->prepare('SELECT * FROM jobs WHERE id=? LIMIT 1');
        $s->execute([$jobId]);
        $job = $s->fetch();
        if (!$job) throw new RuntimeException('That job no longer exists.');
        if (!can_edit_job($job, $me)) deny_403('That posting is not yours to change right now.');

        if (($_POST['action'] ?? '') === 'submit') {
            require_permission(PERM_JOB_POSTING, 'Submitting a posting for approval requires the "Job posting" permission.');
            $wasReturned = in_array(job_state($job), ['rejected','changes_requested'], true);
            $pdo->prepare('UPDATE jobs SET approval_status="pending", status="draft", submitted_by=?, submitted_at=NOW() WHERE id=?')
                ->execute([(int)$me['id'], $jobId]);
            record_job_approval($jobId, $wasReturned ? 'resubmitted' : 'submitted', null, (int)$me['id']);
            audit('job_submit_for_approval', 'job', $jobId, ['title' => $job['title']]);
            notify_reviewers('job_approval', 'Job approval required',
                $me['name'] . ' submitted "' . $job['title'] . '" for approval.', 'job-approvals.php?id=' . $jobId);
            flash('success', '"' . $job['title'] . '" was submitted for Admin approval.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    header('Location: my-jobs.php'); exit;
}

// A Recruiter sees only what they created. Admins have admin.php for the full list.
$stmt = $pdo->prepare("SELECT j.*, d.name department, r.name reviewer_name,
                       (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id) applications
                       FROM jobs j
                       LEFT JOIN departments d ON d.id=j.department_id
                       LEFT JOIN users r ON r.id=j.reviewed_by
                       WHERE j.created_by=? OR j.owner_id=?
                       ORDER BY j.created_at DESC");
$stmt->execute([(int)$me['id'], (int)$me['id']]);
$jobs = $stmt->fetchAll();

$byState = [];
foreach ($jobs as $j) { $st = job_state($j); $byState[$st] = ($byState[$st] ?? 0) + 1; }

$canPost = has_permission(PERM_JOB_POSTING);
$pageTitle = 'My job postings';
include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head">
  <div><div class="eyebrow">Jobs</div><h1>My job postings</h1>
  <p class="meta">Drafts stay private to you. Once submitted, an Admin decides whether a posting goes live.</p></div>
  <?php if ($canPost): ?>
  <div class="actions" style="margin-top:0"><a class="btn" href="job-post.php"><?=icon('plus',16)?> Create job posting</a></div>
  <?php endif; ?>
</div>

<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>

<div class="stats">
  <div class="card stat"><span class="stat-icon"><?=icon('overview')?></span><strong><?=count($jobs)?></strong><span>Total postings</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('clock')?></span><strong><?=$byState['pending'] ?? 0?></strong><span>Pending approval</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('public')?></span><strong><?=$byState['published'] ?? 0?></strong><span>Published</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('chat')?></span><strong><?=($byState['rejected'] ?? 0) + ($byState['changes_requested'] ?? 0)?></strong><span>Needs your attention</span></div>
</div>

<div class="card" style="margin-top:22px">
  <div class="section-head" style="margin:0 0 12px"><div><h2>All of your postings</h2></div><span class="meta">Newest first</span></div>
  <div class="table-wrap"><table class="table">
    <tr><th>Role</th><th>Department</th><th>Applicants</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($jobs as $job): $state = job_state($job); $editable = can_edit_job($job, $me); ?>
    <tr>
      <td><strong><?=e($job['title'])?></strong>
        <?php if($job['is_urgent']): ?> <span class="badge urgent">Urgent</span><?php endif; ?>
        <div class="meta small"><?=e($job['location'] ?: 'Location not set')?> · created <?=e(date('M j, Y', strtotime($job['created_at'])))?></div>
        <?php if (in_array($state, ['rejected','changes_requested'], true) && $job['review_note']): ?>
          <div class="reason-note meta small"><strong><?= $state==='rejected' ? 'Rejected' : 'Changes requested' ?> by <?=e($job['reviewer_name'] ?? 'an Admin')?>:</strong> <?=e($job['review_note'])?></div>
        <?php endif; ?>
      </td>
      <td class="meta"><?=e($job['department'] ?? '—')?></td>
      <td><strong><?=(int)$job['applications']?></strong></td>
      <td><?= job_state_badge($job) ?></td>
      <td>
        <div class="row-actions">
        <?php if ($editable): ?>
          <a class="btn small secondary" href="job-post.php?id=<?=(int)$job['id']?>">Edit</a>
          <?php if ($canPost): ?>
          <form method="post" class="inline-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="job_id" value="<?=(int)$job['id']?>">
            <button class="btn small" type="submit"><?= in_array($state,['rejected','changes_requested'],true) ? 'Resubmit' : 'Submit' ?></button>
          </form>
          <?php endif; ?>
        <?php elseif ($state === 'pending'): ?>
          <span class="meta small">With an Admin for review</span>
        <?php elseif ($state === 'published'): ?>
          <a class="btn small secondary" href="job-detail.php?slug=<?=e($job['slug'])?>" target="_blank" rel="noopener">View live</a>
        <?php else: ?>
          <span class="meta small">—</span>
        <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; if(!$jobs): ?>
      <tr><td colspan="5" class="meta">You have not created any job postings yet.<?= $canPost ? ' Use “Create job posting” to start one.' : '' ?></td></tr>
    <?php endif; ?>
  </table></div>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
