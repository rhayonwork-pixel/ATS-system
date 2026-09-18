<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/interview_lib.php';
require_once __DIR__.'/includes/documents.php';
require_once __DIR__.'/includes/candidate_dal.php';
require_once __DIR__.'/includes/candidate_view_model.php';
require_login(['admin','recruiter','hiring_manager']);
$pdo = db();
$applicationId = (int)($_GET['id'] ?? 0);

// ============================================================================
// CONTROLLER — write actions only. Each branch validates its input and calls
// a parameterized DAL write function (includes/candidate_dal.php); there is
// no inline SQL left in this file. No data is fetched here for display — the
// single read that builds the page happens once, below, after redirecting.
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $postedApp = (int)($_POST['application_id'] ?? $applicationId);

    if ($action === 'stage') {
        $stage = $_POST['stage'] ?? '';
        $stageOrder = ['new','screening','interview','offer','hired'];
        if (in_array($stage, ['new','screening','interview','offer','hired','rejected'], true)) {
            $fromStage = candidate_dal_current_stage($pdo, $postedApp);
            $fromIdx = array_search($fromStage, $stageOrder, true); $toIdx = array_search($stage, $stageOrder, true);
            $isSkip = $stage !== 'rejected' && $fromIdx !== false && $toIdx !== false && $toIdx > $fromIdx + 1;
            $role = current_user()['role'] ?? '';
            if ($isSkip && $role !== 'admin') {
                flash('error','This candidate cannot be moved directly to that stage. Complete the required recruitment stages first.');
            } else {
                candidate_dal_update_stage($pdo, $postedApp, $stage);
                // audit() performs the same INSERT the old inline code did,
                // with the same columns and IP capture, but never throws --
                // a logging hiccup can no longer break a stage update that
                // already succeeded.
                audit('pipeline_stage_move','application',$postedApp,['from'=>$fromStage,'to'=>$stage,'override'=>$isSkip && $role==='admin']);
                flash('success', $isSkip ? 'Stage updated (admin override).' : 'Stage updated.');
            }
        }
    } elseif ($action === 'rating') {
        $rating = max(0, min(5, (int)($_POST['rating'] ?? 0)));
        $candidateId = (int)($_POST['candidate_id'] ?? 0);
        candidate_dal_set_rating($pdo, $candidateId, $rating);
        audit('candidate_rating','candidate',$candidateId);
        flash('success','Rating updated.');
    } elseif ($action === 'note') {
        $note = trim($_POST['note'] ?? '');
        $candidateId = (int)($_POST['candidate_id'] ?? 0);
        if ($note !== '' && $candidateId) {
            candidate_dal_add_note($pdo, $candidateId, (int)$_SESSION['user_id'], $note);
            audit('candidate_note','candidate',$candidateId);
            flash('success','Note added.');
        }
    } elseif ($action === 'feedback') {
        $fit = $_POST['fit'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        if (in_array($fit, ['strong-fit','potential-fit','not-a-fit'], true)) {
            candidate_dal_add_feedback($pdo, $postedApp, (int)$_SESSION['user_id'], $fit, $notes);
            audit('candidate_feedback','application',$postedApp);
            flash('success','Feedback saved.');
        }
    } elseif ($action === 'suggest') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($jobId) {
            candidate_dal_add_suggestion($pdo, $postedApp, $jobId, $note, (int)$_SESSION['user_id']);
            audit('candidate_role_suggestion','application',$postedApp);
            flash('success','Role suggestion saved.');
        }
    } elseif ($action === 'stage_review') {
        $stageType = $_POST['stage_type'] ?? '';
        if (in_array($stageType, ['screening','interview'], true)) {
            $rating = $_POST['review_rating'] !== '' ? max(0, min(100, (int)$_POST['review_rating'])) : null;
            $feedback = trim($_POST['review_feedback'] ?? '');
            $revNotes = trim($_POST['review_notes'] ?? '');
            candidate_dal_save_stage_review($pdo, $postedApp, $stageType, $rating, $feedback ?: null, $revNotes ?: null, (int)$_SESSION['user_id']);
            audit($stageType.'_review','application',$postedApp);
            flash('success', ucfirst($stageType).' review saved.');
        }
    } elseif ($action === 'ai_analyze') {
        $row = candidate_dal_fetch_for_ai($pdo, $postedApp);
        if ($row) {
            $analysis = generate_ai_analysis($row, $row);
            candidate_dal_save_ai_analysis($pdo, $postedApp, $analysis);
            audit('ai_analysis_generated','application',$postedApp);
            flash('success','AI analysis generated.');
        }
    } elseif ($action === 'convert_employee') {
        $row = candidate_dal_fetch_for_employee_conversion($pdo, $postedApp);
        if (!$row || $row['stage'] !== 'hired') {
            flash('error','Only hired candidates can be added to Employees.');
        } elseif (candidate_dal_employee_exists($pdo, $postedApp)) {
            flash('error','This candidate already has an employee record.');
        } else {
            $hiredPosition = trim($_POST['hired_position'] ?? '') ?: $row['title'];
            $employeeNumber = trim($_POST['employee_number'] ?? '') ?: ('EMP-'.date('Y').'-'.str_pad((string)$postedApp, 4, '0', STR_PAD_LEFT));
            $departmentId = (int)($_POST['department_id'] ?? 0) ?: null;
            $startDate = $_POST['start_date'] ?: date('Y-m-d');
            $result = candidate_dal_create_employee($pdo, $row, $postedApp, $hiredPosition, $employeeNumber, $departmentId, $startDate);
            if (is_string($result)) {
                flash('error', $result);
            } else {
                audit('candidate_converted_to_employee','employee',$result);
                flash('success', $row['first_name'].' '.$row['last_name'].' was added to Employees.');
            }
        }
    }
    // The anchor is posted by the form, so it is untrusted: only a plain
    // fragment id is ever echoed back into the Location header.
    $anchor = (string)($_POST['anchor'] ?? '');
    header('Location: candidate.php?id='.$postedApp.(preg_match('/^[A-Za-z0-9_-]{1,64}$/', $anchor) ? '#'.$anchor : '')); exit;
}

