<?php
require_once __DIR__.'/includes/auth.php';
$pdo=db();
$openRoles=(int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status='open'")->fetchColumn();
$activeCandidates=(int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='active' AND stage NOT IN ('hired','rejected')")->fetchColumn();
$weekInterviews=(int)$pdo->query("SELECT COUNT(*) FROM interviews WHERE starts_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY) AND status NOT IN ('cancelled','no_show')")->fetchColumn();
$hiredThisMonth=(int)$pdo->query("SELECT COUNT(*) FROM applications WHERE stage='hired' AND updated_at>=DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();

// Week-over-week deltas for the stat cards (real data, not decorative).
$appsThisWeek=(int)$pdo->query("SELECT COUNT(*) FROM applications WHERE applied_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
$appsPrevWeek=(int)$pdo->query("SELECT COUNT(*) FROM applications WHERE applied_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) AND applied_at<DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
$activePrevWeek=(int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status='active' AND stage NOT IN ('hired','rejected') AND applied_at<DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
$openRolesPrevWeek=(int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND published_at<DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();

function delta_badge(int $current, int $previous): string {
    $diff = $current - $previous;
    if ($diff > 0) return '<span class="ov-stat-delta up">+'.$diff.'</span>';
    if ($diff < 0) return '<span class="ov-stat-delta down">'.$diff.'</span>';
    return '<span class="ov-stat-delta flat">±0</span>';
}

// 14-day applications trend for the area chart.
$trendRows = $pdo->query("SELECT DATE(applied_at) d, COUNT(*) c FROM applications WHERE applied_at>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) GROUP BY DATE(applied_at)")->fetchAll(PDO::FETCH_KEY_PAIR);
$trend = [];
for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $trend[$d] = (int)($trendRows[$d] ?? 0); }
$trendMax = max(1, ...array_values($trend));

$upcoming = $pdo->query("SELECT i.starts_at,i.interview_type,c.first_name,c.last_name,c.profile_image,j.title FROM interviews i
    JOIN applications a ON a.id=i.application_id JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id
    WHERE i.starts_at>=NOW() AND i.status NOT IN ('cancelled','no_show') ORDER BY i.starts_at ASC LIMIT 5")->fetchAll();

$recent=$pdo->query("SELECT a.id application_id,c.first_name,c.last_name,c.profile_image,j.title,a.stage,a.updated_at FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id ORDER BY a.updated_at DESC LIMIT 8")->fetchAll();

$pageTitle='Overview'; include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head"><div><div class="eyebrow">People operations</div><h1>Recruiter overview</h1><p class="meta">A working view of your hiring operation.</p></div>
  <div class="button-row"><a class="btn secondary" href="jobs.php" target="_blank"><?=icon('public',15)?> View careers site</a><a class="btn" href="admin.php">+ New job</a></div>
</div>

<div class="ov-stats">
  <a class="ov-stat" href="admin.php" style="text-decoration:none">
    <div class="ov-stat-top"><div class="ov-stat-icon"><?=icon('overview',18)?></div><?=delta_badge($openRoles,$openRolesPrevWeek)?></div>
    <div class="ov-stat-num"><?=$openRoles?></div><div class="ov-stat-label">Open roles</div>
  </a>
  <a class="ov-stat" href="candidates.php" style="text-decoration:none">
    <div class="ov-stat-top"><div class="ov-stat-icon"><?=icon('candidates',18)?></div><?=delta_badge($activeCandidates,$activePrevWeek)?></div>
    <div class="ov-stat-num"><?=$activeCandidates?></div><div class="ov-stat-label">Active candidates</div>
  </a>
  <a class="ov-stat" href="interviews.php" style="text-decoration:none">
    <div class="ov-stat-top"><div class="ov-stat-icon"><?=icon('pipeline',18)?></div><span class="ov-stat-delta flat">7 days</span></div>
    <div class="ov-stat-num"><?=$weekInterviews?></div><div class="ov-stat-label">Interviews this week</div>
  </a>
  <a class="ov-stat" href="candidates.php?stage=hired" style="text-decoration:none">
    <div class="ov-stat-top"><div class="ov-stat-icon"><?=icon('overview',18)?></div><span class="ov-stat-delta flat">MTD</span></div>
    <div class="ov-stat-num"><?=$hiredThisMonth?></div><div class="ov-stat-label">Hired this month</div>
  </a>
</div>

<div class="card trend-card">
  <div class="trend-head">
    <div><div class="label">Applications received</div><p class="meta small" style="margin-top:4px">Last 14 days · <?=delta_badge($appsThisWeek,$appsPrevWeek)?> vs. the previous 7 days</p></div>
  </div>
  <?php
    $w=760; $h=190; $pad=8; $n=count($trend); $stepX = $n>1 ? ($w-$pad*2)/($n-1) : 0;
    $pts = []; $i=0;
    foreach ($trend as $d=>$c) { $x = $pad + $stepX*$i; $y = $h - $pad - (($c/$trendMax) * ($h-$pad*2)); $pts[] = [$x,$y,$d,$c]; $i++; }
    $linePath = 'M'.implode(' L', array_map(fn($p)=>round($p[0],1).','.round($p[1],1), $pts));
    $areaPath = $linePath.' L'.round($pts[count($pts)-1][0],1).','.$h.' L'.round($pts[0][0],1).','.$h.' Z';
  ?>
  <svg class="trend-svg" viewBox="0 0 <?=$w?> <?=$h?>" preserveAspectRatio="none">
    <defs><linearGradient id="trendFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--green)" stop-opacity="0.28"/><stop offset="100%" stop-color="var(--green)" stop-opacity="0"/></linearGradient></defs>
    <path d="<?=$areaPath?>" fill="url(#trendFill)" stroke="none"></path>
    <path d="<?=$linePath?>" fill="none" stroke="var(--green)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"></path>
    <?php foreach ($pts as $p): ?><circle class="trend-point" cx="<?=round($p[0],1)?>" cy="<?=round($p[1],1)?>" r="3" fill="var(--green)"><title><?=e(date('M j', strtotime($p[2])))?>: <?=$p[3]?> application<?=$p[3]===1?'':'s'?></title></circle><?php endforeach; ?>
  </svg>
  <div class="trend-axis"><span><?=e(date('M j', strtotime(array_key_first($trend))))?></span><span>Today</span></div>
</div>

<div class="grid two" style="margin-top:22px">
  <div class="card">
    <div class="section-heading" style="margin-bottom:6px"><h2 style="font-size:18px;margin:0">Upcoming interviews</h2><a class="meta small" href="interviews.php">See all →</a></div>
    <?php foreach ($upcoming as $u): ?>
      <div class="upcoming-row">
        <div class="upcoming-date"><strong><?=e(date('j', strtotime($u['starts_at'])))?></strong><span><?=e(date('M', strtotime($u['starts_at'])))?></span></div>
        <div style="flex:1;display:flex;align-items:center;gap:10px"><?php if(!empty($u['profile_image'])): ?><img class="avatar-chip avatar-image" src="<?=e($u['profile_image'])?>" alt="<?=e($u['first_name'].' '.$u['last_name'])?>"><?php else: ?><span class="avatar-chip"><?=e(strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1)))?></span><?php endif; ?><div><strong style="display:block;font-size:14px"><?=e($u['first_name'].' '.$u['last_name'])?></strong><span class="meta small"><?=e($u['title'])?> · <?=e(date('g:i A', strtotime($u['starts_at'])))?> · <?=e(ucfirst($u['interview_type']))?></span></div>
      </div></div>
    <?php endforeach; if(!$upcoming): ?><p class="meta">No interviews scheduled yet.</p><?php endif; ?>
  </div>

  <div class="card">
    <div class="section-heading" style="margin-bottom:6px"><h2 style="font-size:18px;margin:0">Applications to review</h2><a class="meta small" href="candidates.php?stage=new">See all →</a></div>
    <?php
      $toReview = $pdo->query("SELECT j.id,j.title,j.published_at,
          (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id AND a.stage='new') new_count,
          (SELECT GROUP_CONCAT(SUBSTRING(c.first_name,1,1) SEPARATOR '') FROM applications a JOIN candidates c ON c.id=a.candidate_id WHERE a.job_id=j.id ORDER BY a.applied_at DESC LIMIT 4) initials_group
          FROM jobs j WHERE j.status='open' ORDER BY j.published_at DESC LIMIT 5")->fetchAll();
    ?>
    <?php foreach ($toReview as $t): $initials = str_split($t['initials_group'] ?? ''); ?>
      <div class="list-row">
        <div><strong style="display:block;font-size:14px"><?=e($t['title'])?></strong><span class="meta small">Published <?=e($t['published_at']?date('M j, Y',strtotime($t['published_at'])):'—')?></span></div>
        <div style="display:flex;align-items:center;gap:10px">
          <div class="avatar-stack"><?php foreach(array_slice($initials,0,3) as $ini): ?><span class="avatar-chip"><?=e($ini)?></span><?php endforeach; if($t['new_count']>3): ?><span class="avatar-chip more">+<?=$t['new_count']-3?></span><?php endif; ?></div>
          <a class="meta small" href="candidates.php?stage=new">Review →</a>
        </div>
      </div>
    <?php endforeach; if(!$toReview): ?><p class="meta">No open roles right now.</p><?php endif; ?>
  </div>
</div>

<div class="section-head"><h2>Recent applications</h2><a class="meta" href="candidates.php">See all candidates →</a></div>
<div class="card">
<table class="table">
<tr><th>Candidate</th><th>Role</th><th>Stage</th><th>Updated</th></tr>
<?php foreach($recent as $r): $initials = strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1)); ?>
<tr>
  <td><div style="display:flex;align-items:center;gap:10px"><?php if(!empty($r['profile_image'])): ?><img class="avatar-chip avatar-image" src="<?=e($r['profile_image'])?>" alt="<?=e($r['first_name'].' '.$r['last_name'])?>"><?php else: ?><span class="avatar-chip" style="margin-left:0"><?=e($initials)?></span><?php endif; ?><strong><?=e($r['first_name'].' '.$r['last_name'])?></strong></div></td>
  <td><?=e($r['title'])?></td>
  <td><?=stage_badge($r['stage'])?></td>
  <td class="meta"><?=e(date('M j, g:i A',strtotime($r['updated_at'])))?></td>
</tr>
<?php endforeach; if(!$recent): ?>
<tr><td colspan="4" class="meta">No applications yet. Publish a job and the public application form will feed candidates here.</td></tr>
<?php endif; ?>
</table>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>
