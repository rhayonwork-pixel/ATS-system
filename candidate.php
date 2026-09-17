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
    header('Location: candidate.php?id='.$postedApp.(isset($_POST['anchor'])?'#'.$_POST['anchor']:'')); exit;
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
<div class="candidate-container">
<a class="meta" href="candidates.php">← Back to candidates</a>
<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>

<!-- Size container: the two-column split reacts to the width left after the
     sidebar, not to the viewport. -->
<div class="page-container candidate-profile-page">

<!-- Quick navigation. Plain anchor links, not tabs: every section below
     stays fully visible and reachable by scrolling regardless of which link
     was clicked -- clicking one only moves the viewport, it does not hide
     the others. Not sticky/fixed, per the earlier wireframe direction for
     this page: it scrolls away with the rest of the header once you start
     reading, rather than pinning itself over the content. -->
<nav class="cprofile-quicknav" aria-label="Jump to a section of this profile">
  <a href="#candidate-info">Profile &amp; Contact</a>
  <a href="#docs-title">Resume / CV</a>
  <a href="#ai-analysis">AI Analysis</a>
  <a href="#interview-history">Interview History</a>
  <a href="#activity-log">Activity</a>
</nav>



<div class="cprofile-head card cprofile-snapshot">
  <?php if(!empty($candidate['profile_image'])): ?><img class="avatar-circle avatar-image-large" src="<?=e($candidate['profile_image'])?>" alt="<?=e($candidate['first_name'].' '.$candidate['last_name'])?>"><?php else: ?><div class="avatar-circle"><?=e($initials)?></div><?php endif; ?>
  <div style="flex:1">
    <div class="eyebrow">Candidate profile</div>
    <div class="cprofile-name-row">
      <h1><?=e($candidate['first_name'].' '.$candidate['last_name'])?></h1>
      <?php $r=(int)$candidate['rating']; ?>
      <?php if($screeningDone): ?>
        <!-- The rating lives beside the name, and writes to the same
             candidates.rating column as before — no second score is created. -->
        <form method="post" class="rating-inline" title="Click a star to rate">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="rating">
          <input type="hidden" name="candidate_id" value="<?=$candidate['candidate_id']?>">
          <?php for($i=1;$i<=5;$i++): ?>
            <button type="submit" name="rating" value="<?=$i?>" class="star-btn <?= $i<=$r?'on':'' ?>" aria-label="Rate <?=$i?> out of 5"><?=$i<=$r?'★':'☆'?></button>
          <?php endfor; ?>
          <span class="rating-value"><?= $r>0 ? e((string)$r).' / 5' : 'Not yet rated' ?></span>
        </form>
      <?php elseif($r>0): ?>
        <span class="rating-inline is-readonly"><?php for($i=1;$i<=5;$i++): ?><span class="star-btn <?= $i<=$r?'on':'' ?>"><?=$i<=$r?'★':'☆'?></span><?php endfor; ?><span class="rating-value"><?=e((string)$r)?> / 5</span></span>
      <?php else: ?>
        <span class="rating-inline is-readonly"><span class="rating-value muted">Rating: not yet rated</span></span>
      <?php endif; ?>
    </div>
    <p class="meta cprofile-subline"><?=e($candidate['job_title'])?><?php if($candidate['department']): ?> · <?=e($candidate['department'])?><?php endif; ?> · <?=e($candidate['email'])?></p>
    <div class="cprofile-head-badges">
      <?=stage_badge($candidate['stage'])?>
      <?php if($aiAnalysis): ?><span class="candidate-card-score">AI score <?=e((string)$aiAnalysis['overall_score'])?>/100</span><?php endif; ?>
    </div>

    <?php if($candidate['stage']==='rejected'): ?>
      <div class="stage-stepper"><div class="stage-step rejected"><span class="dot">✕</span>Rejected</div></div>
    <?php else:
      $stepperStages = ['new'=>'Applied','screening'=>'Screening','interview'=>'Interview','offer'=>'Offer','hired'=>'Hired'];
      $stepperKeys = array_keys($stepperStages); $curIdx = array_search($candidate['stage'], $stepperKeys, true) ?: 0;
    ?>
      <div class="stage-stepper">
        <?php foreach($stepperStages as $key=>$label): $idx = array_search($key,$stepperKeys,true); $cls = $idx < $curIdx ? 'done' : ($idx === $curIdx ? 'current' : ''); ?>
          <?php if($idx>0): ?><span class="stage-connector <?=$idx<=$curIdx?'done':''?>"></span><?php endif; ?>
          <span class="stage-step <?=$cls?>"><span class="dot"><?= $idx < $curIdx ? '✓' : $idx+1 ?></span><?=e($label)?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="cprofile-appnum">Application #<?=e((string)$candidate['application_id'])?></div>
