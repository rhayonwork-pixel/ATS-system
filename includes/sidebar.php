<?php
$sbUser    = current_user();
$role      = $sbUser['role'] ?? '';
$current   = basename($_SERVER['PHP_SELF'] ?? '');
$sbAdmin   = is_admin_level($sbUser);
$sbSuper   = is_super_admin($sbUser);
$canJobs   = has_permission(PERM_JOB_MANAGEMENT, $sbUser);
$canPost   = has_permission(PERM_JOB_POSTING, $sbUser);
$canReview = can_review_approvals($sbUser);
$canAudit  = has_permission(PERM_AUDIT_TRAIL, $sbUser);
$canAccts  = has_permission(PERM_MANAGE_ACCOUNTS, $sbUser);
$canPortal = has_permission(PERM_APPLICANT_PORTAL, $sbUser);
$jobsHref  = $sbAdmin ? 'admin.php' : 'my-jobs.php';
$unread    = $sbUser ? unread_notification_count((int)$sbUser['id']) : 0;

$openRequests  = $sbSuper ? super_admin_action_count() : 0;
$pendingResets = $sbSuper ? pending_password_reset_count() : 0;

// How many postings are sitting with an Admin right now.
$pendingReview = 0;
if ($canReview && !$sbSuper) {
    try { $pendingReview = (int)db()->query("SELECT COUNT(*) FROM jobs WHERE approval_status='pending'")->fetchColumn(); }
    catch (Throwable $e) { $pendingReview = 0; }
}

$isActive = static function (array $pages) use ($current) {
    return in_array($current, $pages, true) ? 'active' : '';
};

/**
 * One navigation link. Returns '' when the person may not see it, so a group
 * can simply concatenate its children and check whether anything came back.
 */
$navItem = static function (array $pages, string $href, string $iconName, string $label, $badge = 0, string $badgeClass = '') use ($current) {
    $active = in_array($current, $pages, true) ? ' active' : '';
    $out  = '<a class="nav-item' . $active . '" href="' . e($href) . '" title="' . e($label) . '">';
    $out .= icon($iconName, 17);
    $out .= '<span class="nav-text">' . e($label) . '</span>';
    if ($badge) $out .= '<span class="nav-count ' . e($badgeClass) . '">' . (int)$badge . '</span>';
    $out .= '</a>';
    return $out;
};

