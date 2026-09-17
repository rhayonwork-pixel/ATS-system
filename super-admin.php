<?php
require_once __DIR__.'/includes/auth.php';
require_super_admin();
$pdo = db();
$me  = current_user();

$catalog = admin_permission_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'admin_permissions') {
            $adminId = (int)($_POST['admin_id'] ?? 0);
            $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='admin' LIMIT 1");
            $s->execute([$adminId]);
            $admin = $s->fetch();
            if (!$admin) throw new RuntimeException('That Admin account no longer exists.');

            $newLimit = max(0, min(999, (int)($_POST['hr_account_limit'] ?? 0)));
            $used = seats_used($adminId);
            if ($newLimit < $used) {
                throw new RuntimeException('That Admin is using ' . $used . ' seats. Delete or release some before lowering the seat limit to ' . $newLimit . '.');
            }
            $oldLimit = (int)$admin['hr_account_limit'];
            if ($oldLimit !== $newLimit) {
                $pdo->prepare('UPDATE users SET hr_account_limit=? WHERE id=?')->execute([$newLimit, $adminId]);
            }

            $requested = array_map('strval', (array)($_POST['permissions'] ?? []));
            [$added, $removed] = set_user_permissions($adminId, $requested, array_keys($catalog), (int)$me['id']);

            if ($oldLimit !== $newLimit || $added || $removed) {
                audit('admin_permissions_update', 'user', $adminId, array_filter([
                    'admin'      => $admin['name'],
                    'seat_limit' => $oldLimit !== $newLimit ? $oldLimit . ' → ' . $newLimit : '',
                    'granted'    => $added ? implode(', ', $added) : '',
                    'revoked'    => $removed ? implode(', ', $removed) : '',
                ]));
                if ($oldLimit !== $newLimit) {
                    audit('seat_limit_changed', 'user', $adminId, ['admin' => $admin['name'], 'seats' => $oldLimit . ' → ' . $newLimit]);
                    notify((int)$adminId, 'seat_limit', 'Your seat limit changed',
                        $me['name'] . ' changed your HR/Recruiter seat limit from ' . $oldLimit . ' to ' . $newLimit . '.',
                        'users.php', 'Open accounts', 'user', $adminId);
                }
                if ($added || $removed) {
                    notify((int)$adminId, 'permissions', 'Your permissions were updated',
                        $me['name'] . ' changed what your account can do.', 'users.php', 'Open accounts', 'user', $adminId);
                }
                flash('success', 'Permissions saved for ' . $admin['name'] . '.');
            } else {
                flash('success', 'No changes to save for ' . $admin['name'] . '.');
            }

        } elseif ($action === 'admin_create') {
            $name  = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $pass  = (string)($_POST['password'] ?? '');
            $limit = max(0, min(999, (int)($_POST['hr_account_limit'] ?? 0)));
            if ($name === '' || $email === '') throw new RuntimeException('Name and email are both required.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That email address is not valid.');
            if (strlen($pass) < 8) throw new RuntimeException('The temporary password must be at least 8 characters.');
            $dupe = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
            $dupe->execute([$email]);
            if ($dupe->fetch()) throw new RuntimeException('An account with that email already exists.');

            $ins = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,active,account_status,created_by,hr_account_limit,approved_by,approved_at) VALUES(?,?,?,?,1,?,?,?,?,NOW())');
            $ins->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'admin', 'active', (int)$me['id'], $limit, (int)$me['id']]);
            $newId = (int)$pdo->lastInsertId();
            set_user_permissions($newId, array_map('strval', (array)($_POST['permissions'] ?? [])), array_keys($catalog), (int)$me['id']);
            audit('admin_create', 'user', $newId, ['admin' => $name, 'seat_limit' => $limit]);
            flash('success', 'Admin account created for ' . $name . '.');

        } elseif ($action === 'admin_status') {
            $adminId = (int)($_POST['admin_id'] ?? 0);
            $status  = (string)($_POST['status'] ?? '');
            if ($adminId === (int)$me['id']) throw new RuntimeException('You cannot change the status of your own account.');
            $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='admin' LIMIT 1");
            $s->execute([$adminId]);
            $admin = $s->fetch();
            if (!$admin) throw new RuntimeException('That Admin account no longer exists.');
            apply_account_status($adminId, $status, (int)$me['id']);
            audit('admin_status_change', 'user', $adminId, ['admin' => $admin['name'], 'status' => account_status_label($status)]);
            flash('success', $admin['name'] . ' is now ' . strtolower(account_status_label($status)) . '.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    header('Location: super-admin.php'); exit;
}

$admins = $pdo->query("SELECT * FROM users WHERE role='admin' ORDER BY name")->fetchAll();
$permsByUser = [];
foreach ($admins as $a) $permsByUser[(int)$a['id']] = user_permissions((int)$a['id'], 'admin');

$recruiters = $pdo->query("SELECT u.*, c.name creator_name FROM users u
    LEFT JOIN users c ON c.id=u.created_by
    WHERE u.role='recruiter' ORDER BY u.created_at DESC")->fetchAll();

$pendingAccounts = 0;
foreach ($recruiters as $r) if ($r['account_status'] === 'pending') $pendingAccounts++;
$pendingReactivations = pending_reactivation_count();

$jobCounts = ['pending'=>0,'approved'=>0,'rejected'=>0,'published'=>0];
$rows = $pdo->query("SELECT approval_status, status, COUNT(*) total FROM jobs GROUP BY approval_status, status")->fetchAll();
foreach ($rows as $r) {
    $state = job_state(['status'=>$r['status'],'approval_status'=>$r['approval_status']]);
    if (isset($jobCounts[$state])) $jobCounts[$state] += (int)$r['total'];
}

$seatsTotal = 0; $seatsUsedTotal = 0;
foreach ($admins as $a) { $seatsTotal += (int)$a['hr_account_limit']; $seatsUsedTotal += seats_used((int)$a['id']); }

$recentActivity = $pdo->query("SELECT ja.*, j.title, u.name actor_name FROM job_approvals ja
    JOIN jobs j ON j.id=ja.job_id LEFT JOIN users u ON u.id=ja.actor_id
    ORDER BY ja.created_at DESC LIMIT 8")->fetchAll();

$pageTitle = 'Admins and permissions';
include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head">
  <div><div class="eyebrow">Super Admin</div><h1>Admins and permissions</h1>
  <p class="meta">Decide what each Admin can do and how many HR/Recruiter accounts they may create. Every change here is written to the audit trail.</p></div>
  <div class="actions" style="margin-top:0">
    <a class="btn secondary" href="users.php"><?=icon('candidates',16)?> All accounts</a>
    <a class="btn secondary" href="audit_trail.php"><?=icon('analytics',16)?> Audit trail</a>
  </div>
</div>

<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>

<?php if ($pendingAccounts || $pendingReactivations): ?>
<div class="notice">
  <strong>Waiting on you:</strong>
  <?php if ($pendingAccounts): ?><?=$pendingAccounts?> new account<?=$pendingAccounts===1?'':'s'?> to approve<?php endif; ?>
  <?php if ($pendingAccounts && $pendingReactivations): ?> · <?php endif; ?>
  <?php if ($pendingReactivations): ?><?=$pendingReactivations?> reactivation request<?=$pendingReactivations===1?'':'s'?><?php endif; ?>.
  <a href="users.php">Open HR / Recruiters →</a>
</div>
<?php endif; ?>

<div class="stats">
  <div class="card stat"><span class="stat-icon"><?=icon('admin')?></span><strong><?=count($admins)?></strong><span>Admins</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('candidates')?></span><strong><?=count($recruiters)?></strong><span>HR / Recruiters</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('limit')?></span><strong><?=$seatsUsedTotal?> / <?=$seatsTotal?></strong><span>Seats used</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('bell')?></span><strong><?=$pendingAccounts + $pendingReactivations?></strong><span>Decisions waiting</span></div>
</div>

<div class="section-head"><div><div class="eyebrow">Provisioning</div><h2>Admin permissions</h2></div><span class="meta">Changes are confirmed before they save.</span></div>

<?php if (!$admins): ?>
<div class="card"><p class="meta">There are no Admin accounts yet. Create one below.</p></div>
<?php endif; ?>

<div class="perm-grid">
<?php foreach ($admins as $admin):
    $aid   = (int)$admin['id'];
    $limit = (int)$admin['hr_account_limit'];
    $used  = seats_used($aid);
    $free  = max(0, $limit - $used);
    $pct   = $limit > 0 ? min(100, (int)round($used / $limit * 100)) : 0;
    $held  = $permsByUser[$aid];
?>
  <div class="card perm-card">
    <div class="perm-card-head">
      <div>
        <h3><?=e($admin['name'])?></h3>
        <span class="meta"><?=e($admin['email'])?></span>
      </div>
      <span class="pill status-<?=e($admin['account_status'])?>"><?=e(account_status_label($admin['account_status']))?></span>
    </div>

  <form method="post" data-perm-form data-admin-name="<?=e($admin['name'])?>">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="admin_permissions">
    <input type="hidden" name="admin_id" value="<?=$aid?>">

    <div class="field">
      <label>HR / Recruiter seat limit</label>
      <input type="number" name="hr_account_limit" min="<?=$used?>" max="999" value="<?=$limit?>" data-original="<?=$limit?>" data-label="Seat limit">
      <div class="seat-inline"><strong><?=$used?> / <?=$limit?> Used</strong><span class="meta"><?=$free?> seat<?=$free===1?'':'s'?> available</span></div>
      <div class="cap-bar"><span style="width:<?=$pct?>%"></span></div>
      <span class="hint">A seat is held until the account is deleted or its seat is released, so the limit cannot go below seats in use.</span>
    </div>

    <div class="perm-toggles">
    <?php foreach ($catalog as $key => [$label, $blurb]): $on = in_array($key, $held, true); ?>
      <label class="perm-toggle">
        <input type="checkbox" name="permissions[]" value="<?=e($key)?>" <?=$on?'checked':''?> data-original="<?=$on?'1':'0'?>" data-label="<?=e($label)?>">
        <span class="perm-toggle-text"><strong><?=e($label)?></strong><small><?=e($blurb)?></small></span>
        <span class="perm-switch" aria-hidden="true"></span>
      </label>
    <?php endforeach; ?>
    </div>

    <div class="perm-card-actions">
      <button class="btn" type="submit"><?=icon('check',16)?> Save permissions</button>
    </div>
  </form>

  <?php if ($aid !== (int)$me['id']): ?>
  <details class="perm-danger">
    <summary class="meta small">Account status</summary>
    <form method="post" class="perm-status-row">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="admin_status">
      <input type="hidden" name="admin_id" value="<?=$aid?>">
      <?php foreach (['active'=>'Activate','suspended'=>'Suspend','disabled'=>'Disable'] as $st => $lbl): if ($admin['account_status'] === $st) continue; ?>
        <button class="btn small <?= $st==='active' ? 'secondary' : 'ghost' ?>" type="submit" name="status" value="<?=e($st)?>"><?=e($lbl)?></button>
      <?php endforeach; ?>
    </form>
    <p class="meta small">Suspending or disabling an Admin signs them out and frees nothing — their HR/Recruiter accounts keep working.</p>
  </details>
  <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<div class="admin-grid" style="margin-top:26px">
  <form class="card form" method="post">
    <h2><?=icon('plus',18)?> Create an Admin</h2>
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="admin_create">
    <div class="field"><label>Full name</label><input name="name" required placeholder="John Doe"></div>
    <div class="field"><label>Email</label><input type="email" name="email" required placeholder="john@acme.test"></div>
    <div class="field"><label>Temporary password <span class="hint">at least 8 characters</span></label><input type="password" name="password" required minlength="8"></div>
    <div class="field"><label>HR / Recruiter seat limit</label><input type="number" name="hr_account_limit" min="0" max="999" value="5"><span class="hint">How many HR/Recruiter accounts this Admin may hold at once.</span></div>
    <div class="field"><label>Starting permissions</label>
      <div class="perm-toggles compact">
      <?php foreach ($catalog as $key => [$label, $blurb]): ?>
        <label class="perm-toggle"><input type="checkbox" name="permissions[]" value="<?=e($key)?>" checked><span class="perm-toggle-text"><strong><?=e($label)?></strong></span><span class="perm-switch" aria-hidden="true"></span></label>
      <?php endforeach; ?>
      </div>
    </div>
    <button class="btn" type="submit"><?=icon('plus',16)?> Create Admin account</button>
  </form>

  <div class="card">
    <h2><?=icon('analytics',18)?> Job approval activity</h2>
    <div class="metric-row"><span>Pending approval</span><strong><?=$jobCounts['pending']?></strong></div>
    <div class="metric-row"><span>Approved, not yet published</span><strong><?=$jobCounts['approved']?></strong></div>
    <div class="metric-row"><span>Rejected</span><strong><?=$jobCounts['rejected']?></strong></div>
    <div class="metric-row"><span>Published</span><strong><?=$jobCounts['published']?></strong></div>
    <h3 style="margin:22px 0 8px;font-size:15px">Latest decisions</h3>
    <?php if (!$recentActivity): ?><p class="meta">No approval activity recorded yet.</p><?php endif; ?>
    <?php foreach ($recentActivity as $a): ?>
      <div class="upcoming-row"><div style="flex:1"><strong><?=e($a['title'])?></strong><div class="meta small"><?=e(ucwords(str_replace('_',' ',$a['action'])))?> by <?=e($a['actor_name'] ?? 'System')?> · <?=e(time_ago($a['created_at']))?></div></div></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" style="margin-top:26px">
  <div class="section-head"><div><div class="eyebrow">Oversight</div><h2>HR / Recruiters</h2></div><span class="meta"><?=count($recruiters)?> accounts across all Admins</span></div>
  <div class="table-wrap"><table class="table">
    <tr><th>Name</th><th>Seat owner</th><th>Permissions</th><th>Status</th></tr>
    <?php foreach ($recruiters as $r): $rp = user_permissions((int)$r['id'], 'recruiter'); ?>
    <tr>
      <td><strong><?=e($r['name'])?></strong><div class="meta small"><?=e($r['email'])?></div></td>
      <td class="meta"><?=e($r['creator_name'] ?? '—')?><?php if((int)$r['seat_released']===1): ?><div class="meta small">Seat released</div><?php endif; ?></td>
      <td><div class="tag-row">
        <?php foreach (recruiter_permission_catalog() as $k => [$lbl, $lblHint]): if (in_array($k, $rp, true)): ?>
          <span class="tag-pill"><?=icon('check',11)?><?=e($lbl)?></span>
        <?php endif; endforeach; if (!$rp): ?><span class="meta small">No permissions granted</span><?php endif; ?>
      </div></td>
      <td><span class="pill status-<?=e($r['account_status'])?>"><?=e(account_status_label($r['account_status']))?></span></td>
    </tr>
    <?php endforeach; if (!$recruiters): ?><tr><td colspan="4" class="meta">No HR/Recruiter accounts have been created yet.</td></tr><?php endif; ?>
  </table></div>
</div>

<div class="modal-overlay" data-confirm-overlay hidden>
  <div class="modal-box">
    <h2>Update Admin permissions?</h2>
    <p class="meta" data-confirm-admin></p>
    <div class="confirm-diff" data-confirm-diff></div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-confirm-cancel>Cancel</button>
      <button type="button" class="btn" data-confirm-ok>Confirm changes</button>
    </div>
    <button type="button" class="modal-close" data-confirm-cancel aria-label="Close">&times;</button>
  </div>
</div>

<script>
(function () {
  var overlay = document.querySelector('[data-confirm-overlay]');
  if (!overlay) return;
  var diffBox = overlay.querySelector('[data-confirm-diff]');
  var whoBox  = overlay.querySelector('[data-confirm-admin]');
  var pending = null;

  function changesFor(form) {
    var out = [];
    form.querySelectorAll('[data-original]').forEach(function (el) {
      var label = el.dataset.label || el.name;
      if (el.type === 'checkbox') {
        var was = el.dataset.original === '1', now = el.checked;
        if (was !== now) out.push([label, was ? 'ON' : 'OFF', now ? 'ON' : 'OFF']);
      } else if (String(el.value) !== String(el.dataset.original)) {
        out.push([label, el.dataset.original, el.value]);
      }
    });
    return out;
  }

  document.querySelectorAll('[data-perm-form]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (form.dataset.skipConfirm === '1') { form.dataset.skipConfirm = ''; return; }
      var changes = changesFor(form);
      if (!changes.length) return;
      ev.preventDefault();
      pending = form;
      whoBox.textContent = 'Admin: ' + (form.dataset.adminName || '');
      diffBox.innerHTML = '';
      changes.forEach(function (c) {
        var row = document.createElement('div');
        row.className = 'confirm-diff-row';
        row.innerHTML = '<span>' + c[0] + '</span><strong>' + c[1] + ' &rarr; ' + c[2] + '</strong>';
        diffBox.appendChild(row);
      });
      overlay.hidden = false;
    });
  });

  function closeOverlay() { overlay.hidden = true; pending = null; }

  // The dialog must never be able to trap the page: X, Cancel, a click on the
  // backdrop and Escape all dismiss it.
  overlay.querySelectorAll('[data-confirm-cancel]').forEach(function (btn) {
    btn.addEventListener('click', closeOverlay);
  });
  overlay.addEventListener('click', function (ev) { if (ev.target === overlay) closeOverlay(); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !overlay.hidden) closeOverlay();
  });
  overlay.querySelector('[data-confirm-ok]').addEventListener('click', function () {
    if (!pending) return;
    var form = pending; closeOverlay();
    form.dataset.skipConfirm = '1';
    form.submit();
  });
})();
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