</div>

<section class="card cprofile-documents" aria-labelledby="docs-title">
  <div class="section-head resume-head">
    <div>
      <div class="eyebrow">Documents</div>
      <h2 id="docs-title">Resume / CV</h2>
      <?php if ($primaryDoc): ?>
        <p class="meta small"><?=e($primaryDoc['original_name'])?> ·
          <?=e(strtoupper($primaryDoc['extension']))?> ·
          <?=e(format_bytes((int)$primaryDoc['byte_size']))?>
          <?php if (count($documents) > 1): ?> · <?=count($documents)?> versions<?php endif; ?>
          <?php if ($resumeIndexed): ?> · <span class="resume-indexed" title="The text of this resume was extracted and is searchable">text indexed</span><?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
    <?php if ($primaryDoc): ?>
      <div class="resume-actions">
        <?php if ($primaryDoc['extension'] === 'pdf'): ?>
          <button type="button" class="btn small secondary" data-resume-toggle
                  aria-expanded="false" aria-controls="resume-viewer"><?=icon('image',14)?> View (PDF)</button>
        <?php endif; ?>
        <a class="btn small" href="download.php?file_id=<?=(int)$primaryDoc['id']?>"><?=icon('link',14)?> Download</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$primaryDoc && $candidate['resume_path']): ?>
    <!-- A candidate from before candidate_documents existed. This is the one
         place resume_path is ever read or displayed — the record is not
         duplicated into a second UI component, only surfaced here as a
         fallback until it is migrated. -->
    <div class="doc-empty is-word">
      <span class="doc-empty-icon" aria-hidden="true"><?=icon('doc',24)?></span>
      <strong>Legacy upload</strong>
      <span class="meta small">This resume predates document versioning and has not been migrated yet.</span>
      <a class="btn small" href="<?=e($candidate['resume_path'])?>" target="_blank" rel="noopener"><?=icon('link',14)?> Open file</a>
    </div>
  <?php elseif (!$primaryDoc): ?>
    <div class="doc-empty">
      <span class="doc-empty-icon" aria-hidden="true"><?=icon('doc',24)?></span>
      <strong>No resume on file</strong>
      <span class="meta small">Nothing has been uploaded for this candidate yet.</span>
    </div>
  <?php else: ?>

    <?php if ($primaryDoc['extension'] === 'pdf'): ?>
      <!-- Served by download.php with an inline disposition, so the file stays
           behind the authorisation check rather than being exposed by URL.
           The skeleton below is real, not decorative: this iframe is the one
           thing on this page that loads asynchronously (the src is withheld
           until View is pressed, so a profile that is only skimmed never
           fetches the PDF). The skeleton is shown for exactly as long as that
           load actually takes and removed on the iframe's own load event. -->
      <div class="doc-viewer-frame" id="resume-viewer" data-resume-panel hidden>
        <div class="doc-viewer-skeleton" data-resume-skeleton aria-hidden="true">
          <span class="skel-line" style="width:40%"></span>
          <span class="skel-line" style="width:85%"></span>
          <span class="skel-line" style="width:70%"></span>
          <span class="skel-line" style="width:60%"></span>
        </div>
        <iframe src="download.php?file_id=<?=(int)$primaryDoc['id']?>&disposition=inline#toolbar=1"
                title="Resume for <?=e($candidate['first_name'].' '.$candidate['last_name'])?>"
                loading="lazy" data-resume-iframe></iframe>
        <noscript><p class="meta">Open the resume using the Download button above.</p></noscript>
      </div>
    <?php else: ?>
      <!-- Browsers cannot render Word documents inline, so the honest answer is
           a download rather than a viewer that would not work. -->
      <div class="doc-empty is-word">
        <span class="doc-empty-icon" aria-hidden="true"><?=icon('doc',24)?></span>
        <strong><?=e(strtoupper($primaryDoc['extension']))?> document</strong>
        <span class="meta small">Word documents cannot be previewed in the browser. Download it to read the full resume.</span>
        <a class="btn small" href="download.php?file_id=<?=(int)$primaryDoc['id']?>"><?=icon('link',14)?> Download <?=e($primaryDoc['original_name'])?></a>
      </div>
    <?php endif; ?>

    <div class="doc-list">
      <?php foreach ($documents as $i => $doc): ?>
        <div class="file-row<?= $doc['is_primary'] ? ' is-primary' : '' ?>">
          <span class="file-icon <?=e(document_icon_class($doc['extension']))?>" aria-hidden="true"><?=e(strtoupper($doc['extension']))?></span>
          <span class="file-meta">
            <span class="file-name"><?=e($doc['original_name'])?>
              <?php if ($doc['is_primary']): ?><span class="doc-current">Current</span><?php endif; ?>
            </span>
            <span class="file-sub">
              <?=e(format_bytes((int)$doc['byte_size']))?> ·
              uploaded <?=e(date('M j, Y', strtotime($doc['created_at'])))?>
              <?php if ($doc['uploader']): ?> by <?=e($doc['uploader'])?><?php endif; ?>
            </span>
          </span>
          <span class="file-actions">
            <a class="file-act" href="download.php?file_id=<?=(int)$doc['id']?>" title="Download" aria-label="Download <?=e($doc['original_name'])?>"><?=icon('link',15)?></a>
          </span>
        </div>
      <?php endforeach; ?>
      <?php if (count($documents) > 1): ?>
        <p class="meta small">Older versions are kept rather than overwritten, so the history stays intact.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
