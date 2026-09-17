<?php
require_once __DIR__.'/includes/auth.php'; require_login(['admin']);
// Being an Admin is not enough on its own — the Super Admin decides which
// Admins get Job Management, and this is checked before anything is read or written.
require_permission(PERM_JOB_MANAGEMENT);
$pdo=db();
$canPublish = can_publish_jobs();
if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf(); $action=$_POST['action']??'';
    try {
        if($action==='job_create'){
            $title=trim($_POST['title']??''); $slug=slugify($title);
            $base=$slug; $n=2; while($pdo->prepare('SELECT id FROM jobs WHERE slug=?')->execute([$slug]) && $pdo->query("SELECT COUNT(*) FROM jobs WHERE slug=".$pdo->quote($slug))->fetchColumn()){ $slug=$base.'-'.$n++; }
            $tags = implode(',', job_tags(trim($_POST['tags']??'')));
            $limitRaw = trim($_POST['applicant_limit']??''); $limit = $limitRaw==='' ? null : max(0,(int)$limitRaw);
            $status=in_array($_POST['status']??'draft',['draft','open','paused','closed'],true)?$_POST['status']:'draft';
            // Publishing needs the Job posting permission on top of Job management.
            if($status==='open' && !$canPublish) throw new RuntimeException('Publishing a role requires the "Job posting" permission. Save it as a draft instead.');
            $approval = $status==='open' ? 'approved' : 'draft';
            $s=$pdo->prepare('INSERT INTO jobs(department_id,title,slug,location,employment_type,tags,applicant_limit,description,requirements,is_urgent,status,approval_status,owner_id,created_by,published_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([(int)$_POST['department_id'], $title, $slug, trim($_POST['location']??''), $_POST['employment_type']??'full_time', $tags?:null, $limit, trim($_POST['description']??''), trim($_POST['requirements']??'') ?: null, isset($_POST['is_urgent'])?1:0, $status, $approval, $_SESSION['user_id'], $_SESSION['user_id'], $status==='open'?date('Y-m-d H:i:s'):null]);
            $newJobId=(int)$pdo->lastInsertId();
            if($status==='open') record_job_approval($newJobId,'published','Created and published by an Admin');
            audit('create','job',$newJobId,['title'=>$title,'status'=>$status]); flash('success','Job listing created.');
        } elseif($action==='job_status'){
            $id=(int)$_POST['job_id']; $status=$_POST['status']??'draft';
            if(!in_array($status,['draft','open','paused','closed'],true)) throw new RuntimeException('Invalid status.');
            $cur=$pdo->prepare('SELECT * FROM jobs WHERE id=? LIMIT 1'); $cur->execute([$id]); $curJob=$cur->fetch();
            if(!$curJob) throw new RuntimeException('That job no longer exists.');
            if($status==='open'){
                if(!$canPublish) throw new RuntimeException('Publishing a role requires the "Job posting" permission.');
                // A posting still waiting on review cannot be shortcut to live from here.
                if($curJob['approval_status']==='pending') throw new RuntimeException('That posting is still awaiting approval. Review it in Job approvals first.');
                if(in_array($curJob['approval_status'],['rejected','changes_requested'],true)) throw new RuntimeException('That posting was returned to its author and cannot be published until it is resubmitted and approved.');
            }
            $s=$pdo->prepare('UPDATE jobs SET status=?,published_at=CASE WHEN ?="open" AND published_at IS NULL THEN NOW() ELSE published_at END WHERE id=?'); $s->execute([$status,$status,$id]);
            if($status==='open') record_job_approval($id,'published','Status changed to open by an Admin');
            audit('update','job',$id,['title'=>$curJob['title'],'status'=>$status]); flash('success','Job listing status updated.');
        } elseif($action==='job_limits'){
            $id=(int)$_POST['job_id']; $tags = implode(',', job_tags(trim($_POST['tags']??'')));
            $limitRaw = trim($_POST['applicant_limit']??''); $limit = $limitRaw==='' ? null : max(0,(int)$limitRaw);
            $s=$pdo->prepare('UPDATE jobs SET tags=?,applicant_limit=? WHERE id=?'); $s->execute([$tags?:null,$limit,$id]); audit('update','job',$id); flash('success','Tags and applicant limit updated.');
        } elseif($action==='job_content'){
            $id=(int)$_POST['job_id'];
            $s=$pdo->prepare('UPDATE jobs SET description=?,requirements=?,is_urgent=? WHERE id=?');
            $s->execute([trim($_POST['description']??''), trim($_POST['requirements']??'') ?: null, isset($_POST['is_urgent'])?1:0, $id]);
            audit('update','job',$id); flash('success','Role details and requirements updated.');
        } elseif($action==='job_urgent'){
            $id=(int)$_POST['job_id']; $urgent = isset($_POST['is_urgent']) ? 1 : 0;
            $pdo->prepare('UPDATE jobs SET is_urgent=? WHERE id=?')->execute([$urgent,$id]);
            audit('update','job',$id); flash('success', $urgent ? 'Marked as urgent hiring.' : 'Removed urgent hiring flag.');
        } elseif($action==='department'){
            $s=$pdo->prepare('INSERT INTO departments(name,description) VALUES(?,?)'); $s->execute([trim($_POST['name']),trim($_POST['description']??'')]); audit('create','department',(int)$pdo->lastInsertId()); flash('success','Department created.');
        }
    } catch(Throwable $e){ flash('error','Could not save this change: '.$e->getMessage()); }
    header('Location: admin.php'); exit;
}
$departments=$pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();
$jobs=$pdo->query('SELECT j.*,d.name department,cu.name creator_name,(SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id) applications FROM jobs j LEFT JOIN departments d ON d.id=j.department_id LEFT JOIN users cu ON cu.id=j.created_by ORDER BY j.created_at DESC')->fetchAll();
$pendingApprovals=(int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE approval_status='pending'")->fetchColumn();
$apps=(int)$pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn();
$interviews=(int)$pdo->query('SELECT COUNT(*) FROM interviews WHERE starts_at>=NOW() AND status NOT IN ("cancelled","no_show")')->fetchColumn();
$pageTitle='Job management'; include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Job management</h1><p class="meta">Control what is published on the public careers site, cap applicant volume per role, and tag roles for easier browsing.</p></div><div class="actions" style="margin-top:0"><?php if(has_permission(PERM_JOB_POSTING)): ?><a class="btn" href="job-post.php"><?=icon('plus',16)?> Create job posting</a><?php endif; ?><?php if(can_review_approvals()): ?><a class="btn secondary" href="job-approvals.php"><?=icon('check',16)?> Job approvals<?php if($pendingApprovals): ?> <span class="nav-count"><?=$pendingApprovals?></span><?php endif; ?></a><?php endif; ?><?php if(has_permission(PERM_AUDIT_TRAIL)): ?><a class="btn secondary" href="audit_trail.php"><?=icon('analytics',16)?> Audit Trail</a><?php endif; ?><a class="btn secondary" href="settings.php"><?=icon('settings',16)?> Workspace settings</a></div></div>
<?php if(can_review_approvals() && $pendingApprovals): ?><div class="notice"><strong><?=$pendingApprovals?></strong> job posting<?=$pendingApprovals===1?'':'s'?> submitted by HR/Recruiters <?=$pendingApprovals===1?'is':'are'?> waiting for your review. <a href="job-approvals.php">Open the approval queue →</a></div><?php endif; ?>
<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>
<div class="stats"><div class="card stat"><span class="stat-icon"><?=icon('overview')?></span><strong><?=count($jobs)?></strong><span>Job listings</span></div><div class="card stat"><span class="stat-icon"><?=icon('candidates')?></span><strong><?=$apps?></strong><span>Total applications</span></div><div class="card stat"><span class="stat-icon"><?=icon('interviews')?></span><strong><?=$interviews?></strong><span>Upcoming interviews</span></div><div class="card stat"><span class="stat-icon"><?=icon('users')?></span><strong><?=count($departments)?></strong><span>Departments</span></div></div>
<div class="admin-grid">
<form class="card form" method="post"><h2><?=icon('plus',18)?> Create a hiring role</h2><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="job_create">
<div class="field"><label>Job title</label><input name="title" placeholder="Senior Product Engineer" required></div>
<div class="form-grid"><div class="field"><label>Department</label><select name="department_id" required><?php foreach($departments as $d): ?><option value="<?=$d['id']?>"><?=e($d['name'])?></option><?php endforeach; ?></select></div><div class="field"><label>Employment type</label><select name="employment_type"><option value="full_time">Full-time</option><option value="part_time">Part-time</option><option value="contract">Contract</option><option value="internship">Internship</option></select></div></div>
<div class="field"><label>Location</label><input name="location" placeholder="Remote / Manila"></div>
<div class="form-grid"><div class="field"><label><?=icon('tag',14)?> Tags <span class="hint">comma-separated</span></label><input name="tags" placeholder="React, Remote, Senior"></div><div class="field"><label><?=icon('limit',14)?> Applicant limit <span class="hint">optional</span></label><input name="applicant_limit" type="number" min="0" placeholder="e.g. 30"></div></div>
<div class="field"><label>Description</label><textarea name="description" rows="6" placeholder="What the person will do, requirements, and benefits..."></textarea></div>
<div class="field"><label>Requirements <span class="hint">one per line</span></label><textarea name="requirements" rows="4" placeholder="5+ years of experience&#10;Strong communication skills&#10;Bachelor's degree or equivalent"></textarea></div>
<label class="confirm-checkbox-row" style="margin:0 0 16px"><input type="checkbox" name="is_urgent" value="1"> Mark as Urgent Hiring — featured at the top of the public careers site</label>
<div class="field"><label>Initial status</label><select name="status"><option value="draft">Draft</option><option value="open">Publish now</option><option value="paused">Paused</option></select></div><button class="btn" type="submit"><?=icon('plus',16)?> Create job listing</button></form>
<form class="card form" method="post"><h2><?=icon('users',18)?> Add department</h2><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="department"><div class="field"><label>Name</label><input name="name" required placeholder="Engineering"></div><div class="field"><label>Description</label><textarea name="description" rows="5"></textarea></div><button class="btn secondary" type="submit">Create department</button><p class="meta">Departments are used by job listings and the public careers filters.</p></form>
</div>
<div class="card"><div class="section-head"><div><div class="eyebrow">Publishing</div><h2>Job listings</h2></div><span class="meta">Auto-dated on creation · applicant counts update live.</span></div>
<div class="table-wrap"><table class="table jobs-table"><tr><th>Role</th><th>Tags</th><th>Applicants</th><th>Posted</th><th>Status</th><th>Change</th></tr><?php foreach($jobs as $job): $limit=$job['applicant_limit']!==null?(int)$job['applicant_limit']:null; $remaining=spots_remaining($limit,(int)$job['applications']); $pct=$limit?min(100,round(($job['applications']/max(1,$limit))*100)):0; ?><tr>
<td><strong><?=e($job['title'])?></strong> <?php if($job['is_urgent']): ?><span class="badge urgent">Urgent</span><?php endif; ?><div class="meta"><?=e($job['department']??'—')?> · <?=e($job['location']?:'Location not set')?></div>
<form method="post" class="inline-form tag-edit-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="job_limits"><input type="hidden" name="job_id" value="<?=$job['id']?>"><input class="mini-input" name="tags" value="<?=e($job['tags']??'')?>" placeholder="tags..."><input class="mini-input narrow" type="number" min="0" name="applicant_limit" value="<?=e($job['applicant_limit']!==null?(string)$job['applicant_limit']:'')?>" placeholder="limit"><button class="btn small secondary" type="submit"><?=icon('check',13)?></button></form></td>
<td><div class="tag-row"><?php foreach(job_tags($job['tags']??'') as $t): ?><span class="tag-pill"><?=icon('tag',11)?><?=e($t)?></span><?php endforeach; if(!$job['tags']): ?><span class="meta">—</span><?php endif; ?></div></td>
<td><strong><?=$job['applications']?></strong><?php if($limit!==null): ?><span class="meta"> / <?=$limit?></span><div class="cap-bar"><span style="width:<?=$pct?>%"></span></div><?php if($remaining===0): ?><span class="badge full">Limit reached</span><?php else: ?><span class="meta small"><?=$remaining?> spots left</span><?php endif; ?><?php else: ?><span class="meta small">Unlimited</span><?php endif; ?></td>
<td class="meta"><?=e(date('M j, Y', strtotime($job['created_at'])))?></td>
<td><?= job_state_badge($job) ?><?php if($job['creator_name']): ?><div class="meta small">by <?=e($job['creator_name'])?></div><?php endif; ?><?php if(in_array(job_state($job),['rejected','changes_requested'],true) && $job['review_note']): ?><div class="reason-note meta small"><?=e($job['review_note'])?></div><?php endif; ?></td>
<td><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="job_status"><input type="hidden" name="job_id" value="<?=$job['id']?>"><select name="status"><option <?= $job['status']==='draft'?'selected':''?>>draft</option><option <?= $job['status']==='open'?'selected':''?>>open</option><option <?= $job['status']==='paused'?'selected':''?>>paused</option><option <?= $job['status']==='closed'?'selected':''?>>closed</option></select><button class="btn small" type="submit">Save</button></form>
<form method="post" class="inline-form" style="margin-top:6px"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="job_urgent"><input type="hidden" name="job_id" value="<?=$job['id']?>"><label class="meta small" style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" name="is_urgent" value="1" onchange="this.form.submit()" <?=$job['is_urgent']?'checked':''?>> Urgent hiring</label></form>
<a class="btn small secondary" style="margin-top:8px" href="job-post.php?id=<?=$job['id']?>"><?=icon('expand',13)?> Open full editor</a>
<details style="margin-top:8px"><summary class="meta small" style="cursor:pointer">Quick edit</summary>
<form method="post" style="margin-top:8px;min-width:260px">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="job_content"><input type="hidden" name="job_id" value="<?=$job['id']?>">
<div class="field"><label>Description</label><textarea name="description" rows="4"><?=e($job['description'] ?? '')?></textarea></div>
<div class="field"><label>Requirements <span class="hint">one per line</span></label><textarea name="requirements" rows="4"><?=e($job['requirements'] ?? '')?></textarea></div>
<label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:12px"><input type="checkbox" name="is_urgent" value="1" <?=$job['is_urgent']?'checked':''?>> Urgent hiring</label>
<button class="btn small secondary" type="submit">Save details</button>
</form>
</details>
</td>
</tr><?php endforeach; if(!$jobs): ?><tr><td colspan="6" class="meta">No job listings yet — create your first role above.</td></tr><?php endif; ?></table></div></div>
<?php include __DIR__.'/includes/footer.php'; ?>
