<?php
/**
 * Acme ATS — role hierarchy, granular permissions, and account provisioning limits.
 *
 *   Super Admin  →  Admin  →  HR / Recruiter  →  Applicant
 *
 * Super Admin decides what each Admin may do and how many HR/Recruiter accounts
 * they may provision. Admin decides what each of their HR/Recruiters may do.
 * Nothing here is cosmetic — every page and every POST handler runs the same
 * checks, so hiding a menu item is never the thing keeping someone out.
 */

const PERM_MANAGE_ACCOUNTS  = 'manage_accounts';
const PERM_JOB_MANAGEMENT   = 'job_management';
const PERM_JOB_POSTING      = 'job_posting';
const PERM_AUDIT_TRAIL      = 'audit_trail';
const PERM_APPLICANT_PORTAL = 'applicant_portal';

/** Permissions a Super Admin may grant to an Admin, with what each one unlocks. */
function admin_permission_catalog(): array {
    return [
        PERM_MANAGE_ACCOUNTS  => ['Manage HR/Recruiter accounts', 'Create HR/Recruiter accounts up to the assigned limit, and set what those accounts can do.'],
        PERM_JOB_MANAGEMENT   => ['Job management',               'Open Job Management, edit job records, and manage drafts.'],
        PERM_JOB_POSTING      => ['Job posting',                  'Create job postings and act on the approval queue — approve, reject, or request changes.'],
        PERM_AUDIT_TRAIL      => ['Audit trail',                  'Open audit_trail.php and review recorded system activity.'],
        PERM_APPLICANT_PORTAL => ['Applicant portal management',  'Oversee applicant registrations, applications, and portal settings.'],
    ];
}

/** The subset an Admin may grant onward to an HR/Recruiter. */
function recruiter_permission_catalog(): array {
    return [
        PERM_JOB_MANAGEMENT => ['Job management', 'View and edit their own job records and drafts.'],
        PERM_JOB_POSTING    => ['Job posting',    'Create job posting drafts and submit them for Admin approval. Does not grant publishing.'],
        PERM_AUDIT_TRAIL    => ['Audit trail',    'Read the audit trail (their own recorded activity).'],
    ];
}

function role_label(?string $role): string {
    return [
        'super_admin'    => 'Super Admin',
        'admin'          => 'Admin',
        'recruiter'      => 'HR / Recruiter',
        'hiring_manager' => 'Hiring Manager',
        'employee'       => 'Employee',
    ][$role ?? ''] ?? 'System';
}

function is_super_admin(?array $user = null): bool {
    $user = $user ?? current_user();
    return ($user['role'] ?? '') === 'super_admin';
}

function is_admin_level(?array $user = null): bool {
    $user = $user ?? current_user();
    return in_array($user['role'] ?? '', ['admin', 'super_admin'], true);
}

/**
 * Permission keys held by a user, read fresh from user_permissions.
 * Super Admin implicitly holds everything, so the table is never consulted for them.
 */
function user_permissions(int $userId, ?string $role = null): array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];

    if ($role === null) {
        $s = db()->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        $s->execute([$userId]);
        $role = (string)($s->fetchColumn() ?: '');
    }
    if ($role === 'super_admin') {
        return $cache[$userId] = array_keys(admin_permission_catalog());
    }
    try {
        $s = db()->prepare('SELECT permission FROM user_permissions WHERE user_id=?');
        $s->execute([$userId]);
        $perms = $s->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        // Migration not yet imported — fall back to the pre-RBAC behaviour so an
        // un-migrated install keeps working instead of locking everyone out.
        $perms = $role === 'admin' ? array_keys(admin_permission_catalog()) : [];
    }
    return $cache[$userId] = $perms;
}

function forget_permission_cache(): void {
    // user_permissions() memoises per request; nothing to do across requests,
    // but redirects after a save make the next page read fresh values anyway.
}

function has_permission(string $permission, ?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if (($user['role'] ?? '') === 'super_admin') return true;
    return in_array($permission, user_permissions((int)$user['id'], $user['role'] ?? null), true);
}

/** True when any one of the given permissions is held. */
function has_any_permission(array $permissions, ?array $user = null): bool {
    foreach ($permissions as $p) if (has_permission($p, $user)) return true;
    return false;
}

/**
 * Publishing is deliberately NOT a grantable permission.
 * Holding "job posting" lets you write a job; only Admin and above can make it live.
 */
function can_publish_jobs(?array $user = null): bool {
    $user = $user ?? current_user();
    return is_admin_level($user) && has_permission(PERM_JOB_POSTING, $user);
}

