<?php
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/documents.php';
$pdo=db();
$slug=trim($_GET['job']??$_POST['job']??'senior-product-engineer');
$stmt=$pdo->prepare("SELECT j.*,d.name department,(SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id) applications FROM jobs j LEFT JOIN departments d ON d.id=j.department_id WHERE j.slug=? AND j.status='open' LIMIT 1"); $stmt->execute([$slug]); $job=$stmt->fetch();
if(!$job){http_response_code(404); exit('This job is no longer accepting applications.');}
// jobs.applicant_limit is a TARGET, not a cap. It says how many people the
// role is looking to hire or shortlist, and is shown to applicants as a plain
// number ("10 spots available"). It no longer closes the role when that many
// have applied: every applicant can apply, however many came before them.
// The column keeps its name so the schema is unchanged.
$spots = $job['applicant_limit'] !== null ? (int)$job['applicant_limit'] : null;

// The ONE thing that still closes this form is the portal-wide pause a Super
// Admin (or an Admin with Applicant Portal permission) can switch on. That is a
// deliberate "stop all intake" decision, not a spot count, so it stays.
$portalOpen = setting('portal_accepting_applications','1') !== '0';
$closed = !$portalOpen;
$error = $closed
    ? (setting('portal_closed_message') ?: 'We are not accepting applications at the moment. Please check back soon.')
    : '';
// Which input an error belongs to, so it can be shown beside that input and
// the input marked aria-invalid, rather than only in the banner at the top.
$errorField = '';

// The JavaScript submit sends the form with XMLHttpRequest so it can show real
// upload progress. It asks for JSON back instead of a page: on success, where
// to go; on failure, the message and the field. The form -- and the resume the
// applicant already chose -- stays on screen instead of being wiped by a reload.
$wantsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

$companyName = setting('company_name','Acme');

$sourceOptions = [
    'linkedin'=>'LinkedIn','facebook'=>'Facebook','indeed'=>'Indeed','jobstreet'=>'JobStreet',
    'company_website'=>'Company Website','google'=>'Google Search','referral'=>'Friend or Colleague',
    'agency'=>'Recruitment Agency','university'=>'University / School','job_fair'=>'Job Fair','other'=>'Other',
];