// ============================================================================
// VIEW MODEL — the single read for this entire request. Every value the
// markup below uses comes from $vm, built exactly once here, so there is
// nowhere else a second, possibly-stale copy of the same data could be read
// from. See includes/candidate_view_model.php.
// ============================================================================
$vm = build_candidate_view_model($pdo, $applicationId);

if (!$vm['ok']) {
    $notFound = $vm['error'] === 'not_found';
    http_response_code($notFound ? 404 : 500);
    $pageTitle = $notFound ? 'Candidate not found' : 'Unable to load';
    include __DIR__.'/includes/header.php';
    // The database's own error text never reaches the page -- either a fixed
    // "not found" message, or the friendly string build_candidate_view_model()
    // already chose for any other failure.
    $heading = $notFound ? 'Candidate not found' : 'Something went wrong';
    $message = $notFound ? 'This application may have been removed.' : $vm['error'];
    echo '<div class="empty-state card"><h2>'.e($heading).'</h2>'
       . '<p class="meta">'.e($message).'</p>'
       . '<a class="btn secondary" href="candidates.php">Back to candidates</a></div>';
    include __DIR__.'/includes/footer.php';
    exit;
}

// Flattened into the same variable names the view below already uses. This
// assignment block is the entire bridge between the new data layer and the
// existing markup -- nothing below this point changed.
$candidate           = $vm['candidate'];
$interviewHistory    = $vm['interviewHistory'];
$documents           = $vm['documents'];
$primaryDoc          = $vm['primaryDoc'];
$resumeIndexed       = $vm['resumeIndexed'];
$notes               = $vm['notes'];
$feedbackList        = $vm['feedbackList'];
$suggestions         = $vm['suggestions'];
$openRoles           = $vm['openRoles'];
$screeningReview     = $vm['screeningReview'];
$interviewReview     = $vm['interviewReview'];
$employeeRecord      = $vm['employeeRecord'];
$hireDepartments     = $vm['hireDepartments'];
$aiAnalysis          = $vm['aiAnalysis'];
$activity            = $vm['activity'];
$screeningDone       = $vm['screeningDone'];
$interviewDone       = $vm['interviewDone'];
$stageLabels         = $vm['stageLabels'];
$activityStageLabels = $vm['activityStageLabels'];
$actionLabels        = $vm['actionLabels'];
$initials            = $vm['initials'];
$sourceLabel         = $vm['sourceLabel'];

$pageTitle = $candidate['first_name'].' '.$candidate['last_name'];
include __DIR__.'/includes/header.php';
?>
<?php
// Row 1: one-click stage moves. Advance goes exactly one step (never a skip),
// Reject is terminal; both post to the same 'stage' handler as the dropdown.
$stageFlow = ['new', 'screening', 'interview', 'offer', 'hired'];
$flowIdx   = array_search($candidate['stage'], $stageFlow, true);
$nextStage = ($flowIdx !== false && $flowIdx < count($stageFlow) - 1) ? $stageFlow[$flowIdx + 1] : null;
$canReject = !in_array($candidate['stage'], ['hired', 'rejected'], true);

// Stage reviews that still need something done, shown on the collapsed bar.
$pendingWork = (int)($screeningDone && !$screeningReview) + (int)($interviewDone && !$interviewReview)
             + (int)($candidate['stage'] === 'hired' && !$employeeRecord);
$hasStageWork = $screeningDone || $interviewDone || $candidate['stage'] === 'hired';

$fullName = $candidate['first_name'].' '.$candidate['last_name'];
$docKind  = $primaryDoc ? ['pdf' => 'pdf', 'docx' => 'docx'][$primaryDoc['extension']] ?? null : null;
?>
<!-- One size container for the whole profile (its @container breakpoints
     measure the space left after the sidebar), and inside it exactly the
     wireframe's seven rows, in order, as the direct children of .cp-rows. -->
<div class="page-container candidate-profile-page">
<a class="cp-back meta" href="candidates.php">← Back to candidates</a>
<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>" role="<?= $f[0]==='error' ? 'alert' : 'status' ?>"><?=e($f[1])?></div><?php endif; ?>

<div class="cp-rows">