/** Admin and Super Admin act on the approval queue. Recruiters never do. */
function can_review_approvals(?array $user = null): bool {
    return can_publish_jobs($user);
}

/**
 * Hard stop for an unauthorised request. Renders inside the app shell so the
 * person keeps their navigation, and always sends a real 403.
 */
function deny_403(string $message = 'You do not have permission to perform this action.'): void {
    http_response_code(403);
    if (!headers_sent()) header('X-Robots-Tag: noindex');
    $GLOBALS['private'] = true;
    $pageTitle = 'Not authorised';
    $private = true;
    include __DIR__ . '/header.php';
    echo '<div class="denied-card card">'
       . '<div class="denied-code">403</div>'
       . '<h1>Not authorised</h1>'
       . '<p class="meta">' . e($message) . '</p>'
       . '<p class="meta small">If you believe you should have access, ask your '
       . (is_admin_level() ? 'Super Admin' : 'Admin') . ' to grant the matching permission.</p>'
       . '<div class="actions"><a class="btn secondary" href="' . (is_super_admin() ? 'super-admin.php' : 'dashboard.php') . '">'
       . (is_super_admin() ? 'Back to administration' : 'Back to overview') . '</a></div>'
       . '</div>';
    include __DIR__ . '/footer.php';
    exit;
}

/** Page-level and action-level guard. Call it before doing anything, not after. */
function require_permission(string $permission, string $message = ''): void {
    if (has_permission($permission)) return;
    $catalog = admin_permission_catalog() + recruiter_permission_catalog();
    $name = $catalog[$permission][0] ?? $permission;
    deny_403($message ?: 'This area requires the "' . $name . '" permission, which has not been granted to your account.');
}

function require_super_admin(): void {
    if (!is_super_admin()) deny_403('Only a Super Admin can open this area.');
}

function require_admin_level(): void {
    if (!is_admin_level()) deny_403('Only an Admin or Super Admin can open this area.');
}

// ---------------------------------------------------------------------------
// HR / Recruiter seats
//
// A "seat" is one HR/Recruiter account an Admin is allowed to hold. The number
// lives in users.hr_account_limit (the column keeps its original name; every
// person-facing label says "seats"). Only a Super Admin can change it.
//
// A seat stays occupied while the account exists in any recoverable state —
// including suspended and disabled — because those accounts can come back.
// A seat is freed by deleting the account, by a rejected application, or by a
// Super Admin explicitly releasing it (users.seat_released).
// ---------------------------------------------------------------------------

/** States that hold a seat. Rejected accounts never did any work, so they do not. */
function seat_holding_statuses(): array {
    return ['pending', 'active', 'suspended', 'disabled', 'pending_reactivation'];
}

function seat_limit(int $adminId): int {
    $s = db()->prepare('SELECT hr_account_limit FROM users WHERE id=? LIMIT 1');
    $s->execute([$adminId]);
    return (int)($s->fetchColumn() ?: 0);
}

