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
    // Land back on the panel that was submitted, with its message shown there.
    $panel = ['profile' => 'edit', 'password' => 'password'][$action] ?? null;
    header('Location: profile.php' . ($panel ? '?done='.$action.'#pf-'.$panel : '')); exit;
}

// Read fresh so the page reflects what was just saved.
$stmt = $pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
$stmt->execute([(int)$me['id']]);
$user = $stmt->fetch() ?: $me;

$myPermissions = user_permissions((int)$user['id'], $user['role']);
$catalog = admin_permission_catalog() + recruiter_permission_catalog();
// Only permissions the catalog can name are shown, so the count matches the tags.
$shownPerms = array_values(array_filter($myPermissions, fn($p) => isset($catalog[$p])));

// After a save the redirect carries ?done=profile|password, and the message is
// shown inside that panel rather than above the page.
$flash = take_flash();
$done  = in_array($_GET['done'] ?? '', ['profile', 'password'], true) ? $_GET['done'] : '';
$panelNotice = function (string $panel) use ($flash, $done): string {
    if (!$flash || $done !== $panel) return '';
    return '<div class="notice '.e($flash[0]).'" role="'.($flash[0] === 'error' ? 'alert' : 'status').'">'.e($flash[1]).'</div>';
};

$pageTitle = 'My profile';
include __DIR__.'/includes/header.php';
?>
<div class="page-container profile-page" data-profile-page>
<div class="dashboard-head">
  <div><div class="eyebrow">Account</div><h1>My profile</h1>
  <p class="meta">Your own details. Role, permissions and account status are set by your administrator and cannot be changed here.</p></div>
</div>

<?php if ($flash && $done === ''): ?><div class="notice <?=e($flash[0])?>"><?=e($flash[1])?></div><?php endif; ?>

<!-- Sticky summary + scrollable form panels. See docs/PROFILE_REDESIGN_SPEC.html.
     The sentinel lets app.js tell when the summary card is pinned. -->
