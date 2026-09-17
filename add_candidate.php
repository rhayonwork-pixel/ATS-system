<?php
/**
 * Add Candidate — manual entry with CV auto-fill.
 *
 * Two notes where this departs from the brief, both deliberate:
 *
 *  1. The brief routes to profile.php after submitting. In this ATS profile.php
 *     is the signed-in STAFF member's own account page; the candidate profile is
 *     candidate.php?id=<application_id>. It routes there.
 *
 *  2. The brief checks $_SESSION['role']. This app has no such key — the role
 *     lives on the user row and is read through current_user(). Trusting a
 *     session-held role would also be weaker, since it would not follow a
 *     permission change until the person signed out.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';
require_login(['admin', 'recruiter', 'hiring_manager']);

$pdo = db();
$me  = current_user();
$isAdmin = is_admin_level($me);

/** Fields an Admin has marked mandatory, on top of the two that always are. */
function required_candidate_fields(): array {
    $raw = setting('candidate_required_fields', '["full_name","email"]');
    $list = json_decode($raw, true);
    if (!is_array($list)) $list = ['full_name', 'email'];
    return array_values(array_unique(array_merge(['full_name', 'email'], $list)));
}

$configurable = [
    'phone'            => 'Phone number',
    'current_title'    => 'Current role',
    'experience_level' => 'Experience level',
    'skills'           => 'Skills',
    'education'        => 'Education',
    'source'           => 'Source',
    'job_id'           => 'Target position',
];
$sources = ['career_site'=>'Career site','linkedin'=>'LinkedIn','referral'=>'Referral','direct'=>'Direct approach',
            'agency'=>'Agency','job_board'=>'Job board','event'=>'Event / careers fair','other'=>'Other'];
$levels  = ['entry'=>'Entry level','mid'=>'Mid level','senior'=>'Senior','lead'=>'Lead / Principal'];

