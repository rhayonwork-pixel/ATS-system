<?php
/**
 * Backward-compatible entry point. Every existing page still does
 * require_once 'includes/config.php' (or __DIR__ . '/config.php') and gets
 * everything it always got: an open session, APP_TIMEZONE, db(), and every
 * helper function below. As of the v1.0 reorganization the session/timezone/
 * URL setup lives in config/app.php and the DB connection lives in
 * config/database.php — both env-driven (see .env.example) — so this file
 * is now a thin loader plus the original helper functions, unchanged.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function time_ago(?string $datetime): string {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60).'m ago';
    if ($diff < 86400) return floor($diff/3600).'h ago';
    if ($diff < 604800) return floor($diff/86400).'d ago';
    return date('M j', strtotime($datetime));
}
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid request token. Please go back and try again.'); } }
function flash(string $type, string $message): void { $_SESSION['flash'] = [$type, $message]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
/**
 * Record an action in the audit trail. $details is optional and is stored as
 * JSON in audit_logs.details — audit_trail.php already renders it as
 * "Key: value · Key: value", so permission and approval changes become readable
 * without a schema change.
 */
function audit(string $action, string $entity = '', ?int $id = null, ?array $details = null): void { try { $s=db()->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address) VALUES(?,?,?,?,?,?)'); $s->execute([$_SESSION['user_id'] ?? null,$action,$entity,$id,$details===null?null:json_encode($details, JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR'] ?? null]); } catch(Throwable $e) {} }
function slugify(string $value): string { $slug = trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-'); return $slug ?: 'job-' . bin2hex(random_bytes(3)); }

function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) { try { $cache = db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { $cache = []; } }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, string $value): void {
    $s = db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $s->execute([$key, $value]);
}

/** Split a stored "tag1,tag2" string into a clean array (max 6, trimmed, deduped). */
/** Renders the company logo image — defaults to the Acme mark (PNG) until an admin sets a custom one in Settings. */
function logo_html(int $size = 32): string {
    $path = setting('logo_path');
    $src = $path !== '' ? e($path) : 'assets/logo.png';
    return '<img class="brand-mark" src="' . $src . '" width="' . $size . '" height="' . $size . '" alt="' . e(setting('company_name', 'Acme')) . ' logo">';
}

function job_tags(?string $raw): array {
    if (!$raw) return [];
    $tags = array_filter(array_unique(array_map('trim', explode(',', $raw))));
    return array_slice(array_values($tags), 0, 6);
}

/** How many spots remain for a job with an applicant_limit; null = unlimited. */
function spots_remaining(?int $limit, int $applications): ?int {
    if ($limit === null) return null;
    return max(0, $limit - $applications);
}

/** Handles a resume upload from apply.php: validates type/size and moves the
 * file into assets/uploads/resumes with a safe, unique name. Returns the
 * relative path to store in candidates.resume_path, or null on failure
 * (with $error populated). Mirrors the old prototype's file-based resume
 * upload instead of a plain text URL field. */
/** Handles an optional candidate profile image upload. */
function save_profile_image_upload(array $file, string $candidateNameForFile, ?string &$error = null): ?string {
    if (!isset($file['error']) || is_array($file['error'])) { $error = 'Invalid profile photo upload.'; return null; }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return '';
    if ($file['error'] !== UPLOAD_ERR_OK) { $error = 'We could not receive the profile photo — please try again.'; return null; }
    $maxBytes = 3 * 1024 * 1024;
    if ((int)$file['size'] > $maxBytes) { $error = 'Profile photo must be under 3MB.'; return null; }
    if (!is_uploaded_file($file['tmp_name'])) { $error = 'We could not receive the profile photo — please try again.'; return null; }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) { $error = 'Please upload a valid JPG, PNG, or WEBP image.'; return null; }
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) { $error = 'The selected profile photo is not a valid image.'; return null; }

    $dir = __DIR__ . '/../assets/uploads/profile-images';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) { $error = 'We could not prepare the profile photo storage.'; return null; }
    $safeName = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($candidateNameForFile)), '-');
    $safeName = $safeName ?: 'candidate';
    $filename = $safeName . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) { $error = 'We could not save the profile photo — please try again.'; return null; }
    return 'assets/uploads/profile-images/' . $filename;
}

