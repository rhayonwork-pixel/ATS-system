<?php
/**
 * Resend (https://resend.com) configuration.
 *
 * RESEND_API_KEY is intentionally left blank in .env.example — add your own
 * key to .env (never commit it). Until a key is present, EmailService simply
 * logs the outgoing email to storage/logs/mail.log and returns without
 * sending, so scheduling an interview never fails just because email isn't
 * configured yet.
 */

require_once __DIR__ . '/env.php';

if (!defined('RESEND_API_KEY'))  define('RESEND_API_KEY', (string) env('RESEND_API_KEY', ''));
if (!defined('RESEND_FROM'))     define('RESEND_FROM', (string) env('RESEND_FROM', 'no-reply@example.com'));
if (!defined('RESEND_FROM_NAME'))define('RESEND_FROM_NAME', (string) env('RESEND_FROM_NAME', 'Acme ATS'));
if (!defined('RESEND_API_URL'))  define('RESEND_API_URL', 'https://api.resend.com/emails');
