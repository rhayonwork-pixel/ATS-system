<?php
require_once __DIR__.'/includes/auth.php'; require_login(['admin','recruiter','hiring_manager']); $pdo=db();
$role = current_user()['role'] ?? '';
// Admin and Super Admin may override a stage skip. Super Admin bypasses every
// role gate, so the check is by level rather than by the literal 'admin' role.
$canOverride = is_admin_level();

// Stage order used to detect a "skip" (moving forward past the next stage
// without going through the ones in between). Rejecting or moving a
// candidate backward is always allowed — only forward skips are gated.
$stageOrder = ['new','screening','interview','offer','hired'];
$stageRank = array_flip($stageOrder);
$stages = ['new'=>'Applied','screening'=>'Screening','interview'=>'Interview','offer'=>'Offer','hired'=>'Hired'];

// The ONLY write path for a pipeline move. assets/pipeline.js calls it solely
// from the approval modal's Confirm button; a drop on its own never reaches it.
if (($_SERVER['REQUEST_METHOD']==='POST') && ($_POST['action']??'')==='move_stage') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Invalid request token.']); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $stage = $_POST['stage'] ?? '';
    $expectedFrom = $_POST['from'] ?? '';
    $override = !empty($_POST['override']) && $canOverride;
    $allowed = ['new','screening','interview','offer','hired','rejected'];
    if (!$id || !in_array($stage, $allowed, true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid stage or application.']); exit; }

    $cur = $pdo->prepare('SELECT stage FROM applications WHERE id=? LIMIT 1'); $cur->execute([$id]); $fromStage = $cur->fetchColumn();
    if ($fromStage === false) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Candidate not found.']); exit; }

    // The board may be stale: a colleague could have moved this candidate since
    // it loaded. Refuse rather than silently overwrite a move nobody here saw.
    if ($expectedFrom !== '' && $expectedFrom !== $fromStage) {
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'stale','message'=>'Someone else moved this candidate to '.($stages[$fromStage] ?? $fromStage).'. Refresh the board and try again.']);
        exit;
    }

    if ($stage !== 'rejected' && isset($stageRank[$fromStage], $stageRank[$stage]) && $stageRank[$stage] > $stageRank[$fromStage] + 1 && !$override) {
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'skip_blocked','message'=>'This candidate cannot skip stages. Complete the required recruitment stages first.']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE applications SET stage=? WHERE id=?');
    $stmt->execute([$stage, $id]);
    $s = $pdo->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address) VALUES(?,?,?,?,?,?)');
    $s->execute([$_SESSION['user_id'] ?? null, 'pipeline_stage_move', 'application', $id, json_encode(['from'=>$fromStage,'to'=>$stage,'override'=>$override]), $_SERVER['REMOTE_ADDR'] ?? null]);
    echo json_encode(['ok'=>true,'from'=>$fromStage,'to'=>$stage]);
    exit;
}

$jobFilter = (int)($_GET['job'] ?? 0);
$deptFilter = (int)($_GET['department'] ?? 0);
$search = trim($_GET['q'] ?? '');

