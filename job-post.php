<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/pdf_extract.php';
require_once __DIR__.'/app/Services/JobDescriptionParser.php';
require_once __DIR__.'/includes/text_sanitize.php';

$pdo = db();
$me  = current_user();
// Reaching this page at all needs one of the two job permissions. Every write
// below is checked again on its own — the page-level gate is not the only guard.
// Reaching this page at all needs one of the two job permissions. Every write
// below is checked again on its own — the page-level gate is not the only guard.
if (!has_permission(PERM_JOB_POSTING) && !has_permission(PERM_JOB_MANAGEMENT)) {
    require_permission(PERM_JOB_POSTING);
}

$canPublishDirectly = can_publish_jobs($me);
$departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();

/**
 * Parser field -> form input. ONE map, used by the server-rendered import and
 * handed to the browser for the AJAX one, so the two paths cannot fill
 * different boxes from the same PDF.
 */
const JOB_FIELD_MAP = [
    'title'            => 'title',
    'company'          => 'company_name',
    'location'         => 'location',
    'employment_type'  => 'employment_type',
    'salary'           => 'salary_info',
    'description'      => 'description',
    'responsibilities' => 'responsibilities',
    'qualifications'   => 'qualifications',
    'skills'           => 'requirements',
    'preferred_skills' => 'preferred_skills',
    'experience'       => 'experience_required',
    'education'        => 'education_required',
    'benefits'         => 'benefits',
    'deadline'         => 'application_deadline',
];

/**
 * Turn a JobDescriptionParser result into form values.
 *
 * Nothing here is saved. The return value only pre-fills the form, which is
 * why every value stays a string the recruiter can edit rather than being
 * coerced into a column type.
 */
