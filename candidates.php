<?php
require_once __DIR__.'/includes/auth.php'; require_login(['admin','recruiter','hiring_manager']); $pdo=db();

$stageFilter = trim($_GET['stage'] ?? '');
$allowedStages = ['new','screening','interview','offer','hired','rejected'];
$jobFilter    = (int)($_GET['job'] ?? 0);
$ownerFilter  = (int)($_GET['owner'] ?? 0);
$ratingFilter = trim($_GET['rating'] ?? '');
$sortKey      = $_GET['sort'] ?? 'recent';

// Filtering happens in SQL so the counts and the rows always agree, and so a
// long list is not shipped to the browser just to be hidden there.
$sql = "SELECT a.id application_id,a.stage,a.applied_at,a.assigned_to,
               c.id candidate_id,c.first_name,c.last_name,c.email,c.rating,c.resume_path,c.profile_image,c.source,
               (SELECT cd.id FROM candidate_documents cd WHERE cd.candidate_id=c.id AND cd.is_primary=1 LIMIT 1) primary_doc_id,
               j.id job_id,j.title job_title, u.name owner_name
        FROM applications a
        JOIN candidates c ON c.id=a.candidate_id
        JOIN jobs j ON j.id=a.job_id
        LEFT JOIN users u ON u.id=a.assigned_to
        WHERE a.status='active'";
$params = [];
if ($stageFilter !== '' && in_array($stageFilter, $allowedStages, true)) { $sql .= ' AND a.stage=?'; $params[] = $stageFilter; }
if ($jobFilter)   { $sql .= ' AND j.id=?';  $params[] = $jobFilter; }
if ($ownerFilter) { $sql .= ' AND a.assigned_to=?'; $params[] = $ownerFilter; }
if ($ratingFilter === 'unrated')      { $sql .= ' AND (c.rating IS NULL OR c.rating=0)'; }
elseif (ctype_digit($ratingFilter))   { $sql .= ' AND c.rating >= ?'; $params[] = (int)$ratingFilter; }

$sorts = [
    'recent' => 'a.applied_at DESC',
    'oldest' => 'a.applied_at ASC',
    'name'   => 'c.first_name ASC, c.last_name ASC',
    'rating' => 'c.rating DESC, a.applied_at DESC',
    'role'   => 'j.title ASC, a.applied_at DESC',
];
$sql .= ' ORDER BY ' . ($sorts[$sortKey] ?? $sorts['recent']);

try {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $candidates = $stmt->fetchAll();
} catch (PDOException $e) {
    // candidate_documents does not exist yet on this install (the resume-
    // storage migration has not been run). Fall back to the query without
    // that subquery rather than a raw SQL error on every visit to this page;
    // the Resume column below already checks for a missing primary_doc_id.
    $sqlLegacy = str_replace(
        "               (SELECT cd.id FROM candidate_documents cd WHERE cd.candidate_id=c.id AND cd.is_primary=1 LIMIT 1) primary_doc_id,\n",
        '', $sql
    );
    $stmt = $pdo->prepare($sqlLegacy); $stmt->execute($params); $candidates = $stmt->fetchAll();
}
$stageLabels = ['new'=>'Applied','screening'=>'Screening','interview'=>'Interview','offer'=>'Offer','hired'=>'Hired','rejected'=>'Rejected'];

$stageCounts = $pdo->query("SELECT stage, COUNT(*) c FROM applications WHERE status='active' GROUP BY stage")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalActive = array_sum($stageCounts);

$roleOptions  = $pdo->query("SELECT DISTINCT j.id, j.title FROM jobs j JOIN applications a ON a.job_id=j.id WHERE a.status='active' ORDER BY j.title")->fetchAll();
$ownerOptions = $pdo->query("SELECT DISTINCT u.id, u.name FROM users u JOIN applications a ON a.assigned_to=u.id WHERE a.status='active' ORDER BY u.name")->fetchAll();

$activeFilters = ($jobFilter?1:0) + ($ownerFilter?1:0) + ($ratingFilter!==''?1:0) + ($sortKey!=='recent'?1:0);

$pageTitle='Candidates'; include __DIR__.'/includes/header.php';
?>
<!-- The candidates page is a CSS size container. Its breakpoints are written
     as @container queries, so the layout reacts to the width the content area
     really has after the sidebar takes its share — a viewport media query
     cannot know whether the sidebar is 250px or 72px. -->