function save_resume_upload(array $file, string $candidateNameForFile, ?string &$error = null): ?string {
    $allowed = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $maxBytes = 5 * 1024 * 1024;

    if (!isset($file['error']) || is_array($file['error'])) { $error = 'Invalid upload.'; return null; }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) { $error = 'Please attach your resume.'; return null; }
    if ($file['error'] !== UPLOAD_ERR_OK) { $error = 'We could not receive that file — please try again.'; return null; }
    if ($file['size'] > $maxBytes) { $error = 'Resume must be under 5MB.'; return null; }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!array_key_exists($ext, $allowed)) { $error = 'Resume must be a PDF, DOC, or DOCX file.'; return null; }
    if (!is_uploaded_file($file['tmp_name'])) { $error = 'We could not receive that file — please try again.'; return null; }

    $dir = __DIR__ . '/../assets/uploads/resumes';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $safeName = preg_replace('/[^a-z0-9]+/', '-', strtolower($candidateNameForFile));
    $safeName = trim($safeName, '-') ?: 'candidate';
    $filename = $safeName . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    $destination = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) { $error = 'We could not save that file — please try again.'; return null; }

    return 'assets/uploads/resumes/' . $filename;
}

/** A legacy candidates.resume_path is only ever the relative path returned by
 * save_resume_upload(). Anything else in that column (an absolute URL, a
 * javascript: URI, a ../ path) is refused rather than written into an href,
 * so a bad row can never turn a "View" button into an off-site redirect. */
function legacy_resume_url(?string $path): ?string {
    $path = trim((string)$path);
    return preg_match('#^assets/uploads/resumes/[A-Za-z0-9._-]+$#', $path) && strpos($path, '..') === false ? $path : null;
}

/** Lists every application under a given email — used when a candidate
 * checks their status without (or with the wrong) application ID. */
function applications_for_email(PDO $pdo, string $email): array {
    $stmt = $pdo->prepare('SELECT a.id,a.stage,a.applied_at,j.title FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id WHERE c.email=? ORDER BY a.applied_at DESC');
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetchAll();
}

/** Simulated AI candidate analysis (prototype, not a real model call).
 * Produces a score breakdown, summary, strengths/concerns, and a
 * recommendation from signals actually present in the application: text
 * length/variety of the cover letter and "why us" answer, keyword overlap
 * with the job's tags, whether a resume/portfolio was provided, and a
 * per-candidate seed (so two candidates with similar text don't look
 * identical). Clearly a heuristic, not real skills/education verification —
 * labelled as a prototype simulation everywhere it's shown. */
