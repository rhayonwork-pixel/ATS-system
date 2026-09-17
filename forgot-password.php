<?php
/**
 * Forgotten password — a request, not a reset.
 *
 * Nothing here changes a password. It records a request for a Super Admin to
 * decide, and it never confirms whether an address exists on the system, so
 * this page cannot be used to enumerate staff accounts.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/account_lib.php';
$pdo = db();

$sent = false; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email  = strtolower(trim($_POST['email'] ?? ''));
    $reason = trim($_POST['reason'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE email=? AND role IN ('super_admin','admin','recruiter','hiring_manager') LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $existing = open_password_reset((int)$user['id']);
            if (!$existing) {
                $ins = $pdo->prepare('INSERT INTO password_reset_requests(user_id,reason,requested_ip) VALUES(?,?,?)');
                $ins->execute([(int)$user['id'], $reason !== '' ? mb_substr($reason, 0, 500) : null, $_SERVER['REMOTE_ADDR'] ?? null]);
                $requestId = (int)$pdo->lastInsertId();

                // Attribute the audit entry to the account itself — there is no
                // session here, so $_SESSION['user_id'] would be null.
                try {
                    $pdo->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address) VALUES(?,?,?,?,?,?)')
                        ->execute([(int)$user['id'], 'password_reset_requested', 'user', (int)$user['id'],
                                   json_encode(['account' => $user['name'], 'reason' => $reason]), $_SERVER['REMOTE_ADDR'] ?? null]);
                } catch (Throwable $e) { /* best effort */ }

                try {
                    $supers = $pdo->query("SELECT id FROM users WHERE role='super_admin' AND active=1")->fetchAll(PDO::FETCH_COLUMN);
                    $ntf = $pdo->prepare('INSERT INTO notifications(user_id,actor_id,type,title,body,link,action_label,entity_type,entity_id) VALUES(?,?,?,?,?,?,?,?,?)');
                    foreach ($supers as $sid) {
                        $ntf->execute([(int)$sid, (int)$user['id'], 'password_reset',
                            'Password reset request',
                            $user['name'] . ' (' . role_label($user['role']) . ') requested a password reset'
                                . ($reason !== '' ? ' — ' . $reason : '.'),
                            'password-resets.php', 'Review request', 'password_reset', $requestId]);
                    }
                } catch (Throwable $e) { /* best effort */ }
            }
        }
        // The same message either way: an unknown address must look identical
        // to a known one.
        $sent = true;
    }
}

$pageTitle = 'Forgot password'; $minimal = true;
include __DIR__ . '/includes/header.php';
?>
<div class="hero"><div class="eyebrow">Recruiter workspace</div><h1>Forgotten password.</h1>
<p>Password resets for staff accounts are approved by a Super Admin. Submit a request below and they will be notified.</p></div>

<?php if ($sent): ?>
  <div class="card form">
    <div class="notice success"><?=icon('check',15)?> Your password reset request has been sent to the Super Admin for approval.</div>
    <p class="meta">Once it is approved you will be given a secure link to create a new password. Your existing password is unchanged in the meantime, and it is never shown to anyone.</p>
    <div class="actions"><a class="btn secondary" href="login.php">Back to sign in</a></div>
  </div>
<?php else: ?>
  <form class="form card" method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <div class="field"><label>Email</label><input type="email" name="email" required placeholder="you@acme.test" value="<?=e($_POST['email'] ?? '')?>"></div>
    <div class="field"><label>Reason <span class="hint">optional</span></label><textarea name="reason" rows="3" placeholder="I forgot my password."><?=e($_POST['reason'] ?? '')?></textarea></div>
    <?php if ($error): ?><p class="error"><?=e($error)?></p><?php endif; ?>
    <button class="btn" type="submit"><?=icon('send',16)?> Submit request</button>
    <p class="meta">Applicants do not need an account — use <a href="application-status.php">check application status</a> instead.</p>
  </form>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
