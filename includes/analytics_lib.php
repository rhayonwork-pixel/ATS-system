<?php
/**
 * Acme ATS — recruiter workload analytics.
 *
 * Everything here is scoped to one person. The scoping happens in SQL, so a
 * recruiter cannot see another recruiter's numbers by editing a query string —
 * analytics.php decides whose id is allowed and this file does the rest.
 *
 * "Mine" is defined from relationships that already existed in the prototype:
 *   - applications.assigned_to (backfilled from the job's author)
 *   - jobs.created_by / jobs.owner_id, for roles that person opened
 *   - interviews.interviewer_id, for meetings they run
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/interview_lib.php';

/** Named reporting periods offered on the analytics page. */
function analytics_ranges(): array {
    return [
        'today'   => 'Today',
        'week'    => 'This week',
        'month'   => 'This month',
        'quarter' => 'Last 3 months',
        'all'     => 'All time',
        'custom'  => 'Custom range',
    ];
}

/**
 * Resolve a range key (plus optional custom dates) into [fromDate, toDate, label].
 * Both dates are inclusive Y-m-d strings; from is null for "all time".
 */
function analytics_resolve_range(string $key, string $customFrom = '', string $customTo = ''): array {
    $today = date('Y-m-d');
    switch ($key) {
        case 'today':
            return [$today, $today, 'Today'];
        case 'week':
            return [date('Y-m-d', strtotime('monday this week')), $today, 'This week'];
        case 'month':
            return [date('Y-m-01'), $today, 'This month'];
        case 'quarter':
            return [date('Y-m-d', strtotime('-3 months')), $today, 'Last 3 months'];
        case 'custom':
            $valid = '/^\d{4}-\d{2}-\d{2}$/';
            $from = preg_match($valid, $customFrom) ? $customFrom : date('Y-m-01');
            $to   = preg_match($valid, $customTo) ? $customTo : $today;
            if ($from > $to) [$from, $to] = [$to, $from];
            return [$from, $to, date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to))];
        case 'all':
        default:
            return [null, null, 'All time'];
    }
}

/** SQL fragment + params limiting a date column to the range. */
function analytics_range_clause(?string $from, ?string $to, string $column): array {
    if ($from === null) return ['', []];
    return [" AND $column >= ? AND $column <= ?", [$from . ' 00:00:00', $to . ' 23:59:59']];
}

/**
 * Applications belonging to one recruiter.
 * Returns [sqlFragment, params] to append inside a query that has `a` (applications)
 * and `j` (jobs) in scope.
 */
function analytics_owned_applications_clause(int $userId): array {
    return [
        ' AND (a.assigned_to = ? OR (a.assigned_to IS NULL AND (j.created_by = ? OR j.owner_id = ?)))',
        [$userId, $userId, $userId],
    ];
}

/**
 * The candidate/application side of one person's workload.
 * $from/$to filter on applied_at; passing null gives all time.
 */
function analytics_candidate_metrics(int $userId, ?string $from, ?string $to): array {
    $pdo = db();
    [$own, $ownParams] = analytics_owned_applications_clause($userId);
    [$range, $rangeParams] = analytics_range_clause($from, $to, 'a.applied_at');

    $sql = "SELECT a.stage, a.status, COUNT(*) total
            FROM applications a JOIN jobs j ON j.id = a.job_id
            WHERE 1=1 $own $range
            GROUP BY a.stage, a.status";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($ownParams, $rangeParams));

    $m = [
        'assigned' => 0, 'processing' => 0, 'shortlisted' => 0, 'contacted' => 0,
        'in_interview' => 0, 'offer' => 0, 'hired' => 0, 'rejected' => 0, 'withdrawn' => 0,
    ];
    foreach ($stmt->fetchAll() as $row) {
        $n = (int)$row['total'];
        $m['assigned'] += $n;
        if ($row['status'] === 'withdrawn') { $m['withdrawn'] += $n; continue; }
        switch ($row['stage']) {
            case 'screening': $m['shortlisted'] += $n; $m['contacted'] += $n; $m['processing'] += $n; break;
            case 'interview': $m['in_interview'] += $n; $m['contacted'] += $n; $m['processing'] += $n; break;
            case 'offer':     $m['offer'] += $n; $m['contacted'] += $n; $m['processing'] += $n; break;
            case 'hired':     $m['hired'] += $n; break;
            case 'rejected':  $m['rejected'] += $n; break;
            default:          $m['processing'] += $n; break;   // 'new'
        }
    }
    return $m;
}

