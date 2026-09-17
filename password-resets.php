<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/account_lib.php';
require_super_admin();
$pdo = db();
$me  = current_user();

$issuedLink = null;   // shown once, right after an approval

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision  = (string)($_POST['decision'] ?? '');
        $note      = trim($_POST['note'] ?? '') ?: null;
        if (!in_array($decision, ['approved','rejected'], true)) throw new RuntimeException('Invalid decision.');

        $q = $pdo->prepare("SELECT r.*, u.name account_name, u.email account_email, u.role account_role
                            FROM password_reset_requests r JOIN users u ON u.id = r.user_id
                            WHERE r.id=? AND r.status='pending' LIMIT 1");
        $q->execute([$requestId]);
        $req = $q->fetch();
        if (!$req) throw new RuntimeException('That request has already been decided.');

        if ($decision === 'approved') {
            // The token is created only now, is single use, and expires. The
            // existing password is never read, shown, or changed by this step —
            // approval only grants the account the right to set a new one.
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + password_reset_window_hours() * 3600);
            $pdo->prepare("UPDATE password_reset_requests
                           SET status='approved', reset_token=?, token_expires_at=?, decided_by=?, decided_at=NOW(), decision_note=?
                           WHERE id=?")
                ->execute([$token, $expires, (int)$me['id'], $note, $requestId]);

            $_SESSION['issued_reset'] = [
                'name'  => $req['account_name'],
                'token' => $token,
                'hours' => password_reset_window_hours(),
            ];
            audit('password_reset_approved', 'user', (int)$req['user_id'], array_filter([
                'account'    => $req['account_name'],
                'expires_in' => password_reset_window_hours() . ' hours',
                'note'       => $note ?? '',
            ]));
            notify((int)$req['user_id'], 'account', 'Your password reset was approved',
                'A Super Admin approved your request. Use the secure link you were given to set a new password.',
                'login.php', 'Sign in');
            flash('success', 'Approved. Share the one-time link below with ' . $req['account_name'] . '.');
        } else {
            $pdo->prepare("UPDATE password_reset_requests
                           SET status='rejected', reset_token=NULL, token_expires_at=NULL, decided_by=?, decided_at=NOW(), decision_note=?
                           WHERE id=?")
                ->execute([(int)$me['id'], $note, $requestId]);
            audit('password_reset_rejected', 'user', (int)$req['user_id'], array_filter([
                'account' => $req['account_name'],
                'reason'  => $note ?? '',
            ]));
            notify((int)$req['user_id'], 'account', 'Your password reset request was rejected',
                $note ?: 'Your existing password is unchanged. Contact your Super Admin if you still need help.',
                'login.php');
            flash('success', 'Rejected. ' . $req['account_name'] . "'s password is unchanged.");
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    header('Location: password-resets.php'); exit;
}

// Shown once after the redirect, then dropped from the session.
if (!empty($_SESSION['issued_reset'])) {
    $issuedLink = $_SESSION['issued_reset'];
    unset($_SESSION['issued_reset']);
}

$pending = $pdo->query("SELECT r.*, u.name account_name, u.email account_email, u.role account_role
                        FROM password_reset_requests r JOIN users u ON u.id=r.user_id
                        WHERE r.status='pending' ORDER BY r.created_at ASC")->fetchAll();

$decided = $pdo->query("SELECT r.*, u.name account_name, u.role account_role, d.name decided_name
                        FROM password_reset_requests r
                        JOIN users u ON u.id=r.user_id
                        LEFT JOIN users d ON d.id=r.decided_by
                        WHERE r.status <> 'pending' ORDER BY r.decided_at DESC LIMIT 15")->fetchAll();

$pageTitle = 'Password reset requests';
include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head">
  <div><div class="eyebrow">Super Admin</div><h1>Password reset requests</h1>
  <p class="meta">Approving a request lets that account set a new password. It never reveals their existing one.</p></div>
  <div class="actions" style="margin-top:0"><a class="btn secondary" href="users.php"><?=icon('candidates',16)?> Accounts</a></div>
</div>

<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>

<?php if ($issuedLink): ?>
<div class="card reset-link-card">
  <h2><?=icon('link',18)?> One-time reset link for <?=e($issuedLink['name'])?></h2>
  <p class="meta">Give this to them through a channel you trust. It works once and expires in <?=e((string)$issuedLink['hours'])?> hours. It is shown here only now — reload the page and it is gone.</p>
  <?php
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/\\');
    $link = $base . '/reset-password.php?token=' . $issuedLink['token'];
  ?>
  <div class="reset-link-row">
    <input type="text" readonly value="<?=e($link)?>" data-reset-link onclick="this.select()">
    <button class="btn secondary" type="button" data-copy-link>Copy</button>
  </div>
</div>
<script>
document.querySelector('[data-copy-link]')?.addEventListener('click', function () {
  var field = document.querySelector('[data-reset-link]');
  field.select();
  navigator.clipboard?.writeText(field.value);
  this.textContent = 'Copied';
});
</script>
<?php endif; ?>

<section class="card">
  <div class="section-head" style="margin:0 0 14px"><div><h2>Waiting for a decision</h2></div><span class="meta"><?=count($pending)?> pending</span></div>
  <?php if (!$pending): ?>
    <p class="meta">No password reset requests are waiting.</p>
  <?php endif; ?>
  <div class="reset-stack">
  <?php foreach ($pending as $r): ?>
    <article class="reset-item">
      <div class="reset-head">
        <div>
          <strong><?=e($r['account_name'])?></strong>
          <div class="meta small"><?=e(role_label($r['account_role']))?> · <?=e($r['account_email'])?></div>
          <div class="meta small">Requested <?=e(date('F j, Y — g:i A', strtotime($r['created_at'])))?></div>
        </div>
        <span class="pill status-pending">Pending</span>
      </div>
      <?php if ($r['reason']): ?><p class="reason-note meta small"><?=e($r['reason'])?></p><?php endif; ?>
      <form method="post" class="perm-status-row">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="request_id" value="<?=(int)$r['id']?>">
        <input class="mini-input" name="note" placeholder="Optional note...">
        <button class="btn small" type="submit" name="decision" value="approved"><?=icon('check',13)?> Approve</button>
        <button class="btn small ghost" type="submit" name="decision" value="rejected">Reject</button>
      </form>
    </article>
  <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <div class="section-head" style="margin:0 0 12px"><div><h2>Recent decisions</h2></div></div>
  <div class="table-wrap"><table class="table">
    <tr><th>Account</th><th>Role</th><th>Outcome</th><th>Decided by</th><th>When</th></tr>
    <?php foreach ($decided as $d): ?>
      <tr>
        <td><strong><?=e($d['account_name'])?></strong><?php if($d['decision_note']): ?><div class="meta small">“<?=e($d['decision_note'])?>”</div><?php endif; ?></td>
        <td class="meta"><?=e(role_label($d['account_role']))?></td>
        <td><span class="pill status-<?= $d['status']==='rejected' ? 'rejected' : 'active' ?>"><?=e(ucfirst($d['status']))?></span></td>
        <td class="meta"><?=e($d['decided_name'] ?? '—')?></td>
        <td class="meta"><?=e($d['decided_at'] ? date('M j, Y', strtotime($d['decided_at'])) : '—')?></td>
      </tr>
    <?php endforeach; if(!$decided): ?><tr><td colspan="5" class="meta">No decisions recorded yet.</td></tr><?php endif; ?>
  </table></div>
</section>

<?php include __DIR__.'/includes/footer.php'; ?>
