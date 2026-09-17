<?php
/**
 * app/Controllers/AuthController.php
 *
 * IMPORTANT — reference implementation, not yet wired in:
 * login.php still handles its own request (cookies, "remember me",
 * redirect-target validation, and the login form's HTML) directly, exactly
 * as it did before this reorganization. Rewriting a session/cookie/CSRF
 * flow that already works, with no live database or browser available to
 * verify the result, is a real way to silently break sign-in for every
 * user — so that rewiring was deliberately left out of this pass rather
 * than done blind. This class extracts the *authentication decision*
 * (is this email+password combination allowed in, and why not if not) so
 * new code — or a future, carefully-tested swap-in for login.php — has a
 * single tested entry point instead of copying the inline logic again.
 */
require_once __DIR__ . '/../../config/database.php';

final class AuthController
{
    /**
     * @return array{ok: bool, user?: array, error?: string}
     * Mirrors login.php's own rules exactly: looked up regardless of
     * `active` so a correct password on a pending/suspended account still
     * gets a specific message, never a generic "wrong password".
     */
    public static function attempt(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') {
            return ['ok' => false, 'error' => 'Please enter both your email address and your password.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That does not look like a valid email address.'];
        }

        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        $passwordOk = $user && password_verify($password, $user['password_hash']);
        if (!$passwordOk) {
            return ['ok' => false, 'error' => 'That email and password combination was not found.'];
        }

        if ((int) $user['active'] !== 1) {
            $accountStatus = $user['account_status'] ?? 'active';
            $error = [
                'pending'              => 'This account is waiting for Super Admin approval. You will be able to sign in once it is approved.',
                'rejected'             => 'This account was not approved. Please contact your Admin.',
                'suspended'            => 'This account is suspended. Please contact your Admin.',
                'disabled'             => 'This account has been disabled. Please contact your Admin.',
                'pending_reactivation' => 'This account is waiting for a Super Admin to approve its reactivation.',
            ][$accountStatus] ?? 'This account is not active. Please contact your Admin.';
            return ['ok' => false, 'error' => $error];
        }

        return ['ok' => true, 'user' => $user];
    }
}