// Board data, fetched by assets/pipeline.js while the columns show skeletons.
// Read-only, so it needs no CSRF token; the login gate above still applies.
if (($_GET['action'] ?? '') === 'board_data') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $sql = "SELECT a.id,a.stage,a.applied_at,a.updated_at,c.id candidate_id,c.first_name,c.last_name,c.email,c.resume_path,c.profile_image,j.title,
            ai.overall_score,
            (SELECT cd.id FROM candidate_documents cd WHERE cd.candidate_id=c.id AND cd.is_primary=1 LIMIT 1) primary_doc_id,
            (SELECT COUNT(*) FROM candidate_notes n WHERE n.candidate_id=c.id) note_count,
            (SELECT n.note FROM candidate_notes n WHERE n.candidate_id=c.id ORDER BY n.created_at DESC LIMIT 1) latest_note,
            (SELECT rating FROM stage_reviews sr WHERE sr.application_id=a.id AND sr.stage_type='screening' LIMIT 1) screening_score,
            (SELECT rating FROM stage_reviews sr WHERE sr.application_id=a.id AND sr.stage_type='interview' LIMIT 1) interview_score
            FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id
            LEFT JOIN candidate_ai_analysis ai ON ai.application_id=a.id
            WHERE a.status='active'";
    $params = [];
    if ($jobFilter) { $sql .= ' AND j.id=?'; $params[] = $jobFilter; }
    if ($deptFilter) { $sql .= ' AND j.department_id=?'; $params[] = $deptFilter; }
    if ($search !== '') { $sql .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR j.title LIKE ?)"; $like = '%'.$search.'%'; array_push($params, $like, $like, $like); }
    $sql .= ' ORDER BY a.updated_at DESC';
    try {
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Same fallback as candidates.php: candidate_documents may not exist yet
        // on this install. Drop the one subquery that depends on it rather than
        // breaking the whole board.
        $sqlLegacy = str_replace(
            "            (SELECT cd.id FROM candidate_documents cd WHERE cd.candidate_id=c.id AND cd.is_primary=1 LIMIT 1) primary_doc_id,\n",
            '', $sql
        );
        $stmt = $pdo->prepare($sqlLegacy); $stmt->execute($params); $rows = $stmt->fetchAll();
    }

    $candidates = [];
    foreach ($rows as $r) {
        if (!isset($stages[$r['stage']])) continue;   // rejected rows are not on the board
        $name = trim($r['first_name'].' '.$r['last_name']);
        $candidates[] = [
            'id' => (int)$r['id'],
            'stage' => $r['stage'],
            'name' => $name,
            'initials' => strtoupper(substr((string)$r['first_name'],0,1).substr((string)$r['last_name'],0,1)),
            'title' => $r['title'],
            'updatedAgo' => time_ago($r['updated_at']),
            'aiScore' => $r['overall_score'] !== null ? (float)$r['overall_score'] : null,
            'resume' => !empty($r['primary_doc_id']) ? ('download.php?file_id='.(int)$r['primary_doc_id'].'&disposition=inline') : legacy_resume_url($r['resume_path']),
            'profileImage' => $r['profile_image'] ?: null,
            'noteCount' => (int)$r['note_count'],
            'latestNote' => $r['latest_note'],
            'screeningScore' => $r['screening_score'],
            'interviewScore' => $r['interview_score'],
        ];
    }
    echo json_encode(['ok'=>true,'candidates'=>$candidates]);
    exit;
}

$jobsList = $pdo->query("SELECT id,title FROM jobs WHERE status IN ('open','paused') ORDER BY title")->fetchAll();
$departments = $pdo->query('SELECT id,name FROM departments ORDER BY name')->fetchAll();

// Contextual messaging for each stage transition — makes the confirmation
// feel specific rather than a generic "are you sure?".
$transitionMessages = [
    'new>screening' => "You're moving this candidate into Screening. Make sure their application and resume have been reviewed.",
    'screening>interview' => "You're moving this candidate into Interview. Review their screening results before proceeding.",
    'interview>offer' => "You're moving this candidate to Offer. Confirm that interview feedback and scorecards are complete.",
    'offer>hired' => "You're marking this candidate as Hired. You'll be able to add them to Employees from their profile.",
    'new>interview' => "This skips the Screening stage.",
    'new>offer' => "This skips Screening and Interview.",
    'new>hired' => "This skips Screening, Interview, and Offer.",
    'screening>offer' => "This skips the Interview stage.",
    'screening>hired' => "This skips Interview and Offer.",
    'interview>hired' => "This skips the Offer stage.",
];
$pageTitle='Hiring pipeline'; include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/pipeline.css?v=<?= @filemtime(__DIR__.'/assets/css/pipeline.css') ?: time() ?>">
<div class="dashboard-head"><div><div class="eyebrow">Recruiter workspace</div><h1>Hiring pipeline</h1><p class="meta">Drag a candidate card to a new column. Nothing changes until you confirm.</p></div><a class="btn" href="candidates.php"><?=icon('plus',15)?> Add candidate</a></div>

