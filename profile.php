<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/account_lib.php';
$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'profile') {
            // Only the fields in editable_profile_fields() are ever written.
            // Role, permissions, seat limit and account status are not among
            // them, so no amount of extra POST data can touch those.
            $name  = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $title = trim($_POST['job_title'] ?? '');
            if ($name === '')  throw new RuntimeException('Your name cannot be blank.');
            if (mb_strlen($name) > 120) throw new RuntimeException('That name is too long.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That email address is not valid.');

            $dupe = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
            $dupe->execute([$email, (int)$me['id']]);
            if ($dupe->fetch()) throw new RuntimeException('Another account already uses that email address.');

            $imgError = null;
            $image = save_user_profile_image($_FILES['profile_image'] ?? [], $name, $imgError);
            if ($imgError) throw new RuntimeException($imgError);

            $changed = [];
            if ($name !== $me['name'])  $changed['name']  = $me['name'] . ' → ' . $name;
            if ($email !== $me['email']) $changed['email'] = $me['email'] . ' → ' . $email;
            if ($title !== (string)($me['job_title'] ?? '')) $changed['job_title'] = $title !== '' ? $title : '(cleared)';
            if ($image) $changed['profile_photo'] = 'updated';

            $sql = 'UPDATE users SET name=?, email=?, job_title=?' . ($image ? ', profile_image=?' : '') . ' WHERE id=?';
            $params = [$name, $email, $title !== '' ? $title : null];
            if ($image) $params[] = $image;
            $params[] = (int)$me['id'];
            $pdo->prepare($sql)->execute($params);

            if ($email !== $me['email']) $_SESSION['user'] = $email;

            if ($changed) {
                audit('profile_updated', 'user', (int)$me['id'], $changed);
                flash('success', 'Your profile has been updated.');
            } else {
                flash('success', 'No changes to save.');
            }

        } elseif ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            // The current password is verified against the stored hash before
            // anything changes — knowing the session is not enough.
            $row = $pdo->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
            $row->execute([(int)$me['id']]);
            $hash = (string)$row->fetchColumn();
            if (!password_verify($current, $hash)) {
                throw new RuntimeException('Your current password is not correct.');
            }
            if (password_verify($new, $hash)) {
                throw new RuntimeException('Your new password must be different from your current one.');
            }
            $problems = password_problems($new, $confirm);
            if ($problems) throw new RuntimeException(password_rules_sentence($problems));

            $pdo->prepare('UPDATE users SET password_hash=?, password_changed_at=NOW() WHERE id=?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$me['id']]);

            // The password itself is never logged — only that it changed.
            audit('password_changed', 'user', (int)$me['id'], ['account' => $me['name']]);
            session_regenerate_id(true);
            flash('success', 'Your password has been changed.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    header('Location: profile.php'); exit;
}

// Read fresh so the page reflects what was just saved.
$stmt = $pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
$stmt->execute([(int)$me['id']]);
$user = $stmt->fetch() ?: $me;

$myPermissions = user_permissions((int)$user['id'], $user['role']);
$catalog = admin_permission_catalog() + recruiter_permission_catalog();

$pageTitle = 'My profile';
include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head">
  <div><div class="eyebrow">Account</div><h1>My profile</h1>
  <p class="meta">Your own details. Role, permissions and account status are set by your administrator and cannot be changed here.</p></div>
</div>

<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>

<div class="profile-layout">
  <section class="card profile-summary">
    <?= user_avatar($user, 96) ?>
    <h2><?=e($user['name'])?></h2>
    <?php if (!empty($user['job_title'])): ?><p class="meta profile-title"><?=e($user['job_title'])?></p><?php endif; ?>
    <div class="profile-kv"><span>Email</span><strong><?=e($user['email'])?></strong></div>
    <div class="profile-kv"><span>Role</span><strong><?=e(role_label($user['role']))?></strong></div>
    <div class="profile-kv"><span>Account status</span><strong><span class="pill status-<?=e($user['account_status'] ?? 'active')?>"><?=e(account_status_label($user['account_status'] ?? 'active'))?></span></strong></div>
    <div class="profile-kv"><span>Member since</span><strong><?=e(date('M j, Y', strtotime($user['created_at'])))?></strong></div>
    <?php if (!empty($user['password_changed_at'])): ?>
      <div class="profile-kv"><span>Password changed</span><strong><?=e(date('M j, Y', strtotime($user['password_changed_at'])))?></strong></div>
    <?php endif; ?>
    <?php if ($user['role'] === 'admin'): ?>
      <div class="profile-kv"><span>HR / Recruiter seats</span><strong><?=seats_used((int)$user['id'])?> / <?=(int)$user['hr_account_limit']?></strong></div>
    <?php endif; ?>

    <div class="profile-perms">
      <span class="label">What your account can do</span>
      <?php if (is_super_admin($user)): ?>
        <p class="meta small">Super Admin — full administrative access.</p>
      <?php elseif ($myPermissions): ?>
        <div class="tag-row">
          <?php foreach ($myPermissions as $p): if (isset($catalog[$p])): ?>
            <span class="tag-pill"><?=icon('check',11)?><?=e($catalog[$p][0])?></span>
          <?php endif; endforeach; ?>
        </div>
      <?php else: ?>
        <p class="meta small">No additional permissions have been granted yet.</p>
      <?php endif; ?>
      <p class="meta small">Granted by your <?= $user['role'] === 'admin' ? 'Super Admin' : 'Admin' ?>. Ask them if you need something changed.</p>
    </div>
  </section>

  <div class="profile-forms">
    <form class="card form" method="post" enctype="multipart/form-data">
      <h2><?=icon('candidates',18)?> Edit profile</h2>
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="profile">

      <div class="photo-row">
        <?= user_avatar($user, 64) ?>
        <div class="field" style="margin:0;flex:1;min-width:0">
          <label for="pf-photo">Profile picture</label>
          <div class="dropzone" data-dropzone>
            <span class="dropzone-icon" aria-hidden="true"><?=icon('image',26)?></span>
            <span class="dropzone-title">Drop an image here, or click to browse</span>
            <span class="dropzone-hint">JPG, PNG or WEBP up to 3MB</span>
            <span class="dropzone-file" data-dropzone-name hidden></span>
            <input id="pf-photo" type="file" name="profile_image"
                   accept="image/jpeg,image/png,image/webp" data-dropzone-input>
          </div>
        </div>
      </div>

      <div class="field"><label>Full name</label><input name="name" required maxlength="120" value="<?=e($user['name'])?>"></div>
      <div class="form-grid">
        <div class="field"><label>Email</label><input type="email" name="email" required value="<?=e($user['email'])?>"><span class="hint">You sign in with this address.</span></div>
        <div class="field"><label>Job title <span class="hint">optional</span></label><input name="job_title" maxlength="120" value="<?=e($user['job_title'] ?? '')?>" placeholder="Talent Partner"></div>
      </div>

      <div class="field readonly-field">
        <label>Role</label>
        <input value="<?=e(role_label($user['role']))?>" disabled>
        <span class="hint">Only your administrator can change your role, permissions or account status.</span>
      </div>

      <button class="btn" type="submit"><?=icon('check',16)?> Save profile</button>
    </form>

    <form class="card form" method="post" autocomplete="off">
      <h2><?=icon('admin',18)?> Change password</h2>
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="password">
      <div class="field"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="form-grid">
        <div class="field"><label>New password</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
      </div>
      <p class="meta small">At least 8 characters, including a letter and a number. You will stay signed in on this device.</p>
      <button class="btn" type="submit"><?=icon('check',16)?> Change password</button>
    </form>
  </div>
</div>

<?php include __DIR__.'/includes/footer.php'; ?>
