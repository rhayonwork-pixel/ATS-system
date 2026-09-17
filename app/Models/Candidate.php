<?php
/** app/Models/Candidate.php — thin wrapper around `candidates` +
 * `applications`. Complements includes/candidate_dal.php, which already
 * holds the richer candidate-profile query used by candidate.php; this
 * model is for the simpler lookups new code needs. */
require_once __DIR__ . '/../../config/database.php';

final class Candidate
{
    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM candidates WHERE id=? LIMIT 1');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM candidates WHERE email=? LIMIT 1');
        $s->execute([strtolower(trim($email))]);
        return $s->fetch() ?: null;
    }

    public static function applications(int $candidateId): array
    {
        $s = Database::connection()->prepare(
            'SELECT a.*, j.title FROM applications a JOIN jobs j ON j.id=a.job_id WHERE a.candidate_id=? ORDER BY a.applied_at DESC'
        );
        $s->execute([$candidateId]);
        return $s->fetchAll();
    }
}
