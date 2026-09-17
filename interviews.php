<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/interview_lib.php';
require_login(['admin','recruiter','hiring_manager']); $pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf(); $action=$_POST['action']??'create';
    if($action==='create'){
        $application=(int)($_POST['application_id']??0); $start=$_POST['starts_at']??''; $end=$_POST['ends_at']??null; $type=$_POST['interview_type']??'video';
        $meetingType=($_POST['meeting_type']??'')==='screening'?'screening':'interview';
        $interviewerId=(int)($_POST['interviewer_id']??$_SESSION['user_id']);
        $useRoom=($_POST['meeting_mode']??'builtin')==='builtin';
        $roomCode=$useRoom ? strtoupper(bin2hex(random_bytes(4))) : null;
        $meetingUrl=$useRoom ? '' : trim($_POST['meeting_url']??'');
        $provider=$useRoom ? 'Acme Room' : trim($_POST['meeting_provider']??'Zoom');
        if($application && $start && in_array($type,['phone','video','onsite','panel'],true)){
            $conflictCheck=$pdo->prepare("SELECT COUNT(*) FROM interviews WHERE interviewer_id=? AND status NOT IN ('cancelled') AND starts_at=?");
            $conflictCheck->execute([$interviewerId,$start]);
            if((int)$conflictCheck->fetchColumn() > 0){
                flash('error','This interviewer already has an interview scheduled at that exact time. Please pick another time or interviewer.');
            } else {
                // Every interview gets its own candidate token so the candidate
                // can join their meeting and only their meeting.
                $candidateToken=generate_candidate_token();
                $s=$pdo->prepare('INSERT INTO interviews(application_id,interviewer_id,meeting_type,starts_at,ends_at,interview_type,meeting_url,meeting_provider,room_code,candidate_token,location,notes,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $s->execute([$application,$interviewerId,$meetingType,$start,$end,$type,$meetingUrl,$provider,$roomCode,$candidateToken,trim($_POST['location']??''),trim($_POST['notes']??''),'scheduled']);
                // Email the candidate their invite. Wrapped so a mail failure
                // (or a not-yet-configured Resend key) never blocks scheduling.
                try {
                    require_once __DIR__ . '/app/Services/EmailService.php';
                    $newInterviewId = (int) $pdo->lastInsertId();
                    $ctx = $pdo->prepare('SELECT CONCAT(c.first_name,\' \',c.last_name) AS name, c.email, j.title FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id WHERE a.id=?');
                    $ctx->execute([$application]);
                    if ($row = $ctx->fetch()) {
                        EmailService::sendInterviewEmail(
                            ['room_code'=>$roomCode,'candidate_token'=>$candidateToken,'meeting_url'=>$meetingUrl,'starts_at'=>$start],
                            ['name'=>$row['name'],'email'=>$row['email']],
                            ['title'=>$row['title']]
                        );
                    }
                } catch (Throwable $e) { @file_put_contents(__DIR__ . "/storage/logs/mail.log", "[" . date("Y-m-d H:i:s") . "] interview invite email failed: " . $e->getMessage() . PHP_EOL, FILE_APPEND); }
                // Scheduling an interview is the clearest signal of ownership, so
                // an unclaimed application becomes this interviewer's to report on.
                $pdo->prepare('UPDATE applications SET assigned_to=? WHERE id=? AND assigned_to IS NULL')
                    ->execute([$interviewerId,$application]);
                $newStage = $meetingType==='screening' ? 'screening' : 'interview';
                $pdo->prepare("UPDATE applications SET stage=? WHERE id=? AND stage NOT IN ('hired','rejected')")->execute([$newStage,$application]); audit('interview_create','interview',(int)$pdo->lastInsertId()); flash('success',ucfirst($meetingType).' scheduled'.($useRoom?' — built-in Acme Room ready.':'.'));
            }
        } else flash('error','Please select an application, date/time, and interview type.');
    } elseif($action==='status'){
        $id=(int)$_POST['interview_id']; $status=$_POST['status']??'scheduled'; if(in_array($status,['scheduled','confirmed','completed','cancelled','no_show'],true)){ $pdo->prepare('UPDATE interviews SET status=? WHERE id=?')->execute([$status,$id]); audit('interview_status','interview',$id); flash('success','Interview status updated.'); }
    } elseif($action==='feedback'){
        $id=(int)$_POST['interview_id']; $score=isset($_POST['score'])&&$_POST['score']!==''?max(0,min(5,(int)$_POST['score'])):null; $fb=trim($_POST['feedback']??'');
        $pdo->prepare('UPDATE interviews SET score=?,feedback=?,status=IF(status IN ("cancelled"),status,"completed") WHERE id=?')->execute([$score,$fb,$id]);
        audit('interview_feedback','interview',$id); flash('success','Interview scorecard saved.');
        header('Location: interview-room.php?code='.urlencode($_POST['room_code']??'').'&saved=1'); exit;
    } elseif($action==='meeting_review' || $action==='submit_review'){
        // The final score and review. Only reachable once the meeting has
        // actually ended — the form is hidden before that, and this check makes
        // the rule real rather than cosmetic.
        $id=(int)$_POST['interview_id'];
        $lookup=$pdo->prepare('SELECT i.*, c.id candidate_id FROM interviews i JOIN applications a ON a.id=i.application_id JOIN candidates c ON c.id=a.candidate_id WHERE i.id=? LIMIT 1');
        $lookup->execute([$id]); $meeting=$lookup->fetch();
        if(!$meeting){
            flash('error','Could not find that meeting.');
        } elseif(!interview_accepts_review($meeting)){
            flash('error','This interview has not ended yet. End the meeting before submitting a score and review.');
        } else {
            $score=isset($_POST['score'])&&$_POST['score']!==''?max(1,min(100,(int)$_POST['score'])):null;
            $review=trim($_POST['review']??'');
            $recommendation=$_POST['recommendation']??'';
            if(!array_key_exists($recommendation, interview_recommendations())) $recommendation='';

            // Notes taken during the meeting are kept as their own record and are
            // never overwritten by the final review.
            $liveNotes=(string)($meeting['live_notes']??'');

            set_interview_state($id,'reviewed',[
                'score'=>$score,
                'feedback'=>$review!==''?$review:null,
                'recommendation'=>$recommendation!==''?$recommendation:null,
                'reviewer_id'=>(int)$_SESSION['user_id'],
            ]);

            $s=$pdo->prepare('INSERT INTO stage_reviews(application_id,stage_type,rating,feedback,notes,reviewer_id) VALUES(?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE rating=VALUES(rating),feedback=VALUES(feedback),notes=VALUES(notes),reviewer_id=VALUES(reviewer_id)');
            $s->execute([$meeting['application_id'],$meeting['meeting_type'],$score,$review?:null,$liveNotes?:null,$_SESSION['user_id']]);

            audit('interview_review_submitted','interview',$id,array_filter([
                'meeting'=>ucfirst($meeting['meeting_type']),
                'score'=>$score!==null?$score.'/100':'',
                'recommendation'=>interview_recommendation_label($recommendation),
            ]));
            flash('success',ucfirst($meeting['meeting_type']).' review submitted and saved to the candidate profile.');
        }
        header('Location: '.(($_POST['room_code']??'')!=='' ? 'interview-room.php?code='.urlencode($_POST['room_code']).'&saved=1' : 'interviews.php')); exit;

    // Notes are saved by interview-notes.php (fetch, no redirect). There is no
    // save_notes form action any more: a form post reloaded the room and sent
    // the interviewer back to the lobby, which read as the meeting ending.
    } elseif($action==='end_meeting'){
        $id=(int)$_POST['interview_id'];
        $lookup=$pdo->prepare('SELECT * FROM interviews WHERE id=? LIMIT 1');
        $lookup->execute([$id]); $meeting=$lookup->fetch();
        if($meeting){
            // Persist any notes still in the box before the state changes.
            if(isset($_POST['live_notes'])){
                $notes=(string)$_POST['live_notes'];
                $pdo->prepare('UPDATE interviews SET live_notes=?, notes_updated_at=NOW(), notes_author_id=? WHERE id=?')
                    ->execute([$notes!==''?$notes:null,(int)$_SESSION['user_id'],$id]);
            }
            set_interview_state($id,'review_pending');
            $pdo->prepare("UPDATE interviews SET room_status='idle' WHERE id=?")->execute([$id]);
            audit('interview_ended','interview',$id);
            flash('success','Meeting ended. You can now score and review this interview.');
        } else flash('error','Could not find that meeting.');
        header('Location: '.(($_POST['room_code']??'')!=='' ? 'interview-room.php?code='.urlencode($_POST['room_code']) : 'interviews.php')); exit;
    }
    header('Location: interviews.php'); exit;
}
$applications=$pdo->query("SELECT a.id,c.first_name,c.last_name,j.title FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id WHERE a.status='active' AND a.stage NOT IN ('hired','rejected') ORDER BY c.last_name,c.first_name")->fetchAll();
$interviewers=$pdo->query("SELECT u.id,u.name,u.role,
    (SELECT COUNT(*) FROM interviews i WHERE i.interviewer_id=u.id AND i.starts_at>=NOW() AND i.status NOT IN ('cancelled','no_show')) upcoming_count
    FROM users u WHERE u.role IN ('admin','recruiter','hiring_manager') AND u.active=1 ORDER BY upcoming_count ASC, u.name ASC")->fetchAll();
$busySlots=$pdo->query("SELECT interviewer_id,starts_at FROM interviews WHERE starts_at>=NOW() AND status NOT IN ('cancelled')")->fetchAll();
$rows=$pdo->query('SELECT i.*,c.id candidate_id,c.first_name,c.last_name,c.email,c.profile_image,j.title,u.name interviewer_name
    FROM interviews i
    JOIN applications a ON a.id=i.application_id
    JOIN candidates c ON c.id=a.candidate_id
    JOIN jobs j ON j.id=a.job_id
    LEFT JOIN users u ON u.id=i.interviewer_id
    ORDER BY i.starts_at DESC')->fetchAll();

// Grouped for the page: today first, then what is still coming, then history.
$today=[]; $upcoming=[]; $past=[];
$todayKey=date('Y-m-d');
foreach($rows as $r){
    $state=interview_state($r);
    $day=date('Y-m-d',strtotime($r['starts_at']));
    if(in_array($state,['review_pending','reviewed','cancelled','no_show'],true) && $day<$todayKey){ $past[]=$r; }
    elseif($day===$todayKey){ $today[]=$r; }
    elseif(strtotime($r['starts_at'])>time()){ $upcoming[]=$r; }
    else { $past[]=$r; }
}
$upcoming=array_reverse($upcoming);
$needsReview=array_values(array_filter($rows,fn($r)=>interview_state($r)==='review_pending'));
$pageTitle='Interviews'; include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head"><div><div class="eyebrow">Hiring operations</div><h1>Interviews</h1><p class="meta">Today's conversations, what is coming up, and which interviews still need a score.</p></div></div>
<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>
<div class="interview-layout"><section class="card interview-schedule">
<div class="schedule-head"><h2><?=icon('interviews',18)?> Schedule interview</h2><p class="meta small">Pick the candidate, set the time, and choose how the meeting happens.</p></div>
<div class="schedule-scroll">
<form method="post" class="form-grid schedule-form" id="schedule-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create"><div class="field full"><label>Candidate / role</label><select name="application_id" required><option value="">Select application</option><?php $preselect=(int)($_GET['application']??0); foreach($applications as $a): ?><option value="<?=$a['id']?>" <?=$preselect===(int)$a['id']?'selected':''?>><?=e($a['first_name'].' '.$a['last_name'].' · '.$a['title'])?></option><?php endforeach; ?></select></div><div class="field"><label>Meeting type</label><select name="meeting_type"><option value="screening">Screening</option><option value="interview" selected>Interview</option></select></div><div class="field"><label>Format</label><select name="interview_type"><option value="video">Video</option><option value="phone">Phone</option><option value="panel">Panel</option><option value="onsite">On-site</option></select></div>
<div class="field full"><label>Interviewer</label><select name="interviewer_id" id="interviewer-select"><?php foreach($interviewers as $iv): ?><option value="<?=$iv['id']?>" data-workload="<?=$iv['upcoming_count']?>" <?=(int)$iv['id']===(int)($_SESSION['user_id']??0)?'selected':''?>><?=e($iv['name'])?> (<?=e(ucfirst(str_replace('_',' ',$iv['role'])))?>) — <?=$iv['upcoming_count']?> upcoming</option><?php endforeach; ?></select><div class="field-hint" id="interviewer-availability-hint">Sorted by lightest workload first.</div><div class="modal-warning" id="interviewer-conflict-warning" hidden>⚠ This interviewer already has a meeting at the selected time. Pick another time or interviewer.</div></div>
<div class="field"><label>Meeting</label><div class="meeting-mode-toggle"><label><input type="radio" name="meeting_mode" value="builtin" checked> <?=icon('video',14)?> Built-in Acme Room</label><label><input type="radio" name="meeting_mode" value="external"> External link</label></div></div><div class="field"><label>Starts at</label><input type="datetime-local" name="starts_at" id="starts-at-input" required></div><div class="field"><label>Ends at</label><input type="datetime-local" name="ends_at"></div><div class="field full external-only" hidden><label>External provider</label><input name="meeting_provider" value="Zoom"><label style="margin-top:10px">Meeting link</label><input type="url" name="meeting_url" placeholder="https://zoom.us/j/..."></div><div class="field full"><label>Location</label><input name="location" placeholder="Acme HQ or remote"></div><div class="field full"><label>Notes</label><textarea name="notes" rows="3"></textarea></div><button class="btn wide" type="submit"><?=icon('plus',16)?> Schedule interview</button></form>
</div>
</section>
<section class="interview-column">
<?php
/** One interview rendered as a responsive card. */
function interview_card(array $r): void {
    $state   = interview_state($r);
    $name    = $r['first_name'].' '.$r['last_name'];
    $initials= strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1));
    $isLive  = ($r['room_status'] ?? 'idle') === 'live';
    $canJoin = in_array($state,['ready','in_progress'],true) || $isLive;
    ?>
  <article class="card interview-card state-<?=e($state)?>">
    <div class="ic-head">
      <?php if(!empty($r['profile_image'])): ?>
        <img class="ic-avatar avatar-image" src="<?=e($r['profile_image'])?>" alt="<?=e($name)?>">
      <?php else: ?>
        <span class="ic-avatar"><?=e($initials)?></span>
      <?php endif; ?>
      <div class="ic-identity">
        <h3><?=e($name)?></h3>
        <p class="meta"><?=e($r['title'])?></p>
      </div>
      <div class="ic-status"><?= interview_state_badge($r) ?><?php if($isLive): ?><span class="ic-live"><span class="live-dot"></span>Live</span><?php endif; ?></div>
    </div>

    <div class="ic-meta">
      <div><span class="label">When</span><strong><?=e(date('M j, Y · g:i A',strtotime($r['starts_at'])))?></strong></div>
      <div><span class="label">Type</span><strong><?=e(ucfirst($r['meeting_type']))?> · <?=e(ucfirst($r['interview_type']))?></strong></div>
      <div><span class="label">Interviewer</span><strong><?=e($r['interviewer_name'] ?? 'Unassigned')?></strong></div>
      <div><span class="label">Review</span><strong>
        <?php if($r['score']!==null): ?><span class="score-pill"><?=icon('star',11)?> <?=(int)$r['score']?>/100</span>
        <?php elseif($state==='review_pending'): ?>Awaiting review
        <?php else: ?>—<?php endif; ?>
      </strong></div>
    </div>

    <?php if(!empty($r['live_notes'])): ?>
      <p class="ic-notes meta small"><?=icon('chat',12)?> Notes recorded during the meeting</p>
    <?php endif; ?>

    <div class="ic-actions">
      <?php if($r['room_code'] && $canJoin): ?>
        <a class="btn small" data-open-room href="interview-room.php?code=<?=urlencode($r['room_code'])?>" data-room-code="<?=e($r['room_code'])?>"><?=icon('video',14)?> <span>Join interview room</span></a>
      <?php elseif($r['room_code']): ?>
        <a class="btn small secondary" data-open-room href="interview-room.php?code=<?=urlencode($r['room_code'])?>" data-room-code="<?=e($r['room_code'])?>"><?=icon('video',14)?> <span>Open interview room</span></a>
      <?php elseif($r['meeting_url']): ?>
        <a class="btn small" target="_blank" rel="noopener" href="<?=e($r['meeting_url'])?>"><?=icon('video',14)?> Join <?=e($r['meeting_provider']?:'meeting')?></a>
      <?php endif; ?>
      <a class="btn small secondary" href="candidate.php?id=<?=(int)$r['candidate_id']?>"><?=icon('candidates',14)?> View candidate</a>
      <?php if($state==='review_pending' && $r['room_code']): ?>
        <a class="btn small" href="interview-room.php?code=<?=urlencode($r['room_code'])?>#review"><?=icon('star',14)?> Score &amp; review</a>
      <?php endif; ?>
      <details class="ic-more">
        <summary class="meta small">Status</summary>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="interview_id" value="<?=$r['id']?>">
          <select name="status">
            <?php foreach(['scheduled','confirmed','completed','cancelled','no_show'] as $st): ?>
              <option <?= $r['status']===$st?'selected':'' ?>><?=$st?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn small secondary">Save</button>
        </form>
      </details>
    </div>
  </article>
<?php }
?>