</div>

<div class="cprofile-layout">
<div class="cprofile-main">
<div class="cprofile-grid-3" id="candidate-info">
  <div class="card">
    <div class="label">Personal information</div>
    <div class="cprofile-kv"><span>Email</span><span><?=e($candidate['email'])?></span></div>
    <div class="cprofile-kv"><span>Phone</span><span><?=e($candidate['phone'] ?: 'Not provided')?></span></div>
    <div class="cprofile-kv"><span>Source</span><span><?=e($sourceLabel)?></span></div>
    <?php if (!empty($candidate['current_title'])): ?>
      <div class="cprofile-kv"><span>Current role</span><span><?=e($candidate['current_title'])?></span></div>
    <?php endif; ?>
    <?php if (!empty($candidate['experience_level'])):
        $levelLabels = ['entry'=>'Entry level','mid'=>'Mid level','senior'=>'Senior','lead'=>'Lead / Principal']; ?>
      <div class="cprofile-kv"><span>Experience</span><span><?=e($levelLabels[$candidate['experience_level']] ?? ucfirst($candidate['experience_level']))?></span></div>
    <?php endif; ?>
    <?php if (!empty($candidate['education'])): ?>
      <div class="cprofile-kv"><span>Education</span><span><?=e($candidate['education'])?></span></div>
    <?php endif; ?>
    <?php if (!empty($candidate['skills'])): ?>
      <div class="cprofile-kv is-stacked"><span>Skills</span>
        <span class="tag-row">
          <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $candidate['skills']))), 0, 20) as $sk): ?>
            <span class="tag-pill"><?=e($sk)?></span>
          <?php endforeach; ?>
        </span>
      </div>
    <?php endif; ?>
    <?php if (!empty($candidate['consent_at'])): ?>
      <div class="cprofile-kv"><span>Consent given</span><span><?=e(date('M j, Y', strtotime($candidate['consent_at'])))?></span></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="label">Application information</div>
    <div class="cprofile-kv"><span>Position</span><span><?=e($candidate['job_title'])?></span></div>
    <div class="cprofile-kv"><span>Department</span><span><?=e($candidate['department'] ?: 'Not set')?></span></div>
    <?php if (!empty($candidate['job_location'])): ?>
      <div class="cprofile-kv"><span>Location</span><span><?=e($candidate['job_location'])?></span></div>
    <?php endif; ?>
    <?php if (!empty($candidate['employment_type'])): ?>
      <div class="cprofile-kv"><span>Employment type</span><span><?=e(ucwords(str_replace('_',' ',$candidate['employment_type'])))?></span></div>
    <?php endif; ?>
    <div class="cprofile-kv"><span>Assigned to</span><span><?=e($candidate['assigned_name'] ?? 'Unassigned')?></span></div>
    <?php if (!empty($candidate['app_status']) && $candidate['app_status'] !== 'active'): ?>
      <div class="cprofile-kv"><span>Application status</span><span class="pill status-<?=e($candidate['app_status'])?>"><?=e(ucfirst($candidate['app_status']))?></span></div>
    <?php endif; ?>
    <?php if (!empty($candidate['record_status']) && $candidate['record_status'] === 'draft'): ?>
      <div class="cprofile-kv"><span>Record</span><span class="pill status-pending">Draft — not yet submitted</span></div>
    <?php endif; ?>

    <?php /* Folded in from the old "Application & links" card, so the wireframe
             keeps three columns instead of four. Resume rows were dropped: the
             Resume / CV section above is the single place a document is
             offered. */ ?>

    <div class="cprofile-kv"><span>Applied</span><span><?=e(date('M j, Y', strtotime($candidate['applied_at'])))?></span></div>
    <div class="cprofile-kv"><span>Current stage</span><span><?=stage_badge($candidate['stage'])?></span></div>
    <form method="post" class="inline-form" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="stage">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <select name="stage">
        <?php foreach ($stageLabels as $key=>$label): ?>
          <option value="<?=e($key)?>" <?= $candidate['stage']===$key?'selected':'' ?>><?=e($label)?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn small">Update stage</button>
    </form>
  </div>
  <div class="card">
  <div class="label">Recruiter notes</div>
    <?php if (!empty($candidate['candidate_notes'])): ?>
      <div class="intake-note">
        <span class="meta small">Captured when this candidate was added</span>
        <p><?=nl2br(e($candidate['candidate_notes']))?></p>
      </div>
    <?php endif; ?>
  <div class="list-stack" style="margin:12px 0">
    <?php foreach ($notes as $n): ?>
      <div class="list-row" style="display:block"><p style="margin:0 0 4px"><?=nl2br(e($n['note']))?></p><span class="meta small"><?=e($n['author'] ?? 'Recruiter')?> · <?=e(time_ago($n['created_at']))?></span></div>
    <?php endforeach; if(!$notes): ?><p class="meta">No notes yet.</p><?php endif; ?>
  </div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="note">
    <input type="hidden" name="candidate_id" value="<?=$candidate['candidate_id']?>">
    <div class="field"><label>Add a note</label><textarea name="note" required placeholder="Write your note here..."></textarea></div>
    <button class="btn small" type="submit">+ Add Note</button>
  </form>
