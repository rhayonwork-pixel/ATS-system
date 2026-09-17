<?php
require_once __DIR__.'/includes/auth.php';
require_permission(PERM_APPLICANT_PORTAL);
$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'portal_settings') {
            $accepting = isset($_POST['accepting_applications']) ? '1' : '0';
            set_setting('portal_accepting_applications', $accepting);
            set_setting('portal_closed_message', trim($_POST['portal_closed_message'] ?? ''));
            set_setting('careers_headline', trim($_POST['careers_headline'] ?? ''));
            set_setting('default_applicant_limit', trim($_POST['default_applicant_limit'] ?? ''));
            audit('applicant_portal_settings', 'settings', null, [
                'accepting_applications' => $accepting === '1' ? 'Yes' : 'No',
            ]);
            flash('success', 'Applicant portal settings saved.');

        } elseif ($action === 'job_visibility') {
            $jobId  = (int)($_POST['job_id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['open','paused','closed'], true)) throw new RuntimeException('Invalid visibility state.');

            $s = $pdo->prepare('SELECT * FROM jobs WHERE id=? LIMIT 1');
            $s->execute([$jobId]);
            $job = $s->fetch();
            if (!$job) throw new RuntimeException('That job no longer exists.');

            // Making a role visible is a publish. It must have cleared approval first,
            // otherwise the approval gate could be walked around from this page.
            if ($status === 'open' && !in_array($job['approval_status'], ['approved','none'], true)) {
                throw new RuntimeException('That posting has not been approved yet. Approve it in Job approvals before making it visible.');
            }
            if ($status === 'open' && !can_publish_jobs($me)) {
                deny_403('Publishing a role requires the "Job posting" permission.');
            }

            $pdo->prepare('UPDATE jobs SET status=?, published_at=CASE WHEN ?="open" AND published_at IS NULL THEN NOW() ELSE published_at END WHERE id=?')
                ->execute([$status, $status, $jobId]);
            if ($status === 'open') record_job_approval($jobId, 'published', 'Made visible from the applicant portal', (int)$me['id']);
            audit('job_visibility_change', 'job', $jobId, ['title' => $job['title'], 'visibility' => $status]);
            flash('success', '"' . $job['title'] . '" is now ' . ($status === 'open' ? 'visible to applicants' : $status) . '.');

        } elseif ($action === 'application_status') {
            $appId = (int)($_POST['application_id'] ?? 0);
            $new   = (string)($_POST['status'] ?? '');
            if (!in_array($new, ['active','withdrawn'], true)) throw new RuntimeException('Invalid application status.');
            $pdo->prepare('UPDATE applications SET status=? WHERE id=?')->execute([$new, $appId]);
            audit('application_status_change', 'application', $appId, ['status' => $new]);
            flash('success', 'Application updated.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    header('Location: applicant-portal.php'); exit;
}

$accepting = setting('portal_accepting_applications', '1') !== '0';

$totalCandidates = (int)$pdo->query('SELECT COUNT(*) FROM candidates')->fetchColumn();
$totalApps       = (int)$pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn();
$activeApps      = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='active'")->fetchColumn();
$withdrawn       = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='withdrawn'")->fetchColumn();
$newThisWeek     = (int)$pdo->query('SELECT COUNT(*) FROM candidates WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();

$visibleJobs = $pdo->query("SELECT j.*, d.name department,
                            (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id) applications
                            FROM jobs j LEFT JOIN departments d ON d.id=j.department_id
                            WHERE j.status IN ('open','paused') OR j.approval_status='approved'
                            ORDER BY FIELD(j.status,'open','paused','draft','closed'), j.title")->fetchAll();

$registrations = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM applications a WHERE a.candidate_id=c.id) applications
                              FROM candidates c ORDER BY c.created_at DESC LIMIT 12')->fetchAll();

$recentApps = $pdo->query("SELECT a.*, c.first_name, c.last_name, c.email, j.title job_title
                           FROM applications a
                           JOIN candidates c ON c.id=a.candidate_id
                           JOIN jobs j ON j.id=a.job_id
                           ORDER BY a.applied_at DESC LIMIT 12")->fetchAll();

$pageTitle = 'Applicant portal';
include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head">
  <div><div class="eyebrow">Administration</div><h1>Applicant portal</h1>
  <p class="meta">Everything applicants see and do, in one place — registrations, applications, which roles are visible, and the portal's own settings.</p></div>
  <div class="actions" style="margin-top:0"><a class="btn secondary" href="index.php" target="_blank" rel="noopener"><?=icon('public',16)?> Open careers site</a></div>
</div>

<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>
<?php if(!$accepting): ?><div class="notice error">The portal is currently <strong>not accepting new applications</strong>. Visitors can browse roles but cannot apply.</div><?php endif; ?>

<div class="stats">
  <div class="card stat"><span class="stat-icon"><?=icon('candidates')?></span><strong><?=$totalCandidates?></strong><span>Registered applicants</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('overview')?></span><strong><?=$totalApps?></strong><span>Applications received</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('pipeline')?></span><strong><?=$activeApps?></strong><span>Active applications</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('sparkle')?></span><strong><?=$newThisWeek?></strong><span>New this week</span></div>
</div>

<div class="admin-grid">
  <form class="card form" method="post">
    <h2><?=icon('settings',18)?> Portal settings</h2>
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="portal_settings">

    <div class="perm-toggles">
      <label class="perm-toggle">
        <input type="checkbox" name="accepting_applications" value="1" <?=$accepting?'checked':''?>>
        <span class="perm-toggle-text"><strong>Accepting applications</strong><small>Turn off to keep roles browsable while pausing all new submissions.</small></span>
        <span class="perm-switch" aria-hidden="true"></span>
      </label>
    </div>

    <div class="field"><label>Message shown when applications are closed</label>
      <textarea name="portal_closed_message" rows="2" placeholder="We are not accepting applications at the moment. Please check back soon."><?=e(setting('portal_closed_message'))?></textarea></div>
    <div class="field"><label>Careers page headline</label>
      <input name="careers_headline" value="<?=e(setting('careers_headline','Do the best work of your career.'))?>"></div>
    <div class="field"><label>Default applicant limit for new roles <span class="hint">optional</span></label>
      <input type="number" min="0" name="default_applicant_limit" value="<?=e(setting('default_applicant_limit'))?>" placeholder="Unlimited"></div>
    <button class="btn" type="submit"><?=icon('check',16)?> Save portal settings</button>
    <p class="meta small">Applicants never see administrative controls — the careers site and status lookup remain public and read-only.</p>
  </form>

  <div class="card">
    <h2><?=icon('candidates',18)?> Recent registrations</h2>
    <div class="list-stack">
    <?php foreach ($registrations as $c): ?>
      <div class="list-row">
        <div><strong><?=e($c['first_name'].' '.$c['last_name'])?></strong>
          <div class="meta small"><?=e($c['email'])?> · <?=e(time_ago($c['created_at']))?></div></div>
        <div class="row-actions"><span class="pill"><?=(int)$c['applications']?> application<?=(int)$c['applications']===1?'':'s'?></span></div>
      </div>
    <?php endforeach; if(!$registrations): ?><p class="meta">No applicants have registered yet.</p><?php endif; ?>
    </div>
  </div>
</div>

<div class="card" style="margin-top:26px">
  <div class="section-head" style="margin:0 0 12px"><div><div class="eyebrow">Visibility</div><h2>What applicants can see</h2></div>
    <span class="meta">Only approved postings can be made visible.</span></div>
  <div class="table-wrap"><table class="table">
    <tr><th>Role</th><th>Department</th><th>Applicants</th><th>State</th><th>Visibility</th></tr>
    <?php foreach ($visibleJobs as $j): $limit = $j['applicant_limit'] !== null ? (int)$j['applicant_limit'] : null; ?>
    <tr>
      <td><strong><?=e($j['title'])?></strong><div class="meta small"><?=e($j['location'] ?: 'Location not set')?></div></td>
      <td class="meta"><?=e($j['department'] ?? '—')?></td>
      <td><strong><?=(int)$j['applications']?></strong><?php if($limit!==null): ?><span class="meta"> / <?=$limit?></span><?php endif; ?></td>
      <td><?= job_state_badge($j) ?></td>
      <td>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="job_visibility">
          <input type="hidden" name="job_id" value="<?=(int)$j['id']?>">
          <select name="status">
            <option value="open" <?=$j['status']==='open'?'selected':''?>>Visible</option>
            <option value="paused" <?=$j['status']==='paused'?'selected':''?>>Paused</option>
            <option value="closed" <?=$j['status']==='closed'?'selected':''?>>Closed</option>
          </select>
          <button class="btn small" type="submit">Save</button>
        </form>
      </td>
    </tr>
    <?php endforeach; if(!$visibleJobs): ?><tr><td colspan="5" class="meta">No approved or published roles yet.</td></tr><?php endif; ?>
  </table></div>
</div>

<div class="card" style="margin-top:26px">
  <div class="section-head" style="margin:0 0 12px"><div><div class="eyebrow">Workflow</div><h2>Latest applications</h2></div>
    <a class="btn secondary small" href="pipeline.php">Open hiring pipeline</a></div>
  <div class="table-wrap"><table class="table">
    <tr><th>Applicant</th><th>Role</th><th>Stage</th><th>Applied</th><th>Status</th></tr>
    <?php foreach ($recentApps as $a): ?>
    <tr>
      <td><strong><?=e($a['first_name'].' '.$a['last_name'])?></strong><div class="meta small"><?=e($a['email'])?></div></td>
      <td class="meta"><?=e($a['job_title'])?></td>
      <td><?= stage_badge($a['stage']) ?></td>
      <td class="meta"><?=e(time_ago($a['applied_at']))?></td>
      <td>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="application_status">
          <input type="hidden" name="application_id" value="<?=(int)$a['id']?>">
          <select name="status">
            <option value="active" <?=$a['status']==='active'?'selected':''?>>Active</option>
            <option value="withdrawn" <?=$a['status']==='withdrawn'?'selected':''?>>Withdrawn</option>
          </select>
          <button class="btn small secondary" type="submit"><?=icon('check',13)?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; if(!$recentApps): ?><tr><td colspan="5" class="meta">No applications received yet.</td></tr><?php endif; ?>
  </table></div>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
