<?php
/** app/Models/Job.php — thin wrapper around the `jobs` table. */
require_once __DIR__ . '/../../config/database.php';

final class Job
{
    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM jobs WHERE id=? LIMIT 1');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM jobs WHERE slug=? LIMIT 1');
        $s->execute([$slug]);
        return $s->fetch() ?: null;
    }

    public static function published(): array
    {
        return Database::connection()
            ->query("SELECT * FROM jobs WHERE status='open' ORDER BY published_at DESC")
            ->fetchAll();
    }

    public static function applicationCount(int $jobId): int
    {
        $s = Database::connection()->prepare('SELECT COUNT(*) FROM applications WHERE job_id=?');
        $s->execute([$jobId]);
        return (int) $s->fetchColumn();
    }
}