function generate_ai_analysis(array $candidate, array $job): array {
    $clamp = fn($n) => max(0, min(100, (int)round($n)));
    $text = strtolower(trim(($candidate['cover_letter'] ?? '').' '.($candidate['why_us'] ?? '')));
    $words = array_values(array_filter(preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', ' ', $text))));
    $wordCount = count($words);
    $uniqueRatio = $wordCount ? count(array_unique($words)) / $wordCount : 0;
    $avgWordLen = $wordCount ? array_sum(array_map('strlen', $words)) / $wordCount : 0;
    $seed = crc32('ai-'.($candidate['candidate_id'] ?? 0).'-'.($candidate['application_id'] ?? 0));

    // Skills match: how many of the job's tags actually show up in what the
    // candidate wrote.
    $tags = job_tags($job['tags'] ?? '');
    if ($tags) {
        $hits = 0; foreach ($tags as $tag) if ($tag !== '' && str_contains($text, strtolower($tag))) $hits++;
        $skillsMatch = $clamp(50 + ($hits / count($tags)) * 50);
    } else {
        $skillsMatch = $clamp(55 + ($seed % 20));
    }

    $applicationQuality = $clamp(40 + min(45, ($wordCount / 2)) + min(10, $uniqueRatio * 10));
    $experience = $clamp((($candidate['resume_path'] ?? '') ? 55 : 35) + ($seed % 21) + min(15, intdiv($wordCount, 8)));
    $education = $clamp(58 + (($seed >> 3) % 32));
    $communication = $clamp(48 + ($avgWordLen * 5) + ($uniqueRatio * 12) + min(15, intdiv($wordCount, 12)));
    if (!empty($candidate['portfolio_url'])) { $skillsMatch = $clamp($skillsMatch + 4); $experience = $clamp($experience + 3); }

    $categories = ['Skills Match' => $skillsMatch, 'Experience' => $experience, 'Education' => $education, 'Application Quality' => $applicationQuality, 'Communication' => $communication];
    $overall = $clamp(($skillsMatch * 0.25) + ($experience * 0.20) + ($education * 0.15) + ($applicationQuality * 0.20) + ($communication * 0.20));

    $strengthPhrases = [
        'Skills Match' => 'Strong overlap between the application content and this role\'s listed requirements',
        'Experience' => 'Application signals indicate relevant hands-on experience',
        'Education' => 'Educational background appears well suited to the role',
        'Application Quality' => 'Thoughtful, detailed responses to the application questions',
        'Communication' => 'Clear, articulate written communication',
    ];
    $concernPhrases = [
        'Skills Match' => 'Limited overlap found between the application text and this role\'s listed requirements',
        'Experience' => 'Experience level is difficult to confirm from the application alone',
        'Education' => 'Educational background is not clearly demonstrated in the application',
        'Application Quality' => 'Application answers are brief and could use more detail',
        'Communication' => 'Written responses could be clearer or more detailed',
    ];
    $strengths = []; $concerns = [];
    foreach ($categories as $label => $score) {
        if ($score >= 80) $strengths[] = $strengthPhrases[$label];
        elseif ($score < 62) $concerns[] = $concernPhrases[$label];
    }
    if (!empty($candidate['portfolio_url'])) $strengths[] = 'Provided a portfolio link showcasing additional work';
    if (!$strengths) $strengths[] = 'Application meets the basic requirements for review';
    if (!$concerns) $concerns[] = 'No significant concerns identified from the application alone — verify further during screening';

    $title = $job['title'] ?? 'this role';
    if ($overall >= 80) { $band = 'a strong'; $recommendation = 'Recommended for Interview'; }
    elseif ($overall >= 65) { $band = 'a promising'; $recommendation = 'Recommended for Screening Call'; }
    else { $band = 'a developing'; $recommendation = 'Not a Strong Match at This Time'; }
    $summary = "The candidate presents {$band} match for the {$title} position based on their application. "
        . ($overall >= 65 ? 'Their responses reflect relevant preparation and reasonable alignment with what the role calls for.' : 'Further screening is recommended to clarify fit before moving forward.');

    $aiNotes = "Candidate demonstrates ".($overall>=80?'strong':($overall>=65?'moderate':'limited'))." alignment with the {$title} position.\n\n"
        . "Key strengths:\n" . implode("\n", array_map(fn($s) => "- $s", $strengths))
        . "\n\nPotential concerns:\n" . implode("\n", array_map(fn($s) => "- $s", $concerns))
        . "\n\nRecommended next step:\n" . ($overall >= 80 ? 'Proceed to interview.' : ($overall >= 65 ? 'Proceed to screening call.' : 'Review carefully before proceeding; consider requesting more information.'));

    return [
        'overall_score' => $overall,
        'category_scores' => $categories,
        'summary' => $summary,
        'strengths' => $strengths,
        'concerns' => $concerns,
        'recommendation' => $recommendation,
        'ai_notes' => $aiNotes,
    ];
}

/** ISO 3166-1 alpha-2 code -> flag emoji, built from Unicode regional
 * indicator symbols (no image assets needed). */
function phone_country_flag(string $iso2): string {
    $iso2 = strtoupper($iso2);
    $flag = '';
    foreach (str_split($iso2) as $c) { $flag .= mb_chr(0x1F1E6 + (ord($c) - 65), 'UTF-8'); }
    return $flag;
}

/** A broad (not exhaustive) list of countries with their international
 * dialing code, used by apply.php's phone country selector. */
function phone_countries(): array {
    return [
        ['PH','Philippines','63'],['US','United States','1'],['CA','Canada','1'],['GB','United Kingdom','44'],
        ['JP','Japan','81'],['KR','South Korea','82'],['SG','Singapore','65'],['AU','Australia','61'],
        ['DE','Germany','49'],['FR','France','33'],['IN','India','91'],['CN','China','86'],['ID','Indonesia','62'],
        ['MY','Malaysia','60'],['TH','Thailand','66'],['VN','Vietnam','84'],['NZ','New Zealand','64'],
        ['IE','Ireland','353'],['NL','Netherlands','31'],['ES','Spain','34'],['IT','Italy','39'],['PT','Portugal','351'],
        ['CH','Switzerland','41'],['SE','Sweden','46'],['NO','Norway','47'],['DK','Denmark','45'],['FI','Finland','358'],
        ['PL','Poland','48'],['AT','Austria','43'],['BE','Belgium','32'],['BR','Brazil','55'],['MX','Mexico','52'],
        ['AR','Argentina','54'],['CL','Chile','56'],['CO','Colombia','57'],['ZA','South Africa','27'],['NG','Nigeria','234'],
        ['KE','Kenya','254'],['EG','Egypt','20'],['AE','United Arab Emirates','971'],['SA','Saudi Arabia','966'],
        ['IL','Israel','972'],['TR','Turkey','90'],['RU','Russia','7'],['UA','Ukraine','380'],['GR','Greece','30'],
        ['CZ','Czech Republic','420'],['HU','Hungary','36'],['RO','Romania','40'],['HK','Hong Kong','852'],
        ['TW','Taiwan','886'],['PK','Pakistan','92'],['BD','Bangladesh','880'],['LK','Sri Lanka','94'],
        ['NP','Nepal','977'],['QA','Qatar','974'],['KW','Kuwait','965'],['OM','Oman','968'],['BH','Bahrain','973'],
        ['JO','Jordan','962'],['LB','Lebanon','961'],['MA','Morocco','212'],['GH','Ghana','233'],
    ];
}

/** Looks up a candidate's application by id + email (the same pair used on the
 * public status page) and returns everything the status page and its live
 * polling endpoint both need, or null if it doesn't match. */
function application_status_payload(PDO $pdo, int $id, string $email): ?array {
    $stmt = $pdo->prepare('SELECT a.id,a.stage,a.status,a.applied_at,a.updated_at,j.title,c.first_name,c.last_name FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id WHERE a.id=? AND c.email=? LIMIT 1');
    $stmt->execute([$id, strtolower(trim($email))]);
    $result = $stmt->fetch();
    if (!$result) return null;

    $stageOrder = ['new'=>'Applied','screening'=>'Screening','interview'=>'Interview','offer'=>'Offer','hired'=>'Hired'];
    $rank = array_flip(array_keys($stageOrder));
    $current = $rank[$result['stage']] ?? 0;
    $timeline = [];
    foreach ($stageOrder as $key=>$label) { $timeline[] = ['key'=>$key,'label'=>$label,'reached'=>($rank[$key] <= $current)]; }

    $feedback = null; $suggestion = null;
    if ($result['stage'] === 'rejected') {
        $fbStmt = $pdo->prepare('SELECT fit,notes FROM candidate_feedback WHERE application_id=? ORDER BY created_at DESC LIMIT 1');
        $fbStmt->execute([$result['id']]); $feedback = $fbStmt->fetch() ?: null;
        $sgStmt = $pdo->prepare('SELECT j.title,s.note FROM candidate_role_suggestions s JOIN jobs j ON j.id=s.suggested_job_id WHERE s.application_id=? ORDER BY s.created_at DESC LIMIT 1');
        $sgStmt->execute([$result['id']]); $suggestion = $sgStmt->fetch() ?: null;
    }

    return [
        'id' => (int)$result['id'],
        'stage' => $result['stage'],
        'stage_label' => $stageOrder[$result['stage']] ?? ucfirst($result['stage']),
        'status' => $result['status'],
        'withdrawn' => $result['status'] === 'withdrawn',
        'title' => $result['title'],
        'first_name' => $result['first_name'],
        'last_name' => $result['last_name'],
        'applied_at' => $result['applied_at'],
        'updated_at' => $result['updated_at'],
        'timeline' => $timeline,
        'rejected' => $result['stage'] === 'rejected',
        'feedback' => $feedback,
        'suggestion' => $suggestion,
    ];
}

/** Withdraws a candidate's own application (ownership verified by id+email,
 * the same pair used everywhere else on the public status page). Reuses
 * the existing applications.status='withdrawn' value — no schema change,
 * and every existing recruiter-side query already filters to
 * status='active', so a withdrawn application disappears from the
 * pipeline/candidates list automatically. */
function withdraw_application(PDO $pdo, int $id, string $email): bool {
    $stmt = $pdo->prepare("SELECT a.id,a.stage,a.status,c.id candidate_id FROM applications a JOIN candidates c ON c.id=a.candidate_id WHERE a.id=? AND c.email=? LIMIT 1");
    $stmt->execute([$id, strtolower(trim($email))]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] === 'withdrawn') return false;

    $pdo->prepare("UPDATE applications SET status='withdrawn' WHERE id=?")->execute([$id]);
    $s = $pdo->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address) VALUES(?,?,?,?,?,?)');
    $s->execute([null, 'application_withdrawn', 'application', $id, json_encode(['previous_stage'=>$row['stage']]), $_SERVER['REMOTE_ADDR'] ?? null]);
    return true;
}