$required = required_candidate_fields();
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? 'submit';

    // Admin-only: which optional fields become mandatory.
    if ($action === 'save_required') {
        if (!$isAdmin) deny_403('Only an Admin can change which fields are required.');
        $picked = array_values(array_intersect((array)($_POST['required'] ?? []), array_keys($configurable)));
        set_setting('candidate_required_fields', json_encode(array_merge(['full_name','email'], $picked)));
        audit('candidate_required_fields_changed', 'settings', null, ['required' => implode(', ', $picked) ?: 'name and email only']);
        flash('success', 'Required fields updated.');
        header('Location: add_candidate.php'); exit;
    }

    $isDraft = ($action === 'draft');
    $old = [
        'full_name'        => trim($_POST['full_name'] ?? ''),
        'email'            => strtolower(trim($_POST['email'] ?? '')),
        'phone'            => trim($_POST['phone'] ?? ''),
        'current_title'    => trim($_POST['current_title'] ?? ''),
        'experience_level' => $_POST['experience_level'] ?? '',
        'skills'           => trim($_POST['skills'] ?? ''),
        'education'        => trim($_POST['education'] ?? ''),
        'source'           => $_POST['source'] ?? 'direct',
        'job_id'           => (int)($_POST['job_id'] ?? 0),
        'notes'            => trim($_POST['notes'] ?? ''),
    ];

    // A draft only needs a name; a submission honours the configured rules.
    $mustHave = $isDraft ? ['full_name'] : $required;
    foreach ($mustHave as $field) {
        $value = $old[$field] ?? '';
        if ($field === 'job_id' ? !$value : $value === '') {
            $errors[$field] = ($configurable[$field] ?? ucfirst(str_replace('_', ' ', $field))) . ' is required.';
        }
    }
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'That email address is not valid.';
    }
    if ($old['phone'] !== '' && !preg_match('/^[\d\s()+.-]{7,20}$/', $old['phone'])) {
        $errors['phone'] = 'That phone number does not look valid.';
    }
    if ($old['experience_level'] !== '' && !isset($levels[$old['experience_level']])) $old['experience_level'] = '';
    if (!isset($sources[$old['source']])) $old['source'] = 'direct';

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $parts = preg_split('/\s+/u', $old['full_name']) ?: [$old['full_name']];
            $first = array_shift($parts);
            $last  = $parts ? implode(' ', $parts) : '';

            // An existing candidate with this email is updated rather than
            // duplicated — the same person can be considered for several roles.
            $existing = null;
            if ($old['email'] !== '') {
                $q = $pdo->prepare('SELECT * FROM candidates WHERE email=? LIMIT 1');
                $q->execute([$old['email']]);
                $existing = $q->fetch() ?: null;
            }

            if ($existing) {
                $candidateId = (int)$existing['id'];
                $pdo->prepare('UPDATE candidates SET first_name=?, last_name=?, phone=?, current_title=?,
                               experience_level=?, skills=?, education=?, notes=?, source=?, record_status=?
                               WHERE id=?')
                    ->execute([$first, $last, $old['phone'] ?: null, $old['current_title'] ?: null,
                               $old['experience_level'] ?: null, $old['skills'] ?: null, $old['education'] ?: null,
                               $old['notes'] ?: null, $old['source'], $isDraft ? 'draft' : 'active', $candidateId]);
            } else {
                $pdo->prepare('INSERT INTO candidates
                               (first_name,last_name,email,phone,current_title,experience_level,skills,education,
                                notes,source,record_status,created_by)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$first, $last, $old['email'], $old['phone'] ?: null, $old['current_title'] ?: null,
                               $old['experience_level'] ?: null, $old['skills'] ?: null, $old['education'] ?: null,
                               $old['notes'] ?: null, $old['source'], $isDraft ? 'draft' : 'active', (int)$me['id']]);
                $candidateId = (int)$pdo->lastInsertId();
            }

            // An application is only created for a real submission with a role.
            $applicationId = null;
            if (!$isDraft && $old['job_id']) {
                $find = $pdo->prepare('SELECT id FROM applications WHERE candidate_id=? AND job_id=? LIMIT 1');
                $find->execute([$candidateId, $old['job_id']]);
                $applicationId = (int)($find->fetchColumn() ?: 0);
                if (!$applicationId) {
                    $pdo->prepare('INSERT INTO applications(candidate_id,job_id,stage,status,assigned_to) VALUES (?,?,?,?,?)')
                        ->execute([$candidateId, $old['job_id'], 'new', 'active', (int)$me['id']]);
                    $applicationId = (int)$pdo->lastInsertId();
                }
            }

            $pdo->commit();

            // Attach the document uploaded during parsing, and keep its text
            // so the resume is searchable from the candidates list.
            $docId = null;
            $token = (string)($_POST['doc_token'] ?? '');
            if ($token !== '') $docId = attach_document_to_candidate($token, $candidateId);

            $resumeText = trim((string)($_POST['resume_text'] ?? ''));
            if ($resumeText !== '') {
                $pdo->prepare('UPDATE candidates SET resume_text=? WHERE id=?')
                    ->execute([mb_substr($resumeText, 0, 60000), $candidateId]);
            }

            audit($isDraft ? 'candidate_saved_as_draft' : 'candidate_added_manually', 'candidate', $candidateId, [
                'name'       => $old['full_name'],
                'source'     => $sources[$old['source']] ?? $old['source'],
                'cv_parsed'  => !empty($_POST['cv_parsed']) ? 'Yes' : 'No',
                'document'   => $docId ? 'attached' : 'none',
                'added_by'   => $me['name'],
            ]);

            if ($isDraft) {
                flash('success', 'Draft saved for ' . $old['full_name'] . '.');
                header('Location: add_candidate.php'); exit;
            }

            flash('success', 'Candidate ' . $old['full_name'] . ' successfully added to the pipeline.');
            // The candidate profile is candidate.php, keyed by application.
            header('Location: ' . ($applicationId ? 'candidate.php?id=' . $applicationId : 'candidates.php'));
            exit;

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors['_form'] = 'Could not save this candidate: ' . $ex->getMessage();
        }
    }
}

$jobs = $pdo->query("SELECT id, title FROM jobs WHERE status='open' ORDER BY title")->fetchAll();

$val = static fn(string $k, string $d = '') => htmlspecialchars((string)($old[$k] ?? $d), ENT_QUOTES, 'UTF-8');
$req = static fn(string $k) => in_array($k, $GLOBALS['required'], true);

$pageTitle = 'Add candidate';
include __DIR__ . '/includes/header.php';
?>
<div class="page-container add-candidate-page">

