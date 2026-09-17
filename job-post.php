<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/pdf_extract.php';

$pdo = db();
$me  = current_user();

// Reaching this page at all needs one of the two job permissions. Every write
// below is checked again on its own — the page-level gate is not the only guard.
if (!has_permission(PERM_JOB_POSTING) && !has_permission(PERM_JOB_MANAGEMENT)) {
    require_permission(PERM_JOB_POSTING);
}

$canPublishDirectly = can_publish_jobs($me);
$departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();

/** Save the uploaded job description PDF alongside the other uploads. */
function save_job_description_pdf(array $file, string $titleHint, ?string &$error = null): ?string {
    if (!isset($file['error']) || is_array($file['error'])) { $error = 'Invalid upload.'; return null; }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) { $error = 'Please attach a PDF job description.'; return null; }
    if ($file['error'] !== UPLOAD_ERR_OK) { $error = 'We could not receive that file — please try again.'; return null; }
    if ($file['size'] > 8 * 1024 * 1024) { $error = 'The job description must be under 8MB.'; return null; }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') { $error = 'The job description must be a PDF file.'; return null; }
    if (!is_uploaded_file($file['tmp_name'])) { $error = 'We could not receive that file — please try again.'; return null; }

    $dir = __DIR__ . '/assets/uploads/job-descriptions';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { $error = 'Upload folder could not be created.'; return null; }

    $safe = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($titleHint)) ?? '', '-') ?: 'job-description';
    $filename = $safe . '-' . bin2hex(random_bytes(5)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) { $error = 'We could not save that file — please try again.'; return null; }
    return 'assets/uploads/job-descriptions/' . $filename;
}

/** Build a slug that does not collide with an existing job. */
function unique_job_slug(PDO $pdo, string $title, ?int $ignoreId = null): string {
    $base = slugify($title);
    $slug = $base; $n = 2;
    $check = $pdo->prepare('SELECT id FROM jobs WHERE slug=? AND id <> ? LIMIT 1');
    while (true) {
        $check->execute([$slug, $ignoreId ?? 0]);
        if (!$check->fetch()) return $slug;
        $slug = $base . '-' . $n++;
        if ($n > 200) return $base . '-' . bin2hex(random_bytes(3));
    }
}

/** Read the posting fields out of $_POST, trimmed and bounded. */
function posted_job_fields(): array {
    $types = ['full_time','part_time','contract','internship'];
    $type = $_POST['employment_type'] ?? 'full_time';
    return [
        'title'               => trim($_POST['title'] ?? ''),
        'department_id'       => (int)($_POST['department_id'] ?? 0) ?: null,
        'location'            => trim($_POST['location'] ?? ''),
        'employment_type'     => in_array($type, $types, true) ? $type : 'full_time',
        'description'         => trim($_POST['description'] ?? ''),
        'responsibilities'    => trim($_POST['responsibilities'] ?? ''),
        'qualifications'      => trim($_POST['qualifications'] ?? ''),
        'requirements'        => trim($_POST['requirements'] ?? ''),
        'preferred_skills'    => trim($_POST['preferred_skills'] ?? ''),
        'experience_required' => trim($_POST['experience_required'] ?? ''),
        'education_required'  => trim($_POST['education_required'] ?? ''),
        'salary_info'         => trim($_POST['salary_info'] ?? ''),
        'tags'                => implode(',', job_tags(trim($_POST['tags'] ?? ''))),
        'applicant_limit'     => trim($_POST['applicant_limit'] ?? '') === '' ? null : max(0, (int)$_POST['applicant_limit']),
        'is_urgent'           => isset($_POST['is_urgent']) ? 1 : 0,
    ];
}