</div>
</div><!-- /.cprofile-grid-3 -->

<div class="card cprofile-section" id="ai-analysis">
  <div class="cprofile-ai-head">
    <div>
      <div class="label">AI application analysis <span class="hint">AI Simulation / Prototype</span></div>
      <p class="meta small" style="margin-top:4px">Generated from this candidate's application text, resume presence, and job match — a prototype heuristic, not a real assessment.</p>
    </div>
    <form method="post" id="ai-analyze-form">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="ai_analyze">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <input type="hidden" name="anchor" value="ai-analysis">
      <button class="btn secondary small" type="submit" id="ai-analyze-btn"><?=icon('sparkle',14)?> <?=$aiAnalysis?'Re-analyze Application':'Analyze Application'?></button>
    </form>
  </div>

  <div id="ai-analyzing-panel" class="cprofile-ai-loading" hidden>
    <div class="cprofile-ai-checklist" id="ai-checklist">
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
        <div class="cprofile-ai-score-label"><?= $aiAnalysis['overall_score']>=80?'Strong Candidate':($aiAnalysis['overall_score']>=65?'Promising Candidate':'Developing Candidate') ?></div>
        <div class="cprofile-ai-bar"><span style="width:<?=$aiAnalysis['overall_score']?>%"></span></div>
        <div class="pill" style="margin-top:12px"><?=e($aiAnalysis['recommendation'])?></div>
        <div class="meta small" style="margin-top:14px">Generated <?=e(time_ago($aiAnalysis['generated_at']))?></div>

        <div style="margin-top:18px">
          <?php foreach($aiAnalysis['category_scores'] as $catLabel=>$catScore): ?>
            <div class="cprofile-cat-row"><span><?=e($catLabel)?></span><span><?=e((string)round($catScore))?>%</span></div>
            <div class="cap-bar" style="margin-bottom:10px"><span style="width:<?=round($catScore)?>%"></span></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <p class="meta small" style="font-weight:700">AI summary</p>
        <p style="margin:6px 0 16px"><?=nl2br(e($aiAnalysis['summary']))?></p>
        <div class="cprofile-ai-list-grid">
          <div>
            <p class="meta small" style="font-weight:700">Strengths</p>
            <ul class="cprofile-ai-list"><?php foreach($aiAnalysis['strengths'] as $s): ?><li><?=e($s)?></li><?php endforeach; ?></ul>
          </div>
          <div>
            <p class="meta small" style="font-weight:700">Concerns</p>
            <ul class="cprofile-ai-list"><?php foreach($aiAnalysis['concerns'] as $c): ?><li><?=e($c)?></li><?php endforeach; ?></ul>
          </div>
        </div>
      </div>
    </div>
    <div class="card" style="margin-top:16px;background:var(--paper)">
      <div class="label">AI analysis notes</div>
      <p style="margin-top:10px;white-space:pre-line"><?=e($aiAnalysis['ai_notes'])?></p>
    </div>
    <p class="meta small" style="margin-top:14px">AI-generated analysis is a prototype recommendation and should be reviewed by a recruiter before making a hiring decision.</p>
  <?php else: ?>
    <p class="meta" style="margin-top:14px">No AI analysis yet. Click "Analyze Application" to generate one.</p>
  <?php endif; ?>