<!-- ══ ROW 1 · Candidate Profile ═════════════════════════════════════════ -->
<section class="card cp-card cp-profile" aria-labelledby="cp-name">
  <div class="cp-profile-main">
    <?php if(!empty($candidate['profile_image'])): ?><img class="avatar-circle avatar-image-large cp-avatar" src="<?=e($candidate['profile_image'])?>" alt=""><?php else: ?><div class="avatar-circle cp-avatar" aria-hidden="true"><?=e($initials)?></div><?php endif; ?>
    <div class="cp-profile-id">
      <div class="eyebrow">Candidate profile · Application #<?=e((string)$candidate['application_id'])?></div>
      <h1 id="cp-name"><?=e($fullName)?></h1>
      <p class="cp-subline"><?=e($candidate['job_title'])?><?php if($candidate['department']): ?> · <?=e($candidate['department'])?><?php endif; ?></p>
      <p class="cp-contact"><a href="mailto:<?=e($candidate['email'])?>"><?=e($candidate['email'])?></a><?php if(!empty($candidate['phone'])): ?> · <a href="tel:<?=e(preg_replace('/[^\d+]/', '', $candidate['phone']))?>"><?=e($candidate['phone'])?></a><?php endif; ?></p>
      <div class="cp-badges">
        <?=stage_badge($candidate['stage'])?>
        <?php if($aiAnalysis): ?><span class="candidate-card-score">AI score <?=e((string)$aiAnalysis['overall_score'])?>/100</span><?php endif; ?>
        <?php $r=(int)$candidate['rating']; ?>
        <?php if($screeningDone): ?>
          <!-- Writes the same candidates.rating column as before. -->
          <form method="post" class="rating-inline" aria-label="Rate this candidate">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="rating">
            <input type="hidden" name="candidate_id" value="<?=$candidate['candidate_id']?>">
            <?php for($i=1;$i<=5;$i++): ?>
              <button type="submit" name="rating" value="<?=$i?>" class="star-btn <?= $i<=$r?'on':'' ?>" aria-label="Rate <?=$i?> out of 5"<?= $i===$r ? ' aria-current="true"' : '' ?>><?=$i<=$r?'★':'☆'?></button>
            <?php endfor; ?>
            <span class="rating-value"><?= $r>0 ? e((string)$r).' / 5' : 'Not yet rated' ?></span>
          </form>
        <?php elseif($r>0): ?>
          <span class="rating-inline is-readonly" aria-label="Rated <?=$r?> out of 5"><?php for($i=1;$i<=5;$i++): ?><span class="star-btn <?= $i<=$r?'on':'' ?>" aria-hidden="true"><?=$i<=$r?'★':'☆'?></span><?php endfor; ?><span class="rating-value"><?=e((string)$r)?> / 5</span></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if($nextStage || $canReject): ?>
    <div class="cp-decide">
      <?php if($nextStage): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="stage">
          <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
          <input type="hidden" name="stage" value="<?=e($nextStage)?>">
          <button class="btn" type="submit"><?=icon('check',15)?> Advance to <?=e($stageLabels[$nextStage] ?? ucfirst($nextStage))?></button>
        </form>
      <?php endif; ?>
      <?php if($canReject): ?>
        <form method="post" onsubmit="return confirm(<?=e(json_encode('Reject '.$fullName.'? This moves the application to Rejected.'))?>);">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="stage">
          <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
          <input type="hidden" name="stage" value="rejected">
          <button class="btn secondary cp-reject" type="submit">Reject</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if($candidate['stage']==='rejected'): ?>
    <div class="stage-stepper"><div class="stage-step rejected"><span class="dot">✕</span>Rejected</div></div>
  <?php else:
    $stepperStages = ['new'=>'Applied','screening'=>'Screening','interview'=>'Interview','offer'=>'Offer','hired'=>'Hired'];
    $stepperKeys = array_keys($stepperStages); $curIdx = array_search($candidate['stage'], $stepperKeys, true) ?: 0;
  ?>
    <ol class="stage-stepper" aria-label="Hiring progress">
      <?php foreach($stepperStages as $key=>$label): $idx = array_search($key,$stepperKeys,true); $cls = $idx < $curIdx ? 'done' : ($idx === $curIdx ? 'current' : ''); ?>
        <?php if($idx>0): ?><li class="stage-connector <?=$idx<=$curIdx?'done':''?>" aria-hidden="true"></li><?php endif; ?>
        <li class="stage-step <?=$cls?>"<?= $idx === $curIdx ? ' aria-current="step"' : '' ?>><span class="dot" aria-hidden="true"><?= $idx < $curIdx ? '✓' : $idx+1 ?></span><?=e($label)?></li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <nav class="cp-quicknav" aria-label="Jump to a section of this profile">
    <a href="#resume">Resume / CV</a>
    <a href="#candidate-info">Details &amp; notes</a>
    <a href="#ai-analysis">AI analysis</a>
    <a href="#interview-history">Interviews</a>
    <a href="#activity-log">Activity</a>
  </nav>

  <?php if($hasStageWork): ?>
  <!-- Stage reviews predate the wireframe. They are real workflow forms, so
       they stay, folded into Row 1 (the row that owns stage and status) as a
       native disclosure: closed it is one line, so the page keeps exactly
       seven rows; open, every form works as before. -->
  <details class="cp-stagework">
    <summary>
      <span class="cp-stagework-title">Stage reviews &amp; hiring actions</span>
      <?php if($pendingWork): ?><span class="pill status-pending"><?=$pendingWork?> to do</span><?php else: ?><span class="meta small">All up to date</span><?php endif; ?>
    </summary>
    <div class="cp-stagework-body">
<?php if($screeningDone): ?>
<section class="cp-subcard" aria-labelledby="sw-screening">
  <h3 id="sw-screening">Screening review</h3>
  <?php if($screeningReview): ?>
    <div class="cprofile-review-summary">
      <span class="pill">Screening completed</span>
      <span class="meta small">Date: <?=e(date('F j, Y', strtotime($screeningReview['created_at'])))?><?php if($screeningReview['reviewer']): ?> · <?=e($screeningReview['reviewer'])?><?php endif; ?></span>
    </div>
    <?php if($screeningReview['rating']!==null): ?><p><strong><?=e((string)$screeningReview['rating'])?> / 100</strong></p><?php endif; ?>
    <?php if($screeningReview['feedback']): ?><p class="cp-mini-label">Screening feedback</p><p><?=nl2br(e($screeningReview['feedback']))?></p><?php endif; ?>
    <?php if($screeningReview['notes']): ?><p class="cp-mini-label">Screening notes</p><p><?=nl2br(e($screeningReview['notes']))?></p><?php endif; ?>
  <?php endif; ?>
  <details class="cp-inline-edit"><summary><?=$screeningReview?'Edit screening review':'Add screening review'?></summary>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="stage_review">
      <input type="hidden" name="stage_type" value="screening">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <div class="field"><label for="sr-rating">Screening rating <span class="hint">0–100</span></label><input id="sr-rating" type="number" min="0" max="100" name="review_rating" value="<?=e($screeningReview['rating']??'')?>"></div>
      <div class="field"><label for="sr-feedback">Screening feedback</label><textarea id="sr-feedback" name="review_feedback"><?=e($screeningReview['feedback']??'')?></textarea></div>
      <div class="field"><label for="sr-notes">Screening notes</label><textarea id="sr-notes" name="review_notes"><?=e($screeningReview['notes']??'')?></textarea></div>
      <button class="btn small" type="submit">Save screening review</button>
    </form>
  </details>