if($_SERVER['REQUEST_METHOD']==='POST' && !$closed){
    check_csrf();
    $name=trim($_POST['name']??''); $email=strtolower(trim($_POST['email']??''));
    $phoneCountry=$_POST['phone_country']??'PH'; $phoneNumberRaw=trim($_POST['phone_number']??'');
    $coverLetter=trim($_POST['cover_letter']??''); $whyUs=trim($_POST['why_us']??'');
    $sourceKey=$_POST['source']??''; $sourceOther=trim($_POST['source_other']??'');
    $portfolio=trim($_POST['portfolio']??''); $confirmed=isset($_POST['confirm']);

    $countryList = phone_countries(); $dialCode = null;
    foreach ($countryList as [$iso,$cName,$dial]) { if ($iso === $phoneCountry) { $dialCode = $dial; break; } }
    $dialCode = $dialCode ?? '63';
    $phoneDigits = preg_replace('/\D/', '', $phoneNumberRaw);
    $phone = null;
    if ($phoneNumberRaw !== '') {
        // Same rule the browser applies (assets/js/apply-form.js): only digits
        // and the separators people actually type, then 6-14 digits. The
        // character check is new -- counting digits alone accepted
        // "call me 0917123456" as a phone number.
        if (!preg_match('/^[0-9 ()+.\-]+$/', $phoneNumberRaw)
            || strlen($phoneDigits) < 6 || strlen($phoneDigits) > 14) {
            $error = 'Please enter a valid phone number: 6 to 14 digits, using only numbers, spaces, brackets, dots or dashes.';
            $errorField = 'phone';
        } else {
            $phone = '+'.$dialCode.' '.$phoneNumberRaw;
        }
    }

    if($error==='' && ($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$coverLetter===''||$whyUs===''||!array_key_exists($sourceKey,$sourceOptions))){
        $error='Please complete all required fields.';
    } elseif($error==='' && $sourceKey==='other' && $sourceOther===''){
        $error='Please tell us how you heard about us.';
    } elseif($error==='' && $portfolio!=='' && !filter_var($portfolio, FILTER_VALIDATE_URL)){
        $error='Please enter a valid portfolio URL, e.g. https://yourportfolio.com';
    } elseif($error==='' && !$confirmed){
        $error='Please confirm your details are accurate before submitting.';
    } elseif($error===''){
        // The resume itself is no longer handled by save_resume_upload() (a
        // second, separate storage path writing into the PUBLIC assets/
        // directory with no access control). It now goes through the exact
        // same store_candidate_document() function the internal Add
        // Candidate form uses -- one storage location, one table, one
        // authenticated download route, regardless of how the candidate
        // entered the system. That call needs a candidate id, which does not
        // exist yet, so only the profile photo (unrelated, unchanged) and
        // basic file presence are checked here; the resume itself is stored
        // further down, once the candidate/application rows are committed.
        if (empty($_FILES['resume']) || ($_FILES['resume']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'Please attach your resume.';
            $errorField = 'resume';
        }
        $photoError=null; $profileImage=$error==='' ? save_profile_image_upload($_FILES['profile_image']??[], $name, $photoError) : null;
        if($error==='' && $profileImage===null){ $error=$photoError; $errorField='photo'; }
        if($error===''){
            try {
                $parts=preg_split('/\s+/', $name, 2); $first=$parts[0]; $last=$parts[1]??'';
                $source = $sourceKey==='other' ? substr('Other: '.$sourceOther, 0, 80) : $sourceKey;
                // The transaction stays: the candidate row, the application
                // row and the stored resume must land together or not at all.
                // What went is the `SELECT ... FOR UPDATE` that counted
                // applications against applicant_limit and rolled back once the
                // count was reached -- the spot number is a target now, not a
                // cap, so there is nothing left to lock against.
                $pdo->beginTransaction();
                {
                    $find=$pdo->prepare('SELECT id FROM candidates WHERE email=? LIMIT 1'); $find->execute([$email]); $candidate=$find->fetchColumn();
                    if($candidate){
                        $candidateId=(int)$candidate;
                        // resume_path is intentionally left untouched here -- it
                        // is legacy display-only data for candidates who predate
                        // candidate_documents (see candidate.php's fallback). No
                        // NEW value is ever written to it again.
                        $upd=$pdo->prepare("UPDATE candidates SET first_name=?,last_name=?,phone=?,portfolio_url=?,source=?,profile_image=COALESCE(NULLIF(?, ''), profile_image),consent_at=NOW() WHERE id=?");
                        $upd->execute([$first,$last,$phone?:null,$portfolio?:null,$source,$profileImage,$candidateId]);
                    } else {
                        $ins=$pdo->prepare("INSERT INTO candidates(first_name,last_name,email,phone,portfolio_url,source,profile_image,consent_at) VALUES(?,?,?,?,?,?,?,NOW())");
                        $ins->execute([$first,$last,$email,$phone?:null,$portfolio?:null,$source,$profileImage ?: null]); $candidateId=(int)$pdo->lastInsertId();
                    }
                    $ins=$pdo->prepare('INSERT INTO applications(candidate_id,job_id,cover_letter,why_us) VALUES(?,?,?,?)');
                    $ins->execute([$candidateId,$job['id'],$coverLetter,$whyUs]); $applicationId=(int)$pdo->lastInsertId();

                    // The candidate and application rows exist now, so the
                    // resume can be stored against the real candidate id.
                    // $userId is null: the uploader is the applicant, not a
                    // signed-in staff member -- store_candidate_document()
                    // accepts that (uploaded_by is a nullable column).
                    [$docOk, $docResult] = store_candidate_document($_FILES['resume'], $candidateId, null);
                    if (!$docOk) {
                        $pdo->rollBack();
                        // A missing candidate_documents table (migration not
                        // yet run) surfaces here as a raw database message
                        // from inside store_candidate_document()'s own
                        // catch block. That must never reach a public,
                        // unauthenticated applicant -- same treatment as the
                        // "Unknown column" case above, for the same reason.
                        $errorField = 'resume';
                        $error = stripos($docResult['error'], 'candidate_documents') !== false
                               || stripos($docResult['error'], "doesn't exist") !== false
                               || stripos($docResult['error'], 'Base table') !== false
                            ? 'The database is missing recent updates. An administrator needs to run database/migration-resume-storage.sql against the acme_ats database.'
                            : $docResult['error'];
                    } else {
                        $pdo->commit();
                        $next = 'application-status.php?id='.$applicationId.'&email='.urlencode($email).'&new=1';
                        if ($wantsJson) {
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode(['ok' => true, 'redirect' => $next]);
                            exit;
                        }
                        header('Location: '.$next); exit;
                    }
                }
            } catch(Throwable $e){
                if($pdo->inTransaction()) $pdo->rollBack();
                error_log('apply.php submission failed: '.$e->getMessage());
                if($e->getCode()==='23000'){
                    $error='You already have an application for this role.';
                } elseif(stripos($e->getMessage(),'Unknown column')!==false){
                    $error='The database is missing recent updates. An administrator needs to run database/migration-apply-questions.sql (and migration-candidate-crm.sql) against the acme_ats database.';
                } else {
                    $error='We could not submit your application. Please try again.';
                }
            }
        }
    }

    if ($wantsJson && $error !== '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error, 'field' => $errorField ?: null]);
        exit;
    }
}
// A closed portal answers an XHR submit the same way, so the page can say why.
if ($wantsJson && $_SERVER['REQUEST_METHOD'] === 'POST' && $closed) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $error, 'field' => null]);
    exit;
}
$openJobs=$pdo->prepare("SELECT j.*,d.name department,(SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id) applications FROM jobs j LEFT JOIN departments d ON d.id=j.department_id WHERE j.status='open' AND j.id<>? ORDER BY j.published_at DESC LIMIT 6");
$openJobs->execute([$job['id']]); $relatedJobs=$openJobs->fetchAll();
$pageTitle='Apply · '.$job['title']; $minimal=true; include __DIR__.'/includes/header.php';
$old = $_POST ?? [];
?>
<link rel="stylesheet" href="assets/css/apply.css?v=<?= @filemtime(__DIR__.'/assets/css/apply.css') ?: time() ?>">
<div class="hero small"><div class="eyebrow">Application · <?=e($job['department']??$companyName)?></div><h1><?=e($job['title'])?></h1><p class="meta small" style="margin:6px 0 12px"><?=icon('public',13)?> <?=e($job['location']?:'Remote')?> · <?=icon('overview',13)?> <?=e(str_replace('_',' ',ucfirst($job['employment_type'])))?> · <?=e($companyName)?></p><p>Tell us a little about yourself. We review every application thoughtfully.<?php if($spots!==null && $spots>0): ?> <span class="apply-spots"><strong><?=$spots?></strong> <?=$spots===1?'spot':'spots'?> available.</span><?php endif; ?></p></div>

