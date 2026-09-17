<?php
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/documents.php';
$pdo=db();
$slug=trim($_GET['job']??$_POST['job']??'senior-product-engineer');
$stmt=$pdo->prepare("SELECT j.*,d.name department,(SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id) applications FROM jobs j LEFT JOIN departments d ON d.id=j.department_id WHERE j.slug=? AND j.status='open' LIMIT 1"); $stmt->execute([$slug]); $job=$stmt->fetch();
if(!$job){http_response_code(404); exit('This job is no longer accepting applications.');}
$limit=$job['applicant_limit']!==null?(int)$job['applicant_limit']:null; $remaining=spots_remaining($limit,(int)$job['applications']); $full=$remaining===0;
// A Super Admin (or an Admin with Applicant Portal permission) can pause intake
// across the whole portal without unpublishing every role.
$portalOpen = setting('portal_accepting_applications','1') !== '0';
if(!$portalOpen) $full = true;
$error = $full
    ? ($portalOpen
        ? 'This role has reached its applicant limit and is no longer accepting applications.'
        : (setting('portal_closed_message') ?: 'We are not accepting applications at the moment. Please check back soon.'))
    : '';
$companyName = setting('company_name','Acme');

$sourceOptions = [
    'linkedin'=>'LinkedIn','facebook'=>'Facebook','indeed'=>'Indeed','jobstreet'=>'JobStreet',
    'company_website'=>'Company Website','google'=>'Google Search','referral'=>'Friend or Colleague',
    'agency'=>'Recruitment Agency','university'=>'University / School','job_fair'=>'Job Fair','other'=>'Other',
];

