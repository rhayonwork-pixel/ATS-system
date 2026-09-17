<?php
require_once __DIR__.'/includes/auth.php';
// The audit trail is a grantable permission, not an automatic Admin privilege.
require_permission(PERM_AUDIT_TRAIL);
$pdo=db();
$search=trim($_GET['q']??''); $userFilter=(int)($_GET['user']??0); $roleFilter=trim($_GET['role']??''); $actionFilter=trim($_GET['action_type']??'');
$from=trim($_GET['from']??''); $to=trim($_GET['to']??'');
$users=$pdo->query("SELECT id,name,role FROM users ORDER BY name")->fetchAll();
$actions=$pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$sql="SELECT a.*,u.name user_name,u.role user_role,c.first_name,c.last_name,j.title job_title
FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id
LEFT JOIN applications ap ON a.entity_type='application' AND a.entity_id=ap.id
LEFT JOIN candidates c ON (a.entity_type='candidate' AND a.entity_id=c.id) OR (a.entity_type='application' AND ap.candidate_id=c.id)
LEFT JOIN jobs j ON (a.entity_type='job' AND a.entity_id=j.id) OR (a.entity_type='application' AND ap.job_id=j.id)
WHERE 1=1"; $params=[];
if($search!==''){ $sql.=" AND (a.action LIKE ? OR a.entity_type LIKE ? OR u.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR j.title LIKE ? OR CAST(a.details AS CHAR) LIKE ?)"; $like='%'.$search.'%'; array_push($params,$like,$like,$like,$like,$like,$like,$like); }
if($userFilter){$sql.=" AND a.user_id=?";$params[]=$userFilter;}
if($roleFilter){$sql.=" AND u.role=?";$params[]=$roleFilter;}
if($actionFilter){$sql.=" AND a.action=?";$params[]=$actionFilter;}
if($from!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){$sql.=" AND a.created_at>=?";$params[]=$from.' 00:00:00';}
if($to!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){$sql.=" AND a.created_at<=?";$params[]=$to.' 23:59:59';}
$sql.=" ORDER BY a.created_at DESC LIMIT 250"; $stmt=$pdo->prepare($sql);$stmt->execute($params);$logs=$stmt->fetchAll();
function audit_detail($log){$d=json_decode($log['details']??'',true); if(is_array($d)){ $bits=[]; foreach($d as $k=>$v){ if(is_scalar($v) && $v!=='') $bits[]=ucwords(str_replace('_',' ',$k)).': '.$v; } if($bits)return implode(' · ',$bits); } return $log['entity_type'] ? ucfirst($log['entity_type']).' #'.$log['entity_id'] : 'System activity';}
// Summary figures for the header cards. Counted across the whole log, not just
// the filtered page, so they stay meaningful while filters are applied.
$totalEvents  = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$todayEvents  = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$actorEvents  = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM audit_logs WHERE user_id IS NOT NULL")->fetchColumn();
$securityEvents = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN
    ('password_changed','password_reset_requested','password_reset_approved','password_reset_rejected',
     'password_reset_completed','admin_permissions_update','hr_permissions_update','hr_account_status',
     'hr_account_delete','reactivation_requested','reactivation_approved','reactivation_rejected',
     'seat_limit_changed','seat_released','admin_status_change')")->fetchColumn();

/**
 * Group an action into a category, which drives its badge colour. Anything
 * unrecognised falls back to 'system' rather than being hidden.
 */
function audit_category(string $action): string {
    foreach ([
        'security' => ['password','permission','reactivation','seat','status_change','account_status','admin_create','hr_account'],
        'approval' => ['approve','reject','request_changes','submit_for_approval','decision'],
        'interview'=> ['interview','meeting'],
        'candidate'=> ['candidate','application','pipeline','stage','ai_analysis'],
        'job'      => ['job'],
        'account'  => ['profile','user','admin','login'],
    ] as $category => $needles) {
        foreach ($needles as $n) if (strpos($action, $n) !== false) return $category;
    }
    return 'system';
}