<?php if($closed): ?>
<div class="notice error"><?=icon('limit',16)?> <?=e($error)?></div><a class="btn" href="jobs.php"><?=icon('chevron',15)?> Browse other open roles</a>
<?php else: ?>
<!-- No data-validate: app.js's generic validator loads in the footer, AFTER
     assets/js/apply-form.js, so it ran after the XHR submit had already gone,
     and it marks fields with an inline #b7492d border that never clears.
     Required fields use the browser's own constraint validation instead, with
     setCustomValidity() for the phone and resume rules; the submit event only
     fires once all of it passes. -->
<form class="apply-layout" id="apply-form" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="job" value="<?=e($job['slug'])?>">

<div class="apply-main">
<div class="notice error" data-form-error role="alert" <?= $error ? '' : 'hidden' ?>><?= e($error) ?></div>

<div class="card apply-section">
  <div class="apply-section-title"><span class="step-num">1</span>Personal Information</div>
  <div class="form-grid">
    <div class="field"><label>Full name <span class="req">*</span></label><input id="f-name" name="name" required value="<?=e($old['name']??'')?>"></div>
    <div class="field"><label>Email <span class="req">*</span></label><input id="f-email" type="email" name="email" required value="<?=e($old['email']??'')?>"></div>
  </div>
  <?php $phoneBad = $errorField === 'phone'; ?>
  <div class="field ap-phone<?= $phoneBad ? ' invalid' : '' ?>" data-phone-field>
    <label for="f-phone-number">Phone <span class="hint">optional</span></label>
    <!-- Country, dial code and number share one grid row on wide screens and
         wrap onto two below 768px: country on its own line, then the dial code
         beside the number. Nothing overlaps and no control is squeezed below a
         usable width. -->
    <div class="ap-phone-row">
      <select id="f-phone-country" name="phone_country" aria-label="Country calling code" class="ap-phone-country">
        <?php $selectedIso = $old['phone_country'] ?? 'PH'; foreach (phone_countries() as [$iso,$name,$dial]): ?>
          <option value="<?=e($iso)?>" data-dial="<?=e($dial)?>" <?=$selectedIso===$iso?'selected':''?>><?=phone_country_flag($iso)?> <?=e($name)?> (+<?=e($dial)?>)</option>
        <?php endforeach; ?>
      </select>
      <!-- The dial code is already announced as part of the selected country,
           so it is hidden from assistive tech here rather than read twice. -->
      <span class="ap-phone-dial" id="phone-dial-display" aria-hidden="true">+63</span>
      <input id="f-phone-number" name="phone_number" type="tel" inputmode="tel" autocomplete="tel-national"
             class="ap-phone-number" placeholder="917 123 4567"
             aria-label="Phone number"
             aria-describedby="phone-hint phone-error"
             <?= $phoneBad ? 'aria-invalid="true"' : '' ?>
             pattern="[0-9 ()+.\-]{6,24}"
             value="<?=e($old['phone_number']??'')?>">
    </div>
    <div class="field-hint" id="phone-hint">Select your country, then enter your number without the country code.</div>
    <div class="field-error" id="phone-error" role="alert"><?= $phoneBad ? e($error) : '' ?></div>
    <div class="phone-valid-msg" id="phone-valid-msg" hidden>Valid phone number</div>
  </div>