$editId    = (int)($_GET['id'] ?? 0);
$job       = null;
$prefill   = null;      // set after a PDF import, before anything is saved
$extractNote = null;
$extractError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $editId = (int)($_POST['job_id'] ?? 0);

    try {
        // ---- Load and authorise the job being edited -----------------------
        $existing = null;
        if ($editId) {
            $s = $pdo->prepare('SELECT * FROM jobs WHERE id=? LIMIT 1');
            $s->execute([$editId]);
            $existing = $s->fetch() ?: null;
            if (!$existing) throw new RuntimeException('That job no longer exists.');
            if (!can_edit_job($existing, $me)) {
                deny_403('This posting is no longer yours to edit — it has moved on in the approval workflow.');
            }
        }

        if ($action === 'pdf_extract') {
            require_permission(PERM_JOB_POSTING, 'Creating job postings requires the "Job posting" permission.');
            $err = null;
            $stored = save_job_description_pdf($_FILES['job_pdf'] ?? [], 'jd', $err);
            if (!$stored) throw new RuntimeException($err ?? 'That file could not be read.');

            $result = pdf_extract_text(__DIR__ . '/' . $stored);
            if ($result['error']) {
                @unlink(__DIR__ . '/' . $stored);
                $extractError = $result['error'];
            } else {
                $fields = jd_parse_fields($result['text']);
                if ($result['quality'] === 'poor') {
                    $extractNote = 'The text layer in this PDF came through with very few word breaks, so some fields may run together. Please review the wording carefully before saving.';
                }
                // Match the extracted department name against real departments.
                $deptId = null;
                if ($fields['department'] !== '') {
                    foreach ($departments as $d) {
                        if (mb_strtolower($d['name']) === mb_strtolower($fields['department'])
                            || mb_stripos($fields['department'], $d['name']) !== false) { $deptId = (int)$d['id']; break; }
                    }
                }
                $prefill = [
                    'title'               => $fields['title'],
                    'department_id'       => $deptId,
                    'department_text'     => $fields['department'],
                    'location'            => $fields['location'],
                    'employment_type'     => $fields['employment_type'] ?: 'full_time',
                    'description'         => $fields['description'],
                    'responsibilities'    => $fields['responsibilities'],
                    'qualifications'      => $fields['qualifications'],
                    'requirements'        => $fields['skills'],
                    'preferred_skills'    => $fields['preferred_skills'],
                    'experience_required' => $fields['experience'],
                    'education_required'  => $fields['education'],
                    'salary_info'         => $fields['salary'],
                    'tags'                => jd_skills_to_tags($fields['skills']),
                    'applicant_limit'     => null,
                    'is_urgent'           => 0,
                    'source_pdf'          => $stored,
                ];
                audit('job_pdf_import', 'job', null, ['file' => basename($stored), 'title' => $fields['title'] ?: '(not detected)']);
            }

        } elseif (in_array($action, ['save_draft','submit','publish'], true)) {
            if ($action === 'publish' && !$canPublishDirectly) {
                deny_403('Publishing a job requires Admin approval. Submit it for review instead.');
            }
            if (!$existing) {
                require_permission(PERM_JOB_POSTING, 'Creating job postings requires the "Job posting" permission.');
            }

            $f = posted_job_fields();
            if ($f['title'] === '') throw new RuntimeException('A job title is required.');
            if (!$f['department_id']) throw new RuntimeException('Please choose a department.');

            $sourcePdf = trim($_POST['source_pdf'] ?? '') ?: ($existing['source_pdf'] ?? null);
            if ($sourcePdf !== null && !preg_match('#^assets/uploads/job-descriptions/[A-Za-z0-9._-]+\.pdf$#', $sourcePdf)) {
                $sourcePdf = $existing['source_pdf'] ?? null;   // ignore anything hand-crafted
            }

            // Only an Admin publishing directly may set a live status. Everything a
            // Recruiter saves stays status='draft', so it can never reach the
            // public careers site without passing through job-approvals.php.
            $isPublish = ($action === 'publish');
            $status    = $isPublish ? 'open' : 'draft';
            $approval  = $isPublish ? 'approved' : ($action === 'submit' ? 'pending' : 'draft');

            if ($existing) {
                $slug = ($existing['title'] === $f['title']) ? $existing['slug'] : unique_job_slug($pdo, $f['title'], (int)$existing['id']);
                $sql = 'UPDATE jobs SET department_id=?,title=?,slug=?,location=?,employment_type=?,tags=?,applicant_limit=?,
                        description=?,requirements=?,responsibilities=?,qualifications=?,preferred_skills=?,
                        experience_required=?,education_required=?,salary_info=?,is_urgent=?,source_pdf=?,
                        status=?,approval_status=?,
                        submitted_by=?,submitted_at=?,
                        published_at=CASE WHEN ?="open" AND published_at IS NULL THEN NOW() ELSE published_at END
                        WHERE id=?';
                $pdo->prepare($sql)->execute([
                    $f['department_id'], $f['title'], $slug, $f['location'], $f['employment_type'], $f['tags'] ?: null, $f['applicant_limit'],
                    $f['description'], $f['requirements'] ?: null, $f['responsibilities'] ?: null, $f['qualifications'] ?: null, $f['preferred_skills'] ?: null,
                    $f['experience_required'] ?: null, $f['education_required'] ?: null, $f['salary_info'] ?: null, $f['is_urgent'], $sourcePdf,
                    $status, $approval,
                    $action === 'submit' ? (int)$me['id'] : $existing['submitted_by'],
                    $action === 'submit' ? date('Y-m-d H:i:s') : $existing['submitted_at'],
                    $status, (int)$existing['id'],
                ]);
                $jobId = (int)$existing['id'];
                $wasReturned = in_array(job_state($existing), ['rejected','changes_requested'], true);
            } else {
                $slug = unique_job_slug($pdo, $f['title']);
                $sql = 'INSERT INTO jobs(department_id,title,slug,location,employment_type,tags,applicant_limit,
                        description,requirements,responsibilities,qualifications,preferred_skills,
                        experience_required,education_required,salary_info,is_urgent,source_pdf,
                        status,approval_status,owner_id,created_by,submitted_by,submitted_at,published_at)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                $pdo->prepare($sql)->execute([
                    $f['department_id'], $f['title'], $slug, $f['location'], $f['employment_type'], $f['tags'] ?: null, $f['applicant_limit'],
                    $f['description'], $f['requirements'] ?: null, $f['responsibilities'] ?: null, $f['qualifications'] ?: null, $f['preferred_skills'] ?: null,
                    $f['experience_required'] ?: null, $f['education_required'] ?: null, $f['salary_info'] ?: null, $f['is_urgent'], $sourcePdf,
                    $status, $approval, (int)$me['id'], (int)$me['id'],
                    $action === 'submit' ? (int)$me['id'] : null,
                    $action === 'submit' ? date('Y-m-d H:i:s') : null,
                    $isPublish ? date('Y-m-d H:i:s') : null,
                ]);
                $jobId = (int)$pdo->lastInsertId();
                $wasReturned = false;
            }

            if ($action === 'save_draft') {
                audit('job_draft_save', 'job', $jobId, ['title' => $f['title']]);
                flash('success', 'Draft saved. It is not visible to applicants.');
            } elseif ($action === 'submit') {
                record_job_approval($jobId, $wasReturned ? 'resubmitted' : 'submitted', null, (int)$me['id']);
                audit('job_submit_for_approval', 'job', $jobId, ['title' => $f['title']]);
                notify_reviewers('job_approval', 'Job approval required',
                    $me['name'] . ' submitted "' . $f['title'] . '" for approval.',
                    'job-approvals.php?id=' . $jobId);
                flash('success', 'Submitted for Admin approval. You will be notified once it is reviewed.');
            } else {
                record_job_approval($jobId, 'published', 'Published directly by ' . $me['name'], (int)$me['id']);
                audit('job_publish', 'job', $jobId, ['title' => $f['title'], 'published_by' => $me['name']]);
                flash('success', '"' . $f['title'] . '" is now live on the careers site.');
            }

            header('Location: ' . ($action === 'save_draft' ? 'job-post.php?id=' . $jobId : (is_admin_level($me) ? 'admin.php' : 'my-jobs.php')));
            exit;
        }
    } catch (Throwable $ex) {
        $extractError = $ex->getMessage();
    }
}