function seats_used(int $adminId): int {
    $states = seat_holding_statuses();
    $in = implode(',', array_fill(0, count($states), '?'));
    $s = db()->prepare("SELECT COUNT(*) FROM users
                        WHERE created_by=? AND role='recruiter' AND seat_released=0
                          AND account_status IN ($in)");
    $s->execute(array_merge([$adminId], $states));
    return (int)$s->fetchColumn();
}

function seats_available(int $adminId): int {
    return max(0, seat_limit($adminId) - seats_used($adminId));
}

function can_create_hr_account(?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if (is_super_admin($user)) return true;
    if (($user['role'] ?? '') !== 'admin') return false;
    if (!has_permission(PERM_MANAGE_ACCOUNTS, $user)) return false;
    return seats_available((int)$user['id']) > 0;
}

function account_status_label(string $status): string {
    return [
        'pending'              => 'Pending approval',
        'active'               => 'Active',
        'rejected'             => 'Rejected',
        'suspended'            => 'Suspended',
        'disabled'             => 'Disabled',
        'pending_reactivation' => 'Pending reactivation approval',
    ][$status] ?? ucfirst($status);
}

/** users.active stays the single source of truth for login; keep it mirrored. */
function apply_account_status(int $userId, string $status, ?int $actorId = null, ?string $note = null): void {
    $allowed = ['pending','active','rejected','suspended','disabled','pending_reactivation'];
    if (!in_array($status, $allowed, true)) throw new RuntimeException('Invalid account status.');
    $s = db()->prepare('UPDATE users SET account_status=?, active=?, approved_by=?, approved_at=?, status_note=? WHERE id=?');
    $s->execute([
        $status,
        $status === 'active' ? 1 : 0,
        $actorId,
        in_array($status, ['active','rejected'], true) ? date('Y-m-d H:i:s') : null,
        $note,
        $userId,
    ]);
}

/**
 * Replace a user's permission set. Only keys present in $allowedKeys are ever
 * written, so a tampered form cannot grant something outside the catalog.
 * Returns [added, removed] for the audit record.
 */
function set_user_permissions(int $userId, array $requested, array $allowedKeys, int $grantedBy): array {
    $pdo = db();
    $requested = array_values(array_intersect(array_unique($requested), $allowedKeys));
    $current = user_permissions($userId);
    $current = array_values(array_intersect($current, $allowedKeys));

    $add = array_diff($requested, $current);
    $remove = array_diff($current, $requested);

    if ($add) {
        $ins = $pdo->prepare('INSERT IGNORE INTO user_permissions(user_id,permission,granted_by) VALUES(?,?,?)');
        foreach ($add as $p) $ins->execute([$userId, $p, $grantedBy]);
    }
    if ($remove) {
        $del = $pdo->prepare('DELETE FROM user_permissions WHERE user_id=? AND permission=?');
        foreach ($remove as $p) $del->execute([$userId, $p]);
    }
    return [array_values($add), array_values($remove)];
}

/** May the signed-in user administer this account? */
function can_manage_account(array $target, ?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if ((int)$target['id'] === (int)$user['id']) return false;      // never yourself
    if (is_super_admin($user)) return true;
    if (($user['role'] ?? '') !== 'admin') return false;
    if (!has_permission(PERM_MANAGE_ACCOUNTS, $user)) return false;
    // An Admin only owns the HR/Recruiters they provisioned.
    return ($target['role'] ?? '') === 'recruiter' && (int)($target['created_by'] ?? 0) === (int)$user['id'];
}

// ---------------------------------------------------------------------------
// Notifications
//
// Every administrative event that a Super Admin needs to act on or know about
// lands here: account suspensions, reactivation requests and decisions, new
// accounts awaiting approval, job approval movement, and permission or seat
// changes. Each row records who did it and what it points at, so the panel can
// render a real "who did what to whom, and when" line plus an action button.
// ---------------------------------------------------------------------------

/** Notification categories, with the label and icon the panel renders. */
function notification_types(): array {
    return [
        'account_suspended'     => ['Account suspended',      'signout'],
        'account_disabled'      => ['Account disabled',       'signout'],
        'reactivation_request'  => ['Reactivation request',   'bell'],
        'reactivation_approved' => ['Reactivation approved',  'check'],
        'reactivation_rejected' => ['Reactivation rejected',  'more'],
        'account_approval'      => ['Account awaiting approval', 'users'],
        'account'               => ['Account update',         'users'],
        'job_approval'          => ['Job approval required',  'admin'],
        'job_decision'          => ['Job decision',           'check'],
        'permissions'           => ['Permission change',      'settings'],
        'seat_limit'            => ['Seat limit change',      'limit'],
        'general'               => ['Notification',           'bell'],
    ];
}

function notification_type_label(string $type): string {
    return notification_types()[$type][0] ?? 'Notification';
}

function notification_type_icon(string $type): string {
    return notification_types()[$type][1] ?? 'bell';
}

function notify(
    int $userId,
    string $type,
    string $title,
    ?string $body = null,
    ?string $link = null,
    ?string $actionLabel = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    try {
        $s = db()->prepare('INSERT INTO notifications(user_id,actor_id,type,title,body,link,action_label,entity_type,entity_id) VALUES(?,?,?,?,?,?,?,?,?)');
        $s->execute([
            $userId,
            $_SESSION['user_id'] ?? null,
            $type,
            mb_substr($title, 0, 200),
            $body === null ? null : mb_substr($body, 0, 500),
            $link,
            $actionLabel === null ? null : mb_substr($actionLabel, 0, 60),
            $entityType,
            $entityId,
        ]);
    } catch (Throwable $e) { /* notifications are best-effort */ }
}

/** Everyone who can actually act on a job approval queue item. */
function notify_reviewers(string $type, string $title, ?string $body = null, ?string $link = null, ?string $actionLabel = null): void {
    try {
        $rows = db()->query("SELECT id, role FROM users WHERE role IN ('admin','super_admin') AND active=1")->fetchAll();
        foreach ($rows as $row) {
            $u = ['id' => $row['id'], 'role' => $row['role']];
            if (can_review_approvals($u)) notify((int)$row['id'], $type, $title, $body, $link, $actionLabel);
        }
    } catch (Throwable $e) { /* best-effort */ }
}

/** Administrative events every Super Admin should see in their panel. */
function notify_super_admins(string $type, string $title, ?string $body = null, ?string $link = null, ?string $actionLabel = null, ?string $entityType = null, ?int $entityId = null): void {
    try {
        $ids = db()->query("SELECT id FROM users WHERE role='super_admin' AND active=1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) notify((int)$id, $type, $title, $body, $link, $actionLabel, $entityType, $entityId);
    } catch (Throwable $e) { /* best-effort */ }
}

function unread_notifications(int $userId, int $limit = 6): array {
    try {
        $s = db()->prepare('SELECT * FROM notifications WHERE user_id=? AND read_at IS NULL ORDER BY created_at DESC LIMIT ' . (int)$limit);
        $s->execute([$userId]);
        return $s->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

function unread_notification_count(int $userId): int {
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');
        $s->execute([$userId]);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

// ---------------------------------------------------------------------------
// Account reactivation requests
//
// Enabling a suspended or disabled account is a request, never a direct edit.
// The account moves to 'pending_reactivation' and stays inactive until a Super
// Admin decides. Because the gate is the row in account_requests plus
// users.active, a hand-crafted POST cannot skip it.
// ---------------------------------------------------------------------------

function open_reactivation_request(int $userId): ?array {
    try {
        $s = db()->prepare("SELECT * FROM account_requests WHERE user_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
        $s->execute([$userId]);
        return $s->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

function pending_reactivation_count(): int {
    try {
        return (int)db()->query("SELECT COUNT(*) FROM account_requests WHERE status='pending'")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Total items a Super Admin currently has to decide on. */
function super_admin_action_count(): int {
    $n = pending_reactivation_count();
    try {
        $n += (int)db()->query("SELECT COUNT(*) FROM users WHERE role='recruiter' AND account_status='pending'")->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    return $n;
}

// ---------------------------------------------------------------------------
// Job posting states
// ---------------------------------------------------------------------------

/**
 * One label for a job, folding the legacy visibility column and the new
 * approval column together. status='open' is the only public state, so
 * "Published" is derived from it rather than duplicated.
 */
function job_state(array $job): string {
    $status = $job['status'] ?? 'draft';
    if ($status === 'open')   return 'published';
    if ($status === 'closed') return 'closed';
    if ($status === 'paused') return 'paused';
    $approval = $job['approval_status'] ?? 'none';
    if ($approval === 'none') return 'draft';
    return $approval;   // draft | pending | approved | rejected | changes_requested
}

function job_state_label(string $state): string {
    return [
        'draft'             => 'Draft',
        'pending'           => 'Pending approval',
        'approved'          => 'Approved',
        'rejected'          => 'Rejected',
        'changes_requested' => 'Changes requested',
        'published'         => 'Published',
        'paused'            => 'Paused',
        'closed'            => 'Closed',
    ][$state] ?? ucfirst($state);
}

function job_state_badge(array $job): string {
    $state = job_state($job);
    return '<span class="jstate jstate-' . e($state) . '"><span class="jstate-dot"></span>' . e(job_state_label($state)) . '</span>';
}

/** Append to the per-job review history. */
function record_job_approval(int $jobId, string $action, ?string $note = null, ?int $actorId = null): void {
    try {
        $s = db()->prepare('INSERT INTO job_approvals(job_id,actor_id,action,note) VALUES(?,?,?,?)');
        $s->execute([$jobId, $actorId ?? ($_SESSION['user_id'] ?? null), $action, $note]);
    } catch (Throwable $e) { /* best-effort */ }
}

function job_approval_history(int $jobId): array {
    try {
        $s = db()->prepare('SELECT ja.*, u.name actor_name, u.role actor_role
                            FROM job_approvals ja LEFT JOIN users u ON u.id=ja.actor_id
                            WHERE ja.job_id=? ORDER BY ja.created_at ASC');
        $s->execute([$jobId]);
        return $s->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Can this user open/edit this particular job?
 * Admin and Super Admin see everything; a Recruiter only sees their own.
 */
function can_edit_job(array $job, ?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;
    if (!has_permission(PERM_JOB_MANAGEMENT, $user) && !has_permission(PERM_JOB_POSTING, $user)) return false;
    if (is_admin_level($user)) return true;
    $mine = (int)($job['created_by'] ?? 0) === (int)$user['id'] || (int)($job['owner_id'] ?? 0) === (int)$user['id'];
    if (!$mine) return false;
    // Once it is with an Admin or live, the Recruiter can no longer alter it.
    return in_array(job_state($job), ['draft', 'rejected', 'changes_requested'], true);
}
