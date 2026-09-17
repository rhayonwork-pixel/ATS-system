<?php
require_once __DIR__.'/includes/auth.php'; require_login(['admin','recruiter','hiring_manager']); $pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    $s=$pdo->prepare('INSERT INTO employees(employee_number,job_title,department_id,start_date,status) VALUES(?,?,?,?,?)');
    $s->execute([trim($_POST['employee_number']),trim($_POST['job_title']),$_POST['department_id'] ?: null,$_POST['start_date'] ?: null,$_POST['status']]);
    audit('create','employee',(int)$pdo->lastInsertId());
    flash('success','Employee record added.');
    header('Location: employees.php'); exit;
}
$departments=$pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();
$employees=$pdo->query("SELECT e.*,d.name department FROM employees e LEFT JOIN departments d ON d.id=e.department_id ORDER BY e.start_date DESC")->fetchAll();
$hiredFromRecruitment = array_filter($employees, fn($e) => !empty($e['application_id']));
$pageTitle='Employees'; include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head"><div><div class="eyebrow">People operations</div><h1>Employees</h1><p class="meta">Maintain your employee directory and employment status. <?=count($hiredFromRecruitment)?> of <?=count($employees)?> came directly from the hiring pipeline.</p></div></div>
<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>
<div class="admin-grid">
  <form class="card form" method="post">
    <h2>Add employee manually</h2>
    <p class="meta small" style="margin-bottom:12px">For hires not tracked through the pipeline. To convert a hired candidate with their full recruitment history, use "Add to Employees" on their candidate profile instead.</p>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="field"><label>Employee number</label><input name="employee_number" required></div>
    <div class="field"><label>Job title</label><input name="job_title" required></div>
    <div class="field"><label>Department</label><select name="department_id"><option value="">Unassigned</option><?php foreach($departments as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Start date</label><input type="date" name="start_date"></div>
    <div class="field"><label>Status</label><select name="status"><option>active</option><option>on_leave</option><option>terminated</option></select></div>
    <button class="btn" type="submit">Add employee</button>
  </form>
  <div class="card">
    <h2>HR checklist</h2>
    <p class="meta">Track onboarding, documents, equipment, and probation reviews for every new hire.</p>
    <ul class="check-list"><li>Employment agreement</li><li>Access and equipment provisioning</li><li>30 / 60 / 90 day reviews</li></ul>
  </div>
</div>
<div class="card">
  <div class="section-head"><h2>Employee directory</h2><span class="meta"><?= count($employees) ?> records</span></div>
  <table class="table">
    <tr><th>Number</th><th>Hired position</th><th>Applied position</th><th>Department</th><th>Start date</th><th>Status</th><th></th></tr>
    <?php foreach($employees as $employee): ?>
    <tr>
      <td><?= e($employee['employee_number']) ?></td>
      <td><?= e($employee['job_title']) ?></td>
      <td class="meta"><?= e($employee['applied_position'] ?: '—') ?></td>
      <td><?= e($employee['department'] ?? '—') ?></td>
      <td><?= e($employee['start_date'] ?? '—') ?></td>
      <td><span class="pill"><?= e($employee['status']) ?></span></td>
      <td><?php if($employee['application_id']): ?><a class="meta small" href="candidate.php?id=<?=$employee['application_id']?>">Recruitment history →</a><?php endif; ?></td>
    </tr>
    <?php endforeach; if(!$employees): ?>
    <tr><td colspan="7" class="meta">No employees yet. Hired candidates can be added from their candidate profile.</td></tr>
    <?php endif; ?>
  </table>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>