/** A collapsible group. Rendered only when it has children. */
$navGroup = static function (string $key, string $label, string $iconName, string $children) use ($current) {
    if (trim($children) === '') return '';
    // A group holding the current page starts open, so the active item is
    // always visible without the person reopening anything.
    $open = strpos($children, 'nav-item active') !== false;
    $out  = '<div class="nav-group' . ($open ? ' is-open' : '') . '" data-nav-group="' . e($key) . '">';
    $out .= '<button type="button" class="nav-group-btn" data-group-toggle aria-expanded="' . ($open ? 'true' : 'false')
          . '" aria-controls="navgrp-' . e($key) . '" title="' . e($label) . '">';
    $out .= icon($iconName, 17);
    $out .= '<span class="nav-text">' . e($label) . '</span>';
    $out .= '<span class="nav-caret" aria-hidden="true">' . icon('chevron', 13) . '</span>';
    $out .= '</button>';
    $out .= '<div class="nav-group-items" id="navgrp-' . e($key) . '" data-group-items>' . $children . '</div>';
    $out .= '</div>';
    return $out;
};
?>
<aside class="sidebar" id="app-sidebar" data-sidebar aria-label="Main navigation">

  <!-- Sidebar header.
       One state, two actions, no duplicate controls:
         collapsed -> the logo is the expand button (arrow appears on hover)
         expanded  -> the arrow beside the logo is the collapse button
       The logo never collapses; the arrow never expands. -->
  <div class="sidebar-brand">
    <button type="button" class="brand-button" data-sidebar-expand
            aria-label="Expand sidebar" title="Expand sidebar">
      <span class="brand-logo"><?= logo_html(32) ?></span>
      <span class="brand-arrow" aria-hidden="true"><?=icon('chevron',18)?></span>
    </button>

    <span class="sidebar-brand-text">
      <strong><?=e(setting('company_name','Acme'))?>/people</strong>
      <small><?=e(role_label($role))?></small>
    </span>

    <button type="button" class="brand-collapse" data-sidebar-collapse
            aria-label="Collapse sidebar" title="Collapse sidebar">
      <?=icon('chevron',15)?>
    </button>
  </div>

  <nav class="sidebar-nav">
  <?php if ($sbSuper): /* Super Admin keeps the deliberately minimal panel. */ ?>

    <?= $navGroup('administration', 'Administration', 'admin',
          $navItem(['super-admin.php'], 'super-admin.php', 'users', 'Admins')
        . $navItem(['users.php'], 'users.php', 'candidates', 'HR / Recruiters', $openRequests, 'alert')
        . $navItem(['password-resets.php'], 'password-resets.php', 'settings', 'Password resets', $pendingResets, 'alert')
        . $navItem(['applicant-portal.php'], 'applicant-portal.php', 'public', 'Applicant portal')
        . $navItem(['audit_trail.php'], 'audit_trail.php', 'analytics', 'Audit trail')
        . $navItem(['settings.php'], 'settings.php', 'settings', 'Settings')
    ) ?>

    <div class="sidebar-label">Notifications</div>
    <?= $navItem(['notifications.php'], 'notifications.php', 'bell', 'Notifications', $unread, 'alert') ?>

  <?php else: ?>

    <div class="sidebar-label">Recruiting</div>
    <?= $navItem(['dashboard.php'], 'dashboard.php', 'overview', 'Overview') ?>
    <?= $navItem(['pipeline.php'], 'pipeline.php', 'pipeline', 'Hiring pipeline') ?>
    <?= $navItem(['candidates.php','candidate.php'], 'candidates.php', 'candidates', 'Candidates') ?>
    <?= $navItem(['add_candidate.php'], 'add_candidate.php', 'plus', 'Add candidate') ?>
    <?= $navItem(['interviews.php','interview-room.php'], 'interviews.php', 'interviews', 'Interviews') ?>
    <?= $navItem(['analytics.php','analytics-report.php'], 'analytics.php', 'analytics', 'Analytics') ?>

    <?= $navGroup('jobs', 'Jobs', 'admin',
          ($canJobs ? $navItem(['admin.php','my-jobs.php'], $jobsHref, 'admin', 'Jobs') : '')
        . ($canPost ? $navItem(['job-post.php'], 'job-post.php', 'plus', 'Create job') : '')
        . ($canReview ? $navItem(['job-approvals.php'], 'job-approvals.php', 'check', 'Job approvals', $pendingReview) : '')
    ) ?>

    <?= $navGroup('people', 'People', 'employees',
          $navItem(['employees.php'], 'employees.php', 'employees', 'Employees')
        . $navItem(['attendance.php','attendance-admin.php'], 'attendance.php', 'attendance', 'Attendance')
    ) ?>

    <?= $navGroup('administration', 'Administration', 'settings',
          ($canAccts ? $navItem(['users.php'], 'users.php', 'candidates', 'HR / Recruiters') : '')
        . ($sbAdmin ? $navItem(['password-resets.php'], 'password-resets.php', 'admin', 'Password resets') : '')
        . ($canPortal ? $navItem(['applicant-portal.php'], 'applicant-portal.php', 'public', 'Applicant portal') : '')
        . ($canAudit ? $navItem(['audit_trail.php'], 'audit_trail.php', 'analytics', 'Audit trail') : '')
        . ($sbAdmin ? $navItem(['settings.php'], 'settings.php', 'settings', 'Settings') : '')
    ) ?>

  <?php endif; ?>
  </nav>

  <div class="sidebar-bottom">
    <?php if (!$sbSuper): ?>
      <?= $navItem(['notifications.php'], 'notifications.php', 'bell', 'Notifications', $unread, 'alert') ?>
    <?php endif; ?>
    <?= $navItem(['profile.php'], 'profile.php', 'candidates', 'My profile') ?>
    <?= $navItem([], 'index.php', 'public', 'Public careers site') ?>
    <?= $navItem([], 'logout.php', 'signout', 'Sign out') ?>
  </div>
</aside>
