<?php
require_once __DIR__.'/includes/auth.php'; require_admin_level();
$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    try {
        set_setting('company_name', trim($_POST['company_name']??'Acme') ?: 'Acme');
        set_setting('careers_headline', trim($_POST['careers_headline']??''));
        set_setting('logo_path', trim($_POST['logo_path']??''));
        set_setting('default_applicant_limit', trim($_POST['default_applicant_limit']??''));
        set_setting('default_leave_days', trim($_POST['default_leave_days']??'20'));
        audit('update','settings');
        flash('success','Settings saved.');
    } catch(Throwable $e){ flash('error','Could not save settings: '.$e->getMessage()); }
    header('Location: settings.php'); exit;
}
$pageTitle='Settings'; include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1><?=icon('settings',26)?> Settings</h1><p class="meta">The essentials recruiters and HR need on hand — branding, careers page copy, and defaults for new roles.</p></div></div>
<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>
<form class="card form settings-form" method="post" style="max-width:640px">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<h2><?=icon('image',18)?> Branding</h2>
<div class="logo-preview-row"><?=logo_html(56)?><div class="field" style="margin:0;flex:1"><label>Logo image path or URL</label><input name="logo_path" value="<?=e(setting('logo_path'))?>" placeholder="assets/uploads/logo.png or https://..."><span class="hint">Leave blank to use the default Acme logo. Point this at an uploaded image if you want to replace it.</span></div></div>
<div class="field"><label>Company name</label><input name="company_name" value="<?=e(setting('company_name','Acme'))?>" required></div>
<div class="field"><label>Careers page headline</label><input name="careers_headline" value="<?=e(setting('careers_headline','Do the best work of your career.'))?>"></div>
<h2><?=icon('limit',18)?> Hiring defaults</h2>
<div class="form-grid">
<div class="field"><label>Default applicant limit for new roles <span class="hint">optional</span></label><input name="default_applicant_limit" type="number" min="0" value="<?=e(setting('default_applicant_limit'))?>" placeholder="Unlimited"></div>
<div class="field"><label>Default annual leave days</label><input name="default_leave_days" type="number" min="0" value="<?=e(setting('default_leave_days','20'))?>"></div>
</div>
<button class="btn" type="submit"><?=icon('check',16)?> Save settings</button>
</form>
<?php include __DIR__.'/includes/footer.php'; ?>