</div>

<div class="card apply-section">
  <div class="apply-section-title"><span class="step-num">2</span>Application Questions</div>
  <div class="field">
    <label>Cover letter <span class="req">*</span></label>
    <textarea id="f-cover" name="cover_letter" required maxlength="2000" rows="6" placeholder="Introduce yourself and share what makes you a strong fit for this role..."><?=e($old['cover_letter']??'')?></textarea>
    <div class="char-counter" id="cover-counter">0 / 2000</div>
  </div>
  <div class="field">
    <label>Why do you want to work at <?=e($companyName)?>? <span class="req">*</span></label>
    <textarea id="f-why" name="why_us" required maxlength="1200" rows="4" placeholder="Tell us what draws you to <?=e($companyName)?> specifically..."><?=e($old['why_us']??'')?></textarea>
    <div class="char-counter" id="why-counter">0 / 1200</div>
  </div>
  <div class="field">
    <label>How did you hear about us? <span class="req">*</span></label>
    <select id="f-source" name="source" required>
      <option value="">Select an option</option>
      <?php foreach($sourceOptions as $key=>$label): ?>
        <option value="<?=e($key)?>" <?= (($old['source']??'')===$key)?'selected':'' ?>><?=e($label)?></option>
      <?php endforeach; ?>
    </select>
    <div class="other-source-field <?= (($old['source']??'')==='other')?'show':'' ?>" id="source-other-wrap">
      <input name="source_other" id="f-source-other" placeholder="Please specify" value="<?=e($old['source_other']??'')?>">
    </div>
  </div>
</div>

<div class="card apply-section">
  <div class="apply-section-title"><span class="step-num">3</span>Professional Links</div>
  <div class="field">
    <label>Portfolio URL <span class="hint">optional</span></label>
    <input id="f-portfolio" name="portfolio" type="url" placeholder="https://yourportfolio.com" value="<?=e($old['portfolio']??'')?>">
    <div class="field-hint">Share your portfolio, GitHub, personal website, or other work samples.</div>
    <div class="field-error" id="portfolio-error">Please enter a valid URL, e.g. https://yourportfolio.com</div>
  </div>
</div>
</div>