// ---- Load the job for the form ------------------------------------------
if ($editId && !$prefill) {
    $s = $pdo->prepare('SELECT * FROM jobs WHERE id=? LIMIT 1');
    $s->execute([$editId]);
    $job = $s->fetch() ?: null;
    if ($job && !can_edit_job($job, $me)) {
        deny_403('This posting is no longer yours to edit — it has moved on in the approval workflow.');
    }
}

/** Value for a form field: PDF import wins, then the saved job, then blank. */
$val = function (string $key, string $default = '') use ($prefill, $job) {
    if ($prefill !== null && array_key_exists($key, $prefill)) return (string)($prefill[$key] ?? '');
    if ($job !== null && array_key_exists($key, $job)) return (string)($job[$key] ?? '');
    return $default;
};
$currentDept = $prefill !== null ? ($prefill['department_id'] ?? null) : ($job['department_id'] ?? null);
$sourcePdfVal = $prefill !== null ? ($prefill['source_pdf'] ?? '') : ($job['source_pdf'] ?? '');
$state = $job ? job_state($job) : 'draft';

$pageTitle = $job ? 'Edit job posting' : 'Create job posting';
include __DIR__.'/includes/header.php';
?>
<div class="dashboard-head">
  <div><div class="eyebrow"><?= $job ? 'Editing' : 'New posting' ?></div>
  <h1><?= $job ? e($job['title']) : 'Create a job posting' ?></h1>
  <p class="meta"><?= $canPublishDirectly
      ? 'You can publish directly, or save a draft and route it through the approval queue.'
      : 'Save a draft while you work. Submitting sends it to an Admin for approval — postings cannot go live without it.' ?></p></div>
  <div class="actions" style="margin-top:0">
    <a class="btn secondary" href="<?= is_admin_level($me) ? 'admin.php' : 'my-jobs.php' ?>">← All jobs</a>
  </div>