<div class="dashboard-head">
  <div>
    <div class="eyebrow">Talent pool</div>
    <h1>Add a candidate</h1>
    <p class="meta">Upload a CV to auto-fill the form, or enter the details by hand. Everything can be edited before you save.</p>
  </div>
  <?php if ($isAdmin): ?>
    <button type="button" class="btn secondary" data-field-settings aria-expanded="false" aria-controls="field-settings">
      <?=icon('settings',16)?> Field settings
    </button>
  <?php endif; ?>
</div>

<?php if ($f = take_flash()): ?><div class="notice <?=e($f[0])?>"><?=e($f[1])?></div><?php endif; ?>
<?php if (!empty($errors['_form'])): ?><div class="notice error" role="alert"><?=e($errors['_form'])?></div><?php endif; ?>

<?php if ($isAdmin): ?>
<!-- Admin only: which optional fields are mandatory on this form. -->
<form class="card field-settings" id="field-settings" method="post" hidden>
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
  <input type="hidden" name="action" value="save_required">
  <h2><?=icon('settings',17)?> Required fields</h2>
  <p class="meta small">Full name and email are always required. Choose which of the rest must be filled in before a candidate can be submitted.</p>
  <div class="field-settings-grid">
    <?php foreach ($configurable as $key => $label): ?>
      <label class="perm-toggle">
        <input type="checkbox" name="required[]" value="<?=e($key)?>" <?= in_array($key, $required, true) ? 'checked' : '' ?>>
        <span class="perm-toggle-text"><strong><?=e($label)?></strong></span>
        <span class="perm-switch" aria-hidden="true"></span>
      </label>
    <?php endforeach; ?>
  </div>
  <button class="btn" type="submit"><?=icon('check',15)?> Save field settings</button>
</form>
<?php endif; ?>

<!-- ── CV auto-fill ──────────────────────────────────────────────────── -->
<section class="card cv-parse" aria-labelledby="cv-parse-title">
  <h2 id="cv-parse-title"><?=icon('doc',18)?> Upload CV/Resume to auto-fill</h2>
  <p class="meta small">We read the file and suggest values. Nothing is saved until you review them and submit.</p>

  <div class="dropzone" data-cv-dropzone>
    <span class="dropzone-icon" aria-hidden="true"><?=icon('doc',28)?></span>
    <span class="dropzone-title">Drop a CV here, or click to browse</span>
    <span class="dropzone-hint">Supports PDF, DOC and DOCX up to 10MB</span>
    <span class="dropzone-file" data-cv-name hidden></span>
    <input type="file" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" data-cv-input
           aria-label="Upload a CV to auto-fill the form">
  </div>

  <div class="cv-status" data-cv-status hidden role="status" aria-live="polite"></div>

  <!-- The stored document. It stays attached even when parsing finds nothing. -->
  <div class="file-row cv-attached" data-cv-attached hidden>
    <span class="file-icon" data-cv-icon aria-hidden="true">PDF</span>
    <span class="file-meta">
      <span class="file-name" data-cv-filename></span>
      <span class="file-sub" data-cv-filesize></span>
    </span>
    <span class="file-actions">
      <button type="button" class="file-act danger" data-cv-remove aria-label="Remove this document">&times;</button>
    </span>
  </div>
</section>