<span class="profile-stick-sentinel" data-stick-sentinel aria-hidden="true"></span>
<div class="profile-layout">
  <aside class="card profile-summary" data-profile-summary aria-labelledby="pf-name">
    <div class="profile-id">
      <?= user_avatar($user, 96) ?>
      <div class="profile-id-text">
        <h2 id="pf-name"><?=e($user['name'])?></h2>
        <?php if (!empty($user['job_title'])): ?><p class="meta profile-title"><?=e($user['job_title'])?></p><?php endif; ?>
        <div class="profile-badges">
          <span class="pill"><?=e(role_label($user['role']))?></span>
          <span class="pill status-<?=e($user['account_status'] ?? 'active')?>"><?=e(account_status_label($user['account_status'] ?? 'active'))?></span>
        </div>
      </div>
    </div>

    <dl class="profile-facts">
      <div class="profile-kv"><dt>Email</dt><dd><?=e($user['email'])?></dd></div>
      <div class="profile-kv"><dt>Member since</dt><dd><?=e(date('M j, Y', strtotime($user['created_at'])))?></dd></div>
      <?php if (!empty($user['password_changed_at'])): ?>
        <div class="profile-kv"><dt>Password changed</dt><dd><?=e(date('M j, Y', strtotime($user['password_changed_at'])))?></dd></div>
      <?php endif; ?>
      <?php if ($user['role'] === 'admin'): ?>
        <div class="profile-kv"><dt>HR / Recruiter seats</dt><dd><?=seats_used((int)$user['id'])?> / <?=(int)$user['hr_account_limit']?></dd></div>
      <?php endif; ?>
    </dl>

    <!-- Collapsed when long: it is the main thing that makes the card too tall to stick. -->
    <details class="profile-perms" <?= (is_super_admin($user) || count($shownPerms) <= 4) ? 'open' : '' ?>>
      <summary>What your account can do <span class="pill"><?= is_super_admin($user) ? 'All' : count($shownPerms) ?></span></summary>
      <?php if (is_super_admin($user)): ?>
        <p class="meta small">Super Admin — full administrative access.</p>
      <?php elseif ($shownPerms): ?>
        <div class="tag-row">
          <?php foreach ($shownPerms as $p): ?>
            <span class="tag-pill"><?=icon('check',11)?><?=e($catalog[$p][0])?></span>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="meta small">No additional permissions have been granted yet.</p>
      <?php endif; ?>
      <p class="meta small">Granted by your <?= $user['role'] === 'admin' ? 'Super Admin' : 'Admin' ?>. Ask them if you need something changed.</p>
    </details>

    <nav class="profile-jump" aria-label="Jump to a section">
      <a class="btn small secondary" href="#pf-edit">Edit profile</a>
      <a class="btn small secondary" href="#pf-password">Change password</a>
    </nav>
  </aside>

  <div class="profile-forms">
    <!-- Head and foot stay put; only the body scrolls. Plain divs, not
         header/footer, so they do not become extra landmarks inside a form. -->
    <form class="card form profile-panel" id="pf-edit" method="post" enctype="multipart/form-data"
          aria-labelledby="pf-edit-title" data-profile-panel>
      <div class="profile-panel-head">
        <h2 id="pf-edit-title"><?=icon('candidates',18)?> Edit profile</h2>
        <p class="meta small">Your name, sign-in email, job title and photo.</p>
        <?= $panelNotice('profile') ?>
      </div>
      <div class="profile-panel-body" data-panel-body role="group" aria-labelledby="pf-edit-title">
        <span class="panel-edge" data-edge="top" aria-hidden="true"></span>
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="profile">

        <div class="photo-row">
          <?= user_avatar($user, 64) ?>
          <div class="field photo-field">
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

        <div class="field"><label for="pf-fullname">Full name</label>
          <input id="pf-fullname" name="name" required maxlength="120" autocomplete="name" value="<?=e($user['name'])?>"></div>
        <div class="form-grid">
          <div class="field"><label for="pf-email">Email</label>
            <input id="pf-email" type="email" name="email" required autocomplete="email" aria-describedby="pf-email-hint" value="<?=e($user['email'])?>">
            <span class="hint" id="pf-email-hint">You sign in with this address.</span></div>
          <div class="field"><label for="pf-jobtitle">Job title <span class="hint">optional</span></label>
            <input id="pf-jobtitle" name="job_title" maxlength="120" autocomplete="organization-title" value="<?=e($user['job_title'] ?? '')?>" placeholder="Talent Partner"></div>
        </div>

        <div class="field readonly-field">
          <label for="pf-role">Role</label>
          <input id="pf-role" value="<?=e(role_label($user['role']))?>" disabled aria-describedby="pf-role-hint">
          <span class="hint" id="pf-role-hint">Only your administrator can change your role, permissions or account status.</span>
        </div>
        <span class="panel-edge" data-edge="bottom" aria-hidden="true"></span>
      </div>
      <div class="profile-panel-foot">
        <button class="btn" type="submit"><?=icon('check',16)?> Save profile</button>
      </div>
    </form>

    <form class="card form profile-panel" id="pf-password" method="post" autocomplete="off"
          aria-labelledby="pf-password-title" data-profile-panel>
      <div class="profile-panel-head">
        <h2 id="pf-password-title"><?=icon('admin',18)?> Change password</h2>
        <p class="meta small">You will stay signed in on this device.</p>
        <?= $panelNotice('password') ?>
      </div>
      <div class="profile-panel-body" data-panel-body role="group" aria-labelledby="pf-password-title">
        <span class="panel-edge" data-edge="top" aria-hidden="true"></span>
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="password">
        <!-- Lets password managers tie the new password to this account. Never read by the handler. -->
        <input type="text" name="username" autocomplete="username" value="<?=e($user['email'])?>" hidden>
        <div class="field"><label for="pf-current">Current password</label>
          <input id="pf-current" type="password" name="current_password" required autocomplete="current-password"></div>
        <div class="form-grid">
          <div class="field"><label for="pf-new">New password</label>
            <input id="pf-new" type="password" name="new_password" required minlength="8" autocomplete="new-password" aria-describedby="pf-rules"></div>
          <div class="field"><label for="pf-confirm">Confirm new password</label>
            <input id="pf-confirm" type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
        </div>
        <p class="meta small" id="pf-rules">At least 8 characters, including a letter and a number.</p>
        <span class="panel-edge" data-edge="bottom" aria-hidden="true"></span>
      </div>
      <div class="profile-panel-foot">
        <button class="btn" type="submit"><?=icon('check',16)?> Change password</button>
      </div>
    </form>
  </div>
</div>
</div><!-- /.profile-page -->

<?php include __DIR__.'/includes/footer.php'; ?>