</section>
<?php endif; ?>

<?php if($interviewDone): ?>
<section class="cp-subcard" aria-labelledby="sw-interview">
  <h3 id="sw-interview">Interview review</h3>
  <?php if($interviewReview): ?>
    <div class="cprofile-review-summary">
      <span class="pill">Interview completed</span>
      <span class="meta small">Date: <?=e(date('F j, Y', strtotime($interviewReview['created_at'])))?><?php if($interviewReview['reviewer']): ?> · <?=e($interviewReview['reviewer'])?><?php endif; ?></span>
    </div>
    <?php if($interviewReview['rating']!==null): ?><p><strong><?=e((string)$interviewReview['rating'])?> / 100</strong></p><?php endif; ?>
    <?php if($interviewReview['feedback']): ?><p class="cp-mini-label">Interview feedback</p><p><?=nl2br(e($interviewReview['feedback']))?></p><?php endif; ?>
    <?php if($interviewReview['notes']): ?><p class="cp-mini-label">Interview notes</p><p><?=nl2br(e($interviewReview['notes']))?></p><?php endif; ?>
  <?php endif; ?>
  <details class="cp-inline-edit"><summary><?=$interviewReview?'Edit interview review':'Add interview review'?></summary>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="stage_review">
      <input type="hidden" name="stage_type" value="interview">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <div class="field"><label for="ir-rating">Interview rating <span class="hint">0–100</span></label><input id="ir-rating" type="number" min="0" max="100" name="review_rating" value="<?=e($interviewReview['rating']??'')?>"></div>
      <div class="field"><label for="ir-feedback">Interview feedback</label><textarea id="ir-feedback" name="review_feedback"><?=e($interviewReview['feedback']??'')?></textarea></div>
      <div class="field"><label for="ir-notes">Interview notes</label><textarea id="ir-notes" name="review_notes"><?=e($interviewReview['notes']??'')?></textarea></div>
      <button class="btn small" type="submit">Save interview review</button>
    </form>
  </details>
</section>
<?php endif; ?>

