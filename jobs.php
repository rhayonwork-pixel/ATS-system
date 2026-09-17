<?php
require_once __DIR__.'/includes/config.php';
$pdo=db(); $team=trim($_GET['team']??'');
$sql="SELECT j.*,d.name department,(SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id) applications FROM jobs j LEFT JOIN departments d ON d.id=j.department_id WHERE j.status='open'"; $params=[];
if($team!==''){ $sql.=' AND d.name=?'; $params[]=$team; }
$sql.=' ORDER BY j.published_at DESC,j.created_at DESC'; $stmt=$pdo->prepare($sql); $stmt->execute($params); $jobs=$stmt->fetchAll();
$pageTitle='Open roles'; $minimal=true; include __DIR__.'/includes/header.php';
?>
<div class="hero"><div class="eyebrow">Open roles</div><h1>Find your place here.</h1><p><?=count($jobs)?> open position<?=count($jobs)===1?'':'s'?> right now. Search the roles where your work can have an outsized impact.</p></div>
<div class="filters"><input data-filter placeholder="Search jobs, tags, locations..."><select><option>All departments</option><?php foreach($pdo->query('SELECT name FROM departments ORDER BY name') as $d): ?><option><?=e($d['name'])?></option><?php endforeach; ?></select><select><option>All locations</option><option>Remote</option><option>Manila</option></select></div>
<div class="grid"><?php foreach($jobs as $job): $limit=$job['applicant_limit']!==null?(int)$job['applicant_limit']:null; $remaining=spots_remaining($limit,(int)$job['applications']); $full=$remaining===0; $tags=job_tags($job['tags']??''); ?>
<a data-row class="card job-card <?=$full?'is-full':''?>" href="job-detail.php?slug=<?=urlencode($job['slug'])?>">
<div class="job-card-top"><span class="pill"><?=e($job['department']??'Acme')?></span><?php if($job['is_urgent']): ?><span class="badge low">Urgent</span><?php endif; ?><?php if($full): ?><span class="badge full">Full</span><?php elseif($limit!==null && $remaining<=5): ?><span class="badge low"><?=icon('limit',11)?> <?=$remaining?> spots left</span><?php endif; ?></div>
<div><h3><?=e($job['title'])?></h3><span class="meta"><?=e($job['location']?:'Location flexible')?> · <?=e(str_replace('_',' ',ucfirst($job['employment_type'])))?></span></div>
<?php if($tags): ?><div class="tag-row"><?php foreach($tags as $t): ?><span class="tag-pill"><?=icon('tag',11)?><?=e($t)?></span><?php endforeach; ?></div><?php endif; ?>
<div class="job-card-bottom"><span class="meta small"><?=icon('clock',13)?> Posted <?=e(date('M j, Y', strtotime($job['published_at']?:$job['created_at'])))?></span><span class="meta small"><?=icon('candidates',13)?> <?=$job['applications']?> applied</span><span class="card-arrow" aria-hidden="true"><?=icon('chevron',15)?></span></div>
</a><?php endforeach; if(!$jobs): ?><div class="card"><h2>No open roles right now.</h2><p class="meta">Check back soon for new opportunities.</p></div><?php endif; ?></div>
<?php include __DIR__.'/includes/footer.php'; ?>
