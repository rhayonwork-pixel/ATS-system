<?php
/**
 * app/Services/UploadService.php — resume + avatar upload handling.
 * Wraps the exact same validated logic that already lives in
 * includes/config.php (save_resume_upload / save_profile_image_upload),
 * which existing pages keep calling directly and unchanged. This class is
 * the same rules, callable from new code as a service instead of loose
 * functions.
 */
require_once __DIR__ . '/../../includes/config.php';

final class UploadService
{
    public static function resume(array $file, string $candidateName, ?string &$error = null): ?string
    {
        return save_resume_upload($file, $candidateName, $error);
    }

    public static function avatar(array $file, string $candidateName, ?string &$error = null): ?string
    {
        return save_profile_image_upload($file, $candidateName, $error);
    }
}