<?php if($candidate['stage']==='hired'): ?>
<section class="cp-subcard" aria-labelledby="sw-hired">
  <h3 id="sw-hired">Hired → Employee</h3>
  <?php if($employeeRecord): ?>
    <div class="cprofile-review-summary">
      <span class="pill">Employee record created</span>
      <span class="meta small">Employee # <?=e($employeeRecord['employee_number'])?> · Started <?=e($employeeRecord['start_date']?date('M j, Y',strtotime($employeeRecord['start_date'])):'—')?></span>
    </div>
    <dl class="cp-kv">
      <div><dt>Applied position</dt><dd><?=e($employeeRecord['applied_position'] ?: $candidate['job_title'])?></dd></div>
      <div><dt>Hired position</dt><dd><?=e($employeeRecord['job_title'])?></dd></div>
      <div><dt>Department</dt><dd><?=e($employeeRecord['department_name'] ?: 'Unassigned')?></dd></div>
    </dl>
    <a class="btn secondary small" href="employees.php">View employee directory →</a>
  <?php else: ?>
    <p class="meta">This candidate was hired. Add them to the employee directory, preserving their recruitment history (application, resume, notes, and reviews stay linked).</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="convert_employee">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <div class="cp-form-2">
        <div class="field"><label for="hp-applied">Applied position</label><input id="hp-applied" value="<?=e($candidate['job_title'])?>" disabled></div>
        <div class="field"><label for="hp-hired">Hired position</label><input id="hp-hired" name="hired_position" value="<?=e($candidate['job_title'])?>" placeholder="<?=e($candidate['job_title'])?>"></div>
        <div class="field"><label for="hp-dept">Department</label><select id="hp-dept" name="department_id"><option value="">Unassigned</option><?php foreach($hireDepartments as $d): ?><option value="<?=$d['id']?>" <?=$candidate['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="hp-num">Employee number <span class="hint">optional — auto-generated if blank</span></label><input id="hp-num" name="employee_number" placeholder="EMP-<?=date('Y')?>-<?=str_pad((string)$candidate['application_id'],4,'0',STR_PAD_LEFT)?>"></div>
        <div class="field"><label for="hp-start">Start date</label><input id="hp-start" type="date" name="start_date" value="<?=date('Y-m-d')?>"></div>
      </div>
      <button class="btn small" type="submit">+ Add to Employees</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
    </div>
  </details>
  <?php endif; ?>
</section>

<!-- ══ ROW 2 · Resume / CV strip, viewer expands beneath ════════════════ -->
<section class="card cp-card cp-resume" id="resume" aria-labelledby="docs-title"
  <?php if($docKind): ?>
         data-doc-viewer data-kind="<?=e($docKind)?>" data-name="<?=e($primaryDoc['original_name'])?>"
         data-src="download.php?file_id=<?=(int)$primaryDoc['id']?>&amp;disposition=inline"
         data-preview="document-preview.php?file_id=<?=(int)$primaryDoc['id']?>"
         data-download="download.php?file_id=<?=(int)$primaryDoc['id']?>"
  <?php endif; ?>>
  <div class="cp-strip">
    <div class="cp-strip-label">
      <h2 id="docs-title">Resume / CV</h2>
      <?php if($primaryDoc): ?>
        <span class="cp-strip-meta">
          <span class="file-icon <?=e(document_icon_class($primaryDoc['extension']))?>" aria-hidden="true"><?=e(strtoupper($primaryDoc['extension']))?></span>
          <span class="cp-filename"><?=e($primaryDoc['original_name'])?></span>
          <span class="meta small"><?=e(format_bytes((int)$primaryDoc['byte_size']))?><?php if ($resumeIndexed): ?> · <span class="resume-indexed" title="The text of this resume was extracted and is searchable">text indexed</span><?php endif; ?></span>
        </span>
      <?php elseif(legacy_resume_url($candidate['resume_path'])): ?>
        <span class="meta small">Legacy upload — not yet migrated to document versioning</span>
      <?php else: ?>
        <span class="meta small">No resume on file</span>
      <?php endif; ?>
    </div>
    <div class="cp-strip-actions">
      <?php if($docKind): ?>
        <button type="button" class="btn secondary" data-doc-toggle aria-expanded="false" aria-controls="resume-viewer"><?=icon('image',15)?> View</button>
        <span class="cp-strip-sep" aria-hidden="true">|</span>
      <?php endif; ?>
      <?php if($primaryDoc): ?>
        <a class="btn" href="download.php?file_id=<?=(int)$primaryDoc['id']?>"><?=icon('link',15)?> Download</a>
      <?php elseif(legacy_resume_url($candidate['resume_path'])): ?>
        <a class="btn" href="<?=e(legacy_resume_url($candidate['resume_path']))?>" target="_blank" rel="noopener"><?=icon('link',15)?> Open file</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if($primaryDoc && !$docKind): ?>
    <p class="meta small cp-strip-note">Preview isn't available for .<?=e($primaryDoc['extension'])?> (the pre-2007 Word format). Download it to read the full resume.</p>
  <?php endif; ?>

  <?php if($docKind): ?>
  <!-- Nothing here is fetched until View is pressed (assets/doc-viewer.js). -->
  <div class="cp-viewer" id="resume-viewer" data-doc-panel hidden>
    <p class="cp-viewer-status meta small" data-doc-status role="status" aria-live="polite"></p>
    <div class="cp-viewer-skeleton" data-doc-skeleton hidden aria-hidden="true">
      <span class="cp-spinner"></span>
      <span class="skel-line" style="width:42%"></span>
      <span class="skel-line" style="width:88%"></span>
      <span class="skel-line" style="width:76%"></span>
      <span class="skel-line" style="width:64%"></span>
      <span class="skel-line" style="width:82%"></span>
    </div>
    <!-- Scrolls on its own and holds only canvases, so it takes focus itself
         (tabindex) and keyboard users can scroll it with the arrow keys. -->
    <div class="cp-viewer-pages" data-doc-pages tabindex="0" role="region" aria-label="Resume preview"></div>
    <noscript><p class="meta">Previews need JavaScript. Use Download above.</p></noscript>
  </div>
  <?php endif; ?>

  <?php if(count($documents) > 1): ?>
    <details class="cp-versions">
      <summary><?=count($documents)?> versions on file</summary>
      <ul class="doc-list">
        <?php foreach ($documents as $doc): ?>
          <li class="file-row<?= $doc['is_primary'] ? ' is-primary' : '' ?>">
            <span class="file-icon <?=e(document_icon_class($doc['extension']))?>" aria-hidden="true"><?=e(strtoupper($doc['extension']))?></span>
            <span class="file-meta">
              <span class="file-name"><?=e($doc['original_name'])?><?php if ($doc['is_primary']): ?> <span class="doc-current">Current</span><?php endif; ?></span>
              <span class="file-sub"><?=e(format_bytes((int)$doc['byte_size']))?> · uploaded <?=e(date('M j, Y', strtotime($doc['created_at'])))?><?php if ($doc['uploader']): ?> by <?=e($doc['uploader'])?><?php endif; ?></span>
            </span>
            <a class="file-act" href="download.php?file_id=<?=(int)$doc['id']?>" aria-label="Download <?=e($doc['original_name'])?>"><?=icon('link',15)?></a>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="meta small">Older versions are kept rather than overwritten, so the history stays intact.</p>
    </details>
  <?php endif; ?>
</section>

<!-- ══ ROW 3 · Personal Info | Application Info | Recruiter Notes ════════ -->
<div class="cp-row cp-row-3" id="candidate-info">
  <section class="card cp-card" aria-labelledby="h-personal">
    <h2 class="cp-card-title" id="h-personal">Personal info</h2>
    <dl class="cp-kv">
      <div><dt>Email</dt><dd><?=e($candidate['email'])?></dd></div>
      <div><dt>Phone</dt><dd><?=e($candidate['phone'] ?: 'Not provided')?></dd></div>
      <div><dt>Source</dt><dd><?=e($sourceLabel)?></dd></div>
      <?php if (!empty($candidate['current_title'])): ?><div><dt>Current role</dt><dd><?=e($candidate['current_title'])?></dd></div><?php endif; ?>
      <?php if (!empty($candidate['experience_level'])): $levelLabels = ['entry'=>'Entry level','mid'=>'Mid level','senior'=>'Senior','lead'=>'Lead / Principal']; ?>
        <div><dt>Experience</dt><dd><?=e($levelLabels[$candidate['experience_level']] ?? ucfirst($candidate['experience_level']))?></dd></div>
      <?php endif; ?>
      <?php if (!empty($candidate['education'])): ?><div><dt>Education</dt><dd><?=e($candidate['education'])?></dd></div><?php endif; ?>
      <?php if (!empty($candidate['consent_at'])): ?><div><dt>Consent given</dt><dd><?=e(date('M j, Y', strtotime($candidate['consent_at'])))?></dd></div><?php endif; ?>
    </dl>
    <?php if (!empty($candidate['skills'])): ?>
      <p class="cp-mini-label">Skills</p>
      <div class="tag-row">
        <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $candidate['skills']))), 0, 20) as $sk): ?>
          <span class="tag-pill"><?=e($sk)?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card cp-card" aria-labelledby="h-application">
    <h2 class="cp-card-title" id="h-application">Application info</h2>
    <dl class="cp-kv">
      <div><dt>Position</dt><dd><?=e($candidate['job_title'])?></dd></div>
      <div><dt>Department</dt><dd><?=e($candidate['department'] ?: 'Not set')?></dd></div>
      <?php if (!empty($candidate['job_location'])): ?><div><dt>Location</dt><dd><?=e($candidate['job_location'])?></dd></div><?php endif; ?>
      <?php if (!empty($candidate['employment_type'])): ?><div><dt>Employment type</dt><dd><?=e(ucwords(str_replace('_',' ',$candidate['employment_type'])))?></dd></div><?php endif; ?>
      <div><dt>Assigned to</dt><dd><?=e($candidate['assigned_name'] ?? 'Unassigned')?></dd></div>
      <?php if (!empty($candidate['app_status']) && $candidate['app_status'] !== 'active'): ?>
        <div><dt>Application status</dt><dd><span class="pill status-<?=e($candidate['app_status'])?>"><?=e(ucfirst($candidate['app_status']))?></span></dd></div>
      <?php endif; ?>
      <?php if (!empty($candidate['record_status']) && $candidate['record_status'] === 'draft'): ?>
        <div><dt>Record</dt><dd><span class="pill status-pending">Draft — not yet submitted</span></dd></div>
      <?php endif; ?>
      <div><dt>Applied</dt><dd><?=e(date('M j, Y', strtotime($candidate['applied_at'])))?></dd></div>
      <div><dt>Current stage</dt><dd><?=stage_badge($candidate['stage'])?></dd></div>
    </dl>
    <form method="post" class="cp-stage-form">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="stage">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <label class="sr-only" for="cp-stage-select">Move to stage</label>
      <select id="cp-stage-select" name="stage">
        <?php foreach ($stageLabels as $key=>$label): ?>
          <option value="<?=e($key)?>" <?= $candidate['stage']===$key?'selected':'' ?>><?=e($label)?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn secondary" type="submit">Update stage</button>
    </form>
  </section>

  <section class="card cp-card" aria-labelledby="h-notes">
    <h2 class="cp-card-title" id="h-notes">Recruiter notes</h2>
    <?php if (!empty($candidate['candidate_notes'])): ?>
      <div class="intake-note">
        <span class="meta small">Captured when this candidate was added</span>
        <p><?=nl2br(e($candidate['candidate_notes']))?></p>
      </div>
    <?php endif; ?>
    <ul class="cp-notes">
      <?php foreach ($notes as $n): ?>
        <li><p><?=nl2br(e($n['note']))?></p><span class="meta small"><?=e($n['author'] ?? 'Recruiter')?> · <?=e(time_ago($n['created_at']))?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if(!$notes): ?><p class="meta">No notes yet.</p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="note">
      <input type="hidden" name="candidate_id" value="<?=$candidate['candidate_id']?>">
      <input type="hidden" name="anchor" value="candidate-info">
      <div class="field"><label for="cp-note">Add a note</label><textarea id="cp-note" name="note" required placeholder="Write your note here..."></textarea></div>
      <button class="btn secondary" type="submit">+ Add note</button>
    </form>
  </section>