</div>

<?php if($screeningDone): ?>
<div class="cprofile-grid-2 cprofile-review-pair">
  <div class="card">
    <div class="label">Feedback for candidate</div>
    <div class="list-stack" style="margin:12px 0">
      <?php foreach ($feedbackList as $fb): ?>
        <div class="list-row" style="display:block"><span class="pill" style="margin-bottom:6px"><?=e(ucwords(str_replace('-',' ',$fb['fit'])))?></span><p style="margin:6px 0"><?=nl2br(e($fb['notes']))?></p><span class="meta small"><?=e($fb['author'] ?? 'Recruiter')?> · <?=e(time_ago($fb['created_at']))?></span></div>
      <?php endforeach; if(!$feedbackList): ?><p class="meta">No feedback recorded yet.</p><?php endif; ?>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="feedback">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <div class="field"><label>Fit assessment</label><select name="fit"><option value="strong-fit">Strong fit</option><option value="potential-fit">Potential fit</option><option value="not-a-fit">Not a fit for this role</option></select></div>
      <div class="field"><label>Areas for improvement</label><textarea name="notes" placeholder="What could the candidate improve?"></textarea></div>
      <button class="btn small" type="submit">Save feedback</button>
    </form>
  </div>

  <div class="card">
  <div class="label">Suggest a different role</div>
  <div class="list-stack" style="margin:12px 0">
    <?php foreach ($suggestions as $s): ?>
      <div class="list-row" style="display:block"><strong><?=e($s['suggested_title'])?></strong><?php if($s['note']): ?><p style="margin:4px 0"><?=nl2br(e($s['note']))?></p><?php endif; ?><span class="meta small"><?=e($s['author'] ?? 'Recruiter')?> · <?=e(time_ago($s['created_at']))?></span></div>
    <?php endforeach; if(!$suggestions): ?><p class="meta">No suggestions yet.</p><?php endif; ?>
  </div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="suggest">
    <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
    <div class="field"><label>Other open role</label><select name="job_id" required><option value="">Select a role</option><?php foreach ($openRoles as $role): ?><option value="<?=$role['id']?>"><?=e($role['title'])?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Why this role? <span class="hint">optional</span></label><textarea name="note"></textarea></div>
    <button class="btn small" type="submit">Save suggestion</button>
  </form>