<aside class="apply-sidebar">
  <div class="card sidebar-card" style="margin-bottom:16px">
    <div class="section-head compact"><h2 style="font-size:15px">Profile Picture <span class="hint">optional</span></h2></div>
    <label class="profile-photo-drop" id="profile-photo-drop" for="f-profile-image">
      <input type="file" id="f-profile-image" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
      <span class="profile-photo-placeholder" id="profile-photo-placeholder"><?=icon('image',22)?><strong>Upload Photo</strong><small>JPG, PNG, WEBP · max 3MB</small></span>
      <img id="profile-photo-preview" class="profile-photo-preview" alt="Profile photo preview" hidden>
    </label>
    <div class="profile-photo-actions" id="profile-photo-actions" hidden>
      <button type="button" class="btn secondary small" id="profile-photo-change">Change Photo</button>
      <button type="button" class="btn ghost small" id="profile-photo-remove">Remove</button>
    </div>
    <div class="field-error" id="profile-photo-error"></div>
  </div>

  <?php
    $resumeBad = $errorField === 'resume';
    // The size limit shown here, enforced in the browser and enforced by the
    // server are ONE number: DOC_MAX_BYTES in includes/documents.php, which is
    // what store_candidate_document() rejects above. They used to be three
    // (the page said 5MB, the script enforced 5MB, the server allowed 10MB).
    $resumeMaxMb = (int)round(DOC_MAX_BYTES / 1048576);
  ?>
  <div class="card sidebar-card" style="margin-bottom:16px">
    <div class="section-head compact"><h2 style="font-size:15px" id="resume-heading">Resume <span class="req">*</span></h2></div>
    <!-- Two states, one zone.

         EMPTY: the prompt, and the real <input type="file"> stretched invisibly
         over the whole zone -- a click anywhere opens the picker, Tab lands on
         it, Enter or Space opens it, and it still uploads with JavaScript off.

         ATTACHED: the prompt is gone entirely and a file card takes its place:
         name, type, size, a "ready" indicator, and Replace / Remove. The input
         stays in the form (it holds the file) but stops covering the zone and
         leaves the tab order, so the two buttons can be clicked and reached. -->
    <div class="ap-resume<?= $resumeBad ? ' is-invalid' : '' ?>" data-resume-zone>
      <input type="file" id="f-resume" name="resume" required
             accept=".pdf, .doc, .docx"
             aria-labelledby="resume-heading"
             aria-describedby="resume-hint resume-error"
             <?= $resumeBad ? 'aria-invalid="true"' : '' ?>
             data-max-bytes="<?= (int)DOC_MAX_BYTES ?>"
             data-resume-input>

      <div class="ap-resume-prompt" data-resume-prompt>
        <span class="ap-resume-icon" aria-hidden="true"><?=icon('upload',24)?></span>
        <span class="ap-resume-title">Drop your resume here, or click to browse</span>
        <span class="ap-resume-hint" id="resume-hint">Supports PDF, DOC, or DOCX up to <?= $resumeMaxMb ?>MB.</span>
      </div>

      <div class="ap-resume-card" data-resume-card role="group" aria-label="Attached resume" hidden>
        <span class="ap-resume-badge" aria-hidden="true" data-resume-ext>PDF</span>
        <div class="ap-resume-meta">
          <!-- Long names wrap to two lines rather than being cut to a few
               words; the full name is always in the title and in what a screen
               reader hears. -->
          <span class="ap-resume-name" data-resume-name></span>
          <span class="ap-resume-detail" data-resume-detail></span>
          <span class="ap-resume-ready">
            <span class="ap-resume-ready-dot" aria-hidden="true"><?=icon('check',12)?></span>
            <!-- "Ready", not "uploaded": the file travels with the form when it
                 is submitted, and saying otherwise would be untrue. -->
            <span data-resume-ready-text>Ready to submit</span>
          </span>
        </div>
        <div class="ap-resume-actions">
          <button type="button" class="ap-btn ap-btn-quiet" data-resume-replace>Replace</button>
          <button type="button" class="ap-btn ap-btn-danger" data-resume-remove>Remove</button>
        </div>
      </div>
    </div>

    <div class="ap-progress" data-upload-progress hidden>
      <div class="ap-progress-track" role="progressbar" aria-label="Upload progress"
           aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-upload-bar>
        <span class="ap-progress-fill" data-upload-fill></span>
      </div>
      <span class="ap-progress-text" data-upload-text aria-live="polite">Uploading… 0%</span>
    </div>

    <!-- Status changes (attached, replaced, removed, restored) are announced
         here; errors are announced by the role="alert" below. Two regions, so
         a status never interrupts an error or the other way round. -->
    <p class="sr-only" role="status" aria-live="polite" data-resume-status></p>
    <div class="field-error ap-resume-error" id="resume-error" role="alert"><?= $resumeBad ? e($error) : '' ?></div>
  </div>

  <div class="card sidebar-card" style="margin-bottom:16px">
    <div class="section-head compact"><h2 style="font-size:15px">Application Progress</h2></div>
    <div class="progress-list">
      <div class="progress-item" id="progress-personal"><span class="dot">1</span>Personal Information</div>
      <div class="progress-item" id="progress-resume"><span class="dot">2</span>Resume</div>
      <div class="progress-item" id="progress-questions"><span class="dot">3</span>Application Questions</div>
      <div class="progress-item" id="progress-review"><span class="dot">4</span>Review &amp; Submit</div>
    </div>
  </div>

  <div class="card sidebar-card">
    <div class="section-head compact"><h2 style="font-size:15px">Review &amp; Submit</h2></div>
    <div class="review-summary" id="review-summary">
      <div class="row"><span>Name</span><span id="rev-name">—</span></div>
      <div class="row"><span>Email</span><span id="rev-email">—</span></div>
      <div class="row"><span>Resume</span><span id="rev-resume">Not uploaded</span></div>
      <div class="row"><span>Cover letter</span><span id="rev-cover">0 chars</span></div>
      <div class="row"><span>Portfolio</span><span id="rev-portfolio">Not provided</span></div>
    </div>
    <label class="confirm-row"><input type="checkbox" name="confirm" id="f-confirm" required> I confirm the information above is accurate and I consent to <?=e($companyName)?> reviewing my application.</label>
    <button class="btn" type="submit" id="submit-btn" style="width:100%"><?=icon('plus',16)?> Submit application</button>
  </div>