</div>

<!-- ══ ROW 4 · AI Application Analysis ═══════════════════════════════════ -->
<section class="card cp-card" id="ai-analysis" aria-labelledby="h-ai">
  <div class="cprofile-ai-head">
    <div>
      <h2 class="cp-card-title" id="h-ai">AI application analysis <span class="hint">AI simulation / prototype</span></h2>
      <p class="meta small">Generated from this candidate's application text, resume presence, and job match — a prototype heuristic, not a real assessment.</p>
    </div>
    <form method="post" id="ai-analyze-form">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="ai_analyze">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <input type="hidden" name="anchor" value="ai-analysis">
      <button class="btn secondary" type="submit" id="ai-analyze-btn"><?=icon('sparkle',14)?> <?=$aiAnalysis?'Re-analyze application':'Analyze application'?></button>
    </form>
  </div>

  <div id="ai-analyzing-panel" class="cprofile-ai-loading" hidden>
    <div class="cprofile-ai-checklist" id="ai-checklist" role="status" aria-live="polite">
      <div data-step>Reading application</div>
      <div data-step>Reviewing resume</div>
      <div data-step>Reviewing cover letter</div>
      <div data-step>Analyzing application answers</div>
      <div data-step>Reviewing recruiter notes</div>
      <div data-step>Comparing skills with job requirements</div>
    </div>
  </div>

  <?php if($aiAnalysis): ?>
    <div class="cprofile-ai-body">
      <div class="cprofile-ai-score">
        <div class="cprofile-ai-score-num"><?=e((string)$aiAnalysis['overall_score'])?><span>/100</span></div>
        <div class="cprofile-ai-score-label"><?= $aiAnalysis['overall_score']>=80?'Strong candidate':($aiAnalysis['overall_score']>=65?'Promising candidate':'Developing candidate') ?></div>
        <div class="cprofile-ai-bar" role="img" aria-label="Score <?=e((string)$aiAnalysis['overall_score'])?> out of 100"><span style="width:<?=(int)$aiAnalysis['overall_score']?>%"></span></div>
        <div class="pill cp-ai-reco"><?=e($aiAnalysis['recommendation'])?></div>
        <div class="meta small">Generated <?=e(time_ago($aiAnalysis['generated_at']))?></div>
        <div class="cp-ai-cats">
          <?php foreach($aiAnalysis['category_scores'] as $catLabel=>$catScore): ?>
            <div class="cprofile-cat-row"><span><?=e($catLabel)?></span><span><?=e((string)round($catScore))?>%</span></div>
            <div class="cap-bar"><span style="width:<?=(int)round($catScore)?>%"></span></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <p class="cp-mini-label">AI summary</p>
        <p><?=nl2br(e($aiAnalysis['summary']))?></p>
        <div class="cprofile-ai-list-grid">
          <div>
            <p class="cp-mini-label">Strengths</p>
            <ul class="cprofile-ai-list"><?php foreach($aiAnalysis['strengths'] as $s): ?><li><?=e($s)?></li><?php endforeach; ?></ul>
          </div>
          <div>
            <p class="cp-mini-label">Concerns</p>
            <ul class="cprofile-ai-list"><?php foreach($aiAnalysis['concerns'] as $c): ?><li><?=e($c)?></li><?php endforeach; ?></ul>
          </div>
        </div>
      </div>
    </div>
    <div class="cp-subcard cp-ai-notes">
      <p class="cp-mini-label">AI analysis notes</p>
      <p><?=e($aiAnalysis['ai_notes'])?></p>
    </div>
    <p class="meta small">AI-generated analysis is a prototype recommendation and should be reviewed by a recruiter before making a hiring decision.</p>
  <?php else: ?>
    <p class="meta">No AI analysis yet. Select "Analyze application" to generate one.</p>
  <?php endif; ?>
