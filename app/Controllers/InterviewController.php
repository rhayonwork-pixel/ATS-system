<?php
/**
 * app/Controllers/InterviewController.php
 *
 * Unlike AuthController, this one IS live: api/interview/schedule.php,
 * notes.php, and scorecard.php are new files added in this reorganization
 * (they did not exist before), so wiring them straight to Models/Services
 * here carries none of the "might silently break a working login" risk —
 * there was no prior working behavior to preserve. interviews.php (the
 * original HR page) is untouched and keeps working exactly as it did.
 */
require_once __DIR__ . '/../Models/Interview.php';
require_once __DIR__ . '/../Models/Scorecard.php';
require_once __DIR__ . '/../Services/EmailService.php';
require_once __DIR__ . '/../Services/TokenService.php';
require_once __DIR__ . '/../../includes/interview_lib.php';

final class InterviewController
{
    /** @return array{ok:bool, interview_id?:int, room_code?:?string, error?:string} */
    public static function schedule(array $input, int $interviewerId): array
    {
        $application = (int) ($input['application_id'] ?? 0);
        $start       = $input['starts_at'] ?? '';
        $type        = $input['interview_type'] ?? 'video';
        $meetingType = ($input['meeting_type'] ?? '') === 'screening' ? 'screening' : 'interview';
        $useRoom     = ($input['meeting_mode'] ?? 'builtin') === 'builtin';

        if (!$application || !$start || !in_array($type, ['phone', 'video', 'onsite', 'panel'], true)) {
            return ['ok' => false, 'error' => 'Missing application, start time, or interview type.'];
        }

        $pdo = Database::connection();
        $conflict = $pdo->prepare("SELECT COUNT(*) FROM interviews WHERE interviewer_id=? AND status NOT IN ('cancelled') AND starts_at=?");
        $conflict->execute([$interviewerId, $start]);
        if ((int) $conflict->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'Interviewer already booked at that time.'];
        }

        $roomCode = $useRoom ? strtoupper(bin2hex(random_bytes(4))) : null;
        $candidateToken = TokenService::newCandidateToken();

        $s = $pdo->prepare('INSERT INTO interviews(application_id,interviewer_id,meeting_type,starts_at,ends_at,interview_type,meeting_url,meeting_provider,room_code,candidate_token,location,notes,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([
            $application, $interviewerId, $meetingType, $start, $input['ends_at'] ?? null, $type,
            $useRoom ? '' : trim($input['meeting_url'] ?? ''),
            $useRoom ? 'Acme Room' : trim($input['meeting_provider'] ?? 'Zoom'),
            $roomCode, $candidateToken, trim($input['location'] ?? ''), trim($input['notes'] ?? ''), 'scheduled',
        ]);
        $interviewId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE applications SET assigned_to=? WHERE id=? AND assigned_to IS NULL')->execute([$interviewerId, $application]);
        $newStage = $meetingType === 'screening' ? 'screening' : 'interview';
        $pdo->prepare("UPDATE applications SET stage=? WHERE id=? AND stage NOT IN ('hired','rejected')")->execute([$newStage, $application]);
        audit('interview_create', 'interview', $interviewId);

        self::notifyCandidate($application, $roomCode, $candidateToken, $input['meeting_url'] ?? '', $start);

        return ['ok' => true, 'interview_id' => $interviewId, 'room_code' => $roomCode];
    }

    private static function notifyCandidate(int $applicationId, ?string $roomCode, string $candidateToken, string $meetingUrl, string $start): void
    {
        try {
            $ctx = Database::connection()->prepare('SELECT CONCAT(c.first_name,\' \',c.last_name) AS name, c.email, j.title FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN jobs j ON j.id=a.job_id WHERE a.id=?');
            $ctx->execute([$applicationId]);
            if ($row = $ctx->fetch()) {
                EmailService::sendInterviewEmail(
                    ['room_code' => $roomCode, 'candidate_token' => $candidateToken, 'meeting_url' => $meetingUrl, 'starts_at' => $start],
                    ['name' => $row['name'], 'email' => $row['email']],
                    ['title' => $row['title']]
                );
            }
        } catch (Throwable $e) { @file_put_contents(__DIR__ . "/../../storage/logs/mail.log", "[" . date("Y-m-d H:i:s") . "] interview invite email failed: " . $e->getMessage() . PHP_EOL, FILE_APPEND); }
    }

    public static function saveScorecard(int $interviewId, ?int $score, string $feedback): array
    {
        $saved = Scorecard::save($interviewId, $score, $feedback);
        return $saved ? ['ok' => true] : ['ok' => false, 'error' => 'This interview cannot be scored yet.'];
    }
}
