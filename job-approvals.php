<?php
require_once __DIR__.'/includes/auth.php';

// Reviewing is Admin-and-above only, and additionally needs the Job posting
// permission. A Recruiter can never reach this page even by typing the URL.
if (!can_review_approvals()) {
    deny_403('Reviewing job postings is limited to Admins with the "Job posting" permission.');
}

$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $jobId  = (int)($_POST['job_id'] ?? 0);
    try {
        $s = $pdo->prepare('SELECT j.*, u.name creator_name FROM jobs j LEFT JOIN users u ON u.id=j.created_by WHERE j.id=? LIMIT 1');
        $s->execute([$jobId]);
        $job = $s->fetch();
        if (!$job) throw new RuntimeException('That job no longer exists.');
        if ($job['approval_status'] !== 'pending') {
            throw new RuntimeException('That posting is not waiting for approval — someone may have reviewed it already.');
        }

        $note = trim($_POST['note'] ?? '');
        $recipient = (int)($job['submitted_by'] ?: $job['created_by'] ?: 0);

        if ($action === 'approve_publish' || $action === 'approve_only') {
            $publish = ($action === 'approve_publish');
            $pdo->prepare('UPDATE jobs SET approval_status="approved", status=?, reviewed_by=?, reviewed_at=NOW(), review_note=?,
                           published_at=CASE WHEN ?=1 AND published_at IS NULL THEN NOW() ELSE published_at END WHERE id=?')
                ->execute([$publish ? 'open' : 'draft', (int)$me['id'], $note ?: null, $publish ? 1 : 0, $jobId]);

            record_job_approval($jobId, 'approved', $note ?: null, (int)$me['id']);
            audit('job_approve', 'job', $jobId, array_filter(['title' => $job['title'], 'note' => $note]));
            if ($publish) {
                record_job_approval($jobId, 'published', null, (int)$me['id']);
                audit('job_publish', 'job', $jobId, ['title' => $job['title'], 'published_by' => $me['name']]);
            }
            if ($recipient) {
                notify($recipient, 'job_decision',
                    $publish ? 'Your job posting was approved and published' : 'Your job posting was approved',
                    '"' . $job['title'] . '" was reviewed by ' . $me['name'] . '.', 'my-jobs.php');
            }
            flash('success', $publish
                ? '"' . $job['title'] . '" was approved and is now live on the careers site.'
                : '"' . $job['title'] . '" was approved. Publish it when you are ready.');

        } elseif ($action === 'reject') {
            if ($note === '') throw new RuntimeException('A reason is required when rejecting a job posting.');
            $pdo->prepare('UPDATE jobs SET approval_status="rejected", status="draft", reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?')
                ->execute([(int)$me['id'], $note, $jobId]);
            record_job_approval($jobId, 'rejected', $note, (int)$me['id']);
            audit('job_reject', 'job', $jobId, ['title' => $job['title'], 'reason' => $note]);
            if ($recipient) {
                notify($recipient, 'job_decision', 'Your job posting was rejected',
                    '"' . $job['title'] . '": ' . $note, 'job-post.php?id=' . $jobId);
            }
            flash('success', '"' . $job['title'] . '" was rejected and the reason was sent back to ' . ($job['creator_name'] ?: 'the author') . '.');

        } elseif ($action === 'request_changes') {
            if ($note === '') throw new RuntimeException('Please say what needs to change.');
            $pdo->prepare('UPDATE jobs SET approval_status="changes_requested", status="draft", reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?')
                ->execute([(int)$me['id'], $note, $jobId]);
            record_job_approval($jobId, 'changes_requested', $note, (int)$me['id']);
            audit('job_request_changes', 'job', $jobId, ['title' => $job['title'], 'requested' => $note]);
            if ($recipient) {
                notify($recipient, 'job_decision', 'Changes requested on your job posting',
                    '"' . $job['title'] . '": ' . $note, 'job-post.php?id=' . $jobId);
            }
            flash('success', 'Change request sent. ' . ($job['creator_name'] ?: 'The author') . ' can edit and resubmit the same posting.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    header('Location: job-approvals.php'); exit;
}

$reviewId = (int)($_GET['id'] ?? 0);
$review = null;
if ($reviewId) {
    $s = $pdo->prepare('SELECT j.*, d.name department, u.name creator_name, u.email creator_email, su.name submitter_name
                        FROM jobs j
                        LEFT JOIN departments d ON d.id=j.department_id
                        LEFT JOIN users u ON u.id=j.created_by
                        LEFT JOIN users su ON su.id=j.submitted_by
                        WHERE j.id=? LIMIT 1');
    $s->execute([$reviewId]);
    $review = $s->fetch() ?: null;
}

$queue = $pdo->query("SELECT j.*, d.name department, u.name creator_name, su.name submitter_name
                      FROM jobs j
                      LEFT JOIN departments d ON d.id=j.department_id
                      LEFT JOIN users u ON u.id=j.created_by
                      LEFT JOIN users su ON su.id=j.submitted_by
                      WHERE j.approval_status='pending'
                      ORDER BY j.submitted_at ASC, j.created_at ASC")->fetchAll();

$decided = $pdo->query("SELECT j.*, d.name department, u.name creator_name, r.name reviewer_name
                        FROM jobs j
                        LEFT JOIN departments d ON d.id=j.department_id
                        LEFT JOIN users u ON u.id=j.created_by
                        LEFT JOIN users r ON r.id=j.reviewed_by
                        WHERE j.approval_status IN ('approved','rejected','changes_requested')
                        ORDER BY j.reviewed_at DESC LIMIT 12")->fetchAll();

$pageTitle = 'Job approvals';
include __DIR__.'/includes/header.php';

/** Render a block of the posting, skipping anything empty. */
function review_block(string $label, ?string $body, bool $asList = false): void {
    $body = trim((string)$body);
    if ($body === '') return;
    echo '<div class="review-block"><h3>' . e($label) . '</h3>';
    if ($asList) {
        $lines = array_filter(array_map('trim', preg_split('/\R/u', $body) ?: []));
        if (count($lines) > 1) {
            echo '<ul>';
            foreach ($lines as $line) echo '<li>' . e($line) . '</li>';
            echo '</ul></div>';
            return;
        }
    }
    echo '<p>' . nl2br(e($body)) . '</p></div>';
}
?>
<div class="dashboard-head">
  <div><div class="eyebrow">Administration</div><h1>Job posting approvals</h1>
  <p class="meta">Nothing an HR/Recruiter writes reaches the careers site until it passes through here.</p></div>
  <div class="actions" style="margin-top:0"><a class="btn secondary" href="admin.php"><?=icon('admin',16)?> Job management</a></div>
</div>

<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>

<?php if ($review && $review['approval_status'] === 'pending'): ?>
  <?php $limit = $review['applicant_limit'] !== null ? (int)$review['applicant_limit'] : null; ?>
  <div class="card review-screen">
    <div class="review-head">
      <div>
        <div class="eyebrow">Reviewing</div>
        <h2><?=e($review['title'])?></h2>
        <div class="meta"><?=e($review['department'] ?? 'No department')?> · <?=e($review['location'] ?: 'Location not set')?> · <?=e(ucwords(str_replace('_',' ',$review['employment_type'])))?></div>
      </div>
      <?= job_state_badge($review) ?>
    </div>

    <div class="review-meta-grid">
      <div><span class="label">Created by</span><strong><?=e($review['creator_name'] ?? '—')?></strong></div>
      <div><span class="label">Submitted by</span><strong><?=e($review['submitter_name'] ?? $review['creator_name'] ?? '—')?></strong></div>
      <div><span class="label">Date created</span><strong><?=e(date('M j, Y', strtotime($review['created_at'])))?></strong></div>
      <div><span class="label">Submitted</span><strong><?=e($review['submitted_at'] ? date('M j, Y · g:i A', strtotime($review['submitted_at'])) : '—')?></strong></div>
      <div><span class="label">Salary</span><strong><?=e($review['salary_info'] ?: 'Not stated')?></strong></div>
      <div><span class="label">Applicant limit</span><strong><?= $limit === null ? 'Unlimited' : $limit ?></strong></div>
    </div>

    <?php if ($review['source_pdf']): ?>
      <p class="meta"><?=icon('link',14)?> Generated from an uploaded job description: <a href="<?=e($review['source_pdf'])?>" target="_blank" rel="noopener"><?=e(basename($review['source_pdf']))?></a></p>
    <?php endif; ?>

    <?php if ($review['tags']): ?>
      <div class="tag-row" style="margin:14px 0">
        <?php foreach (job_tags($review['tags']) as $t): ?><span class="tag-pill"><?=icon('tag',11)?><?=e($t)?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php
      review_block('Description', $review['description']);
      review_block('Responsibilities', $review['responsibilities'], true);
      review_block('Qualifications', $review['qualifications'], true);
      review_block('Required skills', $review['requirements'], true);
      review_block('Preferred skills', $review['preferred_skills'], true);
      review_block('Experience', $review['experience_required']);
      review_block('Education', $review['education_required']);
    ?>

    <?php $history = job_approval_history((int)$review['id']); if ($history): ?>
      <div class="review-block"><h3>History</h3>
        <div class="list-stack">
        <?php foreach ($history as $h): ?>
          <div class="list-row"><div><strong><?=e(ucwords(str_replace('_',' ',$h['action'])))?></strong>
            <div class="meta small"><?=e($h['actor_name'] ?? 'System')?> · <?=e(date('M j, Y · g:i A', strtotime($h['created_at'])))?></div>
            <?php if ($h['note']): ?><div class="meta small">“<?=e($h['note'])?>”</div><?php endif; ?>
          </div></div>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="review-actions">
      <form method="post" class="review-decide">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="job_id" value="<?=(int)$review['id']?>">
        <div class="field"><label>Note <span class="hint">required when rejecting or requesting changes</span></label>
          <textarea name="note" rows="3" placeholder="Please update the required qualifications…"></textarea></div>
        <div class="review-buttons">
          <button class="btn" type="submit" name="action" value="approve_publish"><?=icon('public',16)?> Approve &amp; publish</button>
          <button class="btn secondary" type="submit" name="action" value="approve_only"><?=icon('check',16)?> Approve without publishing</button>
          <button class="btn ghost" type="submit" name="action" value="request_changes"><?=icon('chat',16)?> Request changes</button>
          <button class="btn danger" type="submit" name="action" value="reject"><?=icon('more',16)?> Reject</button>
        </div>
      </form>
    </div>
    <p style="margin-top:14px"><a class="meta" href="job-approvals.php">← Back to the queue</a></p>
  </div>
<?php elseif ($review): ?>
  <div class="notice">That posting has already been reviewed. It is now <strong><?=e(job_state_label(job_state($review)))?></strong>.</div>
<?php endif; ?>

<?php if (!$review || $review['approval_status'] !== 'pending'): ?>
<div class="section-head"><div><div class="eyebrow">Queue</div><h2>Waiting for review</h2></div><span class="meta"><?=count($queue)?> posting<?=count($queue)===1?'':'s'?></span></div>

<?php if (!$queue): ?>
  <div class="card"><p class="meta">Nothing is waiting for approval right now.</p></div>
<?php endif; ?>

<div class="approval-grid">
<?php foreach ($queue as $q): ?>
  <div class="card approval-card">
    <div class="approval-card-head">
      <h3><?=e($q['title'])?></h3>
      <?= job_state_badge($q) ?>
    </div>
    <div class="metric-row"><span>Created by</span><strong><?=e($q['creator_name'] ?? '—')?></strong></div>
    <div class="metric-row"><span>Department</span><strong><?=e($q['department'] ?? '—')?></strong></div>
    <div class="metric-row"><span>Submitted</span><strong><?=e($q['submitted_at'] ? time_ago($q['submitted_at']) : '—')?></strong></div>
    <?php if ($q['source_pdf']): ?><p class="meta small"><?=icon('link',12)?> From an uploaded PDF</p><?php endif; ?>
    <div class="perm-card-actions"><a class="btn" href="job-approvals.php?id=<?=(int)$q['id']?>"><?=icon('expand',15)?> Review</a></div>
  </div>
<?php endforeach; ?>
</div>

<div class="card" style="margin-top:26px">
  <div class="section-head" style="margin:0 0 12px"><div><h2>Recently decided</h2></div></div>
  <div class="table-wrap"><table class="table">
    <tr><th>Role</th><th>Created by</th><th>Reviewer</th><th>When</th><th>Outcome</th></tr>
    <?php foreach ($decided as $d): ?>
      <tr>
        <td><strong><?=e($d['title'])?></strong><div class="meta small"><?=e($d['department'] ?? '—')?></div>
          <?php if ($d['review_note']): ?><div class="meta small">“<?=e($d['review_note'])?>”</div><?php endif; ?></td>
        <td class="meta"><?=e($d['creator_name'] ?? '—')?></td>
        <td class="meta"><?=e($d['reviewer_name'] ?? '—')?></td>
        <td class="meta"><?=e($d['reviewed_at'] ? date('M j, Y', strtotime($d['reviewed_at'])) : '—')?></td>
        <td><?= job_state_badge($d) ?></td>
      </tr>
    <?php endforeach; if(!$decided): ?><tr><td colspan="5" class="meta">No decisions recorded yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php endif; ?>

<?php include __DIR__.'/includes/footer.php'; ?>
