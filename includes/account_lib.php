<?php
/**
 * Acme ATS — staff self-service: profile, password, and password reset requests.
 *
 * The rule running through this file: a person may edit who they are, never
 * what they are allowed to do. Role, permissions, seat limit and account status
 * are simply not writable from anything here, so a tampered form cannot reach
 * them.
 */

require_once __DIR__ . '/config.php';

/** Fields a user is permitted to change about themselves. Nothing else. */
function editable_profile_fields(): array {
    return ['name', 'email', 'job_title', 'profile_image'];
}

/** Minimum password rules, applied on every path that sets a password. */
function password_problems(string $password, string $confirm): array {
    $problems = [];
    if (strlen($password) < 8)              $problems[] = 'be at least 8 characters long';
    if (!preg_match('/[A-Za-z]/', $password)) $problems[] = 'contain at least one letter';
    if (!preg_match('/\d/', $password))       $problems[] = 'contain at least one number';
    if ($password !== $confirm)               $problems[] = 'match the confirmation field';
    return $problems;
}

function password_rules_sentence(array $problems): string {
    return 'Your new password must ' . implode(', ', $problems) . '.';
}

/** Save a staff profile photo, reusing the same rules as candidate photos. */
function save_user_profile_image(array $file, string $nameForFile, ?string &$error = null): ?string {
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($file['error']) || is_array($file['error'])) { $error = 'Invalid upload.'; return null; }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;              // nothing chosen is fine
    if ($file['error'] !== UPLOAD_ERR_OK) { $error = 'We could not receive that image — please try again.'; return null; }
    if ($file['size'] > 3 * 1024 * 1024) { $error = 'Profile photo must be under 3MB.'; return null; }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!array_key_exists($ext, $allowed)) { $error = 'Profile photo must be a JPG, PNG, or WEBP image.'; return null; }
    if (!is_uploaded_file($file['tmp_name'])) { $error = 'We could not receive that image — please try again.'; return null; }

    // Trust the file's actual contents, not its extension.
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $error = 'That file does not look like an image.'; return null;
    }

    $dir = __DIR__ . '/../assets/uploads/profile-images';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { $error = 'Upload folder could not be created.'; return null; }

    $safe = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($nameForFile)) ?? '', '-') ?: 'user';
    $filename = $safe . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) { $error = 'We could not save that image — please try again.'; return null; }
    return 'assets/uploads/profile-images/' . $filename;
}

/** Avatar markup for a staff user, falling back to initials. */
function user_avatar(array $user, int $size = 40): string {
    $name = trim((string)($user['name'] ?? '')) ?: 'User';
    $parts = preg_split('/\s+/', $name) ?: [$name];
    $initials = strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    $style = 'width:' . $size . 'px;height:' . $size . 'px';
    if (!empty($user['profile_image'])) {
        return '<img class="user-avatar avatar-image" style="' . $style . '" src="' . e($user['profile_image'])
             . '" alt="' . e($name) . '">';
    }
    return '<span class="user-avatar" style="' . $style . ';font-size:' . max(11, (int)round($size / 2.8)) . 'px">'
         . e($initials) . '</span>';
}

// ---------------------------------------------------------------------------
// Password reset requests
// ---------------------------------------------------------------------------

function password_reset_window_hours(): int {
    return max(1, (int)setting('password_reset_hours', '24'));
}

/** An open request for this account, if any. */
function open_password_reset(int $userId): ?array {
    try {
        $s = db()->prepare("SELECT * FROM password_reset_requests
                            WHERE user_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
        $s->execute([$userId]);
        return $s->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

function pending_password_reset_count(): int {
    try {
        return (int)db()->query("SELECT COUNT(*) FROM password_reset_requests WHERE status='pending'")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * Validate a reset token and return the request plus its account.
 * Expired tokens are marked so they cannot be retried.
 */
function password_reset_by_token(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return null;
    try {
        $s = db()->prepare("SELECT r.*, u.name, u.email, u.role, u.account_status
                            FROM password_reset_requests r JOIN users u ON u.id = r.user_id
                            WHERE r.reset_token = ? AND r.status = 'approved' LIMIT 1");
        $s->execute([$token]);
        $row = $s->fetch() ?: null;
    } catch (Throwable $e) { return null; }
    if (!$row) return null;

    if ($row['token_expires_at'] !== null && strtotime($row['token_expires_at']) < time()) {
        db()->prepare("UPDATE password_reset_requests SET status='expired' WHERE id=?")->execute([(int)$row['id']]);
        return null;
    }
    return $row;
}

/** Apply a new password and close out the request in one place. */
function complete_password_reset(int $requestId, int $userId, string $newPassword): void {
    $pdo = db();
    $pdo->prepare('UPDATE users SET password_hash=?, password_changed_at=NOW() WHERE id=?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    // Single use: the token is cleared so the same link cannot be replayed.
    $pdo->prepare("UPDATE password_reset_requests SET status='used', reset_token=NULL, token_expires_at=NULL WHERE id=?")
        ->execute([$requestId]);
}
