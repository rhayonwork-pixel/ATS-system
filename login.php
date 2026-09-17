<?php
/**
 * Staff sign-in.
 *
 * Layout: a two-column split at >=992px (brand showcase | form), collapsing to
 * a single column with a compact hero below that. Built with CSS Grid and the
 * project's existing custom properties — no framework.
 *
 * Security notes, in one place:
 *   - Session cookie flags (httponly, samesite=Strict, secure under HTTPS) and
 *     use_strict_mode are configured in includes/config.php, because they must
 *     be set BEFORE session_start() and the session is already open by the time
 *     this file runs.
 *   - CSRF uses the project-wide token: bin2hex(random_bytes(32)) held in
 *     $_SESSION['csrf'], compared with hash_equals(). It is validated inline
 *     here rather than via check_csrf() so a stale token renders a proper error
 *     banner instead of a bare 419 page.
 *   - Credentials are checked with a PDO prepared statement and
 *     password_verify(). The failure message is identical for an unknown email
 *     and a wrong password, so the form cannot be used to enumerate accounts.
 *   - session_regenerate_id(true) on success defeats session fixation.
 *   - Every echoed value goes through e(), which is htmlspecialchars with
 *     ENT_QUOTES and UTF-8.
 */
require_once __DIR__ . '/includes/config.php';

// Already signed in? Nothing to do here.
if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

$error       = '';
$errorField  = '';          // which input to mark aria-invalid
$emailValue  = '';          // sticky on failure; the password never is
$remember    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- CSRF ---------------------------------------------------------------
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        $error = 'Your session expired before the form was submitted. Please try again.';
    } else {
        $emailValue = trim((string)filter_input(INPUT_POST, 'email', FILTER_UNSAFE_RAW));
        $email      = strtolower($emailValue);
        $password   = (string)($_POST['password'] ?? '');
        $remember   = !empty($_POST['remember']);

        // --- Validation -----------------------------------------------------
        if ($email === '' || $password === '') {
            $error = 'Please enter both your email address and your password.';
            $errorField = $email === '' ? 'email' : 'password';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That does not look like a valid email address.';
            $errorField = 'email';
        } else {
            // --- Authentication ---------------------------------------------
            // Looked up regardless of `active` so a correct password on a
            // pending or suspended account gets a useful message. The lookup
            // still reveals nothing on its own: a wrong password falls through
            // to the same generic error as an unknown address.
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            $passwordOk = $user && password_verify($password, $user['password_hash']);
            $accountStatus = $user['account_status'] ?? 'active';

            if ($passwordOk && (int)$user['active'] !== 1) {
                $error = [
                    'pending'              => 'This account is waiting for Super Admin approval. You will be able to sign in once it is approved.',
                    'rejected'             => 'This account was not approved. Please contact your Admin.',
                    'suspended'            => 'This account is suspended. Please contact your Admin.',
                    'disabled'             => 'This account has been disabled. Please contact your Admin.',
                    'pending_reactivation' => 'This account is waiting for a Super Admin to approve its reactivation.',
                ][$accountStatus] ?? 'This account is not active. Please contact your Admin.';

            } elseif ($passwordOk) {
                session_regenerate_id(true);          // defeat session fixation
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user']    = $user['email'];

                // "Remember me" extends the session cookie rather than issuing a
                // second long-lived credential — one thing to expire, one thing
                // to revoke.
                if ($remember) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), session_id(), [
                        'expires'  => time() + 60 * 60 * 24 * 30,
                        'path'     => $params['path'],
                        'domain'   => $params['domain'],
                        'secure'   => $params['secure'],
                        'httponly' => true,
                        'samesite' => 'Strict',
                    ]);
                }

                audit('login', 'user', (int)$user['id']);

                // Only ever redirect to a bare filename in this app.
                $next = $_GET['next'] ?? 'dashboard.php';
                if (!preg_match('/^[a-zA-Z0-9_-]+\.php$/', $next)) $next = 'dashboard.php';
                // The Super Admin panel is administration-only.
                if (($user['role'] ?? '') === 'super_admin' && $next === 'dashboard.php') $next = 'super-admin.php';

                header('Location: ' . $next);
                exit;

            } else {
                // Identical for an unknown email and a wrong password.
                $error = 'Invalid email or password.';
                $errorField = 'password';
            }
        }
    }
}