/** Cumulative funnel: how many applications reached each stage or beyond. */
function analytics_funnel(int $userId, ?string $from, ?string $to): array {
    $order = ['new' => 'Applied', 'screening' => 'Shortlisted', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired'];
    $rank  = array_flip(array_keys($order));

    $pdo = db();
    [$own, $ownParams] = analytics_owned_applications_clause($userId);
    [$range, $rangeParams] = analytics_range_clause($from, $to, 'a.applied_at');

    $stmt = $pdo->prepare("SELECT a.stage, COUNT(*) total
                           FROM applications a JOIN jobs j ON j.id = a.job_id
                           WHERE a.status='active' $own $range
                           GROUP BY a.stage");
    $stmt->execute(array_merge($ownParams, $rangeParams));
    $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $funnel = [];
    foreach ($order as $key => $label) {
        $reached = 0;
        foreach ($counts as $stage => $total) {
            if (isset($rank[$stage]) && $rank[$stage] >= $rank[$key]) $reached += (int)$total;
        }
        $funnel[$label] = $reached;
    }
    return $funnel;
}

/** Everything about the interviews one person runs. */
function analytics_interview_metrics(int $userId, ?string $from, ?string $to): array {
    $pdo = db();
    [$range, $rangeParams] = analytics_range_clause($from, $to, 'i.starts_at');

    $stmt = $pdo->prepare("SELECT i.status, i.meeting_state, i.starts_at, i.score
                           FROM interviews i
                           WHERE i.interviewer_id = ? $range");
    $stmt->execute(array_merge([$userId], $rangeParams));
    $rows = $stmt->fetchAll();

    $m = [
        'total' => 0, 'upcoming' => 0, 'completed' => 0, 'cancelled' => 0,
        'in_progress' => 0, 'awaiting_review' => 0, 'reviewed' => 0,
        'avg_score' => null, 'scored' => 0,
    ];
    $scoreSum = 0;
    foreach ($rows as $r) {
        $m['total']++;
        $state = interview_state($r);
        if ($state === 'cancelled' || $state === 'no_show') { $m['cancelled']++; continue; }
        if ($state === 'in_progress') $m['in_progress']++;
        if ($state === 'review_pending') $m['awaiting_review']++;
        if ($state === 'reviewed') { $m['reviewed']++; }
        if (in_array($state, ['ended', 'review_pending', 'reviewed'], true)) $m['completed']++;
        if (in_array($state, ['scheduled', 'ready'], true) && strtotime($r['starts_at']) >= time()) $m['upcoming']++;
        if ($r['score'] !== null) { $m['scored']++; $scoreSum += (int)$r['score']; }
    }
    if ($m['scored'] > 0) $m['avg_score'] = (int)round($scoreSum / $m['scored']);
    return $m;
}

/**
 * How many candidates moved forward after an interview this person ran,
 * versus how many did not. Read from the application's current stage.
 */
function analytics_interview_outcomes(int $userId, ?string $from, ?string $to): array {
    $pdo = db();
    [$range, $rangeParams] = analytics_range_clause($from, $to, 'i.starts_at');
    $stmt = $pdo->prepare("SELECT a.stage, COUNT(DISTINCT a.id) total
                           FROM interviews i JOIN applications a ON a.id = i.application_id
                           WHERE i.interviewer_id = ? AND i.meeting_state IN ('ended','review_pending','reviewed') $range
                           GROUP BY a.stage");
    $stmt->execute(array_merge([$userId], $rangeParams));

    $out = ['progressed' => 0, 'not_progressed' => 0];
    foreach ($stmt->fetchAll() as $r) {
        $n = (int)$r['total'];
        if (in_array($r['stage'], ['offer', 'hired'], true)) $out['progressed'] += $n;
        elseif ($r['stage'] === 'rejected') $out['not_progressed'] += $n;
    }
    return $out;
}

/** Interviews completed per period, for the activity chart. */
function analytics_interview_timeline(int $userId, ?string $from, ?string $to, int $buckets = 8): array {
    $pdo = db();
    // Without an explicit range, look back over the last two months.
    $start = $from ?? date('Y-m-d', strtotime('-8 weeks'));
    $end   = $to ?? date('Y-m-d');

    $stmt = $pdo->prepare("SELECT DATE(i.starts_at) day, COUNT(*) total
                           FROM interviews i
                           WHERE i.interviewer_id = ?
                             AND i.starts_at >= ? AND i.starts_at <= ?
                             AND i.meeting_state IN ('ended','review_pending','reviewed')
                           GROUP BY day ORDER BY day");
    $stmt->execute([$userId, $start . ' 00:00:00', $end . ' 23:59:59']);
    $byDay = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    $span    = max(1, (int)ceil(($endTs - $startTs) / 86400) + 1);
    $size    = max(1, (int)ceil($span / $buckets));

    $series = [];
    for ($offset = 0; $offset < $span; $offset += $size) {
        $bStart = strtotime("+$offset days", $startTs);
        $bEnd   = min($endTs, strtotime('+' . ($offset + $size - 1) . ' days', $startTs));
        $total  = 0;
        for ($d = $bStart; $d <= $bEnd; $d = strtotime('+1 day', $d)) {
            $total += (int)($byDay[date('Y-m-d', $d)] ?? 0);
        }
        $series[] = [
            'label' => $size === 1 ? date('M j', $bStart) : date('M j', $bStart) . '–' . date('j', $bEnd),
            'total' => $total,
        ];
    }
    return $series;
}

/**
 * Recent activity attributed to one person, drawn from the audit trail that
 * already records every meaningful action. No new tracking was introduced.
 */
function analytics_activity_timeline(int $userId, ?string $from, ?string $to, int $limit = 12): array {
    $pdo = db();
    [$range, $rangeParams] = analytics_range_clause($from, $to, 'al.created_at');
    $relevant = "('pipeline_stage_move','interview_create','interview_ended','interview_review_submitted',
                  'interview_notes_saved','meeting_review','candidate_note','job_submit_for_approval',
                  'job_draft_save','interview_status','interview_feedback')";
    $stmt = $pdo->prepare("SELECT al.*, c.first_name, c.last_name, j.title job_title
                           FROM audit_logs al
                           LEFT JOIN applications ap ON al.entity_type='application' AND al.entity_id=ap.id
                           LEFT JOIN interviews iv ON al.entity_type='interview' AND al.entity_id=iv.id
                           LEFT JOIN applications ai ON ai.id = iv.application_id
                           LEFT JOIN candidates c ON c.id = COALESCE(ap.candidate_id, ai.candidate_id)
                           LEFT JOIN jobs j ON j.id = COALESCE(ap.job_id, ai.job_id)
                           WHERE al.user_id = ? AND al.action IN $relevant $range
                           ORDER BY al.created_at DESC LIMIT " . (int)$limit);
    $stmt->execute(array_merge([$userId], $rangeParams));
    return $stmt->fetchAll() ?: [];
}

/** A readable sentence for one activity row. */
function analytics_activity_text(array $row): string {
    $who = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $what = [
        'pipeline_stage_move'         => 'Moved %s in the pipeline',
        'interview_create'            => 'Scheduled an interview with %s',
        'interview_ended'             => 'Ended the interview with %s',
        'interview_review_submitted'  => 'Submitted the interview review for %s',
        'meeting_review'              => 'Submitted the interview review for %s',
        'interview_notes_saved'       => 'Saved interview notes for %s',
        'interview_status'            => 'Updated the interview status for %s',
        'interview_feedback'          => 'Recorded interview feedback for %s',
        'candidate_note'              => 'Added a note on %s',
        'job_submit_for_approval'     => 'Submitted a job posting for approval',
        'job_draft_save'              => 'Saved a job draft',
    ][$row['action']] ?? ucwords(str_replace('_', ' ', $row['action']));

    if (strpos($what, '%s') === false) return $what;
    return sprintf($what, $who !== '' ? $who : 'a candidate');
}

/**
 * The HR/Recruiters an Admin monitors: the ones they provisioned.
 * A Super Admin passing through sees everyone, which is only reachable from
 * analytics.php when the signed-in user really is a Super Admin.
 */
function analytics_team_members(int $adminId, bool $allTeams = false): array {
    $pdo = db();
    if ($allTeams) {
        return $pdo->query("SELECT id, name, email, role, account_status FROM users
                            WHERE role IN ('recruiter','hiring_manager') ORDER BY name")->fetchAll() ?: [];
    }
    $stmt = $pdo->prepare("SELECT id, name, email, role, account_status FROM users
                           WHERE role IN ('recruiter','hiring_manager') AND created_by = ? ORDER BY name");
    $stmt->execute([$adminId]);
    return $stmt->fetchAll() ?: [];
}

/** One summary row per team member, for the comparison table. */
function analytics_team_summary(array $members, ?string $from, ?string $to): array {
    $rows = [];
    foreach ($members as $m) {
        $uid = (int)$m['id'];
        $c = analytics_candidate_metrics($uid, $from, $to);
        $i = analytics_interview_metrics($uid, $from, $to);
        $rows[] = [
            'user'       => $m,
            'candidates' => $c['assigned'],
            'processing' => $c['processing'],
            'interviews' => $i['total'],
            'completed'  => $i['completed'],
            'hired'      => $c['hired'],
            'rejected'   => $c['rejected'],
            'avg_score'  => $i['avg_score'],
        ];
    }
    return $rows;
}

/**
 * Time to hire, in days: application date to the moment it reached "hired".
 * applications has no hired_at column, so updated_at is used as the closest
 * honest proxy — it is the timestamp of the stage move. Returns null when
 * nobody has been hired in the period, rather than a misleading zero.
 */
function analytics_time_to_hire(int $userId, ?string $from, ?string $to): ?float {
    $pdo = db();
    [$own, $ownParams] = analytics_owned_applications_clause($userId);
    [$range, $rangeParams] = analytics_range_clause($from, $to, 'a.updated_at');
    try {
        $stmt = $pdo->prepare("SELECT AVG(DATEDIFF(a.updated_at, a.applied_at)) avg_days
                               FROM applications a JOIN jobs j ON j.id = a.job_id
                               WHERE a.stage='hired' AND a.updated_at IS NOT NULL
                                 AND a.updated_at >= a.applied_at $own $range");
        $stmt->execute(array_merge($ownParams, $rangeParams));
        $val = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    return ($val === null || $val === false) ? null : round((float)$val, 1);
}

/**
 * Offer acceptance rate: of the applications that reached an offer, how many
 * ended as hired. Anything still sitting at "offer" is undecided and is
 * excluded from both sides, so a pending offer cannot drag the rate down.
 * Returns null when no offer has been decided yet.
 */
function analytics_offer_acceptance(int $userId, ?string $from, ?string $to): ?array {
    $pdo = db();
    [$own, $ownParams] = analytics_owned_applications_clause($userId);
    [$range, $rangeParams] = analytics_range_clause($from, $to, 'a.applied_at');
    try {
        $stmt = $pdo->prepare("SELECT
                    SUM(a.stage='hired') hired,
                    SUM(a.stage='offer') pending,
                    SUM(a.stage='rejected' AND a.status='withdrawn') declined
                  FROM applications a JOIN jobs j ON j.id = a.job_id
                  WHERE 1=1 $own $range");
        $stmt->execute(array_merge($ownParams, $rangeParams));
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    $hired    = (int)($row['hired'] ?? 0);
    $declined = (int)($row['declined'] ?? 0);
    $decided  = $hired + $declined;
    if ($decided === 0) return null;
    return [
        'rate'     => (int)round($hired / $decided * 100),
        'hired'    => $hired,
        'declined' => $declined,
        'pending'  => (int)($row['pending'] ?? 0),
    ];
}
