<?php
require_once __DIR__.'/includes/auth.php'; require_login(['admin','recruiter','hiring_manager']); $pdo=db();
$role = current_user()['role'] ?? '';

// Stage order used to detect a "skip" (moving forward past the next stage
// without going through the ones in between). Rejecting or moving a
// candidate backward is always allowed — only forward skips are gated.
$stageOrder = ['new','screening','interview','offer','hired'];
$stageRank = array_flip($stageOrder);

if (($_SERVER['REQUEST_METHOD']==='POST') && ($_POST['action']??'')==='move_stage') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Invalid request token.']); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $stage = $_POST['stage'] ?? '';
    $override = !empty($_POST['override']) && $role === 'admin';
    $allowed = ['new','screening','interview','offer','hired','rejected'];
    if (!$id || !in_array($stage, $allowed, true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid stage or application.']); exit; }

    $cur = $pdo->prepare('SELECT stage FROM applications WHERE id=? LIMIT 1'); $cur->execute([$id]); $fromStage = $cur->fetchColumn();
    if ($fromStage === false) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Candidate not found.']); exit; }

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
$jobsList = $pdo->query("SELECT id,title FROM jobs WHERE status IN ('open','paused') ORDER BY title")->fetchAll();
$departments = $pdo->query('SELECT id,name FROM departments ORDER BY name')->fetchAll();

$stages = ['new'=>'Applied','screening'=>'Screening','interview'=>'Interview','offer'=>'Offer','hired'=>'Hired'];
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
        "        (SELECT cd.id FROM candidate_documents cd WHERE cd.candidate_id=c.id AND cd.is_primary=1 LIMIT 1) primary_doc_id,\n",
        '', $sql
    );
    $stmt = $pdo->prepare($sqlLegacy); $stmt->execute($params); $rows = $stmt->fetchAll();
}
$columns = array_fill_keys(array_keys($stages), []);
foreach ($rows as $r) { $columns[$r['stage']][] = $r; }

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
<div class="dashboard-head"><div><div class="eyebrow">Recruiter workspace</div><h1>Hiring pipeline</h1><p class="meta">Drag a candidate card to a new column. You'll be asked to confirm before anything changes.</p></div><a class="btn" href="candidates.php"><?=icon('plus',15)?> Add candidate</a></div>

<form method="get" class="pipeline-filters">
  <input type="text" name="q" placeholder="Search candidates..." value="<?=e($search)?>">
  <select name="job" onchange="this.form.submit()"><option value="">All jobs</option><?php foreach($jobsList as $j): ?><option value="<?=$j['id']?>" <?=$jobFilter===(int)$j['id']?'selected':''?>><?=e($j['title'])?></option><?php endforeach; ?></select>
  <select name="department" onchange="this.form.submit()"><option value="">All departments</option><?php foreach($departments as $d): ?><option value="<?=$d['id']?>" <?=$deptFilter===(int)$d['id']?'selected':''?>><?=e($d['name'])?></option><?php endforeach; ?></select>
  <button class="btn secondary small" type="submit">Filter</button>
  <?php if($jobFilter||$deptFilter||$search): ?><a class="btn ghost small" href="pipeline.php">Clear</a><?php endif; ?>
</form>

<input type="hidden" id="pipeline-csrf" value="<?=e(csrf_token())?>">
<input type="hidden" id="pipeline-role" value="<?=e($role)?>">
<div class="kanban">
<?php foreach ($stages as $key=>$label): ?>
  <div class="kanban-col" data-drop data-stage="<?=e($key)?>" data-stage-label="<?=e($label)?>">
    <h3><?=e($label)?> <span class="muted" data-col-count><?=count($columns[$key])?></span></h3>
    <div class="kanban-col-body" data-col-body>
    <?php foreach ($columns[$key] as $r): $initials = strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1));
      $preview = [
        'name' => $r['first_name'].' '.$r['last_name'],
        'title' => $r['title'],
        'stage' => $stages[$r['stage']],
        'aiScore' => $r['overall_score'],
        'resume' => !empty($r['primary_doc_id']) ? ('download.php?file_id='.(int)$r['primary_doc_id'].'&disposition=inline') : ($r['resume_path'] ?: null),
        'profileImage' => $r['profile_image'],
        'noteCount' => (int)$r['note_count'],
        'latestNote' => $r['latest_note'],
        'screeningScore' => $r['screening_score'],
        'interviewScore' => $r['interview_score'],
        'applicationId' => $r['id'],
      ];
    ?>
      <div class="candidate-card" draggable="true" data-id="<?=$r['id']?>" data-preview='<?=e(json_encode($preview))?>'>
        <div class="candidate-card-head">
          <?php if(!empty($r['profile_image'])): ?><img class="candidate-card-avatar avatar-image" src="<?=e($r['profile_image'])?>" alt="<?=e($r['first_name'].' '.$r['last_name'])?>"><?php else: ?><span class="candidate-card-avatar"><?=e($initials)?></span><?php endif; ?>
          <strong><?=e($r['first_name'].' '.$r['last_name'])?></strong>
          <?php if($r['overall_score']!==null): ?><span class="candidate-card-score">AI <?=e((string)$r['overall_score'])?></span><?php endif; ?>
        </div>
        <span class="meta"><?=e($r['title'])?> · <?=e(time_ago($r['updated_at']))?></span>
        <div class="candidate-card-actions">
          <a href="candidate.php?id=<?=$r['id']?>" data-no-preview>View</a>
          <a href="interviews.php?application=<?=$r['id']?>" data-no-preview>Schedule</a>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$columns[$key]): ?><p class="kanban-empty meta small">Drop a candidate here</p><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

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

<!-- Modal 1: Move candidate? -->
<div class="modal-overlay" data-modal-move hidden>
  <div class="modal-box">
    <h2>Move Candidate?</h2>
    <p class="meta" data-move-candidate-name></p>
    <div class="modal-transition">
      <span class="stage-badge" data-move-from></span>
      <span>→</span>
      <span class="stage-badge" data-move-to></span>
    </div>
    <div class="modal-warning" data-move-warning hidden></div>
    <a class="btn secondary wide" data-move-view-profile href="#" target="_blank" rel="noopener" style="margin-top:6px">View Candidate Profile</a>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-move-cancel>Cancel</button>
      <button type="button" class="btn" data-move-continue>Continue</button>
    </div>
  </div>
</div>

<!-- Modal 2: Confirm stage change -->
<div class="modal-overlay" data-modal-confirm hidden>
  <div class="modal-box">
    <h2>Confirm Stage Change</h2>
    <p class="meta">Candidate: <strong data-confirm-candidate-name></strong></p>
    <div class="modal-transition">
      <span class="stage-badge" data-confirm-from></span>
      <span>→</span>
      <span class="stage-badge" data-confirm-to></span>
    </div>
    <p class="meta small">This action will update the candidate's pipeline stage and record the change in the activity history.</p>
    <label class="confirm-checkbox-row"><input type="checkbox" data-confirm-checkbox> I confirm that I want to move this candidate.</label>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-confirm-back>Back</button>
      <button type="button" class="btn" data-confirm-move disabled>Confirm &amp; Move</button>
    </div>
  </div>
</div>

<script>
window.PIPELINE_TRANSITION_MESSAGES = <?=json_encode($transitionMessages)?>;
window.PIPELINE_STAGE_LABELS = <?=json_encode($stages)?>;
</script>
<script src="assets/pipeline.js"></script>
<?php include 'includes/footer.php'; ?>
