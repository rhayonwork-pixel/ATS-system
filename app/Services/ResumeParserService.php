<?php
/** app/Services/ResumeParserService.php — thin entry point over
 * includes/pdf_extract.php's pdf_extract_text(), so new code has one call
 * instead of including the file and knowing its function name directly. */
require_once __DIR__ . '/../../includes/pdf_extract.php';

final class ResumeParserService
{
    /** Extracts raw text + metadata from a PDF resume on disk.
     * @return array{text?: string, pages?: int} shape as returned by pdf_extract_text() */
    public static function extractPdfText(string $absolutePath): array
    {
        return function_exists('pdf_extract_text') ? pdf_extract_text($absolutePath) : [];
    }
}