</aside>
</form>

<?php if($relatedJobs): ?>
<div class="section-head" style="margin-top:44px"><h2 style="font-size:22px">Other open positions</h2></div>
<!-- Was an inline repeat(3,1fr): three columns even on a 320px phone, which
     pushed the page 2px wider than the screen. -->
<div class="grid apply-related">
<?php foreach($relatedJobs as $rj): $rjTags=job_tags($rj['tags']??''); ?>
<a class="card job-card" href="job-detail.php?slug=<?=urlencode($rj['slug'])?>">
  <div><span class="meta small"><?=e($rj['department']??$companyName)?></span><h3><?=e($rj['title'])?></h3><span class="meta small"><?=icon('public',12)?> <?=e($rj['location']?:'Remote')?></span></div>
  <?php if($rjTags): ?><div class="tag-row tiny" style="margin-top:10px"><?php foreach(array_slice($rjTags,0,3) as $t): ?><span class="tag-pill"><?=e($t)?></span><?php endforeach; ?></div><?php endif; ?>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
(function(){
  const form = document.getElementById('apply-form');
  if (!form) return;

  function bindCounter(fieldId, counterId, max){
    const field = document.getElementById(fieldId), counter = document.getElementById(counterId);
    if (!field || !counter) return;
    const update = () => {
      const len = field.value.length;
      counter.textContent = len + ' / ' + max;
      counter.classList.toggle('limit', len >= max);
    };
    field.addEventListener('input', () => { update(); updateProgress(); updateReview(); });
    update();
  }

  // Profile photo preview / validation
  const photoInput = document.getElementById('f-profile-image');
  const photoPreview = document.getElementById('profile-photo-preview');
  const photoPlaceholder = document.getElementById('profile-photo-placeholder');
  const photoActions = document.getElementById('profile-photo-actions');
  const photoError = document.getElementById('profile-photo-error');
  const photoDrop = document.getElementById('profile-photo-drop');
  function clearPhotoError(){ if(photoError) photoError.textContent=''; }
  function resetPhoto(){
    if(photoInput) photoInput.value='';
    if(photoPreview){ photoPreview.hidden=true; photoPreview.removeAttribute('src'); }
    if(photoPlaceholder) photoPlaceholder.hidden=false;
    if(photoActions) photoActions.hidden=true;
    clearPhotoError();
    updateProgress(); updateReview();
  }
  function handlePhoto(){
    clearPhotoError();
    const file = photoInput?.files?.[0];
    if(!file){ resetPhoto(); return; }
    const allowed=['image/jpeg','image/png','image/webp'];
    if(!allowed.includes(file.type)){ resetPhoto(); photoError.textContent='Please upload a valid JPG, PNG, or WEBP image.'; return; }
    if(file.size > 3*1024*1024){ resetPhoto(); photoError.textContent='Profile photo must be under 3MB.'; return; }
    const reader=new FileReader();
    reader.onload=e=>{ photoPreview.src=e.target.result; photoPreview.hidden=false; photoPlaceholder.hidden=true; photoActions.hidden=false; updateProgress(); updateReview(); };
    reader.readAsDataURL(file);
  }
  photoInput?.addEventListener('change', handlePhoto);
  document.getElementById('profile-photo-change')?.addEventListener('click',()=>photoInput?.click());
  document.getElementById('profile-photo-remove')?.addEventListener('click',resetPhoto);

  // Source "Other" reveal
  const sourceSelect = document.getElementById('f-source');
  const otherWrap = document.getElementById('source-other-wrap');
  const otherInput = document.getElementById('f-source-other');
  function toggleOther(){
    const isOther = sourceSelect.value === 'other';
    otherWrap.classList.toggle('show', isOther);
    if (!isOther) otherInput.value = '';
  }
  if (sourceSelect) { sourceSelect.addEventListener('change', () => { toggleOther(); updateProgress(); }); toggleOther(); }

  // Resume: handled by assets/js/apply-form.js, which dispatches `change` on
  // the input after a drop or a removal, so the progress and review panels
  // below stay in step without knowing how the file got there.
  const resumeInput = document.getElementById('f-resume');
  if (resumeInput) resumeInput.addEventListener('change', () => { updateProgress(); updateReview(); });

  // Portfolio URL validation
  const portfolioInput = document.getElementById('f-portfolio');
  const portfolioField = portfolioInput ? portfolioInput.closest('.field') : null;
  function validatePortfolio(){
    if (!portfolioInput) return true;
    const val = portfolioInput.value.trim();
    if (val === '') { portfolioField.classList.remove('invalid'); return true; }
    let ok = false;
    try { const u = new URL(val); ok = u.protocol === 'http:' || u.protocol === 'https:'; } catch(e) { ok = false; }
    portfolioField.classList.toggle('invalid', !ok);
    return ok;
  }
  if (portfolioInput) portfolioInput.addEventListener('input', () => { validatePortfolio(); updateProgress(); updateReview(); });

  // Phone: handled by assets/js/apply-form.js.

  // Progress tracker
  function updateProgress(){
    const nameOk = document.getElementById('f-name').value.trim() !== '';
    const emailOk = /.+@.+\..+/.test(document.getElementById('f-email').value.trim());
    setStep('progress-personal', nameOk && emailOk);

    const resumeOk = resumeInput && resumeInput.files && resumeInput.files.length > 0;
    setStep('progress-resume', resumeOk);

    const coverOk = document.getElementById('f-cover').value.trim() !== '';
    const whyOk = document.getElementById('f-why').value.trim() !== '';
    const sourceOk = sourceSelect.value !== '' && (sourceSelect.value !== 'other' || otherInput.value.trim() !== '');
    setStep('progress-questions', coverOk && whyOk && sourceOk);

    const confirmOk = document.getElementById('f-confirm').checked;
    setStep('progress-review', confirmOk && nameOk && emailOk && resumeOk && coverOk && whyOk && sourceOk && validatePortfolio());
  }
  function setStep(id, done){
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('done', done);
    if (done) el.querySelector('.dot').textContent = '✓';
  }

  // Live review summary
  function updateReview(){
    document.getElementById('rev-name').textContent = document.getElementById('f-name').value.trim() || '—';
    document.getElementById('rev-email').textContent = document.getElementById('f-email').value.trim() || '—';
    document.getElementById('rev-resume').textContent = (resumeInput && resumeInput.files.length) ? resumeInput.files[0].name : 'Not uploaded';
    document.getElementById('rev-cover').textContent = document.getElementById('f-cover').value.length + ' chars';
    document.getElementById('rev-portfolio').textContent = portfolioInput && portfolioInput.value.trim() ? portfolioInput.value.trim() : 'Not provided';
  }
  bindCounter('f-cover', 'cover-counter', 2000);
  bindCounter('f-why', 'why-counter', 1200);
  ['f-name','f-email','f-confirm'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', () => { updateProgress(); updateReview(); });
    if (el) el.addEventListener('change', () => { updateProgress(); updateReview(); });
  });

  // Runs before apply-form.js's submit handler (this script loads first), and
  // that handler steps aside when defaultPrevented is already set.
  form.addEventListener('submit', (e) => {
    if (!validatePortfolio()) { e.preventDefault(); portfolioInput.focus(); }
  });

  updateProgress(); updateReview();
})();
</script>
<script src="assets/js/apply-form.js?v=<?= @filemtime(__DIR__.'/assets/js/apply-form.js') ?: time() ?>"></script>
<?php include __DIR__.'/includes/footer.php'; ?>