</div>
</div>

<?php else: ?>
<p class="cprofile-locked-note">
  <?=icon('clock',14)?>
  Rating, candidate feedback and role suggestions unlock once this candidate moves past Applied.
</p>
<?php endif; ?>

</div>

<script>
(function(){
  const form = document.getElementById('ai-analyze-form');
  const panel = document.getElementById('ai-analyzing-panel');
  const checklist = document.getElementById('ai-checklist');
  if (!form || !panel || !checklist) return;
  let animating = false;
  form.addEventListener('submit', function(e){
    if (animating) return; // second submit call (after animation) goes through
    e.preventDefault();
    panel.hidden = false;
    const steps = checklist.querySelectorAll('[data-step]');
    steps.forEach(s => s.classList.remove('done'));
    let i = 0;
    const tick = () => {
      if (i < steps.length) { steps[i].classList.add('done'); i++; setTimeout(tick, 320); }
      else { setTimeout(() => { animating = true; form.submit(); }, 400); }
    };
    tick();
  });
})();
</script>
</div><!-- /.cprofile-main -->
</div><!-- /.cprofile-layout -->

<section class="card cprofile-interviews" id="interview-history">
  <div class="section-head" style="margin:0 0 14px">
    <div><div class="eyebrow">Interviews</div><h2>Interview history</h2></div>
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
          <strong><?=e(ucfirst($iv['meeting_type']))?> interview</strong>
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

<section class="card cprofile-section cprofile-activity" id="activity-log" data-activity>
  <div class="collapse-head">
    <div><div class="eyebrow">History</div><h2>Activity</h2></div>
    <button type="button" class="collapse-btn" data-activity-toggle aria-expanded="true" aria-controls="activity-body" title="Minimise activity">&minus;</button>
  </div>
  <div class="collapse-body" id="activity-body" data-activity-body>
  <div class="list-stack" style="margin-top:12px">
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
      <div class="list-row"><span><?=e($line)?></span><span class="meta small"><?=e($a['actor'] ?? 'System')?> · <?=e(time_ago($a['created_at']))?></span></div>
    <?php endforeach; if(!$activity): ?><p class="meta">No activity recorded yet.</p><?php endif; ?>
    </div>
  </div>
</section>

<script>
/* Activity collapses to keep the profile scannable. Collapsing only hides the
   list — the records themselves are untouched, and the state is remembered per
   candidate so it does not reset on every visit. */
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
    btn.title = open ? 'Minimise activity' : 'Expand activity';
    try { localStorage.setItem(KEY, open ? '1' : '0'); } catch (e) {}
  }

  btn.addEventListener('click', function () { setOpen(body.hidden); });

  var saved = '1';
  try { saved = localStorage.getItem(KEY) || '1'; } catch (e) {}
  setOpen(saved === '1');
})();
</script>

<!-- Stage reviews. These are real workflow panels that predate the wireframe,
     so rather than delete them they are collected into one collapsed block.
     Closed, it is a single slim bar and the wireframe's rhythm is unchanged;
     open, everything still works exactly as before. -->
<section class="card cprofile-stagework" data-stagework>
  <button type="button" class="stagework-toggle" data-stagework-toggle
          aria-expanded="false" aria-controls="stagework-body">
    <span class="stagework-head">
      <span class="label">Stage reviews &amp; actions</span>
      <span class="meta small">Screening review, interview review, and hiring actions for this stage</span>
    </span>
    <span class="stagework-chevron" aria-hidden="true"><?=icon('chevron',15)?></span>
  </button>
  <div class="stagework-body" id="stagework-body" data-stagework-body hidden>