</section>

<!-- ══ ROW 5 · Feedback for candidate | Suggest a different role ═════════ -->
<?php if($screeningDone): ?>
<div class="cp-row cp-row-2">
  <section class="card cp-card" aria-labelledby="h-feedback">
    <h2 class="cp-card-title" id="h-feedback">Feedback for candidate</h2>
    <ul class="cp-notes">
      <?php foreach ($feedbackList as $fb): ?>
        <li><span class="pill"><?=e(ucwords(str_replace('-',' ',$fb['fit'])))?></span><p><?=nl2br(e($fb['notes']))?></p><span class="meta small"><?=e($fb['author'] ?? 'Recruiter')?> · <?=e(time_ago($fb['created_at']))?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if(!$feedbackList): ?><p class="meta">No feedback recorded yet.</p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="feedback">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <div class="field"><label for="fb-fit">Fit assessment</label><select id="fb-fit" name="fit"><option value="strong-fit">Strong fit</option><option value="potential-fit">Potential fit</option><option value="not-a-fit">Not a fit for this role</option></select></div>
      <div class="field"><label for="fb-notes">Areas for improvement</label><textarea id="fb-notes" name="notes" placeholder="What could the candidate improve?"></textarea></div>
      <button class="btn secondary" type="submit">Save feedback</button>
    </form>
  </section>

  <section class="card cp-card" aria-labelledby="h-suggest">
    <h2 class="cp-card-title" id="h-suggest">Suggest a different role</h2>
    <ul class="cp-notes">
      <?php foreach ($suggestions as $s): ?>
        <li><strong><?=e($s['suggested_title'])?></strong><?php if($s['note']): ?><p><?=nl2br(e($s['note']))?></p><?php endif; ?><span class="meta small"><?=e($s['author'] ?? 'Recruiter')?> · <?=e(time_ago($s['created_at']))?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if(!$suggestions): ?><p class="meta">No suggestions yet.</p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="suggest">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <div class="field"><label for="sg-role">Other open role</label><select id="sg-role" name="job_id" required><option value="">Select a role</option><?php foreach ($openRoles as $role): ?><option value="<?=$role['id']?>"><?=e($role['title'])?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="sg-note">Why this role? <span class="hint">optional</span></label><textarea id="sg-note" name="note"></textarea></div>
      <button class="btn secondary" type="submit">Save suggestion</button>
    </form>
  </section>
</div>
<?php else: ?>
<!-- Row 5 still occupies its place in the stack while locked, so the page
     order never shifts between stages. -->
<div class="cp-row cp-row-2 is-locked">
  <p class="card cp-card cp-locked"><?=icon('clock',14)?> Candidate feedback and role suggestions unlock once this candidate moves past Applied.</p>
</div>
<?php endif; ?>

