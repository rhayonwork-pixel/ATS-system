<?php
/** app/Models/Scorecard.php — the interview score/feedback fields live on
 * `interviews` itself (score, feedback columns) rather than a separate
 * table, so this model reads/writes those two columns with the same
 * "review_pending onward" rule interviews.php already enforces. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/interview_lib.php';

final class Scorecard
{
    public static function save(int $interviewId, ?int $score, string $feedback): bool
    {
        $interview = Interview::find($interviewId);
        if (!$interview || !interview_accepts_review($interview)) return false;

        Database::connection()
            ->prepare('UPDATE interviews SET score=?, feedback=?, status=IF(status IN ("cancelled"),status,"completed") WHERE id=?')
            ->execute([$score !== null ? max(0, min(5, $score)) : null, $feedback, $interviewId]);
        return true;
    }
}