<?php if($screeningDone): ?>
<div class="card cprofile-section">
  <div class="label">Screening review</div>
  <?php if($screeningReview): ?>
    <div class="cprofile-review-summary">
      <span class="pill">Screening Completed</span>
      <span class="meta small">Date: <?=e(date('F j, Y', strtotime($screeningReview['created_at'])))?><?php if($screeningReview['reviewer']): ?> · <?=e($screeningReview['reviewer'])?><?php endif; ?></span>
    </div>
    <?php if($screeningReview['rating']!==null): ?><p style="margin-top:10px"><strong><?=e((string)$screeningReview['rating'])?> / 100</strong></p><?php endif; ?>
    <?php if($screeningReview['feedback']): ?><p class="meta small" style="font-weight:700;margin-top:10px">Screening feedback</p><p style="margin-top:4px"><?=nl2br(e($screeningReview['feedback']))?></p><?php endif; ?>
    <?php if($screeningReview['notes']): ?><p class="meta small" style="font-weight:700;margin-top:10px">Screening notes</p><p style="margin-top:4px"><?=nl2br(e($screeningReview['notes']))?></p><?php endif; ?>
  <?php endif; ?>
  <details style="margin-top:14px"><summary class="meta small" style="cursor:pointer"><?=$screeningReview?'Edit screening review':'Add screening review'?></summary>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="stage_review">
      <input type="hidden" name="stage_type" value="screening">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <div class="field"><label>Screening rating <span class="hint">0–100</span></label><input type="number" min="0" max="100" name="review_rating" value="<?=e($screeningReview['rating']??'')?>"></div>
      <div class="field"><label>Screening feedback</label><textarea name="review_feedback"><?=e($screeningReview['feedback']??'')?></textarea></div>
      <div class="field"><label>Screening notes</label><textarea name="review_notes"><?=e($screeningReview['notes']??'')?></textarea></div>
      <button class="btn small" type="submit">Save screening review</button>
    </form>
  </details>
</div>
<?php endif; ?>

<?php if($interviewDone): ?>
<div class="card cprofile-section">
  <div class="label">Interview review</div>
  <?php if($interviewReview): ?>
    <div class="cprofile-review-summary">
      <span class="pill">Interview Completed</span>
      <span class="meta small">Date: <?=e(date('F j, Y', strtotime($interviewReview['created_at'])))?><?php if($interviewReview['reviewer']): ?> · <?=e($interviewReview['reviewer'])?><?php endif; ?></span>
    </div>
    <?php if($interviewReview['rating']!==null): ?><p style="margin-top:10px"><strong><?=e((string)$interviewReview['rating'])?> / 100</strong></p><?php endif; ?>
    <?php if($interviewReview['feedback']): ?><p class="meta small" style="font-weight:700;margin-top:10px">Interview feedback</p><p style="margin-top:4px"><?=nl2br(e($interviewReview['feedback']))?></p><?php endif; ?>
    <?php if($interviewReview['notes']): ?><p class="meta small" style="font-weight:700;margin-top:10px">Interview notes</p><p style="margin-top:4px"><?=nl2br(e($interviewReview['notes']))?></p><?php endif; ?>
  <?php endif; ?>
  <details style="margin-top:14px"><summary class="meta small" style="cursor:pointer"><?=$interviewReview?'Edit interview review':'Add interview review'?></summary>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="stage_review">
      <input type="hidden" name="stage_type" value="interview">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <div class="field"><label>Interview rating <span class="hint">0–100</span></label><input type="number" min="0" max="100" name="review_rating" value="<?=e($interviewReview['rating']??'')?>"></div>
      <div class="field"><label>Interview feedback</label><textarea name="review_feedback"><?=e($interviewReview['feedback']??'')?></textarea></div>
      <div class="field"><label>Interview notes</label><textarea name="review_notes"><?=e($interviewReview['notes']??'')?></textarea></div>
      <button class="btn small" type="submit">Save interview review</button>
    </form>
  </details>
</div>
<?php endif; ?>