<div class="page-container candidates-page">

<!-- PAGE HEADER — title, supporting count, page-level action -->
<div class="dashboard-head">
  <div>
    <div class="eyebrow">Talent pool</div>
    <h1>Candidates</h1>
    <p class="meta"><strong><?=count($candidates)?></strong> <?=count($candidates)===1?'candidate':'candidates'?><?= $activeFilters || $stageFilter!=='' ? ' matching your filters' : ' across every open role' ?><span class="count-total"> · <?=$totalActive?> active in total</span></p>
  </div>
  <div class="actions" style="margin-top:0">
    <a class="btn secondary" href="pipeline.php"><?=icon('pipeline',15)?> View pipeline</a>
    <a class="btn" href="add_candidate.php"><?=icon('plus',15)?> Add candidate</a>
  </div>
</div>

<!-- STAGE TABS — scoping, kept separate from the toolbar -->
<div class="stage-tabs">
  <a class="stage-tab <?=$stageFilter===''?'active':''?>" href="candidates.php">All <?=$totalActive?></a>
  <?php foreach($stageLabels as $key=>$label): $c=(int)($stageCounts[$key]??0); ?>
    <a class="stage-tab <?=$stageFilter===$key?'active':''?>" href="candidates.php?stage=<?=e($key)?>"><?=e($label)?> <?=$c?></a>
  <?php endforeach; ?>
</div>

<!-- SEARCH & ACTION TOOLBAR
     Search sits on the left and takes the free space (flex:1); the filter
     group and the actions never shrink, so buttons cannot be squeezed or
     pushed out of the toolbar. -->
<form class="candidates-toolbar" method="get" role="search">
  <?php if($stageFilter!==''): ?><input type="hidden" name="stage" value="<?=e($stageFilter)?>"><?php endif; ?>

  <div class="search-wrapper">
    <label class="sr-only" for="cand-search">Search candidates</label>
    <span class="search-icon" aria-hidden="true"><?=icon('search',16)?></span>
    <input id="cand-search" class="search-input" data-filter type="search"
           placeholder="Search candidates by name, email, or role…" autocomplete="off">
  </div>

  <div class="toolbar-filters">
    <label class="sr-only" for="f-job">Role</label>
    <select id="f-job" name="job" aria-label="Filter by role">
      <option value="">All roles</option>
      <?php foreach($roleOptions as $r): ?><option value="<?=$r['id']?>" <?=$jobFilter===(int)$r['id']?'selected':''?>><?=e($r['title'])?></option><?php endforeach; ?>
    </select>

    <label class="sr-only" for="f-owner">HR / Recruiter</label>
    <select id="f-owner" name="owner" aria-label="Filter by HR or recruiter">
      <option value="">All HR / Recruiters</option>
      <?php foreach($ownerOptions as $o): ?><option value="<?=$o['id']?>" <?=$ownerFilter===(int)$o['id']?'selected':''?>><?=e($o['name'])?></option><?php endforeach; ?>
    </select>

    <label class="sr-only" for="f-rating">Rating</label>
    <select id="f-rating" name="rating" aria-label="Filter by rating">
      <option value="">Any rating</option>
      <option value="unrated" <?=$ratingFilter==='unrated'?'selected':''?>>Not yet rated</option>
      <?php foreach([4,3,2,1] as $rv): ?><option value="<?=$rv?>" <?=$ratingFilter===(string)$rv?'selected':''?>><?=$rv?>+ stars</option><?php endforeach; ?>
    </select>

    <label class="sr-only" for="f-sort">Sort</label>
    <select id="f-sort" name="sort" aria-label="Sort candidates">
      <option value="recent" <?=$sortKey==='recent'?'selected':''?>>Newest first</option>
      <option value="oldest" <?=$sortKey==='oldest'?'selected':''?>>Oldest first</option>
      <option value="name" <?=$sortKey==='name'?'selected':''?>>Name A–Z</option>
      <option value="rating" <?=$sortKey==='rating'?'selected':''?>>Highest rated</option>
      <option value="role" <?=$sortKey==='role'?'selected':''?>>By role</option>
    </select>
  </div>

  <div class="toolbar-actions">
    <button class="btn small" type="submit"><?=icon('filter',14)?> Apply</button>
    <?php if($activeFilters): ?>
      <a class="btn small ghost" href="candidates.php<?= $stageFilter!=='' ? '?stage='.e($stageFilter) : '' ?>">Clear</a>
    <?php endif; ?>
  </div>