$pageTitle = 'Sign in';
$authPage  = true;
include __DIR__ . '/includes/header.php';
?>
<main class="auth-split">

  <!-- LEFT: brand showcase. Decorative only, so it is aria-hidden below the
       heading and carries no keyboard stops. On < 992px it becomes a compact
       banner above the form. -->
  <section class="auth-brand">
    <div class="auth-brand-inner">
      <div class="auth-logo"><?= logo_html(38) ?><span><?=e(setting('company_name','Acme'))?><span class="auth-logo-slash">/</span>people</span></div>

      <div class="auth-brand-copy">
        <h1>Welcome back</h1>
        <p>The recruiting workspace for <?=e(setting('company_name','Acme'))?> — candidates, interviews and hiring decisions in one place.</p>
      </div>

      <ul class="auth-badges">
        <li><span class="auth-badge-icon" aria-hidden="true"><?=icon('check',14)?></span>Enterprise-grade access control</li>
        <li><span class="auth-badge-icon" aria-hidden="true"><?=icon('admin',14)?></span>Encrypted session, HTTP-only cookie</li>
        <li><span class="auth-badge-icon" aria-hidden="true"><?=icon('analytics',14)?></span>Every action written to the audit trail</li>
      </ul>

      <p class="auth-foot">Applicants do not need an account —
        <a href="index.php">browse open roles</a> or
        <a href="application-status.php">check an application</a>.</p>
    </div>
  </section>

  <!-- RIGHT: the form, centred in its column. -->
  <section class="auth-form-col">
    <div class="auth-card">
      <header class="auth-card-head">
        <h2>Sign in</h2>
        <p class="meta">For authorised Acme recruiting and HR staff.</p>
      </header>

      <?php if ($error): ?>
        <!-- role="alert" so it is announced the moment the page renders after a
             failed post; referenced by aria-describedby on the faulty input. -->
        <div class="auth-alert" role="alert" id="login-error">
          <span class="auth-alert-icon" aria-hidden="true"><?=icon('more',16)?></span>
          <span><?=e($error)?></span>
        </div>
      <?php endif; ?>

      <form method="post" novalidate class="auth-form">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">

        <div class="auth-field">
          <label for="login-email">Email address</label>
          <input id="login-email" type="email" name="email"
                 value="<?=e($emailValue)?>"
                 autocomplete="username" inputmode="email"
                 placeholder="you@acme.test" required
                 <?= $errorField === 'email' ? 'aria-invalid="true" aria-describedby="login-error"' : '' ?>
                 <?= $error === '' ? 'autofocus' : ($errorField === 'email' ? 'autofocus' : '') ?>>
        </div>

        <div class="auth-field">
          <label for="login-password">Password</label>
          <input id="login-password" type="password" name="password"
                 autocomplete="current-password" required
                 <?= $errorField === 'password' ? 'aria-invalid="true" aria-describedby="login-error" autofocus' : '' ?>>
        </div>

        <div class="auth-aux">
          <label class="auth-remember">
            <input type="checkbox" name="remember" value="1" <?= $remember ? 'checked' : '' ?>>
            <span>Remember me</span>
          </label>
          <a class="auth-link" href="forgot-password.php">Forgot password?</a>
        </div>

        <button class="auth-submit" type="submit" data-auth-submit>
          <span class="auth-submit-text">Sign in</span>
          <span class="auth-spinner" aria-hidden="true"></span>
        </button>
      </form>

      <p class="auth-note">Password resets for staff accounts are approved by a Super Admin.</p>

      <details class="auth-demo">
        <summary>Prototype accounts</summary>
        <p>superadmin@acme.test · admin@acme.test · recruiter@acme.test<br>
           Password for all three: <strong>password</strong></p>
      </details>
    </div>
  </section>
</main>

<script>
/* Disables the button on submit so a double click cannot post twice, and gives
   the pending state something visible. Native validation still runs first. */
(function () {
  var form = document.querySelector('.auth-form');
  var btn = document.querySelector('[data-auth-submit]');
  if (!form || !btn) return;
  form.addEventListener('submit', function () {
    if (!form.checkValidity()) return;      // let the browser report the problem
    btn.classList.add('is-loading');
    btn.disabled = true;
    btn.querySelector('.auth-submit-text').textContent = 'Signing in…';
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
