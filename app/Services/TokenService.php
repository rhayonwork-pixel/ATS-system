<?php
/** app/Services/TokenService.php — the two token shapes this app already
 * relies on: a candidate's per-interview join token (interviews.candidate_token,
 * 32 hex chars — generate_candidate_token() in includes/interview_lib.php)
 * and a CSRF token (csrf_token() in includes/config.php). Centralized here
 * so new code has one obvious place to generate either, without redefining
 * the format. */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/interview_lib.php';

final class TokenService
{
    public static function newCandidateToken(): string
    {
        return generate_candidate_token();
    }

    public static function csrf(): string
    {
        return csrf_token();
    }

    public static function checkCsrf(): void
    {
        check_csrf();
    }
}
