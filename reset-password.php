<?php
/**
 * Set a new password using a Super Admin approved, single-use token.
 * The token is the only thing that authorises this page — there is no session,
 * and the old password is never read or shown.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/account_lib.php';
$pdo = db();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$request = password_reset_by_token($token);
$done = false; $error = '';

if ($request && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $problems = password_problems($new, $confirm);
    if ($problems) {
        $error = password_rules_sentence($problems);
    } else {
        complete_password_reset((int)$request['id'], (int)$request['user_id'], $new);
        try {
            $pdo->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address) VALUES(?,?,?,?,?,?)')
                ->execute([(int)$request['user_id'], 'password_reset_completed', 'user', (int)$request['user_id'],
                           json_encode(['account' => $request['name']]), $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) { /* best effort */ }
        $done = true;
    }
}

$pageTitle = 'Set a new password'; $minimal = true;
include __DIR__ . '/includes/header.php';
?>
<?php if ($done): ?>
  <div class="hero"><div class="eyebrow">Recruiter workspace</div><h1>Password updated.</h1>
  <p>You can now sign in with your new password. This link has been used and will not work again.</p></div>
  <div class="actions"><a class="btn" href="login.php">Go to sign in</a></div>

<?php elseif (!$request): ?>
  <div class="hero"><div class="eyebrow">Recruiter workspace</div><h1>This link is not valid.</h1>
  <p>Reset links work once and expire. Ask your Super Admin to approve a new request, or submit one yourself.</p></div>
  <div class="actions"><a class="btn secondary" href="forgot-password.php">Request a reset</a><a class="btn secondary" href="login.php">Back to sign in</a></div>

<?php else: ?>
  <div class="hero"><div class="eyebrow">Approved by a Super Admin</div><h1>Set a new password.</h1>
  <p>You are setting a new password for <strong><?=e($request['email'])?></strong>. This link works once.</p></div>
  <form class="form card" method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="token" value="<?=e($token)?>">
    <div class="field"><label>New password</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
    <p class="meta small">At least 8 characters, including a letter and a number.</p>
    <?php if ($error): ?><p class="error"><?=e($error)?></p><?php endif; ?>
    <button class="btn" type="submit"><?=icon('check',16)?> Set new password</button>
  </form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