<form method="get" class="pipeline-filters">
  <input type="text" name="q" placeholder="Search candidates..." value="<?=e($search)?>">
  <select name="job" onchange="this.form.submit()"><option value="">All jobs</option><?php foreach($jobsList as $j): ?><option value="<?=$j['id']?>" <?=$jobFilter===(int)$j['id']?'selected':''?>><?=e($j['title'])?></option><?php endforeach; ?></select>
  <select name="department" onchange="this.form.submit()"><option value="">All departments</option><?php foreach($departments as $d): ?><option value="<?=$d['id']?>" <?=$deptFilter===(int)$d['id']?'selected':''?>><?=e($d['name'])?></option><?php endforeach; ?></select>
  <button class="btn secondary small" type="submit">Filter</button>
  <?php if($jobFilter||$deptFilter||$search): ?><a class="btn ghost small" href="pipeline.php">Clear</a><?php endif; ?>
</form>

<p id="pb-instructions" class="pb-sr-only">
  Press Space to pick up a candidate. Use the Left and Right arrow keys to choose a stage, then press Space again to drop it.
  You will be asked to confirm before the move is saved. Press Escape to cancel. Up and Down move between candidates in a column.
</p>
<div class="pb-sr-only" aria-live="assertive" data-pb-live></div>

<noscript><p class="notice">The hiring pipeline needs JavaScript to load. Use <a href="candidates.php">Candidates</a> to change a stage without it.</p></noscript>

<!-- PipelineBoard. Columns are server-rendered with skeleton cards so the
     layout is stable before data arrives; pipeline.js fetches ?action=board_data
     and replaces each skeleton list with the real (possibly virtualized) list. -->
<div class="pb-board" data-pipeline-board aria-busy="true"
     data-csrf="<?=e(csrf_token())?>" data-can-override="<?=$canOverride ? '1' : '0'?>"
     data-query="<?=e(http_build_query(array_filter(['job'=>$jobFilter ?: null,'department'=>$deptFilter ?: null,'q'=>$search !== '' ? $search : null])))?>">
<?php foreach ($stages as $key=>$label): ?>
  <section class="pb-col" data-pb-col data-stage="<?=e($key)?>" aria-labelledby="pb-col-title-<?=e($key)?>">
    <header class="pb-col-head">
      <div class="pb-col-titlebar">
        <h2 class="pb-col-title" id="pb-col-title-<?=e($key)?>"><?=e($label)?></h2>
        <span class="pb-col-pending" data-pb-col-pending hidden>Pending</span>
        <span class="pb-col-count" data-pb-col-count>—</span>
      </div>
      <div class="pb-col-progress">
        <span class="pb-col-progress-track" aria-hidden="true"><span class="pb-col-progress-fill" data-pb-col-progress-fill></span></span>
        <span class="pb-col-progress-label" data-pb-col-progress-label>Loading…</span>
      </div>
      <!-- Only shown in the phone accordion (<=480px); display:none elsewhere. -->
      <button type="button" class="pb-col-toggle" data-pb-col-toggle aria-expanded="true" aria-controls="pb-col-list-<?=e($key)?>" aria-label="Show or hide <?=e($label)?> candidates"><span class="pb-col-caret" aria-hidden="true"><?=icon('chevron',16)?></span></button>
    </header>
    <div class="pb-col-list" id="pb-col-list-<?=e($key)?>" data-pb-col-list role="list" aria-label="<?=e($label)?> candidates">
      <?php for ($i = 0; $i < 3; $i++): ?>
      <div class="pb-skel" aria-hidden="true"><span class="pb-skel-avatar"></span><span class="pb-skel-line"></span><span class="pb-skel-line short"></span></div>
      <?php endfor; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<!-- CandidateCard template. Cloned per card; every field is filled with
     textContent or a validated attribute, never innerHTML. -->