$pageTitle='Audit Trail'; include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Audit Trail</h1><p class="meta">Review system activity without cluttering Job Management.</p></div><div class="actions" style="margin-top:0"><?php if(is_super_admin()): ?><a class="btn secondary" href="super-admin.php"><?=icon('users',16)?> Admins</a><?php endif; ?><?php if(has_permission(PERM_JOB_MANAGEMENT)): ?><a class="btn secondary" href="admin.php">← Job Management</a><?php endif; ?></div></div>
<div class="stats metric-grid">
  <div class="card stat"><span class="stat-icon"><?=icon('analytics')?></span><strong><?=number_format($totalEvents)?></strong><span>Total events</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('clock')?></span><strong><?=number_format($todayEvents)?></strong><span>Today</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('users')?></span><strong><?=number_format($actorEvents)?></strong><span>Users with activity</span></div>
  <div class="card stat"><span class="stat-icon"><?=icon('admin')?></span><strong><?=number_format($securityEvents)?></strong><span>Security events</span></div>
</div>

<form method="get" class="audit-filters card">
<input name="q" value="<?=e($search)?>" placeholder="Search audit activity...">
<select name="action_type"><option value="">All actions</option><?php foreach($actions as $a): ?><option value="<?=e($a)?>" <?=$actionFilter===$a?'selected':''?>><?=e(ucwords(str_replace('_',' ',$a)))?></option><?php endforeach; ?></select>
<select name="user"><option value="">All users</option><?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=$userFilter===(int)$u['id']?'selected':''?>><?=e($u['name'])?></option><?php endforeach; ?></select>
<select name="role"><option value="">All roles</option><option value="super_admin" <?=$roleFilter==='super_admin'?'selected':''?>>Super Admin</option><option value="admin" <?=$roleFilter==='admin'?'selected':''?>>Admin</option><option value="recruiter" <?=$roleFilter==='recruiter'?'selected':''?>>Recruiter</option><option value="hiring_manager" <?=$roleFilter==='hiring_manager'?'selected':''?>>Hiring Manager</option><option value="employee" <?=$roleFilter==='employee'?'selected':''?>>Employee</option></select>
<input type="date" name="from" value="<?=e($from)?>"><input type="date" name="to" value="<?=e($to)?>">
<button class="btn small" type="submit">Apply Filters</button><?php if($search||$userFilter||$roleFilter||$actionFilter||$from||$to): ?><a class="btn ghost small" href="audit_trail.php">Clear</a><?php endif; ?>
</form>
<div class="card">
  <div class="section-head" style="margin:0 0 14px"><div><h2>Activity history</h2></div><span class="meta"><?=count($logs)?> record<?=count($logs)===1?'':'s'?> shown<?= count($logs)>=250 ? ' (most recent 250)' : '' ?></span></div>

  <?php if(!$logs): ?>
    <div class="empty-panel">
      <h2><?=icon('analytics',20)?> No matching activity</h2>
      <p class="meta">Nothing in the audit trail matches these filters. Try clearing them or widening the date range.</p>
    </div>
  <?php else: ?>
  <!-- Wide screens get a table; narrow screens get the same rows as stacked
       cards through CSS, so nothing is duplicated and the page never scrolls
       sideways. -->
  <div class="table-wrap">
    <table class="table audit-table">
      <thead><tr><th>Date/Time</th><th>User</th><th>Role</th><th>Action</th><th>Related</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach($logs as $log): $cat = audit_category($log['action']); ?>
        <tr>
          <td data-label="Date/Time" class="meta audit-when">
            <strong><?=e(date('M j, Y',strtotime($log['created_at'])))?></strong>
            <span class="meta small"><?=e(date('g:i A',strtotime($log['created_at'])))?></span>
          </td>
          <td data-label="User"><strong><?=e($log['user_name']??'System')?></strong></td>
          <td data-label="Role"><span class="pill"><?=e(role_label($log['user_role']??null))?></span></td>
          <td data-label="Action"><span class="audit-badge cat-<?=e($cat)?>"><?=e(ucwords(str_replace('_',' ',$log['action'])))?></span></td>
          <td data-label="Related" class="meta"><?=e(trim(($log['first_name']??'').' '.($log['last_name']??'')) ?: ($log['job_title']??(($log['entity_type']??'') ? ucfirst($log['entity_type']).' #'.$log['entity_id'] : '—')) )?></td>
          <td data-label="Details" class="meta audit-details"><?=e(audit_detail($log))?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>