<?php if($candidate['stage']==='hired'): ?>
<div class="card cprofile-section">
  <div class="label">Hired → Employee</div>
  <?php if($employeeRecord): ?>
    <div class="cprofile-review-summary">
      <span class="pill">Employee Record Created</span>
      <span class="meta small">Employee # <?=e($employeeRecord['employee_number'])?> · Started <?=e($employeeRecord['start_date']?date('M j, Y',strtotime($employeeRecord['start_date'])):'—')?></span>
    </div>
    <div class="cprofile-kv" style="margin-top:12px"><span>Applied position</span><span><?=e($employeeRecord['applied_position'] ?: $candidate['job_title'])?></span></div>
    <div class="cprofile-kv"><span>Hired position</span><span><?=e($employeeRecord['job_title'])?></span></div>
    <div class="cprofile-kv"><span>Department</span><span><?=e($employeeRecord['department_name'] ?: 'Unassigned')?></span></div>
    <a class="btn secondary small" style="margin-top:12px" href="employees.php">View employee directory →</a>
  <?php else: ?>
    <p class="meta" style="margin-top:8px">This candidate was hired. Add them to the employee directory, preserving their recruitment history (application, resume, notes, and reviews stay linked).</p>
    <form method="post" style="margin-top:14px">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="convert_employee">
      <input type="hidden" name="application_id" value="<?=$candidate['application_id']?>">
      <div class="cprofile-app-grid" style="grid-template-columns:1fr 1fr">
        <div>
          <div class="field"><label>Applied position</label><input value="<?=e($candidate['job_title'])?>" disabled></div>
          <div class="field"><label>Hired position</label><input name="hired_position" value="<?=e($candidate['job_title'])?>" placeholder="<?=e($candidate['job_title'])?>"></div>
          <div class="field"><label>Department</label><select name="department_id"><option value="">Unassigned</option><?php foreach($hireDepartments as $d): ?><option value="<?=$d['id']?>" <?=$candidate['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach; ?></select></div>
        </div>
        <div>
          <div class="field"><label>Employee number <span class="hint">optional — auto-generated if blank</span></label><input name="employee_number" placeholder="EMP-<?=date('Y')?>-<?=str_pad((string)$candidate['application_id'],4,'0',STR_PAD_LEFT)?>"></div>
          <div class="field"><label>Start date</label><input type="date" name="start_date" value="<?=date('Y-m-d')?>"></div>
        </div>
      </div>
      <button class="btn small" type="submit">+ Add to Employees</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

  </div>
</section>

</div><!-- /.candidate-profile-page -->

<script>
/* The resume panel is collapsed by default: the iframe is not created until
   View is pressed, so the PDF is never fetched on a page the recruiter is only
   skimming. The skeleton is shown for exactly the span of that real load --
   removed on the iframe's own 'load' event, not on a timer. */
(function () {
  const btn = document.querySelector('[data-resume-toggle]');
  if (btn) btn.dataset.openLabel = btn.textContent.trim();
  const panel = document.querySelector('[data-resume-panel]');
  if (!btn || !panel) return;
  // The panel IS the frame wrapper now, so look inside it or at it.
  const frame = panel.matches('iframe') ? panel : panel.querySelector('[data-resume-iframe]');
  const skeleton = panel.querySelector('[data-resume-skeleton]');
  const src = frame ? frame.getAttribute('src') : null;
  if (frame) frame.removeAttribute('src');

  if (frame && skeleton) {
    frame.addEventListener('load', function () { skeleton.hidden = true; });
  }

  btn.addEventListener('click', function () {
    const open = panel.hidden;
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.innerHTML = open ? 'Hide' : (btn.dataset.openLabel || 'View (PDF)');
    if (open && frame && src && !frame.getAttribute('src')) {
      if (skeleton) skeleton.hidden = false;
      frame.setAttribute('src', src);
    }
    if (open) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
})();
</script>

<script>
/* Stage reviews collapse so the profile reads as the wireframe intends.
   Collapsing only hides the panel — the forms and their values stay in the DOM,
   so nothing is lost and a submit still carries everything. */
(function () {
  const btn = document.querySelector('[data-stagework-toggle]');
  const body = document.querySelector('[data-stagework-body]');
  if (!btn || !body) return;
  const wrap = btn.closest('[data-stagework]');

  function setOpen(open) {
    body.hidden = !open;
    wrap.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  btn.addEventListener('click', function () { setOpen(body.hidden); });

  // Opens itself when something inside needs attention, so a pending review is
  // never hidden behind a click.
  if (body.querySelector('textarea:not(:placeholder-shown), input[value]:not([value=""])')) setOpen(true);
})();
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
