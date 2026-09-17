<?php
/** app/Models/Interview.php — thin wrapper around `interviews`. The richer
 * lifecycle helpers (state machine, review eligibility) stay in
 * includes/interview_lib.php, which both interview-room.php and this model
 * depend on, so there is exactly one definition of each rule. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/interview_lib.php';

final class Interview
{
    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM interviews WHERE id=? LIMIT 1');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function findByRoomCode(string $roomCode): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM interviews WHERE room_code=? LIMIT 1');
        $s->execute([$roomCode]);
        return $s->fetch() ?: null;
    }

    public static function upcomingForInterviewer(int $userId): array
    {
        $s = Database::connection()->prepare(
            "SELECT * FROM interviews WHERE interviewer_id=? AND status NOT IN ('cancelled','completed') ORDER BY starts_at ASC"
        );
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    public static function state(array $interview): string
    {
        return interview_state($interview);
    }

    public static function acceptsReview(array $interview): bool
    {
        return interview_accepts_review($interview);
    }
}
