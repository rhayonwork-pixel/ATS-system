<?php
require_once __DIR__.'/includes/config.php'; $pdo=db();
$error=''; $success=false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $referrerName = trim($_POST['referrer_name'] ?? '');
    $referrerEmail = strtolower(trim($_POST['referrer_email'] ?? ''));
    $candidateName = trim($_POST['candidate_name'] ?? '');
    $candidateEmail = strtolower(trim($_POST['candidate_email'] ?? ''));
    $jobId = $_POST['job_id'] !== '' ? (int)$_POST['job_id'] : null;
    $note = trim($_POST['note'] ?? '');
    if ($referrerName==='' || !filter_var($referrerEmail, FILTER_VALIDATE_EMAIL) || $candidateName==='' || !filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please complete all required fields with a valid email address.';
    } else {
        $s = $pdo->prepare('INSERT INTO referrals(referrer_name,referrer_email,candidate_name,candidate_email,job_id,notes) VALUES(?,?,?,?,?,?)');
        try {
            $s->execute([$referrerName,$referrerEmail,$candidateName,$candidateEmail,$jobId,$note]);
        } catch (Throwable $e) {
            // Older installs may not have a notes column on referrals yet — fall back gracefully.
            $s = $pdo->prepare('INSERT INTO referrals(referrer_name,referrer_email,candidate_name,candidate_email,job_id) VALUES(?,?,?,?,?)');
            $s->execute([$referrerName,$referrerEmail,$candidateName,$candidateEmail,$jobId]);
        }
        audit('referral_create','referral',(int)$pdo->lastInsertId());
        $success = true;
    }
}
$openJobs = $pdo->query("SELECT id,title FROM jobs WHERE status='open' ORDER BY title")->fetchAll();
$pageTitle='Refer a friend'; $minimal=true; include __DIR__.'/includes/header.php';
?>
<?php if ($success): ?>
<div class="notice success">Referral submitted! Our recruiting team will reach out to your candidate.</div>
<div class="card" style="text-align:center;padding:40px">
  <div style="font-size:3rem;margin-bottom:1rem">🙌</div>
  <h2>Thanks for the referral!</h2>
  <p class="meta" style="margin-top:10px">We appreciate you helping us find great people.</p>
  <a class="btn" style="margin-top:20px" href="jobs.php">Back to jobs</a>
</div>
<?php else: ?>
<div class="hero"><div class="eyebrow">Spread the word</div><h1>Know someone great?</h1><p>Send them a role that could be the start of something meaningful.</p></div>
<?php if ($error): ?><div class="notice error"><?=e($error)?></div><?php endif; ?>
<form class="form card" data-validate method="post">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
  <div class="form-grid">
    <div class="field"><label>Your name *</label><input name="referrer_name" required value="<?=e($_POST['referrer_name'] ?? '')?>"></div>
    <div class="field"><label>Your email *</label><input type="email" name="referrer_email" required value="<?=e($_POST['referrer_email'] ?? '')?>"></div>
    <div class="field"><label>Friend's name *</label><input name="candidate_name" required value="<?=e($_POST['candidate_name'] ?? '')?>"></div>
    <div class="field"><label>Friend's email *</label><input type="email" name="candidate_email" required value="<?=e($_POST['candidate_email'] ?? '')?>"></div>
    <div class="field full"><label>Position they're a fit for</label><select name="job_id"><option value="">General / not sure</option><?php foreach ($openJobs as $job): ?><option value="<?=$job['id']?>"><?=e($job['title'])?></option><?php endforeach; ?></select></div>
    <div class="field full"><label>Note</label><textarea name="note" placeholder="Why would they love Acme?"><?=e($_POST['note'] ?? '')?></textarea></div>
  </div>
  <button class="btn" type="submit">Send referral</button>
</form>
<?php endif; ?>
<?php include __DIR__.'/includes/footer.php'; ?>