<!-- ── The form ──────────────────────────────────────────────────────── -->
<form class="card candidate-form" method="post" novalidate data-candidate-form>
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
  <input type="hidden" name="action" value="submit" data-form-action>
  <input type="hidden" name="cv_parsed" value="0" data-cv-parsed>
  <input type="hidden" name="doc_token" value="" data-doc-token>
  <input type="hidden" name="resume_text" value="" data-resume-text>

  <fieldset>
    <legend>Personal details</legend>
    <div class="form-2col">
      <div class="field">
        <label for="c-name">Full name <span class="req" aria-hidden="true">*</span><span class="sr-only">required</span></label>
        <input id="c-name" name="full_name" value="<?=$val('full_name')?>" required
               autocomplete="name" data-fill="full_name"
               <?= isset($errors['full_name']) ? 'aria-invalid="true" aria-describedby="e-name"' : '' ?>>
        <?php if (isset($errors['full_name'])): ?><p class="field-error" id="e-name"><?=e($errors['full_name'])?></p><?php endif; ?>
      </div>
      <div class="field">
        <label for="c-email">Email address <span class="req" aria-hidden="true">*</span><span class="sr-only">required</span></label>
        <input id="c-email" type="email" name="email" value="<?=$val('email')?>" required
               autocomplete="email" data-fill="email"
               <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="e-email"' : '' ?>>
        <?php if (isset($errors['email'])): ?><p class="field-error" id="e-email"><?=e($errors['email'])?></p><?php endif; ?>
      </div>
      <div class="field">
        <label for="c-phone">Phone number <?php if ($req('phone')): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
        <input id="c-phone" type="tel" name="phone" value="<?=$val('phone')?>" autocomplete="tel" data-fill="phone"
               <?= $req('phone') ? 'required' : '' ?>
               <?= isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="e-phone"' : '' ?>>
        <?php if (isset($errors['phone'])): ?><p class="field-error" id="e-phone"><?=e($errors['phone'])?></p><?php endif; ?>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Professional details</legend>
    <div class="form-2col">
      <div class="field">
        <label for="c-role">Current role <?php if ($req('current_title')): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
        <input id="c-role" name="current_title" value="<?=$val('current_title')?>" data-fill="current_title"
               placeholder="Senior Backend Engineer" <?= $req('current_title') ? 'required' : '' ?>>
      </div>
      <div class="field">
        <label for="c-level">Experience level <?php if ($req('experience_level')): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
        <select id="c-level" name="experience_level" data-fill="experience_level" <?= $req('experience_level') ? 'required' : '' ?>>
          <option value="">Not specified</option>
          <?php foreach ($levels as $k => $lbl): ?>
            <option value="<?=e($k)?>" <?= ($old['experience_level'] ?? '') === $k ? 'selected' : '' ?>><?=e($lbl)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field span-2">
        <label for="c-skills">Skills <?php if ($req('skills')): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
        <!-- Tokenised input: the chips are the visible control, the hidden
             field is what actually posts, so it works without JavaScript. -->
        <div class="token-input" data-token-input>
          <div class="token-list" data-token-list></div>
          <input type="text" class="token-entry" data-token-entry
                 placeholder="Type a skill and press Enter" aria-label="Add a skill">
        </div>
        <input type="hidden" name="skills" value="<?=$val('skills')?>" data-fill="skills" data-token-value>
        <span class="hint">Press Enter or comma to add. Click a chip to remove it.</span>
      </div>
      <div class="field span-2">
        <label for="c-education">Education <?php if ($req('education')): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
        <input id="c-education" name="education" value="<?=$val('education')?>" data-fill="education"
               placeholder="BSc Computer Science, University of the Philippines"
               <?= $req('education') ? 'required' : '' ?>>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Application</legend>
    <div class="form-2col">
      <div class="field">
        <label for="c-source">Source <?php if ($req('source')): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
        <select id="c-source" name="source" <?= $req('source') ? 'required' : '' ?>>
          <?php foreach ($sources as $k => $lbl): ?>
            <option value="<?=e($k)?>" <?= ($old['source'] ?? 'direct') === $k ? 'selected' : '' ?>><?=e($lbl)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="c-job">Target position <?php if ($req('job_id')): ?><span class="req" aria-hidden="true">*</span><?php endif; ?></label>
        <select id="c-job" name="job_id" <?= $req('job_id') ? 'required' : '' ?>>
          <option value="">No position yet</option>
          <?php foreach ($jobs as $j): ?>
            <option value="<?=(int)$j['id']?>" <?= (int)($old['job_id'] ?? 0) === (int)$j['id'] ? 'selected' : '' ?>><?=e($j['title'])?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Only published roles appear here. Leave blank to add them to the pool.</span>
      </div>
      <div class="field span-2">
        <label for="c-notes">Recruiter notes</label>
        <textarea id="c-notes" name="notes" rows="3" placeholder="Where they came from, first impressions, anything worth remembering."><?=$val('notes')?></textarea>
      </div>
    </div>
  </fieldset>

  <!-- Sticky action bar -->
  <div class="form-actionbar">
    <a class="btn ghost" href="candidates.php">Cancel</a>
    <div class="actionbar-right">
      <button class="btn secondary" type="submit" data-submit-draft>Save as draft</button>
      <button class="btn" type="submit" data-submit-final><?=icon('check',16)?> Submit candidate</button>
    </div>
  </div>
</form>
</div><!-- /.add-candidate-page -->

