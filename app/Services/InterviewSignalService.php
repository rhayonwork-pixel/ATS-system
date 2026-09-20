<?php
/**
 * InterviewSignalService
 *
 * Backs the WebRTC signaling endpoints in api/interview/. InfinityFree gives
 * us no WebSockets, so two browsers exchange their SDP offer/answer and ICE
 * candidates by polling MySQL through short PHP scripts — this class is the
 * only thing that touches the interview_signals / interview_ice_candidates
 * tables (migration 010).
 *
 * Identity check is intentionally a copy of interview-access.php's model
 * (session for staff, per-interview token for the candidate) rather than a
 * shared include, so nothing about the already-working waiting room changes
 * — this class only has to agree with it, not depend on it.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/interview_lib.php';

final class InterviewSignalService
{
    /** @return array{row: array, role: 'host'|'candidate'}|null */
    public static function resolve(string $roomCode, string $token): ?array
    {
        if ($roomCode === '') return null;

        if (!empty($_SESSION['user_id'])) {
            $s = db()->prepare('SELECT * FROM users WHERE id=? AND active=1 LIMIT 1');
            $s->execute([(int) $_SESSION['user_id']]);
            $user = $s->fetch();
            if ($user && in_array($user['role'] ?? '', ['admin', 'recruiter', 'hiring_manager', 'super_admin'], true)) {
                $row = self::findByRoomCode($roomCode);
                if ($row) return ['row' => $row, 'role' => 'host'];
            }
        }

        if ($token !== '' && function_exists('interview_for_candidate_token')) {
            $row = interview_for_candidate_token($roomCode, $token);
            if ($row) return ['row' => $row, 'role' => 'candidate'];
        }

        return null;
    }

    private static function findByRoomCode(string $roomCode): ?array
    {
        $s = db()->prepare('SELECT * FROM interviews WHERE room_code=? LIMIT 1');
        $s->execute([$roomCode]);
        return $s->fetch() ?: null;
    }

    /**
     * Every SDP line, including the last, must end with CRLF — RFC 4566 treats
     * the terminator as part of the line, and Chrome refuses to parse a session
     * description whose final line is unterminated ("Invalid SDP line").
     *
     * The signalling endpoints trim() what they receive, which strips that final
     * CRLF. That went unnoticed while the last line was an a=ssrc attribute the
     * parser tolerated; adding a data channel moved a=max-message-size to the
     * end, and every answer then failed. Normalising here fixes both directions
     * at once and also repairs rows that were stored before this change.
     */
    private static function normaliseSdp(string $sdp): string
    {
        $sdp = str_replace("
", "
", $sdp);
        $sdp = rtrim($sdp, "
");
        $sdp = str_replace("
", "
", $sdp);
        return $sdp === '' ? '' : $sdp . "
";
    }

    private static function ensureRow(int $interviewId): void
    {
        db()->prepare('INSERT IGNORE INTO interview_signals(interview_id) VALUES (?)')->execute([$interviewId]);
    }

    public static function saveOffer(int $interviewId, string $sdp): void
    {
        self::ensureRow($interviewId);
        db()->prepare('UPDATE interview_signals SET offer_sdp=?, offer_updated_at=NOW() WHERE interview_id=?')
            ->execute([self::normaliseSdp($sdp), $interviewId]);
    }

    public static function getOffer(int $interviewId): ?string
    {
        $s = db()->prepare('SELECT offer_sdp FROM interview_signals WHERE interview_id=?');
        $s->execute([$interviewId]);
        $sdp = $s->fetchColumn();
        return $sdp !== false && $sdp !== null ? self::normaliseSdp((string) $sdp) : null;
    }

    public static function saveAnswer(int $interviewId, string $sdp): void
    {
        self::ensureRow($interviewId);
        db()->prepare('UPDATE interview_signals SET answer_sdp=?, answer_updated_at=NOW(), connected_at=COALESCE(connected_at, NOW()) WHERE interview_id=?')
            ->execute([self::normaliseSdp($sdp), $interviewId]);
    }

    public static function getAnswer(int $interviewId): ?string
    {
        $s = db()->prepare('SELECT answer_sdp FROM interview_signals WHERE interview_id=?');
        $s->execute([$interviewId]);
        $sdp = $s->fetchColumn();
        return $sdp !== false && $sdp !== null ? self::normaliseSdp((string) $sdp) : null;
    }

    /** The other side's role — a host only needs candidate ICE and vice versa. */
    private static function peerRole(string $role): string
    {
        return $role === 'host' ? 'candidate' : 'host';
    }

    public static function saveIce(int $interviewId, string $role, string $candidateJson): void
    {
        db()->prepare('INSERT INTO interview_ice_candidates(interview_id, role, candidate) VALUES (?,?,?)')
            ->execute([$interviewId, $role, $candidateJson]);
    }

    /** Returns ICE candidates from the *other* side that came after $sinceId. */
    public static function getIce(int $interviewId, string $role, int $sinceId): array
    {
        $s = db()->prepare('SELECT id, candidate FROM interview_ice_candidates WHERE interview_id=? AND role=? AND id > ? ORDER BY id ASC');
        $s->execute([$interviewId, self::peerRole($role), $sinceId]);
        return $s->fetchAll();
    }

    public static function markEnded(int $interviewId): void
    {
        self::ensureRow($interviewId);
        db()->prepare('UPDATE interview_signals SET ended_at=NOW() WHERE interview_id=?')->execute([$interviewId]);
    }

    /** True once the row has been marked ended (either side hung up / left). */
    public static function isEnded(int $interviewId): bool
    {
        $s = db()->prepare('SELECT ended_at FROM interview_signals WHERE interview_id=?');
        $s->execute([$interviewId]);
        return (bool) $s->fetchColumn();
    }
}
