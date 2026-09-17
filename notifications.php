<?php
require_once __DIR__.'/includes/auth.php';
$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'mark_all') {
            $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([(int)$me['id']]);
            flash('success', 'All notifications marked as read.');
        } elseif ($action === 'mark_one') {
            // Scoped to the signed-in user, so an id from elsewhere does nothing.
            $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?')
                ->execute([(int)($_POST['notification_id'] ?? 0), (int)$me['id']]);
        } elseif ($action === 'clear_read') {
            $pdo->prepare('DELETE FROM notifications WHERE user_id=? AND read_at IS NOT NULL')->execute([(int)$me['id']]);
            flash('success', 'Read notifications cleared.');
        }
    } catch (Throwable $ex) {
        flash('error', 'Could not update notifications: ' . $ex->getMessage());
    }
    header('Location: notifications.php' . (($_POST['back'] ?? '') !== '' ? '?filter=' . urlencode($_POST['back']) : '')); exit;
}

$filter = $_GET['filter'] ?? '';
$where  = 'n.user_id = ?';
$params = [(int)$me['id']];
if ($filter === 'unread')      { $where .= ' AND n.read_at IS NULL'; }
elseif ($filter === 'accounts'){ $where .= " AND n.type IN ('account_suspended','account_disabled','reactivation_request','reactivation_approved','reactivation_rejected','account_approval','account')"; }
elseif ($filter === 'jobs')    { $where .= " AND n.type IN ('job_approval','job_decision')"; }
elseif ($filter === 'access')  { $where .= " AND n.type IN ('permissions','seat_limit')"; }

$stmt = $pdo->prepare("SELECT n.*, a.name actor_name, a.role actor_role
                       FROM notifications n LEFT JOIN users a ON a.id = n.actor_id
                       WHERE $where
                       ORDER BY n.read_at IS NOT NULL, n.created_at DESC LIMIT 150");
$stmt->execute($params);
$items = $stmt->fetchAll();
$unread = unread_notification_count((int)$me['id']);

$tabs = ['' => 'All', 'unread' => 'Unread', 'accounts' => 'Accounts', 'jobs' => 'Jobs', 'access' => 'Permissions & seats'];

$pageTitle = 'Notifications';
include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head">
  <div><div class="eyebrow"><?= is_super_admin($me) ? 'Super Admin' : 'Inbox' ?></div><h1>Notifications</h1>
  <p class="meta"><?= $unread ? $unread . ' unread' : 'You are all caught up.' ?></p></div>
  <div class="actions" style="margin-top:0">
    <?php if ($unread): ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="mark_all">
      <input type="hidden" name="back" value="<?=e($filter)?>">
      <button class="btn secondary" type="submit"><?=icon('check',16)?> Mark all read</button>
    </form>
    <?php endif; ?>
    <form method="post" style="display:inline" data-confirm-clear>
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="clear_read">
      <input type="hidden" name="back" value="<?=e($filter)?>">
      <button class="btn ghost" type="submit">Clear read</button>
    </form>
  </div>
</div>

<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>

<div class="filters">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="stage-tab <?= $filter===$key?'active':'' ?>" href="notifications.php<?= $key ? '?filter='.e($key) : '' ?>"><?=e($label)?></a>
  <?php endforeach; ?>
</div>

<div class="notif-stack">
<?php foreach ($items as $n):
    $type = $n['type'] ?? 'general';
    $isUnread = $n['read_at'] === null;
?>
  <article class="card notif-card <?= $isUnread ? 'is-unread' : '' ?>">
    <span class="notif-icon type-<?=e($type)?>"><?= icon(notification_type_icon($type), 17) ?></span>
    <div class="notif-body">
      <div class="notif-top">
        <span class="notif-type"><?=e(notification_type_label($type))?></span>
        <?php if ($isUnread): ?><span class="notif-new">New</span><?php endif; ?>
        <time class="meta small"><?=e(date('M j, Y — g:i A', strtotime($n['created_at'])))?></time>
      </div>
      <h3><?=e($n['title'])?></h3>
      <?php if ($n['body']): ?><p class="meta"><?=e($n['body'])?></p><?php endif; ?>
      <?php if ($n['actor_name']): ?>
        <p class="meta small">By <?=e($n['actor_name'])?> · <?=e(role_label($n['actor_role']))?></p>
      <?php endif; ?>
      <div class="notif-actions">
        <?php if ($n['link']): ?>
          <a class="btn small" href="<?=e($n['link'])?>"><?=e($n['action_label'] ?: 'Open')?></a>
        <?php endif; ?>
        <?php if ($isUnread): ?>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="mark_one">
          <input type="hidden" name="notification_id" value="<?=(int)$n['id']?>">
          <input type="hidden" name="back" value="<?=e($filter)?>">
          <button class="btn small ghost" type="submit">Mark read</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </article>
<?php endforeach; if(!$items): ?>
  <div class="card"><p class="meta">Nothing here<?= $filter ? ' under this filter' : ' yet' ?>.</p></div>
<?php endif; ?>
</div>

<script>
document.querySelectorAll('[data-confirm-clear]').forEach(function (form) {
  form.addEventListener('submit', function (ev) {
    if (!confirm('Remove all notifications you have already read?')) ev.preventDefault();
  });
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