/** Consistent colored stage badge markup, shared by dashboard.php,
 * candidates.php, and candidate.php so status colors never drift apart. */
function stage_badge(string $stage, ?string $label = null): string {
    $labels = ['new'=>'Applied','screening'=>'Screening','interview'=>'Interview','offer'=>'Offer','hired'=>'Hired','rejected'=>'Rejected'];
    $cls = in_array($stage, array_keys($labels), true) ? $stage : 'new';
    $text = $label ?? ($labels[$stage] ?? ucfirst($stage));
    return '<span class="stage-badge stage-'.e($cls).'">'.e($text).'</span>';
}

/** Small inline SVG icon set — no external requests, themeable via currentColor. */
function icon(string $name, int $size = 18): string {
    $paths = [
        'overview'   => '<rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/>',
        'pipeline'   => '<circle cx="5" cy="6" r="2.5"/><circle cx="5" cy="18" r="2.5"/><circle cx="19" cy="12" r="2.5"/><path d="M5 8.5v7M7.3 6.9 16.7 11M7.3 17.1 16.7 13"/>',
        'candidates' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/><circle cx="18" cy="7" r="2.5"/><path d="M15.5 13.2c2.9.4 5 2.9 5 5.8"/>',
        'interviews' => '<rect x="2.5" y="5" width="13" height="14" rx="2.5"/><path d="M15.5 10.5 21 7v10l-5.5-3.5z"/>',
        'analytics'  => '<path d="M4 20V10M11 20V4M18 20v-7"/><path d="M2.5 20h19"/>',
        'employees'  => '<circle cx="8" cy="7" r="3.5"/><path d="M2 19.5c0-3.6 2.7-6.5 6-6.5s6 2.9 6 6.5"/><circle cx="17.5" cy="7.5" r="2.5"/><path d="M14.8 13.3c2.6.5 4.5 2.9 4.7 5.7"/>',
        'attendance' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'leave'      => '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/><path d="M8 14l2 2 4-4"/>',
        'payroll'    => '<rect x="2.5" y="5.5" width="19" height="13" rx="2.5"/><circle cx="12" cy="12" r="3"/><path d="M6 9v0M18 15v0"/>',
        'admin'      => '<path d="M12 2.5 4.5 6v6c0 4.8 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.7 7.5-9.5V6z"/><path d="M9 12l2 2 4-4.2"/>',
        'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'signout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'public'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-6-3.8-9S9.5 5.6 12 3z"/>',
        'tag'        => '<path d="M12.5 3H4.5A1.5 1.5 0 0 0 3 4.5v8l9.6 9.6a2 2 0 0 0 2.8 0l7.2-7.2a2 2 0 0 0 0-2.8L13 3.5z"/><circle cx="8" cy="8" r="1.6"/>',
        'users'      => '<circle cx="8.5" cy="8" r="3.3"/><path d="M2.7 20c0-3.4 2.6-6.1 5.8-6.1S14.3 16.6 14.3 20"/><circle cx="17" cy="8.4" r="2.4"/><path d="M14.7 14c2.5.5 4.3 2.8 4.5 5.4"/>',
        'limit'      => '<circle cx="12" cy="12" r="9"/><path d="M8 15l2.2-6L14 15M9 12.8h4"/>',
        'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.4 2"/>',
        'video'      => '<rect x="2.5" y="6" width="13" height="12" rx="2.5"/><path d="M15.5 11 21 8v8l-5.5-3z"/>',
        'mic'        => '<rect x="9" y="2.5" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M9 21h6"/>',
        'mic-off'    => '<path d="M3 3l18 18"/><path d="M9 5a3 3 0 0 1 6 0v6c0 .4 0 .8-.1 1.1M15 13c-.5.9-1.5 1.5-3 1.5A3 3 0 0 1 9 11.5V8"/><path d="M5.5 11a6.5 6.5 0 0 0 9.9 5.6M18.5 11a6.5 6.5 0 0 1-.5 2.5M12 17.5V21M9 21h6"/>',
        'cam-off'    => '<path d="M3 3l18 18"/><rect x="2.5" y="6" width="13" height="12" rx="2.5"/><path d="M15.5 11 21 8v8l-5.5-3z"/>',
        'star'       => '<path d="M12 3.5l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17.4l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
        'plus'       => '<path d="M12 5v14M5 12h14"/>',
        'check'      => '<path d="M4 12.5l5 5L20 6.5"/>',
        'sparkle'    => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
        'chevron'    => '<path d="M6 9l6 6 6-6"/>',
        'image'      => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="M21 15.5l-5.5-5.5L4 21"/>',
        'screen'     => '<rect x="2.5" y="4" width="19" height="13" rx="2.2"/><path d="M8 21h8M12 17v4"/><path d="M12 8v5M9.3 10.7 12 8l2.7 2.7"/>',
        'chat'       => '<path d="M3.5 12c0-4.7 3.9-8.5 8.7-8.5s8.7 3.8 8.7 8.5-3.9 8.5-8.7 8.5c-1.1 0-2.2-.2-3.1-.6L4 21l1.2-4.1A8.3 8.3 0 0 1 3.5 12z"/>',
        'hand'       => '<path d="M8 12.5V5a1.8 1.8 0 0 1 3.6 0v6M11.6 11V4a1.8 1.8 0 0 1 3.6 0v7M15.2 11.4V6.3a1.8 1.8 0 0 1 3.6 0V14c0 4.4-2.9 8-7.4 8-2.6 0-4.2-.9-5.7-2.7L2.8 15a1.7 1.7 0 0 1 2.6-2.2L8 15.5"/>',
        'more'       => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
        'record'     => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4.2" fill="currentColor" stroke="none"/>',
        'grid'       => '<rect x="3" y="3" width="8" height="8" rx="1.8"/><rect x="13" y="3" width="8" height="8" rx="1.8"/><rect x="3" y="13" width="8" height="8" rx="1.8"/><rect x="13" y="13" width="8" height="8" rx="1.8"/>',
        'send'       => '<path d="M21.5 2.5 2.5 9.7l7.4 3.4 3.4 7.4z"/><path d="M21.5 2.5 13.3 20.5l-3.4-7.4-7.4-3.4z"/>',
        'expand'     => '<path d="M9 3H3v6M15 21h6v-6M15 3h6v6M9 21H3v-6"/>',
        'link'       => '<path d="M9.5 14.5 14.5 9.5"/><path d="M11 6.5 13 4.4a3.5 3.5 0 0 1 5 5L16 11.5"/><path d="M13 17.5 11 19.6a3.5 3.5 0 0 1-5-5L8 12.5"/>',
        'bell'       => '<path d="M6 10a6 6 0 0 1 12 0c0 3.7.6 5.3 2 6.3.3.2.4.5.3.7-.1.3-.4.5-.7.5H4.4c-.3 0-.6-.2-.7-.5-.1-.2 0-.5.3-.7 1.4-1 2-2.6 2-6.3z"/><path d="M9.5 20a2.5 2.5 0 0 0 5 0"/>',
        'search'     => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
        'filter'     => '<path d="M4 5h16"/><path d="M7 12h10"/><path d="M10 19h4"/>',
    ];
    $body = $paths[$name] ?? $paths['sparkle'];
    return '<svg class="icon icon-' . e($name) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

