<?php
require_once __DIR__.'/includes/config.php'; $pdo=db(); $slug=trim($_GET['slug']??'senior-product-engineer');
$stmt=$pdo->prepare("SELECT j.*,d.name department,(SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id) applications FROM jobs j LEFT JOIN departments d ON d.id=j.department_id WHERE j.slug=? AND j.status='open' LIMIT 1"); $stmt->execute([$slug]); $job=$stmt->fetch();
if(!$job){http_response_code(404); $pageTitle='Role not found'; include __DIR__.'/includes/header.php'; echo '<div class="hero"><div class="eyebrow">404</div><h1>Role not found.</h1><p>This position may have closed or moved.</p><a class="btn" href="jobs.php">View open roles</a></div>'; include __DIR__.'/includes/footer.php'; exit;}
$limit=$job['applicant_limit']!==null?(int)$job['applicant_limit']:null; $remaining=spots_remaining($limit,(int)$job['applications']); $full=$remaining===0; $tags=job_tags($job['tags']??'');
$pageTitle=$job['title']; $minimal=true; include __DIR__.'/includes/header.php';
?>
<a class="meta back-link" href="jobs.php"><?=icon('chevron',15)?><span>Back to roles</span></a>
<div class="hero">
<div class="job-detail-badges"><span class="pill"><?=e($job['department']??'Acme')?> · <?=e($job['location']?:'Flexible')?></span><?php if($job['is_urgent']): ?><span class="pill" style="background:#fdf3e2;color:#8a6212">Urgent Hiring</span><?php endif; ?><span class="meta small"><?=icon('clock',13)?> Posted <?=e(date('M j, Y', strtotime($job['published_at']?:$job['created_at'])))?></span></div>
<h1><?=e($job['title'])?></h1>
<p><?=e(preg_replace('/\s+/', ' ', substr(strip_tags($job['description']??'Tell us why you would be a great fit for this role.'),0,260)))?></p>
<?php if($tags): ?><div class="tag-row"><?php foreach($tags as $t): ?><span class="tag-pill"><?=icon('tag',12)?><?=e($t)?></span><?php endforeach; ?></div><?php endif; ?>
<div class="actions">
<?php if($full): ?><span class="btn disabled"><?=icon('limit',16)?> Applications closed — role is full</span><?php else: ?><a class="btn" href="apply.php?job=<?=urlencode($job['slug'])?>"><?=icon('plus',16)?> Apply for this role</a><?php endif; ?>
<a class="btn secondary" href="refer.php">Refer someone</a>
</div>
<div class="applicant-meter"><?=icon('candidates',15)?> <strong><?=$job['applications']?></strong><span> applied<?=$limit!==null?" of {$limit} spots":''?></span><?php if($limit!==null): $pct=min(100,round(($job['applications']/max(1,$limit))*100)); ?><div class="cap-bar wide"><span style="width:<?=$pct?>%"></span></div><?php endif; ?></div>
</div>
<div class="card">
  <div class="eyebrow">About the role</div>
  <div class="job-description"><?=nl2br(e($job['description']?:'We are looking for thoughtful people who want to do meaningful work with a collaborative team.'))?></div>
  <?php $reqs = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($job['requirements'] ?? ''))))); ?>
  <?php if ($reqs): ?>
    <div class="eyebrow" style="margin-top:26px">Requirements</div>
    <ul style="padding-left:1.25rem;color:var(--muted);line-height:1.8;margin-top:10px">
      <?php foreach ($reqs as $req): ?><li><?=e(ltrim($req, '-• '))?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>