</form>


<!-- One list, two presentations: a table on wide screens and stacked cards on
     narrow ones. Same markup, so nothing is duplicated or hidden from search. -->
<div class="card candidate-list-card">
<table class="table candidate-table">
<thead><tr><th></th><th>Candidate</th><th>Role</th><th>Stage</th><th>Rating</th><th>Assigned to</th><th>Applied</th><th>Resume</th><th></th></tr></thead>
<tbody>
<?php foreach ($candidates as $c):
    $initials = strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1));
    $rating = (int)$c['rating'];
?>
<tr data-row>
  <td data-label="" class="cell-avatar">
    <?php if(!empty($c['profile_image'])): ?>
      <img class="avatar-chip avatar-image" src="<?=e($c['profile_image'])?>" alt="<?=e($c['first_name'].' '.$c['last_name'])?>">
    <?php else: ?>
      <span class="avatar-chip"><?=e($initials)?></span>
    <?php endif; ?>
  </td>
  <td data-label="Candidate" class="cell-name">
    <a class="candidate-name" href="candidate.php?id=<?=$c['application_id']?>"><?=e($c['first_name'].' '.$c['last_name'])?></a>
    <span class="email-cell meta small" title="<?=e($c['email'])?>"><?=e($c['email'])?></span>
  </td>
  <td data-label="Role" class="cell-role"><span class="role-text" title="<?=e($c['job_title'])?>"><?=e($c['job_title'])?></span></td>
  <td data-label="Stage" class="cell-stage"><?=stage_badge($c['stage'])?></td>
  <td data-label="Rating" class="cell-rating">
    <?php if($rating>0): ?>
      <span class="rating-mini" title="<?=$rating?> out of 5"><?php for($i=1;$i<=5;$i++): ?><span class="<?= $i<=$rating?'on':'' ?>"><?= $i<=$rating?'★':'☆' ?></span><?php endfor; ?></span>
    <?php else: ?><span class="meta small">Not yet rated</span><?php endif; ?>
  </td>
  <td data-label="Assigned to" class="cell-owner meta"><span title="<?=e($c['owner_name'] ?? 'Unassigned')?>"><?=e($c['owner_name'] ?? 'Unassigned')?></span></td>
  <td data-label="Applied" class="cell-date meta"><?=e(date('M j, Y', strtotime($c['applied_at'])))?></td>
  <td data-label="Resume" class="cell-resume">
    <?php if(!empty($c['primary_doc_id'])): ?>
      <a class="btn small secondary" href="download.php?file_id=<?=(int)$c['primary_doc_id']?>&disposition=inline" target="_blank" rel="noopener">View</a>
    <?php elseif($c['resume_path']): ?>
      <!-- Legacy: a resume stored before candidate_documents existed. -->
      <a class="btn small secondary" href="<?=e($c['resume_path'])?>" target="_blank" rel="noopener">View</a>
    <?php else: ?><span class="meta small">None</span><?php endif; ?>
  </td>
  <td data-label="" class="cell-action"><a class="btn small" href="candidate.php?id=<?=$c['application_id']?>">Open profile</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if(!$candidates): ?>
  <div class="empty-panel">
    <h2><?=icon('candidates',20)?> No candidates found</h2>
    <p class="meta"><?= $activeFilters || $stageFilter!=='' ? 'Nothing matches these filters. Try widening them.' : 'Applicants will appear here as soon as they apply.' ?></p>
  </div>
<?php endif; ?>
<p class="meta small no-results" data-no-results hidden>No candidates match your search.</p>
</div>

<script>
/* The shared [data-filter] handler hides non-matching rows. This just surfaces
   an honest empty state when everything ends up hidden, rather than leaving a
   blank card. */
(function () {
  var input = document.querySelector('[data-filter]');
  var note  = document.querySelector('[data-no-results]');
  if (!input || !note) return;
  input.addEventListener('input', function () {
    var rows = document.querySelectorAll('[data-row]');
    var visible = 0;
    rows.forEach(function (r) { if (r.style.display !== 'none') visible++; });
    note.hidden = visible > 0 || rows.length === 0;
  });
})();
</script>

</div><!-- /.candidates-page -->

<?php include __DIR__.'/includes/footer.php'; ?>