<template id="pb-card-template">
  <article class="pb-card" role="listitem" tabindex="0" aria-roledescription="draggable candidate" aria-describedby="pb-instructions">
    <div class="pb-card-head">
      <span class="candidate-card-avatar" data-f="avatar"></span>
      <strong class="pb-card-name" data-f="name"></strong>
      <span class="candidate-card-score" data-f="score" hidden></span>
    </div>
    <span class="pb-card-meta" data-f="meta"></span>
    <span class="pb-card-pending-tag">Pending approval</span>
    <div class="pb-card-actions">
      <a data-f="view" data-no-drag draggable="false">View</a>
      <a data-f="schedule" data-no-drag draggable="false">Schedule</a>
    </div>
  </article>
</template>

<!-- Candidate preview modal -->
<div class="candidate-preview-overlay" data-preview-overlay hidden>
  <div class="candidate-preview-modal" role="dialog" aria-modal="true" aria-labelledby="preview-title">
    <div class="preview-panel-head">
      <div class="candidate-preview-identity">
        <div data-preview-photo></div>
        <div><div class="eyebrow">Candidate preview</div><h2 id="preview-title" data-preview-name style="margin:0;font-size:22px"></h2><p class="meta" style="margin:4px 0 0" data-preview-title></p></div>
      </div>
      <button type="button" class="preview-panel-close" data-preview-close aria-label="Close candidate preview">&times;</button>
    </div>
    <div class="preview-stage-row"><span>Current stage</span><span class="stage-badge" data-preview-stage></span></div>
    <div class="preview-grid">
      <div class="preview-row"><span>AI match score</span><strong data-preview-ai>—</strong></div>
      <div class="preview-row"><span>Screening review</span><span data-preview-screening>—</span></div>
      <div class="preview-row"><span>Interview review</span><span data-preview-interview>—</span></div>
      <div class="preview-row"><span>Resume</span><span data-preview-resume>—</span></div>
      <div class="preview-row"><span>Recruiter notes</span><span data-preview-notes>—</span></div>
    </div>
    <p class="meta small preview-latest-note" data-preview-latest-note></p>
    <div class="modal-actions">
      <a class="btn secondary" data-preview-full-link href="#">View Full Profile</a>
      <button type="button" class="btn" data-preview-close>Close</button>
    </div>
  </div>
</div>

<!-- ApprovalModal. The overlay element IS the backdrop: a click whose target is
     the overlay itself (not the box) cancels, as do Cancel and Escape. Only
     [data-approval-confirm] can commit. -->
<div class="pb-modal" data-approval-modal hidden>
  <div class="pb-modal-box" role="dialog" aria-modal="true" aria-labelledby="pb-approval-title" aria-describedby="pb-approval-desc">
    <h2 id="pb-approval-title">Approve stage change?</h2>
    <p class="meta" id="pb-approval-desc">Candidate: <strong data-approval-name></strong></p>
    <div class="modal-transition">
      <span class="stage-badge" data-approval-from></span>
      <span aria-hidden="true">→</span><span class="pb-sr-only">to</span>
      <span class="stage-badge" data-approval-to></span>
    </div>
    <div class="modal-warning" data-approval-warning hidden></div>
    <label class="confirm-checkbox-row" data-approval-ack-row hidden><input type="checkbox" data-approval-ack> I understand this skips required stages.</label>
    <p class="meta small">Confirming saves the new stage and records it in the activity history. Cancel, Escape, or a click outside this box puts the candidate back.</p>
    <p class="pb-modal-error" data-approval-error role="alert" hidden></p>
    <a class="btn secondary wide" data-approval-profile href="#" target="_blank" rel="noopener">View candidate profile</a>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-approval-cancel>Cancel</button>
      <button type="button" class="btn" data-approval-confirm>Confirm move</button>
    </div>
  </div>
</div>

<script>
window.PIPELINE_TRANSITION_MESSAGES = <?=json_encode($transitionMessages)?>;
window.PIPELINE_STAGE_LABELS = <?=json_encode($stages)?>;
</script>
<script src="assets/pipeline.js?v=<?= @filemtime(__DIR__.'/assets/pipeline.js') ?: time() ?>"></script>
<?php include 'includes/footer.php'; ?>