<!-- ══ ROW 6 · Interview History ═════════════════════════════════════════ -->
<section class="card cp-card cprofile-interviews" id="interview-history" aria-labelledby="h-interviews">
  <div class="cp-card-head">
    <h2 class="cp-card-title" id="h-interviews">Interview history</h2>
    <span class="meta"><?=count($interviewHistory)?> on record</span>
  </div>

  <?php if (!$interviewHistory): ?>
    <p class="meta">No interviews have been scheduled for this candidate yet.</p>
  <?php endif; ?>

  <div class="ih-stack">
  <?php foreach ($interviewHistory as $iv): $ivState = interview_state($iv); ?>
    <article class="ih-item state-<?=e($ivState)?>">
      <div class="ih-head">
        <div>
          <h3><?=e(ucfirst($iv['meeting_type']))?> interview</h3>
          <div class="meta small"><?=e(date('F j, Y · g:i A', strtotime($iv['starts_at'])))?> · <?=e(ucfirst($iv['interview_type']))?><?php if($iv['job_title']): ?> · <?=e($iv['job_title'])?><?php endif; ?></div>
          <div class="meta small">Interviewer: <?=e($iv['interviewer_name'] ?? 'Unassigned')?></div>
        </div>
        <div class="ih-status">
          <?= interview_state_badge($iv) ?>
          <?php if ($iv['score'] !== null): ?><span class="score-pill"><?=icon('star',11)?> <?=(int)$iv['score']?> / 100</span><?php endif; ?>
        </div>
      </div>

      <?php if (trim((string)($iv['live_notes'] ?? '')) !== ''): ?>
        <div class="ih-block">
          <h4>Interview notes</h4>
          <p><?=nl2br(e($iv['live_notes']))?></p>
          <span class="meta small">Recorded during the meeting</span>
        </div>
      <?php endif; ?>

      <?php if (trim((string)($iv['feedback'] ?? '')) !== '' || $iv['recommendation']): ?>
        <div class="ih-block is-final">
          <h4>Final review</h4>
          <?php if (trim((string)($iv['feedback'] ?? '')) !== ''): ?><p><?=nl2br(e($iv['feedback']))?></p><?php endif; ?>
          <?php if ($iv['recommendation']): ?>
            <p class="ih-reco"><span class="label">Recommendation</span><strong><?=e(interview_recommendation_label($iv['recommendation']))?></strong></p>
          <?php endif; ?>
          <span class="meta small">Submitted<?php if($iv['reviewer_name']): ?> by <?=e($iv['reviewer_name'])?><?php endif; ?><?php if($iv['reviewed_at']): ?> · <?=e(date('M j, Y', strtotime($iv['reviewed_at'])))?><?php endif; ?></span>
        </div>
      <?php elseif ($ivState === 'review_pending'): ?>
        <p class="meta small ih-pending"><?=icon('clock',12)?> The meeting has ended and is waiting for a score and review.</p>
      <?php endif; ?>

      <?php if ($iv['room_code']): ?>
        <div class="ih-actions"><a class="btn small secondary" href="interview-room.php?code=<?=urlencode($iv['room_code'])?>"><?=icon('video',13)?> Open room</a></div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
</section>

<!-- ══ ROW 7 · History / Activity ════════════════════════════════════════ -->
<section class="card cp-card cprofile-activity" id="activity-log" data-activity aria-labelledby="h-activity">
  <div class="cp-card-head">
    <h2 class="cp-card-title" id="h-activity">History / activity</h2>
    <button type="button" class="collapse-btn" data-activity-toggle aria-expanded="true" aria-controls="activity-body" aria-label="Collapse activity">&minus;</button>
  </div>
  <div id="activity-body" data-activity-body>
    <ul class="cp-activity">
      <?php foreach ($activity as $a):
        $details = $a['details'] ? json_decode($a['details'], true) : null;
        if ($a['action'] === 'pipeline_stage_move' && $details) {
          $from = $activityStageLabels[$details['from'] ?? ''] ?? ($details['from'] ?? '?');
          $to = $activityStageLabels[$details['to'] ?? ''] ?? ($details['to'] ?? '?');
          $line = "Moved from {$from} → {$to}" . (!empty($details['override']) ? ' (admin override)' : '');
        } else {
          $line = $actionLabels[$a['action']] ?? ucfirst(str_replace('_',' ',$a['action']));
        }
      ?>
        <li><span><?=e($line)?></span><span class="meta small"><?=e($a['actor'] ?? 'System')?> · <?=e(time_ago($a['created_at']))?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if(!$activity): ?><p class="meta">No activity recorded yet.</p><?php endif; ?>
  </div>
</section>

</div><!-- /.cp-rows -->
</div><!-- /.candidate-profile-page -->

<script>
/* "Analyze" plays a short checklist before submitting. The analysis itself
   runs on the server in the POST handler. */
(function(){
  const form = document.getElementById('ai-analyze-form');
  const panel = document.getElementById('ai-analyzing-panel');
  const checklist = document.getElementById('ai-checklist');
  if (!form || !panel || !checklist) return;
  let animating = false;
  form.addEventListener('submit', function(e){
    if (animating) return; // the second submit (after the animation) goes through
    e.preventDefault();
    panel.hidden = false;
    const steps = checklist.querySelectorAll('[data-step]');
    steps.forEach(s => s.classList.remove('done'));
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    let i = 0;
    const tick = () => {
      if (i < steps.length) { steps[i].classList.add('done'); i++; setTimeout(tick, reduce ? 0 : 320); }
      else { setTimeout(() => { animating = true; form.submit(); }, reduce ? 0 : 400); }
    };
    tick();
  });
})();

/* Activity collapses to keep the profile scannable; only the list is hidden,
   and the choice is remembered per candidate. */
(function () {
  var section = document.querySelector('[data-activity]');
  if (!section) return;
  var btn  = section.querySelector('[data-activity-toggle]');
  var body = section.querySelector('[data-activity-body]');
  if (!btn || !body) return;
  var KEY = 'acme-activity-open:' + <?=json_encode((string)$candidate['candidate_id'])?>;
  function setOpen(open) {
    body.hidden = !open;
    section.classList.toggle('is-collapsed', !open);
    btn.innerHTML = open ? '&minus;' : '&plus;';
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'Collapse activity' : 'Expand activity');
    try { localStorage.setItem(KEY, open ? '1' : '0'); } catch (e) {}
  }
  btn.addEventListener('click', function () { setOpen(body.hidden); });
  var saved = '1';
  try { saved = localStorage.getItem(KEY) || '1'; } catch (e) {}
  setOpen(saved === '1');
})();
</script>
<?php if($docKind): ?>
<script src="assets/doc-viewer.js?v=<?= @filemtime(__DIR__.'/assets/doc-viewer.js') ?: time() ?>"></script>
<?php endif; ?>

<?php include __DIR__.'/includes/footer.php'; ?>