if($_SERVER['REQUEST_METHOD']==='POST' && !$full){
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
        if (strlen($phoneDigits) < 6 || strlen($phoneDigits) > 14) {
            $error = 'Please enter a valid phone number.';
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
        }
        $photoError=null; $profileImage=$error==='' ? save_profile_image_upload($_FILES['profile_image']??[], $name, $photoError) : null;
        if($error==='' && $profileImage===null){ $error=$photoError; }
        if($error===''){
            try {
                $parts=preg_split('/\s+/', $name, 2); $first=$parts[0]; $last=$parts[1]??'';
                $source = $sourceKey==='other' ? substr('Other: '.$sourceOther, 0, 80) : $sourceKey;
                $pdo->beginTransaction();
                $lockCheck=$pdo->prepare('SELECT applicant_limit,(SELECT COUNT(*) FROM applications WHERE job_id=?) c FROM jobs WHERE id=? FOR UPDATE');
                $lockCheck->execute([$job['id'],$job['id']]); $lock=$lockCheck->fetch();
                if($lock && $lock['applicant_limit']!==null && (int)$lock['c']>=(int)$lock['applicant_limit']){ $pdo->rollBack(); $error='This role just reached its applicant limit. Please explore other open roles.'; }
                else {
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
                        $error = stripos($docResult['error'], 'candidate_documents') !== false
                               || stripos($docResult['error'], "doesn't exist") !== false
                               || stripos($docResult['error'], 'Base table') !== false
                            ? 'The database is missing recent updates. An administrator needs to run database/migration-resume-storage.sql against the acme_ats database.'
                            : $docResult['error'];
                    } else {
                        $pdo->commit();
                        header('Location: application-status.php?id='.$applicationId.'&email='.urlencode($email).'&new=1'); exit;
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
}
$openJobs=$pdo->prepare("SELECT j.*,d.name department,(SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id) applications FROM jobs j LEFT JOIN departments d ON d.id=j.department_id WHERE j.status='open' AND j.id<>? ORDER BY j.published_at DESC LIMIT 6");
$openJobs->execute([$job['id']]); $relatedJobs=$openJobs->fetchAll();
$pageTitle='Apply · '.$job['title']; $minimal=true; include __DIR__.'/includes/header.php';
$old = $_POST ?? [];
?>
<div class="hero small"><div class="eyebrow">Application · <?=e($job['department']??$companyName)?></div><h1><?=e($job['title'])?></h1><p class="meta small" style="margin:6px 0 12px"><?=icon('public',13)?> <?=e($job['location']?:'Remote')?> · <?=icon('overview',13)?> <?=e(str_replace('_',' ',ucfirst($job['employment_type'])))?> · <?=e($companyName)?></p><p>Tell us a little about yourself. We review every application thoughtfully.<?php if($limit!==null && !$full): ?> <strong><?=$remaining?></strong> of <?=$limit?> spots remaining.<?php endif; ?></p></div>

<?php if($full): ?>
<div class="notice error"><?=icon('limit',16)?> <?=e($error)?></div><a class="btn" href="jobs.php"><?=icon('chevron',15)?> Browse other open roles</a>
<?php else: ?>
<form class="apply-layout" data-validate id="apply-form" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="job" value="<?=e($job['slug'])?>">

<div class="apply-main">
<?php if($error): ?><div class="notice error"><?=e($error)?></div><?php endif; ?>

<div class="card apply-section">
  <div class="apply-section-title"><span class="step-num">1</span>Personal Information</div>
  <div class="form-grid">
    <div class="field"><label>Full name <span class="req">*</span></label><input id="f-name" name="name" required value="<?=e($old['name']??'')?>"></div>
    <div class="field"><label>Email <span class="req">*</span></label><input id="f-email" type="email" name="email" required value="<?=e($old['email']??'')?>"></div>
  </div>
  <div class="field">
    <label>Phone <span class="hint">optional</span></label>
    <div class="phone-input-row">
      <select id="f-phone-country" name="phone_country">
        <?php $selectedIso = $old['phone_country'] ?? 'PH'; foreach (phone_countries() as [$iso,$name,$dial]): ?>
          <option value="<?=e($iso)?>" data-dial="<?=e($dial)?>" <?=$selectedIso===$iso?'selected':''?>><?=phone_country_flag($iso)?> <?=e($name)?> (+<?=e($dial)?>)</option>
        <?php endforeach; ?>
      </select>
      <span class="phone-dial-code" id="phone-dial-display">+63</span>
      <input id="f-phone-number" name="phone_number" inputmode="tel" placeholder="917 123 4567" value="<?=e($old['phone_number']??'')?>">
    </div>
    <div class="field-hint" id="phone-hint">Select your country, then enter your number without the country code.</div>
    <div class="field-error" id="phone-error">Please enter a valid phone number.</div>
    <div class="phone-valid-msg" id="phone-valid-msg" hidden>✓ Valid phone number</div>
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

  <div class="card sidebar-card" style="margin-bottom:16px">
    <div class="section-head compact"><h2 style="font-size:15px">Resume <span class="req">*</span></h2></div>
    <label class="resume-drop" id="resume-drop" for="f-resume">
      <div class="dropzone" data-dropzone>
      <span class="dropzone-icon" aria-hidden="true">&#8593;</span>
      <span class="dropzone-title">Drop your resume here, or click to browse</span>
      <span class="dropzone-hint">Supports PDF, DOC or DOCX up to 5MB</span>
      <span class="dropzone-file" data-dropzone-name hidden></span>
      <input data-dropzone-input type="file" id="f-resume" name="resume" accept=".pdf,.doc,.docx" required>
    </div>
      <div><?=icon('image',20)?></div>
      <div style="margin-top:6px;font-weight:700;font-size:13px">Click to upload resume</div>
      <div class="field-hint">PDF, DOC, or DOCX up to 5MB</div>
    </label>
    <div class="resume-picked" id="resume-picked">
      <span id="resume-filename" style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
      <button type="button" class="resume-remove" id="resume-remove">Remove</button>
    </div>
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
<div class="grid" style="grid-template-columns:repeat(3,1fr)">
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

  // Resume upload preview / remove
  const resumeInput = document.getElementById('f-resume');
  const resumeDrop = document.getElementById('resume-drop');
  const resumePicked = document.getElementById('resume-picked');
  const resumeFilename = document.getElementById('resume-filename');
  const resumeRemove = document.getElementById('resume-remove');
  if (resumeInput) {
    resumeInput.addEventListener('change', () => {
      if (resumeInput.files && resumeInput.files.length) {
        resumeFilename.textContent = resumeInput.files[0].name;
        resumePicked.classList.add('show');
        resumeDrop.classList.add('hide');
      }
      updateProgress(); updateReview();
    });
    resumeRemove.addEventListener('click', () => {
      resumeInput.value = '';
      resumePicked.classList.remove('show');
      resumeDrop.classList.remove('hide');
      updateProgress(); updateReview();
    });
  }

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

  // International phone: country selector drives the dial-code display and
  // the expected-length validation. Loose on purpose — different countries
  // have different valid lengths, so this checks plausibility, not an exact
  // per-country pattern, and never rejects a number just for not looking
  // like a Philippine one.
  const phoneCountrySelect = document.getElementById('f-phone-country');
  const phoneDialDisplay = document.getElementById('phone-dial-display');
  const phoneNumberInput = document.getElementById('f-phone-number');
  const phoneField = phoneNumberInput ? phoneNumberInput.closest('.field') : null;
  const phoneValidMsg = document.getElementById('phone-valid-msg');
  const phonePlaceholders = { PH:'917 123 4567', US:'(555) 123-4567', CA:'(555) 123-4567', GB:'7911 123456', JP:'90 1234 5678', KR:'10 1234 5678', SG:'9123 4567', AU:'412 345 678', DE:'151 23456789', FR:'6 12 34 56 78', IN:'98765 43210' };

  function updateDialDisplay(){
    if (!phoneCountrySelect || !phoneDialDisplay) return;
    const opt = phoneCountrySelect.options[phoneCountrySelect.selectedIndex];
    const dial = opt.dataset.dial;
    phoneDialDisplay.textContent = '+' + dial;
    if (phoneNumberInput) phoneNumberInput.placeholder = phonePlaceholders[opt.value] || (dial + ' followed by your number');
  }
  function validatePhone(){
    if (!phoneNumberInput) return true;
    const val = phoneNumberInput.value.trim();
    if (val === '') { phoneField.classList.remove('invalid'); phoneValidMsg.hidden = true; return true; }
    const digits = val.replace(/\D/g, '');
    const ok = digits.length >= 6 && digits.length <= 14;
    phoneField.classList.toggle('invalid', !ok);
    phoneValidMsg.hidden = !ok;
    return ok;
  }
  if (phoneCountrySelect) { phoneCountrySelect.addEventListener('change', () => { updateDialDisplay(); validatePhone(); }); updateDialDisplay(); }
  if (phoneNumberInput) phoneNumberInput.addEventListener('input', validatePhone);

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

  form.addEventListener('submit', (e) => {
    if (!validatePortfolio()) { e.preventDefault(); portfolioInput.focus(); return; }
    if (!validatePhone()) { e.preventDefault(); phoneNumberInput.focus(); }
  });

  updateProgress(); updateReview();
})();
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
