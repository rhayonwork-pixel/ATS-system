<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
$employee = db()->prepare('SELECT e.*,u.name,u.email FROM employees e JOIN users u ON u.id=e.user_id WHERE e.user_id=?');
$employee->execute([$_SESSION['user_id']]);
$employee = $employee->fetch();
if (!$employee) {
  http_response_code(200);
  $pageTitle = 'My Attendance'; include __DIR__.'/includes/header.php';
  $role = current_user()['role'] ?? '';
  ?>
  <div class="page-heading"><div><span class="eyebrow">Employee self-service</span><h1>Attendance</h1><p>Clock in, take a break, and keep your workday records accurate.</p></div></div>
  <div class="card empty-state">
    <div class="empty-icon"><?=icon('attendance',30)?></div>
    <h2>No employee profile linked yet</h2>
    <p class="muted">Attendance is tracked per employee record. Your account (<?=e(current_user()['email'] ?? '')?>) isn't linked to an employee profile yet, so there's nothing to clock in against.</p>
    <?php if(in_array($role, ['admin','hiring_manager'], true)): ?>
      <div class="button-row">
        <a class="button primary" href="employees.php"><?=icon('employees',16)?> Link a profile in Employees</a>
        <a class="button secondary" href="attendance-admin.php"><?=icon('attendance',16)?> View team attendance instead</a>
      </div>
    <?php else: ?>
      <div class="button-row"><a class="button secondary" href="dashboard.php"><?=icon('overview',16)?> Back to overview</a></div>
      <p class="meta">Ask an admin to link your account to an employee profile.</p>
    <?php endif; ?>
  </div>
  <?php
  include __DIR__.'/includes/footer.php';
  exit;
}
$today = date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $existing = db()->prepare('SELECT * FROM attendance_records WHERE employee_id=? AND work_date=?');
  $existing->execute([$employee['id'],$today]); $record = $existing->fetch();
  if ($action === 'clock_in' && !$record) {
    $stmt=db()->prepare("INSERT INTO attendance_records(employee_id,work_date,clock_in,status) VALUES(?,?,NOW(),'present')");
    $stmt->execute([$employee['id'],$today]); audit('clock_in','attendance',(int)db()->lastInsertId()); flash('success','You are clocked in.');
  } elseif ($action === 'clock_out' && $record && !$record['clock_out']) {
    $stmt=db()->prepare("UPDATE attendance_records SET clock_out=NOW(),worked_minutes=TIMESTAMPDIFF(MINUTE,clock_in,NOW())-break_minutes,status=IF(TIMESTAMPDIFF(MINUTE,clock_in,NOW())<240,'half_day',status) WHERE id=?");
    $stmt->execute([$record['id']]); audit('clock_out','attendance',(int)$record['id']); flash('success','You are clocked out.');
  }
  header('Location: attendance.php'); exit;
}
$recordStmt=db()->prepare('SELECT * FROM attendance_records WHERE employee_id=? AND work_date=?'); $recordStmt->execute([$employee['id'],$today]); $todayRecord=$recordStmt->fetch();
$history=db()->prepare('SELECT * FROM attendance_records WHERE employee_id=? ORDER BY work_date DESC LIMIT 31'); $history->execute([$employee['id']]); $history=$history->fetchAll();
$pageTitle='My Attendance'; include __DIR__.'/includes/header.php';
?>
<div class="page-heading"><div><span class="eyebrow">Employee self-service</span><h1>Attendance</h1><p>Clock in, take a break, and keep your workday records accurate.</p></div></div>
<?php if($msg=take_flash()): ?><div class="toast success"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="stats-grid"><article class="stat-card"><span>Today</span><strong><?=date('D, M j')?></strong><small><?= $todayRecord ? htmlspecialchars(ucwords(str_replace('_',' ',$todayRecord['status']))) : 'Not started' ?></small></article><article class="stat-card"><span>Clock in</span><strong><?= $todayRecord && $todayRecord['clock_in'] ? date('g:i A',strtotime($todayRecord['clock_in'])) : '—' ?></strong><small>Local time</small></article><article class="stat-card"><span>Clock out</span><strong><?= $todayRecord && $todayRecord['clock_out'] ? date('g:i A',strtotime($todayRecord['clock_out'])) : '—' ?></strong><small><?= $todayRecord && $todayRecord['worked_minutes'] ? round($todayRecord['worked_minutes']/60,1).' hours worked' : 'Keep your record current' ?></small></article></div>
<div class="card attendance-card"><div><span class="eyebrow">Workday controls</span><h2><?= $todayRecord && !$todayRecord['clock_out'] ? 'You are currently working' : ($todayRecord ? 'Your workday is complete' : 'Start your workday') ?></h2><p class="muted">Your attendance is recorded against <?=htmlspecialchars($employee['name'])?>.</p></div><div class="button-row"><?php if(!$todayRecord): ?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="clock_in"><button class="button primary" type="submit">Clock in</button></form><?php elseif(!$todayRecord['clock_out']): ?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="clock_out"><button class="button primary" type="submit">Clock out</button></form><?php else: ?><span class="badge green">Completed</span><?php endif; ?></div></div>
<div class="card"><div class="section-heading"><div><span class="eyebrow">History</span><h2>Recent attendance</h2></div><a class="button secondary" href="attendance-export.php">Export CSV</a></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Clock in</th><th>Clock out</th><th>Hours</th><th>Status</th></tr></thead><tbody><?php foreach($history as $row): ?><tr><td><?=htmlspecialchars($row['work_date'])?></td><td><?= $row['clock_in'] ? date('M j, g:i A',strtotime($row['clock_in'])) : '—' ?></td><td><?= $row['clock_out'] ? date('g:i A',strtotime($row['clock_out'])) : '—' ?></td><td><?= $row['worked_minutes'] ? number_format($row['worked_minutes']/60,1) : '—' ?></td><td><span class="badge"><?=htmlspecialchars(ucwords(str_replace('_',' ',$row['status'])))?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php include __DIR__.'/includes/footer.php'; ?>