function job_prefill_from_parse(array $result, array $departments, string $token): array
{
    $fields = $result['fields'];
    $prefill = ['source_pdf' => $token, 'auto' => []];

    foreach (JOB_FIELD_MAP as $from => $to) {
        $value = trim((string)($fields[$from] ?? ''));
        if ($value === '') continue;
        // A deadline the parser could not read unambiguously comes back as the
        // raw phrase. The date input would silently discard it, so it is kept
        // aside and shown as a hint instead of being half-filled.
        if ($to === 'application_deadline' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $prefill['deadline_text'] = $value;
            continue;
        }
        $prefill[$to] = $value;
        $prefill['auto'][] = $to;
    }

    $deptText = trim((string)($fields['department'] ?? ''));
    $prefill['department_text'] = $deptText;
    $prefill['department_id'] = null;
    if ($deptText !== '') {
        foreach ($departments as $d) {
            if (mb_strtolower($d['name']) === mb_strtolower($deptText)
                || mb_stripos($deptText, $d['name']) !== false) {
                $prefill['department_id'] = (int)$d['id'];
                $prefill['auto'][] = 'department_id';
                break;
            }
        }
    }
    if (!empty($fields['skills'])) {
        $prefill['tags'] = jd_skills_to_tags($fields['skills']);
        if ($prefill['tags'] !== '') $prefill['auto'][] = 'tags';
    }
    $prefill['applicant_limit'] = null;
    $prefill['is_urgent'] = 0;
    return $prefill;
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
    // Every value is sanitised on the way in: emoji removed, bullet glyphs
    // normalised to "- ", whitespace tidied. This is the authoritative pass --
    // the browser does the same thing for immediate feedback, but a POST that
    // never touched the form (curl, a stale tab, scripting off) lands here too.
    return [
        'title'               => sanitize_job_line($_POST['title'] ?? '', 180),
        'company_name'        => sanitize_job_line($_POST['company_name'] ?? '', 160),
        'department_id'       => (int)($_POST['department_id'] ?? 0) ?: null,
        'location'            => sanitize_job_line($_POST['location'] ?? '', 160),
        'employment_type'     => in_array($type, $types, true) ? $type : 'full_time',
        'description'         => sanitize_job_text($_POST['description'] ?? ''),
        'responsibilities'    => sanitize_job_text($_POST['responsibilities'] ?? ''),
        'qualifications'      => sanitize_job_text($_POST['qualifications'] ?? ''),
        'requirements'        => sanitize_job_text($_POST['requirements'] ?? ''),
        'preferred_skills'    => sanitize_job_text($_POST['preferred_skills'] ?? ''),
        'experience_required' => sanitize_job_line($_POST['experience_required'] ?? '', 255),
        'education_required'  => sanitize_job_line($_POST['education_required'] ?? '', 255),
        'salary_info'         => sanitize_job_line($_POST['salary_info'] ?? '', 255),
        'benefits'            => sanitize_job_text($_POST['benefits'] ?? ''),
        // A blank or malformed date is stored as NULL rather than 0000-00-00:
        // the column is a real DATE and the field is optional.
        'application_deadline'=> preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['application_deadline'] ?? ''))
                                 ? trim($_POST['application_deadline']) : null,
        'tags'                => implode(',', job_tags(sanitize_job_line($_POST['tags'] ?? '', 255))),
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
            // The no-JavaScript path. It does exactly what upload-parser.php
            // does over AJAX -- same validation, same storage, same parser --
            // and renders the result into the form below instead of returning
            // JSON, so the feature works with scripting switched off.
            require_permission(PERM_JOB_POSTING, 'Creating job postings requires the "Job posting" permission.');

            [$valid, $code, $message] = JobDescriptionParser::validate($_FILES['job_pdf'] ?? []);
            if (!$valid) throw new RuntimeException($message);

            $token = JobDescriptionParser::store($_FILES['job_pdf']['tmp_name'], (string)$_FILES['job_pdf']['name']);
            $stored = $token ? JobDescriptionParser::resolve($token) : null;
            if ($stored === null) throw new RuntimeException('We could not save that file — please try again.');

            $result = JobDescriptionParser::parse($stored);
            if (!$result['ok']) {
                @unlink($stored);
                throw new RuntimeException($result['message'] ?: 'The PDF could not be read or contains scanned images without text.');
            }

            $prefill = job_prefill_from_parse($result, $departments, $token);
            if (($result['error_code'] ?? null) === 'ERR_MISSING_FIELDS') {
                $extractNote = "We extracted the text, but couldn't identify specific fields. Please fill the remaining fields manually.";
            } elseif ($result['warnings']) {
                $extractNote = $result['warnings'][0];
            }
            audit('job_pdf_import', 'job', null, [
                'file' => $token, 'engine' => $result['engine'],
                'title' => $result['fields']['title'] ?? '(not detected)',
            ]);

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

            // The attachment travels through the form as the stored name the
            // parser handed back, never as a path. Anything that does not look
            // like one of our generated names is discarded rather than trusted,
            // and an edit with no new upload keeps whatever the job already had.
            $pdfToken = trim($_POST['source_pdf'] ?? '');
            if ($pdfToken === '' || JobDescriptionParser::resolve($pdfToken) === null) {
                $pdfToken = $existing['original_pdf_path'] ?? null;
            }

            // Only an Admin publishing directly may set a live status. Everything a
            // Recruiter saves stays status='draft', so it can never reach the
            // public careers site without passing through job-approvals.php.
            $isPublish = ($action === 'publish');
            $status    = $isPublish ? 'open' : 'draft';
            $approval  = $isPublish ? 'approved' : ($action === 'submit' ? 'pending' : 'draft');

            if ($existing) {
                $slug = ($existing['title'] === $f['title']) ? $existing['slug'] : unique_job_slug($pdo, $f['title'], (int)$existing['id']);
                $sql = 'UPDATE jobs SET department_id=?,title=?,company_name=?,slug=?,location=?,employment_type=?,tags=?,applicant_limit=?,
                        description=?,requirements=?,responsibilities=?,qualifications=?,preferred_skills=?,
                        experience_required=?,education_required=?,salary_info=?,benefits=?,application_deadline=?,
                        is_urgent=?,original_pdf_path=?,
                        status=?,approval_status=?,
                        submitted_by=?,submitted_at=?,
                        published_at=CASE WHEN ?="open" AND published_at IS NULL THEN NOW() ELSE published_at END
                        WHERE id=?';
                $pdo->prepare($sql)->execute([
                    $f['department_id'], $f['title'], $f['company_name'] ?: null, $slug, $f['location'], $f['employment_type'], $f['tags'] ?: null, $f['applicant_limit'],
                    $f['description'], $f['requirements'] ?: null, $f['responsibilities'] ?: null, $f['qualifications'] ?: null, $f['preferred_skills'] ?: null,
                    $f['experience_required'] ?: null, $f['education_required'] ?: null, $f['salary_info'] ?: null,
                    $f['benefits'] ?: null, $f['application_deadline'],
                    $f['is_urgent'], $pdfToken,
                    $status, $approval,
                    $action === 'submit' ? (int)$me['id'] : $existing['submitted_by'],
                    $action === 'submit' ? date('Y-m-d H:i:s') : $existing['submitted_at'],
                    $status, (int)$existing['id'],
                ]);
                $jobId = (int)$existing['id'];
                $wasReturned = in_array(job_state($existing), ['rejected','changes_requested'], true);
            } else {
                $slug = unique_job_slug($pdo, $f['title']);
                $sql = 'INSERT INTO jobs(department_id,title,company_name,slug,location,employment_type,tags,applicant_limit,
                        description,requirements,responsibilities,qualifications,preferred_skills,
                        experience_required,education_required,salary_info,benefits,application_deadline,
                        is_urgent,original_pdf_path,
                        status,approval_status,owner_id,created_by,submitted_by,submitted_at,published_at)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                $pdo->prepare($sql)->execute([
                    $f['department_id'], $f['title'], $f['company_name'] ?: null, $slug, $f['location'], $f['employment_type'], $f['tags'] ?: null, $f['applicant_limit'],
                    $f['description'], $f['requirements'] ?: null, $f['responsibilities'] ?: null, $f['qualifications'] ?: null, $f['preferred_skills'] ?: null,
                    $f['experience_required'] ?: null, $f['education_required'] ?: null, $f['salary_info'] ?: null,
                    $f['benefits'] ?: null, $f['application_deadline'],
                    $f['is_urgent'], $pdfToken,
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
// The organisation's own name, used as the Company field's placeholder: blank
// means "this posting is ours".
$companyNameDefault = setting('company_name', 'Acme');

// Which inputs the parser filled. Defined BEFORE the two closures below,
// because an arrow function captures by value at the point it is created — a
// later assignment would never reach it.
$autoFilled = $prefill !== null ? array_flip($prefill['auto'] ?? []) : [];

/**
 * Review mode, server-rendered.
 *
 * The no-JavaScript import lands on a fully built page, so the highlight and
 * the badge are rendered here rather than only by job-post-import.js. The two
 * produce the same markup — class `auto-filled` on the wrapper, a decorative
 * badge in the label, and a visually hidden sentence for screen readers — so
 * the page looks identical whichever path filled it.
 *
 * The badge carries an inline SVG tick, not an emoji: emoji are stripped from
 * posting CONTENT by includes/text_sanitize.php, and putting one in the UI that
 * announces that cleanup would be odd.
 */
$autoClass = fn(string $name): string => isset($autoFilled[$name]) ? ' auto-filled' : '';
$autoBadge = function (string $name) use ($autoFilled): string {
    if (!isset($autoFilled[$name])) return '';
    return '<span class="auto-filled-badge" aria-hidden="true">'
         . '<svg viewBox="0 0 16 16" width="12" height="12" focusable="false">'
         . '<path d="M6.2 11.6 2.8 8.2l1.1-1.1 2.3 2.3 5.9-5.9 1.1 1.1z" fill="currentColor"/></svg>'
         . '<span>Auto-filled</span></span>'
         . '<span class="sr-only"> Auto-filled from your document, please verify.</span>';
};

/**
 * The per-field status light.
 *
 * Rendered server-side with its starting state so it is correct before any
 * JavaScript runs; job-post-import.js takes over from there. The dot itself is
 * aria-hidden and a visually hidden word carries the state, because colour on
 * its own is not an accessible signal (WCAG 1.4.1).
 */
$light = function (string $name) use ($val, $autoFilled): string {
    $filled = trim($val($name)) !== '' || isset($autoFilled[$name]);
    $state = $filled ? 'filled' : 'empty';
    $word  = $filled ? 'filled' : 'empty';
    return '<span class="jp-light is-' . $state . '" data-light="' . e($name) . '" aria-hidden="true"></span>'
         . '<span class="sr-only" data-light-text="' . e($name) . '">' . $word . '</span>';
};
$currentDept = $prefill !== null ? ($prefill['department_id'] ?? null) : ($job['department_id'] ?? null);
$sourcePdfVal = $prefill !== null ? ($prefill['source_pdf'] ?? '') : ($job['original_pdf_path'] ?? '');
$state = $job ? job_state($job) : 'draft';

$pageTitle = $job ? 'Edit job posting' : 'Create job posting';
include __DIR__.'/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/job-post-import.css?v=<?= @filemtime(__DIR__.'/assets/css/job-post-import.css') ?: time() ?>">
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

<?php if (has_permission(PERM_JOB_POSTING) && !$job): ?>
<!-- DUAL ENTRY ------------------------------------------------------------
     Two ways to start a posting. The tab bar is hidden until JavaScript
     unhides it: with scripting off both panels stay visible and both work,
     because the upload posts to this page and renders the same review form. -->
<div class="card jp-entry" data-entry>
  <div class="jp-entry-head">
    <div><div class="eyebrow">Start a posting</div><h2>How would you like to begin?</h2></div>
  </div>

  <div class="jp-tabs" role="tablist" aria-label="How to create this posting" data-entry-tabs hidden>
    <button type="button" class="jp-tab is-active" role="tab" aria-selected="true"
            aria-controls="jp-panel-upload" id="jp-tab-upload" data-entry-tab="upload">
      <?=icon('doc',18)?><span><strong>Upload PDF description</strong><small>We read it and fill the form for you</small></span>
    </button>
    <button type="button" class="jp-tab" role="tab" aria-selected="false"
            aria-controls="jp-panel-manual" id="jp-tab-manual" data-entry-tab="manual">
      <?=icon('chat',18)?><span><strong>Fill in manually</strong><small>Type the posting yourself</small></span>
    </button>
  </div>

  <div class="jp-panel" id="jp-panel-upload" role="tabpanel" aria-labelledby="jp-tab-upload" data-entry-panel="upload">
    <form method="post" enctype="multipart/form-data" class="jp-upload-form" data-upload-form
          action="job-post.php<?= $editId ? '?id='.$editId : '' ?>">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="pdf_extract">
      <?php if ($editId): ?><input type="hidden" name="job_id" value="<?=$editId?>"><?php endif; ?>

      <div class="jp-dropzone" data-dropzone tabindex="0" role="button"
           aria-label="Upload a job description. Drop a PDF or Word file here, or press Enter to browse.">
        <span class="jp-dz-icon" aria-hidden="true"><?=icon('doc',28)?></span>
        <span class="jp-dz-title">Drop a job description here, or click to browse</span>
        <span class="jp-dz-hint">PDF or Word (.docx) · up to 5MB · stored privately, never published</span>
        <span class="jp-dz-file" data-dropzone-name hidden></span>
        <input type="file" name="job_pdf" required data-dropzone-input
               accept="application/pdf,.pdf,.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
      </div>

      <div class="jp-dz-actions">
        <button class="btn" type="submit" data-upload-submit><?=icon('link',16)?> Read this file</button>
        <span class="jp-dz-status" data-upload-status role="status" aria-live="polite"></span>
      </div>
      <p class="meta small">Nothing is saved to the careers site yet. Every field the parser fills is marked for
        you to review, and you still choose whether to save a draft, submit or publish.</p>
    </form>
  </div>
</div>
<?php endif; ?>

<form class="card form job-form" method="post" id="jp-panel-manual" role="tabpanel"
      aria-labelledby="jp-tab-manual" data-entry-panel="manual" data-job-form>
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
  <?php if ($editId): ?><input type="hidden" name="job_id" value="<?=$editId?>"><?php endif; ?>
  <input type="hidden" name="source_pdf" value="<?=e($sourcePdfVal)?>">

  <!-- Review banner. It is in the markup for the server-rendered import and is
       also the element the AJAX path fills, so both paths look the same. -->
  <div class="jp-review-banner<?= $prefill === null ? '' : ' is-on' ?>" data-review-banner
       <?= $prefill === null ? 'hidden' : '' ?> role="status">
    <span>
      <strong data-review-title><?= $prefill === null ? '' : count($autoFilled) . ' field' . (count($autoFilled) === 1 ? '' : 's') . ' filled in from your PDF.' ?></strong>
      Extraction is a starting point, not a finished posting — check every highlighted field before you save.
    </span>
  </div>

  <?php if ($sourcePdfVal): ?>
    <p class="meta small jp-attached"><?=icon('check',13)?> Attached:
      <a href="job-attachment.php?<?= $job ? 'job='.(int)$job['id'] : 'file='.urlencode($sourcePdfVal) ?>"
         target="_blank" rel="noopener">view the PDF this came from</a>
    </p>
  <?php endif; ?>

  <h2><?=icon('overview',18)?> Role basics</h2>
  <div class="field<?=$autoClass('title')?>" data-field="title"><label>Job title <?=$light('title')?><?=$autoBadge('title')?></label><input name="title" required aria-required="true" value="<?=e($val('title'))?>" placeholder="Senior Product Engineer"></div>
  <div class="form-grid">
    <div class="field<?=$autoClass('department_id')?>" data-field="department_id"><label>Department <?=$light('department_id')?><?=$autoBadge('department_id')?></label>
      <select name="department_id" required aria-required="true">
        <option value="">Choose a department…</option>
        <?php foreach($departments as $d): ?>
          <option value="<?=$d['id']?>" <?= (int)$currentDept===(int)$d['id']?'selected':'' ?>><?=e($d['name'])?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($prefill !== null && !$currentDept && ($prefill['department_text'] ?? '') !== ''): ?>
        <span class="hint">The PDF said “<?=e($prefill['department_text'])?>” — no matching department exists yet, so please pick one.</span>
      <?php endif; ?>
    </div>
    <div class="field<?=$autoClass('employment_type')?>" data-field="employment_type"><label>Employment type <?=$light('employment_type')?><?=$autoBadge('employment_type')?></label>
      <select name="employment_type">
        <?php foreach(['full_time'=>'Full-time','part_time'=>'Part-time','contract'=>'Contract','internship'=>'Internship'] as $k=>$lbl): ?>
          <option value="<?=$k?>" <?= $val('employment_type','full_time')===$k?'selected':'' ?>><?=$lbl?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-grid">
    <div class="field<?=$autoClass('company_name')?>" data-field="company_name">
      <label for="jp-company">Company <span class="hint">optional</span> <?=$light('company_name')?><?=$autoBadge('company_name')?></label>
      <input id="jp-company" name="company_name" value="<?=e($val('company_name'))?>"
             placeholder="<?=e($companyNameDefault)?>">
      <!-- Blank means "us". This ATS is single-tenant, so a posting only needs
           its own company when it is being advertised for someone else -- an
           agency's client, a subsidiary, a trading name. -->
      <span class="hint">Leave blank to post as <?=e($companyNameDefault)?>.</span>
    </div>
    <div class="field<?=$autoClass('location')?>" data-field="location"><label>Location <?=$light('location')?><?=$autoBadge('location')?></label><input name="location" value="<?=e($val('location'))?>" placeholder="Remote / Manila"></div>
    <div class="field<?=$autoClass('salary_info')?>" data-field="salary_info"><label>Salary information <span class="hint">optional</span> <?=$light('salary_info')?><?=$autoBadge('salary_info')?></label><input name="salary_info" value="<?=e($val('salary_info'))?>" placeholder="PHP 120,000 – 160,000 / month"></div>
  </div>

  <h2><?=icon('chat',18)?> The posting</h2>
  <div class="field<?=$autoClass('description')?>" data-field="description"><label>Description <?=$light('description')?><?=$autoBadge('description')?></label><textarea name="description" rows="6" placeholder="What this person will do and why the role exists…"><?=e($val('description'))?></textarea></div>
  <div class="field<?=$autoClass('responsibilities')?>" data-field="responsibilities"><label>Responsibilities <span class="hint">one per line</span> <?=$light('responsibilities')?><?=$autoBadge('responsibilities')?></label><textarea name="responsibilities" rows="5"><?=e($val('responsibilities'))?></textarea></div>
  <div class="field<?=$autoClass('qualifications')?>" data-field="qualifications"><label>Qualifications <span class="hint">one per line</span> <?=$light('qualifications')?><?=$autoBadge('qualifications')?></label><textarea name="qualifications" rows="5"><?=e($val('qualifications'))?></textarea></div>
  <div class="field<?=$autoClass('requirements')?>" data-field="requirements"><label>Required skills <span class="hint">one per line — shown publicly as requirements</span> <?=$light('requirements')?><?=$autoBadge('requirements')?></label><textarea name="requirements" rows="4"><?=e($val('requirements'))?></textarea></div>
  <div class="field<?=$autoClass('preferred_skills')?>" data-field="preferred_skills"><label>Preferred skills <span class="hint">optional</span> <?=$light('preferred_skills')?><?=$autoBadge('preferred_skills')?></label><textarea name="preferred_skills" rows="3"><?=e($val('preferred_skills'))?></textarea></div>
  <div class="form-grid">
    <div class="field<?=$autoClass('experience_required')?>" data-field="experience_required"><label>Experience required <?=$light('experience_required')?><?=$autoBadge('experience_required')?></label><input name="experience_required" value="<?=e($val('experience_required'))?>" placeholder="5+ years in backend engineering"></div>
    <div class="field<?=$autoClass('education_required')?>" data-field="education_required"><label>Education required <?=$light('education_required')?><?=$autoBadge('education_required')?></label><input name="education_required" value="<?=e($val('education_required'))?>" placeholder="Bachelor's degree in Computer Science"></div>
  </div>

  <div class="field<?=$autoClass('benefits')?>" data-field="benefits">
    <label>Benefits <span class="hint">one per line — optional</span> <?=$light('benefits')?><?=$autoBadge('benefits')?></label>
    <textarea name="benefits" rows="3" placeholder="HMO from day one&#10;20 days paid leave"><?=e($val('benefits'))?></textarea>
  </div>

  <h2><?=icon('tag',18)?> Listing options</h2>
  <div class="form-grid">
    <div class="field<?=$autoClass('tags')?>" data-field="tags"><label>Tags <span class="hint">comma-separated, max 6</span> <?=$light('tags')?><?=$autoBadge('tags')?></label><input name="tags" value="<?=e($val('tags'))?>" placeholder="React, Remote, Senior"></div>
    <div class="field"><label>Applicant limit <span class="hint">optional</span></label><input type="number" min="0" name="applicant_limit" value="<?=e($val('applicant_limit'))?>" placeholder="Unlimited"></div>
  </div>
  <div class="form-grid">
    <div class="field<?=$autoClass('application_deadline')?>" data-field="application_deadline">
      <label>Application deadline <span class="hint">optional</span> <?=$light('application_deadline')?><?=$autoBadge('application_deadline')?></label>
      <input type="date" name="application_deadline" value="<?=e($val('application_deadline'))?>">
      <?php if ($prefill !== null && ($prefill['deadline_text'] ?? '') !== ''): ?>
        <!-- The parser found a closing date it could not read without guessing
             (03/04/2026 is two different days in two countries), so it reports
             what the PDF holds instead of filling in the wrong one. -->
        <span class="hint">The PDF said &ldquo;<?=e($prefill['deadline_text'])?>&rdquo; — please set the date yourself.</span>
      <?php endif; ?>
    </div>
    <div class="field"></div>
  </div>
  <label class="confirm-checkbox-row" style="margin:0 0 18px"><input type="checkbox" name="is_urgent" value="1" <?= $val('is_urgent')==='1'?'checked':'' ?>> Mark as Urgent Hiring — featured at the top of the public careers site</label>

  <div class="job-form-actions">
    <!-- Destructive, so it is the quietest control in the row and sits away
         from the save buttons it must not be mistaken for. -->
    <button class="btn ghost jp-clear" type="button" data-clear-form>Clear form</button>
    <span class="job-form-actions-spacer" aria-hidden="true"></span>
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

<!-- Clear-form confirmation. Same modal furniture as the withdraw dialog on
     application-status.php, so focus handling and Escape behave identically. -->
<div class="modal-overlay" data-modal-clear hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="jp-clear-title">
    <h2 id="jp-clear-title">Clear this job post?</h2>
    <p class="meta" style="margin-top:8px">Everything you have entered or imported will be removed from the form.
      Nothing that has already been saved is deleted.</p>
    <div class="modal-warning">This cannot be undone once the ten-second undo has passed.</div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-clear-cancel>Keep editing</button>
      <button type="button" class="btn danger" data-clear-confirm>Clear everything</button>
    </div>
  </div>
</div>

<!-- Configuration for assets/js/job-post-import.js. The field map is the PHP
     constant above, serialised: the AJAX import and the no-JavaScript import
     fill the form from one definition. -->
<script type="application/json" id="jp-config"><?= json_encode([
    'endpoint' => 'upload-parser.php',
    'csrf'     => csrf_token(),
    'map'      => JOB_FIELD_MAP,
    'maxBytes' => JobDescriptionParser::MAX_BYTES,
    'accept'   => ['pdf', 'docx'],
    // Cleared by "Clear form"; also the set the status lights watch.
    'fields'   => ['title','company_name','department_id','employment_type','location','salary_info',
                   'description','responsibilities','qualifications','requirements','preferred_skills',
                   'experience_required','education_required','benefits','tags','applicant_limit',
                   'application_deadline','is_urgent'],
    'required' => ['title', 'department_id'],
    'messages' => [
        'ERR_FILE_FORMAT'    => 'Invalid file format. Please upload a PDF.',
        'ERR_UNREADABLE'     => 'The PDF could not be read or contains scanned images without text.',
        'ERR_MISSING_FIELDS' => "We extracted the text, but couldn't identify specific fields. Please fill the remaining fields manually.",
        'ERR_NETWORK'        => 'We could not reach the server. Check your connection and try again.',
        'ERR_SESSION'        => 'Your session has expired, so nothing was uploaded.',
    ],
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/js/job-post-import.js?v=<?= @filemtime(__DIR__.'/assets/js/job-post-import.js') ?: time() ?>" defer></script>

<script>
document.querySelectorAll('[data-publish]').forEach(function (btn) {
  btn.addEventListener('click', function (ev) {
    if (!confirm('Publish this role to the public careers site now?')) ev.preventDefault();
  });
});
</script>

<?php include __DIR__.'/includes/footer.php'; ?>