<?php if($needsReview): ?>
  <div class="notice"><strong><?=count($needsReview)?></strong> interview<?=count($needsReview)===1?'':'s'?> finished and <?=count($needsReview)===1?'is':'are'?> waiting for a score and review.</div>
<?php endif; ?>

<div class="section-head" style="margin:0 0 14px"><div><h2>Today</h2></div><span class="meta"><?=count($today)?> scheduled</span></div>
<div class="interview-grid">
  <?php foreach($today as $r) interview_card($r); ?>
  <?php if(!$today): ?><div class="card"><p class="meta">Nothing scheduled for today.</p></div><?php endif; ?>
</div>

<div class="section-head"><div><h2>Upcoming</h2></div><span class="meta"><?=count($upcoming)?> scheduled</span></div>
<div class="interview-grid">
  <?php foreach($upcoming as $r) interview_card($r); ?>
  <?php if(!$upcoming): ?><div class="card"><p class="meta">No upcoming interviews.</p></div><?php endif; ?>
</div>

<?php if($past): ?>
<div class="section-head"><div><h2>Past interviews</h2></div><span class="meta"><?=count($past)?> on record</span></div>
<div class="interview-grid">
  <?php foreach(array_slice($past,0,12) as $r) interview_card($r); ?>
</div>
<?php endif; ?>
</section>
</div>
<script>
window.INTERVIEWER_BUSY_SLOTS = <?=json_encode(array_map(fn($b)=>['interviewer_id'=>(int)$b['interviewer_id'],'starts_at'=>$b['starts_at']], $busySlots))?>;
(function(){
  const select = document.getElementById('interviewer-select');
  const startInput = document.getElementById('starts-at-input');
  const warning = document.getElementById('interviewer-conflict-warning');
  const submitBtn = document.querySelector('#schedule-form button[type=submit]');
  function checkConflict(){
    if (!select || !startInput || !warning) return;
    const interviewerId = parseInt(select.value, 10);
    const startVal = startInput.value ? startInput.value + ':00' : '';
    const conflict = window.INTERVIEWER_BUSY_SLOTS.some(b => b.interviewer_id === interviewerId && b.starts_at.replace(' ', 'T') === startVal);
    warning.hidden = !conflict;
    if (submitBtn) submitBtn.disabled = conflict;
  }
  if (select) select.addEventListener('change', checkConflict);
  if (startInput) startInput.addEventListener('change', checkConflict);
})();
</script>
<script>
document.querySelectorAll('input[name=meeting_mode]').forEach(function (r) {
  r.addEventListener('change', function () {
    document.querySelector('.external-only').hidden =
      document.querySelector('input[name=meeting_mode]:checked').value !== 'external';
  });
});

/* Opening a room is ordinary navigation.
   It used to be e.preventDefault() followed by window.open(...), which popup
   blockers stop — and because the default was already cancelled, the click did
   nothing at all and the button looked dead. The link now simply navigates.
   The only handler left is a short lock so a double click cannot start two
   join attempts. */
document.querySelectorAll('[data-open-room]').forEach(function (a) {
  a.addEventListener('click', function () {
    if (a.dataset.busy === '1') return;
    a.dataset.busy = '1';
    a.classList.add('is-busy');
    var label = a.querySelector('span');
    if (label) label.textContent = 'Opening…';
    // Released if the browser restores this page from the back/forward cache.
    setTimeout(function () { a.dataset.busy = ''; a.classList.remove('is-busy'); }, 8000);
  });
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
