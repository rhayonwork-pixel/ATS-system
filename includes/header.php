<?php
// config.php owns session configuration (cookie flags must be set before the
// session opens). Requiring it here guarantees the hardened settings apply even
// if a page reaches the header first.
require_once __DIR__ . '/config.php';
$pageTitle = $pageTitle ?? 'Acme Careers';
$private = $private ?? false;
$minimal = $minimal ?? false;
// Auth pages own the whole viewport: no topbar, no shell wrapper, no <main>
// from the header (the page supplies its own).
$authPage = $authPage ?? false;
$user = function_exists('current_user') ? current_user() : null;

// Is there really an interview live right now? Checked straight against the
// database (interviews.room_status / room_last_ping) — a room can only be
// "live" here if a room actually pinged it recently, so a page load can
// never show this for an interview that was never created or already ended.
// Scoped to rooms this session opened (room-presence.php records them), so a
// colleague's interview never covers this user's page.
$activeRoom = null;
if ($private && $user) {
    $pdo = db();
    $pdo->exec('UPDATE interviews SET room_status="idle" WHERE room_status="live" AND (room_last_ping IS NULL OR room_last_ping < NOW() - INTERVAL 12 SECOND)');
    $myRooms = array_values(array_filter((array)($_SESSION['open_room_codes'] ?? []), 'is_string'));
    if ($myRooms) {
        $in = implode(',', array_fill(0, count($myRooms), '?'));
        $stmt = $pdo->prepare("SELECT i.room_code, j.title FROM interviews i
            JOIN applications a ON a.id=i.application_id JOIN jobs j ON j.id=a.job_id
            WHERE i.room_status='live' AND i.room_last_ping IS NOT NULL
              AND i.room_last_ping >= NOW() - INTERVAL 12 SECOND
              AND i.room_code IN ($in)
            ORDER BY i.room_last_ping DESC LIMIT 1");
        $stmt->execute($myRooms);
        $activeRoom = $stmt->fetch() ?: null;
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Acme</title>
<link rel="stylesheet" href="assets/css/theme-tokens.css?v=<?= @filemtime(__DIR__.'/../assets/css/theme-tokens.css') ?: time() ?>">
<link rel="stylesheet" href="assets/css/components.css?v=<?= @filemtime(__DIR__.'/../assets/css/components.css') ?: time() ?>">
<link rel="stylesheet" href="assets/styles.css?v=<?= @filemtime(__DIR__.'/../assets/styles.css') ?: time() ?>">
<link rel="stylesheet" href="assets/light-theme-v3.css?v=<?= @filemtime(__DIR__.'/../assets/light-theme-v3.css') ?: time() ?>">
<script>
/* Applied before first paint so the page never flashes the wrong theme.
   Explicit choice wins; otherwise the OS preference decides. body.dark is set
   alongside data-theme because a lot of existing CSS is written against it. */
(function () {
  try {
    var saved = localStorage.getItem('ats_theme_preference');
    if (!saved) {                       // migrate the older key, once
      var old = localStorage.getItem('theme');
      if (old === 'dark' || old === 'light') { saved = old; localStorage.setItem('ats_theme_preference', old); }
    }
    var dark = saved ? saved === 'dark'
                     : window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    document.documentElement.classList.toggle('dark', dark);
  } catch (e) {}
})();
</script>
<script>/* Runs immediately, before any external/cached script — wipes the old
   localStorage "meeting ongoing" flag from earlier app versions so a stale
   cached copy of app.js can never read it and re-show the overlay. */
try{ localStorage.removeItem('acme_active_room'); }catch(e){}</script>
</head>
<body class="<?= $private ? 'private-app' : ($authPage ? 'auth-page' : ($minimal ? 'minimal-app' : 'public-site')) ?>">
<?php if ($authPage): /* page renders its own <main> */ ?>
<?php elseif ($private): ?>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <!-- Backdrop for the mobile drawer. Clicking it closes the sidebar. -->
    <div class="sidebar-backdrop" data-sidebar-backdrop hidden></div>
    <main class="app-main">
        <!-- Floating transparent utility bar.
             No title, brand, background, border or shadow — every page renders
             its own <h1> in the body, so nothing is lost by removing it here.
             Sticky rather than absolute: it still pins to the top and content
             scrolls beneath it, but it reserves its own height, so it can never
             occlude the first interactive element on a page. -->
        <header class="util-bar" data-util-bar>
            <button class="util-btn util-menu" data-menu-toggle type="button"
                    aria-label="Open navigation" aria-expanded="false"
                    aria-controls="app-sidebar"><?= icon('chevron', 20) ?></button>

            <div class="header-utils">
                <button class="util-btn" data-theme-toggle type="button"
                        aria-label="Toggle color theme" title="Toggle color theme">
                    <span class="util-glyph" aria-hidden="true">◐</span>
                </button>

                <?php $bellCount = $user ? unread_notification_count((int)$user['id']) : 0; ?>
                <div class="notif-rail">
                    <button type="button" class="util-btn notif-trigger" data-notif-trigger
                            aria-haspopup="true" aria-expanded="false" aria-controls="notif-flyout"
                            aria-label="View notifications<?= $bellCount ? ' - ' . $bellCount . ' unread' : '' ?>">
                        <?= icon('bell', 20) ?>
                        <span class="notif-badge<?= $bellCount ? '' : ' is-empty' ?>" data-notif-badge
                              data-unread-count="<?= (int)$bellCount ?>" aria-hidden="true"></span>
                    </button>

                    <div class="notif-flyout" id="notif-flyout" data-notif-flyout
                         data-csrf="<?=e(csrf_token())?>" hidden
                         role="dialog" aria-label="Notifications Panel">
                        <header class="notif-flyout-head">
                            <div class="notif-head-titles">
                                <strong>Notifications</strong>
                                <span class="notif-count-pill" data-notif-summary hidden></span>
                            </div>
                            <button type="button" class="notif-markall" data-notif-markall>Mark all as read</button>
                        </header>

                        <div class="notif-chips" role="tablist" aria-label="Filter notifications">
                            <button type="button" class="notif-chip is-active" role="tab" aria-selected="true" data-notif-cat="">All</button>
                            <button type="button" class="notif-chip" role="tab" aria-selected="false" data-notif-cat="application">Applications</button>
                            <button type="button" class="notif-chip" role="tab" aria-selected="false" data-notif-cat="interview">Interviews</button>
                            <button type="button" class="notif-chip" role="tab" aria-selected="false" data-notif-cat="system">System</button>
                        </div>

                        <div class="notif-list" data-notif-list aria-live="polite" aria-busy="false">
                            <p class="notif-empty">Loading…</p>
                        </div>

                        <footer class="notif-flyout-foot">
                            <a href="notifications.php">View all notifications</a>
                        </footer>
                    </div>
                </div>

                <?php if ($user): ?>
                <a class="util-btn util-avatar" href="profile.php"
                   aria-label="Open user profile menu" title="<?=e($user['name'])?>">
                    <?= function_exists('user_avatar') ? user_avatar($user, 32) : '' ?>
                </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- Backdrop for the mobile notification sheet. -->
        <div class="notif-backdrop" data-notif-backdrop hidden></div>

        <div class="room-active-overlay" data-room-overlay data-room-code="<?= $activeRoom ? e($activeRoom['room_code']) : '' ?>" <?= $activeRoom ? '' : 'hidden' ?>>
            <div class="room-active-card">
                <button type="button" class="room-active-close" data-room-overlay-dismiss aria-label="Dismiss">×</button>
                <span class="live-dot"></span>
                <h3>The meeting is ongoing</h3>
                <p class="meta" data-room-overlay-title><?= $activeRoom ? e($activeRoom['title']).' — ' : '' ?>this interview room is open in another tab.</p>
                <button type="button" class="btn danger wide" data-room-overlay-back><?= icon('video', 15) ?> Back to meeting</button>
            </div>
        </div>
        <section class="content fade-in">
<?php elseif ($minimal): ?>
<header class="minimalbar"><a class="brand" href="index.php"><?= logo_html(30) ?><span>acme<span class="muted">/</span>careers</span></a><a class="meta back-link" href="index.php"><?= icon('chevron', 15) ?><span>Back to careers</span></a></header>
<main class="public-shell"><section class="content fade-in">
<?php else: ?>
<header class="topbar">
    <a class="brand" href="index.php" aria-label="Acme careers home"><?= logo_html(32) ?><span>acme<span class="muted">/</span>careers</span></a>
    <nav class="main-nav" aria-label="Primary navigation">
        <div class="nav-group"><a class="nav-link" href="jobs.php">Find roles <?= icon('chevron', 13) ?></a><div class="nav-menu"><a href="jobs.php"><?= icon('overview', 15) ?>All open roles</a><a href="jobs.php?team=Engineering"><?= icon('pipeline', 15) ?>Engineering</a><a href="jobs.php?team=Design"><?= icon('sparkle', 15) ?>Design</a></div></div>
        <a class="nav-link" href="index.php#values">Life at Acme</a><a class="nav-link" href="application-status.php">Check status</a><a class="nav-link" href="refer.php">Refer someone</a>
        <a class="nav-cta" href="login.php"><?= icon('admin', 15) ?> Recruiter hub</a>
    </nav>
    <div class="top-actions"><button class="theme-toggle" data-theme-toggle aria-label="Toggle color theme">◐</button><button class="menu-toggle" data-menu-toggle aria-label="Open navigation menu"><?= icon('chevron', 20) ?></button></div>
</header>
<main class="public-shell"><section class="content fade-in">
<?php endif; ?>