</div>

<?php if($f=take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>
<?php if($extractError): ?><div class="notice error"><?=e($extractError)?></div><?php endif; ?>
<?php if($extractNote): ?><div class="notice"><?=e($extractNote)?></div><?php endif; ?>

<?php if ($job && in_array($state, ['rejected','changes_requested'], true) && $job['review_note']): ?>
<div class="card review-callout <?= $state==='rejected'?'is-rejected':'is-changes' ?>">
  <div class="review-callout-head"><?= job_state_badge($job) ?><span class="meta">Reviewed <?=e(time_ago($job['reviewed_at']))?></span></div>
  <p><strong><?= $state==='rejected' ? 'Reason for rejection' : 'Changes requested' ?>:</strong> <?=e($job['review_note'])?></p>
  <p class="meta small">Edit the posting below and submit it again — you do not need to start over.</p>
</div>
<?php endif; ?>

<?php if (has_permission(PERM_JOB_POSTING)): ?>
<div class="card pdf-import">
  <div class="section-head" style="margin:0 0 14px">
    <div><div class="eyebrow">Shortcut</div><h2>Job description → job posting</h2></div>
  </div>
  <p class="meta">Upload a PDF job description and the fields below will be filled in for you to review and edit. Nothing is saved until you choose to save a draft or submit.</p>
  <form method="post" enctype="multipart/form-data" class="pdf-import-form">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="pdf_extract">
    <?php if ($editId): ?><input type="hidden" name="job_id" value="<?=$editId?>"><?php endif; ?>
    <div class="dropzone" data-dropzone>
      <span class="dropzone-icon" aria-hidden="true"><?=icon('doc',26)?></span>
      <span class="dropzone-title">Drop a job description here, or click to browse</span>
      <span class="dropzone-hint">Supports PDF up to 8MB</span>
      <span class="dropzone-file" data-dropzone-name hidden></span>
      <input type="file" name="job_pdf" accept="application/pdf,.pdf" required data-dropzone-input>
    </div>
    <button class="btn secondary" type="submit"><?=icon('link',16)?> Extract from PDF</button>
  </form>
  <?php if ($sourcePdfVal): ?>
    <p class="meta small"><?=icon('check',13)?> Attached: <a href="<?=e($sourcePdfVal)?>" target="_blank" rel="noopener"><?=e(basename($sourcePdfVal))?></a></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<form class="card form job-form" method="post">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
  <?php if ($editId): ?><input type="hidden" name="job_id" value="<?=$editId?>"><?php endif; ?>
  <input type="hidden" name="source_pdf" value="<?=e($sourcePdfVal)?>">

  <?php if ($prefill !== null): ?>
    <div class="notice"><strong>Generated from your PDF.</strong> Review every field before saving — extraction is a starting point, not a final posting.</div>
  <?php endif; ?>

  <h2><?=icon('overview',18)?> Role basics</h2>
  <div class="field"><label>Job title</label><input name="title" required value="<?=e($val('title'))?>" placeholder="Senior Product Engineer"></div>
  <div class="form-grid">
    <div class="field"><label>Department</label>
      <select name="department_id" required>
        <option value="">Choose a department…</option>
        <?php foreach($departments as $d): ?>
          <option value="<?=$d['id']?>" <?= (int)$currentDept===(int)$d['id']?'selected':'' ?>><?=e($d['name'])?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($prefill !== null && !$currentDept && ($prefill['department_text'] ?? '') !== ''): ?>
        <span class="hint">The PDF said “<?=e($prefill['department_text'])?>” — no matching department exists yet, so please pick one.</span>
      <?php endif; ?>
    </div>
    <div class="field"><label>Employment type</label>
      <select name="employment_type">
        <?php foreach(['full_time'=>'Full-time','part_time'=>'Part-time','contract'=>'Contract','internship'=>'Internship'] as $k=>$lbl): ?>
          <option value="<?=$k?>" <?= $val('employment_type','full_time')===$k?'selected':'' ?>><?=$lbl?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-grid">
    <div class="field"><label>Location</label><input name="location" value="<?=e($val('location'))?>" placeholder="Remote / Manila"></div>
    <div class="field"><label>Salary information <span class="hint">optional</span></label><input name="salary_info" value="<?=e($val('salary_info'))?>" placeholder="PHP 120,000 – 160,000 / month"></div>
  </div>

  <h2><?=icon('chat',18)?> The posting</h2>
  <div class="field"><label>Description</label><textarea name="description" rows="6" placeholder="What this person will do and why the role exists…"><?=e($val('description'))?></textarea></div>
  <div class="field"><label>Responsibilities <span class="hint">one per line</span></label><textarea name="responsibilities" rows="5"><?=e($val('responsibilities'))?></textarea></div>
  <div class="field"><label>Qualifications <span class="hint">one per line</span></label><textarea name="qualifications" rows="5"><?=e($val('qualifications'))?></textarea></div>
  <div class="field"><label>Required skills <span class="hint">one per line — shown publicly as requirements</span></label><textarea name="requirements" rows="4"><?=e($val('requirements'))?></textarea></div>
  <div class="field"><label>Preferred skills <span class="hint">optional</span></label><textarea name="preferred_skills" rows="3"><?=e($val('preferred_skills'))?></textarea></div>
  <div class="form-grid">
    <div class="field"><label>Experience required</label><input name="experience_required" value="<?=e($val('experience_required'))?>" placeholder="5+ years in backend engineering"></div>
    <div class="field"><label>Education required</label><input name="education_required" value="<?=e($val('education_required'))?>" placeholder="Bachelor's degree in Computer Science"></div>
  </div>

  <h2><?=icon('tag',18)?> Listing options</h2>
  <div class="form-grid">
    <div class="field"><label>Tags <span class="hint">comma-separated, max 6</span></label><input name="tags" value="<?=e($val('tags'))?>" placeholder="React, Remote, Senior"></div>
    <div class="field"><label>Applicant limit <span class="hint">optional</span></label><input type="number" min="0" name="applicant_limit" value="<?=e($val('applicant_limit'))?>" placeholder="Unlimited"></div>
  </div>
  <label class="confirm-checkbox-row" style="margin:0 0 18px"><input type="checkbox" name="is_urgent" value="1" <?= $val('is_urgent')==='1'?'checked':'' ?>> Mark as Urgent Hiring — featured at the top of the public careers site</label>

  <div class="job-form-actions">
    <button class="btn secondary" type="submit" name="action" value="save_draft"><?=icon('check',16)?> Save as draft</button>
    <button class="btn" type="submit" name="action" value="submit"><?=icon('send',16)?> Submit for approval</button>
    <?php if ($canPublishDirectly): ?>
      <button class="btn" type="submit" name="action" value="publish" data-publish><?=icon('public',16)?> Publish now</button>
    <?php endif; ?>
  </div>
  <p class="meta small"><?= $canPublishDirectly
      ? 'Publishing puts this role on the public careers site immediately. Your own postings do not need a second approval.'
      : 'A draft stays private to you. Submitting hands it to an Admin — you cannot publish a posting yourself.' ?></p>
</form>

<script>
document.querySelectorAll('[data-publish]').forEach(function (btn) {
  btn.addEventListener('click', function (ev) {
    if (!confirm('Publish this role to the public careers site now?')) ev.preventDefault();
  });
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
