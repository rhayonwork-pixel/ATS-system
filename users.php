<?php
require_once __DIR__.'/includes/auth.php';
require_permission(PERM_MANAGE_ACCOUNTS);
$pdo = db();
$me  = current_user();
$iAmSuper = is_super_admin($me);

$catalog = recruiter_permission_catalog();

/**
 * An Admin only ever sees and touches the accounts they provisioned.
 * A Super Admin sees every HR/Recruiter in the workspace.
 */
function load_recruiter(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='recruiter' LIMIT 1");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'hr_create') {
            // The owning Admin is whoever is signed in. A Super Admin creating an
            // account directly owns it too, which keeps the counting honest.
            $ownerId = (int)$me['id'];

            if (!$iAmSuper) {
                if (seats_available($ownerId) < 1) {
                    throw new RuntimeException('No available HR/Recruiter seats. Please contact the Super Admin to increase your seat limit.');
                }
            }

            $name  = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $pass  = (string)($_POST['password'] ?? '');
            if ($name === '' || $email === '') throw new RuntimeException('Name and email are both required.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That email address is not valid.');
            if (strlen($pass) < 8) throw new RuntimeException('The temporary password must be at least 8 characters.');
            $dupe = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
            $dupe->execute([$email]);
            if ($dupe->fetch()) throw new RuntimeException('An account with that email already exists.');

            // New HR/staff accounts wait for Super Admin approval before they can
            // sign in. active=0 until then, which is what login.php checks.
            $status = $iAmSuper ? 'active' : 'pending';
            $ins = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,active,account_status,created_by,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $ins->execute([
                $name, $email, password_hash($pass, PASSWORD_DEFAULT), 'recruiter',
                $status === 'active' ? 1 : 0, $status, $ownerId,
                $iAmSuper ? (int)$me['id'] : null,
                $iAmSuper ? date('Y-m-d H:i:s') : null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            set_user_permissions($newId, array_map('strval', (array)($_POST['permissions'] ?? [])), array_keys($catalog), (int)$me['id']);

            audit('hr_account_create', 'user', $newId, [
                'account' => $name,
                'status'  => account_status_label($status),
                'created_by' => $me['name'],
            ]);

            if ($status === 'pending') {
                $supers = $pdo->query("SELECT id FROM users WHERE role='super_admin' AND active=1")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($supers as $sid) {
                    notify((int)$sid, 'account_approval', 'HR/Recruiter account needs approval',
                        $me['name'] . ' created an account for ' . $name . '.', 'users.php?status=pending');
                }
                flash('success', $name . ' was created and is waiting for Super Admin approval before they can sign in.');
            } else {
                flash('success', $name . ' can now sign in.');
            }

        } elseif ($action === 'hr_permissions') {
            $target = load_recruiter($pdo, (int)($_POST['user_id'] ?? 0));
            if (!$target || !can_manage_account($target, $me)) deny_403('You can only change permissions for HR/Recruiter accounts you manage.');

            $requested = array_map('strval', (array)($_POST['permissions'] ?? []));
            [$added, $removed] = set_user_permissions((int)$target['id'], $requested, array_keys($catalog), (int)$me['id']);
            if ($added || $removed) {
                audit('hr_permissions_update', 'user', (int)$target['id'], array_filter([
                    'account' => $target['name'],
                    'granted' => $added ? implode(', ', $added) : '',
                    'revoked' => $removed ? implode(', ', $removed) : '',
                ]));
                notify((int)$target['id'], 'permissions', 'Your permissions were updated',
                    $me['name'] . ' changed what your account can do.', 'dashboard.php');
                flash('success', 'Permissions saved for ' . $target['name'] . '.');
            } else {
                flash('success', 'No changes to save for ' . $target['name'] . '.');
            }

        } elseif ($action === 'hr_status') {
            $target = load_recruiter($pdo, (int)($_POST['user_id'] ?? 0));
            if (!$target) throw new RuntimeException('That account no longer exists.');
            $status = (string)($_POST['status'] ?? '');
            $note   = trim($_POST['note'] ?? '') ?: null;

            // Approving or rejecting a brand-new account is a Super Admin decision.
            if (in_array($status, ['active','rejected'], true) && $target['account_status'] === 'pending' && !$iAmSuper) {
                deny_403('Only a Super Admin can approve or reject a new HR/Recruiter account.');
            }
            if (!$iAmSuper && !can_manage_account($target, $me)) {
                deny_403('You can only change accounts you manage.');
            }
            // Bringing a suspended or disabled account back is a request, never a
            // direct edit. Admins must go through 'request_reactivation'.
            if ($status === 'active' && in_array($target['account_status'], ['suspended','disabled','pending_reactivation'], true) && !$iAmSuper) {
                deny_403('Reactivating an account needs Super Admin approval. Use "Request reactivation" instead.');
            }
            if (!in_array($status, ['active','rejected','suspended','disabled'], true)) {
                throw new RuntimeException('Invalid account status.');
            }

            // Coming back into an Admin's roster must not exceed their seats.
            if ($status === 'active' && !in_array($target['account_status'], ['pending','active'], true)) {
                $ownerId = (int)($target['created_by'] ?? 0);
                if ($ownerId && (int)$target['seat_released'] === 1) {
                    $ownerRole = $pdo->prepare('SELECT role FROM users WHERE id=?');
                    $ownerRole->execute([$ownerId]);
                    if ($ownerRole->fetchColumn() === 'admin' && seats_available($ownerId) < 1) {
                        throw new RuntimeException('That Admin has no available seats. Increase their seat limit first.');
                    }
                    $pdo->prepare('UPDATE users SET seat_released=0 WHERE id=?')->execute([(int)$target['id']]);
                }
            }

            apply_account_status((int)$target['id'], $status, (int)$me['id'], $note);

            // Any open reactivation request is settled by a direct status change.
            $pdo->prepare("UPDATE account_requests SET status='approved', decided_by=?, decided_at=NOW(), decision_note=? WHERE user_id=? AND status='pending'")
                ->execute([(int)$me['id'], $note, (int)$target['id']]);

            audit('hr_account_status', 'user', (int)$target['id'], array_filter([
                'account' => $target['name'],
                'email'   => $target['email'],
                'status'  => account_status_label($status),
                'reason'  => $note ?? '',
            ]));

            // A suspension or disabling is something the Super Admin must know about.
            if (in_array($status, ['suspended','disabled'], true)) {
                notify_super_admins(
                    $status === 'suspended' ? 'account_suspended' : 'account_disabled',
                    'HR/Recruiter ' . $status,
                    $me['name'] . ' ' . $status . ' ' . $target['name'] . ' (' . $target['email'] . ')'
                        . ($note ? ' — reason: ' . $note : '.'),
                    'users.php?status=' . $status,
                    'Review account', 'user', (int)$target['id']
                );
            }
            notify((int)$target['id'], 'account',
                'Your account is now ' . strtolower(account_status_label($status)),
                $note ?: null, 'dashboard.php');
            flash('success', $target['name'] . ' is now ' . strtolower(account_status_label($status)) . '.');

        } elseif ($action === 'request_reactivation') {
            $target = load_recruiter($pdo, (int)($_POST['user_id'] ?? 0));
            if (!$target) throw new RuntimeException('That account no longer exists.');
            if (!$iAmSuper && !can_manage_account($target, $me)) {
                deny_403('You can only request reactivation for accounts you manage.');
            }
            if (!in_array($target['account_status'], ['suspended','disabled'], true)) {
                throw new RuntimeException('Only a suspended or disabled account can be reactivated.');
            }
            if (open_reactivation_request((int)$target['id'])) {
                throw new RuntimeException('A reactivation request for that account is already waiting for the Super Admin.');
            }

            $reason = trim($_POST['reason'] ?? '') ?: null;
            $pdo->prepare('INSERT INTO account_requests(user_id,requested_by,request_type,reason) VALUES(?,?,?,?)')
                ->execute([(int)$target['id'], (int)$me['id'], 'reactivation', $reason]);
            $requestId = (int)$pdo->lastInsertId();

            // The account moves to a holding state and stays unable to sign in.
            apply_account_status((int)$target['id'], 'pending_reactivation', (int)$me['id'], $reason);

            audit('reactivation_requested', 'user', (int)$target['id'], array_filter([
                'account'      => $target['name'],
                'email'        => $target['email'],
                'requested_by' => $me['name'],
                'reason'       => $reason ?? '',
            ]));
            notify_super_admins('reactivation_request', 'HR account reactivation request',
                $me['name'] . ' requested reactivation of ' . $target['name'] . "'s account"
                    . ($reason ? ' — ' . $reason : '.'),
                'users.php?status=pending_reactivation', 'Review request', 'account_request', $requestId);
            flash('success', 'Reactivation requested. ' . $target['name'] . ' stays inactive until a Super Admin approves it.');

        } elseif ($action === 'decide_reactivation') {
            // Only a Super Admin decides, and the check is here rather than in the view.
            if (!$iAmSuper) deny_403('Only a Super Admin can decide a reactivation request.');

            $requestId = (int)($_POST['request_id'] ?? 0);
            $decision  = (string)($_POST['decision'] ?? '');
            if (!in_array($decision, ['approved','rejected'], true)) throw new RuntimeException('Invalid decision.');

            $q = $pdo->prepare("SELECT ar.*, u.name account_name, u.email account_email, u.created_by owner_id,
                                       rq.name requester_name
                                FROM account_requests ar
                                JOIN users u ON u.id = ar.user_id
                                LEFT JOIN users rq ON rq.id = ar.requested_by
                                WHERE ar.id=? AND ar.status='pending' LIMIT 1");
            $q->execute([$requestId]);
            $req = $q->fetch();
            if (!$req) throw new RuntimeException('That request has already been decided.');

            $note = trim($_POST['note'] ?? '') ?: null;

            if ($decision === 'approved') {
                $ownerId = (int)($req['owner_id'] ?? 0);
                if ($ownerId) {
                    $ownerRole = $pdo->prepare('SELECT role FROM users WHERE id=?');
                    $ownerRole->execute([$ownerId]);
                    if ($ownerRole->fetchColumn() === 'admin' && seats_available($ownerId) < 1) {
                        throw new RuntimeException('That Admin has no available seats. Increase their seat limit before approving.');
                    }
                }
                $pdo->prepare('UPDATE users SET seat_released=0 WHERE id=?')->execute([(int)$req['user_id']]);
                apply_account_status((int)$req['user_id'], 'active', (int)$me['id'], $note);
            } else {
                // Rejected: the account stays exactly where it was before the request.
                apply_account_status((int)$req['user_id'], 'disabled', (int)$me['id'], $note);
            }

            $pdo->prepare("UPDATE account_requests SET status=?, decided_by=?, decided_at=NOW(), decision_note=? WHERE id=?")
                ->execute([$decision, (int)$me['id'], $note, $requestId]);

            audit($decision === 'approved' ? 'reactivation_approved' : 'reactivation_rejected', 'user', (int)$req['user_id'], array_filter([
                'account'      => $req['account_name'],
                'requested_by' => $req['requester_name'] ?? '',
                'decided_by'   => $me['name'],
                'reason'       => $note ?? '',
            ]));

            if (!empty($req['requested_by'])) {
                notify((int)$req['requested_by'],
                    $decision === 'approved' ? 'reactivation_approved' : 'reactivation_rejected',
                    'Reactivation ' . $decision,
                    $req['account_name'] . "'s account was " . $decision . ' by ' . $me['name']
                        . ($note ? ' — ' . $note : '.'),
                    'users.php', 'Open accounts', 'user', (int)$req['user_id']);
            }
            notify((int)$req['user_id'], 'account',
                $decision === 'approved' ? 'Your account was reactivated' : 'Your reactivation was not approved',
                $note ?: null, 'dashboard.php');

            flash('success', $decision === 'approved'
                ? $req['account_name'] . ' can sign in again.'
                : 'Reactivation rejected. ' . $req['account_name'] . ' stays disabled.');

        } elseif ($action === 'release_seat') {
            if (!$iAmSuper) deny_403('Only a Super Admin can release a seat.');
            $target = load_recruiter($pdo, (int)($_POST['user_id'] ?? 0));
            if (!$target) throw new RuntimeException('That account no longer exists.');
            if ($target['account_status'] === 'active') throw new RuntimeException('Suspend or disable the account before releasing its seat.');
            $pdo->prepare('UPDATE users SET seat_released=1 WHERE id=?')->execute([(int)$target['id']]);
            audit('seat_released', 'user', (int)$target['id'], ['account' => $target['name']]);
            flash('success', "The seat held by " . $target['name'] . ' has been released. The account itself is untouched.');

        } elseif ($action === 'hr_delete') {
            $target = load_recruiter($pdo, (int)($_POST['user_id'] ?? 0));
            if (!$target) throw new RuntimeException('That account no longer exists.');
            if (!$iAmSuper && !can_manage_account($target, $me)) {
                deny_403('You can only delete accounts you manage.');
            }
            // Detach the things that merely reference this person so their work
            // survives: jobs, notes, audit history and interviews all stay.
            $pdo->prepare('UPDATE jobs SET created_by=NULL WHERE created_by=?')->execute([(int)$target['id']]);
            $pdo->prepare('UPDATE jobs SET submitted_by=NULL WHERE submitted_by=?')->execute([(int)$target['id']]);
            $pdo->prepare('UPDATE jobs SET reviewed_by=NULL WHERE reviewed_by=?')->execute([(int)$target['id']]);
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([(int)$target['id']]);

            audit('hr_account_delete', 'user', null, [
                'account' => $target['name'],
                'email'   => $target['email'],
                'seat'    => 'released',
            ]);
            notify_super_admins('account', 'HR/Recruiter account deleted',
                $me['name'] . ' deleted ' . $target['name'] . ' (' . $target['email'] . '). The seat is now free.',
                'users.php', 'Open accounts');
            flash('success', $target['name'] . ' was deleted and their seat is free again.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    header('Location: users.php'); exit;
}

$filter = $_GET['status'] ?? '';
$params = [];
$sql = "SELECT u.*, c.name creator_name FROM users u LEFT JOIN users c ON c.id=u.created_by WHERE u.role='recruiter'";
if (!$iAmSuper) { $sql .= ' AND u.created_by = ?'; $params[] = (int)$me['id']; }
if (in_array($filter, ['pending','active','rejected','suspended','disabled','pending_reactivation'], true)) {
    $sql .= ' AND u.account_status = ?'; $params[] = $filter;
}
$sql .= ' ORDER BY FIELD(u.account_status,"pending","pending_reactivation","active","suspended","disabled","rejected"), u.name';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$accounts = $stmt->fetchAll();

$limit     = $iAmSuper ? null : seat_limit((int)$me['id']);
$used      = $iAmSuper ? null : seats_used((int)$me['id']);
$available = $iAmSuper ? null : max(0, $limit - $used);
$pct       = ($limit !== null && $limit > 0) ? min(100, (int)round($used / $limit * 100)) : 0;
$noSeats   = !$iAmSuper && $available < 1;

// Open reactivation requests, keyed by account, so each card can show its own.
$requests = [];
try {
    $rq = $pdo->query("SELECT ar.*, rq.name requester_name FROM account_requests ar
                       LEFT JOIN users rq ON rq.id=ar.requested_by
                       WHERE ar.status='pending'");
    foreach ($rq->fetchAll() as $r) $requests[(int)$r['user_id']] = $r;
} catch (Throwable $e) { $requests = []; }

$pageTitle = 'HR / Recruiter accounts';
include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head">
  <div><div class="eyebrow"><?= $iAmSuper ? 'Super Admin' : 'Administration' ?></div><h1>HR / Recruiters</h1>
  <p class="meta"><?= $iAmSuper
      ? 'Every HR/Recruiter account in the workspace, including accounts waiting for your approval.'
      : 'Create and manage the HR/Recruiter accounts assigned to you by your Super Admin.' ?></p></div>
  <div class="actions" style="margin-top:0">
    <?php if ($iAmSuper): ?><a class="btn secondary" href="super-admin.php"><?=icon('users',16)?> Admin permissions</a><?php endif; ?>
  </div>
</div>

<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>

<?php if (!$iAmSuper): ?>
<div class="card seat-card">
  <div class="seat-head">
    <div>
      <div class="eyebrow">HR / Recruiter seats</div>
      <strong class="seat-count"><?=$used?> / <?=$limit?> <span>Used</span></strong>
      <span class="meta"><?=$available?> seat<?=$available===1?'':'s'?> available</span>
    </div>
    <div class="seat-pct"><?=$pct?>%</div>
  </div>
  <div class="cap-bar"><span style="width:<?=$pct?>%"></span></div>
  <?php if ($noSeats): ?>
    <p class="meta seat-note"><?=icon('limit',14)?> No available HR/Recruiter seats. Please contact the Super Admin to increase your seat limit.</p>
  <?php else: ?>
    <p class="meta seat-note">A seat stays occupied while an account exists — including while it is suspended or disabled. Deleting an account frees its seat.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="filters">
  <a class="stage-tab <?= $filter===''?'active':'' ?>" href="users.php">All</a>
  <?php foreach (['pending','pending_reactivation','active','suspended','disabled','rejected'] as $st): ?>
    <a class="stage-tab <?= $filter===$st?'active':'' ?>" href="users.php?status=<?=e($st)?>"><?=e(account_status_label($st))?></a>
  <?php endforeach; ?>
</div>

<div class="perm-grid">
<?php foreach ($accounts as $acct):
    $uid  = (int)$acct['id'];
    $held = user_permissions($uid, 'recruiter');
    $mine = can_manage_account($acct, $me);
    $canDecide = $iAmSuper && $acct['account_status'] === 'pending';
    $openReq = $requests[$uid] ?? null;
    $suspendedish = in_array($acct['account_status'], ['suspended','disabled'], true);
?>
  <div class="card perm-card">
    <div class="perm-card-head">
      <div>
        <h3><?=e($acct['name'])?></h3>
        <span class="meta"><?=e($acct['email'])?></span>
        <div class="meta small">Created by <?=e($acct['creator_name'] ?? '—')?> · <?=e(date('M j, Y', strtotime($acct['created_at'])))?></div>
      </div>
      <span class="pill status-<?=e($acct['account_status'])?>"><?=e(account_status_label($acct['account_status']))?></span>
    </div>

    <?php if ($acct['status_note']): ?>
      <p class="meta small reason-note"><?=e($acct['status_note'])?></p>
    <?php endif; ?>

    <?php if ($canDecide): ?>
      <div class="pending-decision">
        <p class="meta">This account cannot sign in until you approve it.</p>
        <form method="post" class="perm-status-row">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="hr_status">
          <input type="hidden" name="user_id" value="<?=$uid?>">
          <input class="mini-input" name="note" placeholder="Optional note...">
          <button class="btn small" type="submit" name="status" value="active"><?=icon('check',13)?> Approve</button>
          <button class="btn small ghost" type="submit" name="status" value="rejected">Reject</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($openReq): ?>
      <div class="pending-decision is-reactivation">
        <strong><?=icon('bell',14)?> Reactivation requested</strong>
        <p class="meta small">Requested by <?=e($openReq['requester_name'] ?? 'an Admin')?> · <?=e(time_ago($openReq['created_at']))?><?php if($openReq['reason']): ?> — “<?=e($openReq['reason'])?>”<?php endif; ?></p>
        <?php if ($iAmSuper): ?>
          <form method="post" class="perm-status-row">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="decide_reactivation">
            <input type="hidden" name="request_id" value="<?=(int)$openReq['id']?>">
            <input class="mini-input" name="note" placeholder="Optional reason...">
            <button class="btn small" type="submit" name="decision" value="approved"><?=icon('check',13)?> Approve</button>
            <button class="btn small ghost" type="submit" name="decision" value="rejected">Reject</button>
          </form>
        <?php else: ?>
          <p class="meta small">This account stays inactive until a Super Admin decides.</p>
        <?php endif; ?>
      </div>
    <?php elseif ($suspendedish && ($mine || $iAmSuper)): ?>
      <div class="pending-decision">
        <p class="meta">Reactivating this account needs Super Admin approval.</p>
        <form method="post" class="perm-status-row">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="request_reactivation">
          <input type="hidden" name="user_id" value="<?=$uid?>">
          <input class="mini-input" name="reason" placeholder="Why should this account come back?">
          <button class="btn small secondary" type="submit"><?=icon('bell',13)?> Request reactivation</button>
        </form>
        <?php if ($iAmSuper && (int)$acct['seat_released'] === 0): ?>
          <form method="post" class="perm-status-row">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="release_seat">
            <input type="hidden" name="user_id" value="<?=$uid?>">
            <button class="btn small ghost" type="submit">Release the seat this account holds</button>
          </form>
        <?php elseif ((int)$acct['seat_released'] === 1): ?>
          <p class="meta small"><?=icon('check',12)?> The seat for this account has been released.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($mine): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="hr_permissions">
      <input type="hidden" name="user_id" value="<?=$uid?>">
      <div class="perm-toggles">
      <?php foreach ($catalog as $key => [$label, $blurb]): $on = in_array($key, $held, true); ?>
        <label class="perm-toggle">
          <input type="checkbox" name="permissions[]" value="<?=e($key)?>" <?=$on?'checked':''?>>
          <span class="perm-toggle-text"><strong><?=e($label)?></strong><small><?=e($blurb)?></small></span>
          <span class="perm-switch" aria-hidden="true"></span>
        </label>
      <?php endforeach; ?>
      </div>
      <div class="perm-card-actions"><button class="btn" type="submit"><?=icon('check',16)?> Save permissions</button></div>
    </form>

    <details class="perm-danger">
      <summary class="meta small">Account status</summary>
      <form method="post" class="perm-status-row">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="hr_status">
        <input type="hidden" name="user_id" value="<?=$uid?>">
        <?php foreach (['active'=>'Activate','suspended'=>'Suspend','disabled'=>'Disable'] as $st => $lbl):
              if ($acct['account_status'] === $st) continue;
              // Bringing an account back always goes through the request flow,
              // so a plain Activate button is only offered to a Super Admin.
              if ($st === 'active' && !$iAmSuper) continue;
              if ($st === 'active' && $acct['account_status'] === 'pending_reactivation') continue; ?>
          <button class="btn small <?= $st==='active'?'secondary':'ghost' ?>" type="submit" name="status" value="<?=e($st)?>"><?=e($lbl)?></button>
        <?php endforeach; ?>
      </form>
      <p class="meta small">Suspending or disabling keeps the account and its seat. Deleting removes the account and frees the seat.</p>
      <form method="post" class="perm-status-row" data-confirm-delete data-account-name="<?=e($acct['name'])?>">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="hr_delete">
        <input type="hidden" name="user_id" value="<?=$uid?>">
        <button class="btn small danger" type="submit"><?=icon('more',13)?> Delete account &amp; free seat</button>
      </form>
    </details>
    <?php else: ?>
      <div class="perm-toggles readonly">
      <?php foreach ($catalog as $key => [$label, $labelHint]): ?>
        <div class="perm-readonly-row"><span><?=e($label)?></span><span class="pill <?= in_array($key,$held,true)?'':'status-draft' ?>"><?= in_array($key,$held,true) ? 'On' : 'Off' ?></span></div>
      <?php endforeach; ?>
      </div>
      <p class="meta small">Managed by <?=e($acct['creator_name'] ?? 'another Admin')?>.</p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if (!$accounts): ?>
  <div class="card"><p class="meta">No HR/Recruiter accounts<?= $filter ? ' with that status' : '' ?> yet.</p></div>
<?php endif; ?>
</div>

<div class="section-head" style="margin-top:30px"><div><div class="eyebrow">Provisioning</div><h2>Create an HR/Recruiter</h2></div></div>

<?php if ($noSeats): ?>
<div class="card limit-block">
  <h3><?=icon('limit',18)?> No available HR/Recruiter seats</h3>
  <p class="meta">Your seat limit is <strong><?=$limit?></strong> and all of them are in use. Please contact the Super Admin to increase your seat limit, or delete an account to free a seat.</p>
  <button class="btn" type="button" disabled><?=icon('plus',16)?> Create account</button>
</div>
<?php else: ?>
<form class="card form" method="post">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
  <input type="hidden" name="action" value="hr_create">
  <div class="form-grid">
    <div class="field"><label>Full name</label><input name="name" required placeholder="John Recruiter"></div>
    <div class="field"><label>Email</label><input type="email" name="email" required placeholder="john@acme.test"></div>
  </div>
  <div class="field"><label>Temporary password <span class="hint">at least 8 characters</span></label><input type="password" name="password" required minlength="8"></div>
  <div class="field"><label>Permissions</label>
    <div class="perm-toggles compact">
    <?php foreach ($catalog as $key => [$label, $blurb]): ?>
      <label class="perm-toggle">
        <input type="checkbox" name="permissions[]" value="<?=e($key)?>" <?= $key===PERM_AUDIT_TRAIL?'':'checked' ?>>
        <span class="perm-toggle-text"><strong><?=e($label)?></strong><small><?=e($blurb)?></small></span>
        <span class="perm-switch" aria-hidden="true"></span>
      </label>
    <?php endforeach; ?>
    </div>
  </div>
  <p class="meta"><?= $iAmSuper
      ? 'You are a Super Admin, so this account is active immediately.'
      : 'New accounts are created as <strong>Pending</strong> and can only sign in once a Super Admin approves them.' ?></p>
  <button class="btn" type="submit"><?=icon('plus',16)?> Create HR/Recruiter</button>
</form>
<?php endif; ?>

<script>
document.querySelectorAll('[data-confirm-delete]').forEach(function (form) {
  form.addEventListener('submit', function (ev) {
    var name = form.dataset.accountName || 'this account';
    if (!confirm('Delete ' + name + '? This removes the account permanently and frees their seat. Jobs, notes and audit history they created are kept.')) {
      ev.preventDefault();
    }
  });
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