<script>
(function () {
  const form = document.querySelector('[data-candidate-form]');
  if (!form) return;

  /* ---------- Admin: field settings panel ---------- */
  const settingsBtn = document.querySelector('[data-field-settings]');
  const settingsPanel = document.getElementById('field-settings');
  if (settingsBtn && settingsPanel) {
    settingsBtn.addEventListener('click', function () {
      const open = settingsPanel.hidden;
      settingsPanel.hidden = !open;
      settingsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ---------- Which button was pressed ---------- */
  const actionField = form.querySelector('[data-form-action]');
  form.querySelector('[data-submit-draft]')?.addEventListener('click', function () { actionField.value = 'draft'; });
  form.querySelector('[data-submit-final]')?.addEventListener('click', function () { actionField.value = 'submit'; });

  /* ---------- Tokenised skills ----------
     The chips are a view over a hidden input, which is what posts. With
     JavaScript off the hidden field still carries whatever was there. */
  const tokenBox = document.querySelector('[data-token-input]');
  if (tokenBox) {
    const list = tokenBox.querySelector('[data-token-list]');
    const entry = tokenBox.querySelector('[data-token-entry]');
    const store = document.querySelector('[data-token-value]');
    let tokens = (store.value || '').split(',').map(s => s.trim()).filter(Boolean);

    function sync() {
      store.value = tokens.join(', ');
      list.innerHTML = '';
      tokens.forEach(function (t, i) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'token-chip';
        chip.setAttribute('aria-label', 'Remove ' + t);
        chip.textContent = t;
        chip.addEventListener('click', function () { tokens.splice(i, 1); sync(); });
        list.appendChild(chip);
      });
    }
    function add(raw) {
      raw.split(',').map(s => s.trim()).filter(Boolean).forEach(function (t) {
        if (t.length <= 40 && !tokens.some(x => x.toLowerCase() === t.toLowerCase())) tokens.push(t);
      });
      sync();
    }
    entry.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(entry.value); entry.value = ''; }
      else if (e.key === 'Backspace' && !entry.value && tokens.length) { tokens.pop(); sync(); }
    });
    entry.addEventListener('blur', function () { if (entry.value.trim()) { add(entry.value); entry.value = ''; } });
    window.__setSkills = function (v) { tokens = v.split(',').map(s => s.trim()).filter(Boolean); sync(); };
    sync();
  }

  /* ---------- Inline validation ---------- */
  function invalid(input, msg) {
    input.setAttribute('aria-invalid', 'true');
    let el = input.parentNode.querySelector('.field-error');
    if (!el) { el = document.createElement('p'); el.className = 'field-error'; input.parentNode.appendChild(el); }
    el.textContent = msg;
  }
  function clearInvalid(input) {
    input.removeAttribute('aria-invalid');
    input.parentNode.querySelector('.field-error')?.remove();
  }
  function validate(input) {
    const v = input.value.trim();
    if (input.required && !v) { invalid(input, 'This field is required.'); return false; }
    if (input.type === 'email' && v && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) {
      invalid(input, 'Enter a valid email address, e.g. name@example.com.'); return false;
    }
    if (input.type === 'tel' && v && !/^[\d\s()+.-]{7,20}$/.test(v)) {
      invalid(input, 'Enter a valid phone number.'); return false;
    }
    clearInvalid(input);
    return true;
  }
  form.querySelectorAll('input, select, textarea').forEach(function (input) {
    // Validate on blur, then live once it has been corrected — not on every
    // keystroke of a half-typed address.
    input.addEventListener('blur', function () { validate(input); });
    input.addEventListener('input', function () {
      if (input.getAttribute('aria-invalid') === 'true') validate(input);
    });
  });
  form.addEventListener('submit', function (e) {
    if (actionField.value === 'draft') return;      // drafts may be incomplete
    let ok = true, first = null;
    form.querySelectorAll('input, select, textarea').forEach(function (input) {
      if (!validate(input)) { ok = false; first = first || input; }
    });
    if (!ok) { e.preventDefault(); first?.focus(); }
  });

  /* ---------- CV parsing ---------- */
  const zone = document.querySelector('[data-cv-dropzone]');
  const input = document.querySelector('[data-cv-input]');
  const nameEl = document.querySelector('[data-cv-name]');
  const status = document.querySelector('[data-cv-status]');
  const parsedFlag = document.querySelector('[data-cv-parsed]');
  const CSRF = form.querySelector('input[name=csrf]').value;
  if (!zone || !input) return;

  function say(kind, text) {
    status.hidden = false;
    status.className = 'cv-status is-' + kind;
    status.textContent = text;
  }

  /**
   * Mark a field as auto-filled.
   * No emoji: a bracketed [Auto-filled] badge, a tinted field and a left accent
   * rule. The badge is aria-hidden and a visually hidden sentence carries the
   * meaning instead, so a screen reader hears "Auto-filled from CV, please
   * verify" rather than a decorative glyph.
   */
  function markFilled(key) {
    const field = form.querySelector('[data-fill="' + key + '"]');
    if (!field) return;
    const wrap = field.closest('.field');
    if (!wrap || wrap.querySelector('.autofill-tag')) return;

    wrap.classList.add('is-autofilled');

    const tag = document.createElement('span');
    tag.className = 'autofill-tag';
    tag.setAttribute('aria-hidden', 'true');
    tag.textContent = '[Auto-filled]';

    const sr = document.createElement('span');
    sr.className = 'sr-only';
    sr.textContent = ' Auto-filled from CV, please verify.';

    const label = wrap.querySelector('label');
    label?.appendChild(tag);
    label?.appendChild(sr);

    // The treatment is a prompt to check the value, so it clears once touched.
    const clear = function () {
      wrap.classList.remove('is-autofilled');
      tag.remove();
      sr.remove();
    };
    field.addEventListener('input', clear, { once: true });
    field.addEventListener('change', clear, { once: true });
  }

  const attached = document.querySelector('[data-cv-attached]');
  const tokenField = document.querySelector('[data-doc-token]');

  function showAttached(doc) {
    if (!attached) return;
    tokenField.value = doc.token || '';
    attached.querySelector('[data-cv-filename]').textContent = doc.original_name;
    attached.querySelector('[data-cv-filesize]').textContent =
      doc.extension.toUpperCase() + ' · ' + doc.size_label + ' · attached to this candidate';
    const icon = attached.querySelector('[data-cv-icon]');
    icon.textContent = doc.extension.toUpperCase();
    icon.className = 'file-icon ' + (doc.icon_class || 'type-doc');
    attached.hidden = false;
  }

  document.querySelector('[data-cv-remove]')?.addEventListener('click', function () {
    tokenField.value = '';
    document.querySelector('[data-resume-text]').value = '';
    attached.hidden = true;
    nameEl.hidden = true;
    say('warn', 'Document removed. The form values you already have are kept.');
  });

  function upload(file) {
    if (!file) return;
    nameEl.textContent = file.name;
    nameEl.hidden = false;
    zone.classList.add('is-loading');
    say('loading', 'Reading ' + file.name + '…');

    const body = new FormData();
    body.append('csrf', CSRF);
    body.append('cv', file);

    fetch('parse-cv.php', { method: 'POST', body: body })
      .then(r => r.json().catch(() => null))
      .then(function (data) {
        zone.classList.remove('is-loading');
        if (!data) { say('error', 'The server did not respond. Please try again or enter the details manually.'); return; }
        if (!data.ok) {
          // The document may still have been stored even though parsing failed.
          if (data.document) showAttached(data.document);
          say('error', data.error || 'That file could not be read.');
          return;
        }

        if (data.document) showAttached(data.document);
        if (data.resume_text) document.querySelector('[data-resume-text]').value = data.resume_text;

        Object.keys(data.fields).forEach(function (key) {
          const field = form.querySelector('[data-fill="' + key + '"]');
          if (!field) return;
          if (key === 'skills' && window.__setSkills) window.__setSkills(data.fields[key]);
          else field.value = data.fields[key];
          markFilled(key);
        });
        parsedFlag.value = '1';
        say(data.partial ? 'warn' : 'ok', data.message);
        form.querySelector('[data-fill="full_name"]')?.focus();
      })
      .catch(function () {
        zone.classList.remove('is-loading');
        say('error', 'Upload failed. Please check your connection, or enter the details manually.');
      });
  }

  input.addEventListener('change', function () { upload(input.files[0]); });
  ['dragenter', 'dragover'].forEach(evt => zone.addEventListener(evt, function (e) {
    e.preventDefault(); zone.classList.add('is-dragover');
  }));
  ['dragleave', 'drop'].forEach(evt => zone.addEventListener(evt, function (e) {
    if (evt === 'dragleave' && zone.contains(e.relatedTarget)) return;
    zone.classList.remove('is-dragover');
  }));
  zone.addEventListener('drop', function (e) {
    e.preventDefault();
    if (e.dataTransfer?.files.length) upload(e.dataTransfer.files[0]);
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
