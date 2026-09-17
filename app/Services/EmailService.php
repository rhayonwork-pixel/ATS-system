<?php
/**
 * EmailService — sends transactional email through Resend's HTTP API.
 *
 * No SDK dependency: a single cURL POST to RESEND_API_URL, since InfinityFree
 * shared hosting cannot guarantee outbound SMTP ports but does allow outbound
 * HTTPS. If RESEND_API_KEY is empty (the shipped default), send() logs the
 * message to storage/logs/mail.log instead of calling the API and returns
 * true — so nothing in the interview-scheduling flow breaks while the key is
 * still blank, per the deployment contract.
 */

require_once __DIR__ . '/../../config/resend.php';
require_once __DIR__ . '/../../config/app.php';

final class EmailService
{
    /**
     * @param string[] $to
     * @return bool true on success (or on the deliberate no-op fallback)
     */
    public static function send(array $to, string $subject, string $html, ?string $text = null): bool
    {
        if (RESEND_API_KEY === '') {
            self::logToFile($to, $subject, 'RESEND_API_KEY not set — email not sent, logged only.');
            return true;
        }

        $payload = json_encode([
            'from'    => RESEND_FROM_NAME . ' <' . RESEND_FROM . '>',
            'to'      => $to,
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text ?? strip_tags($html),
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(RESEND_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            // Some shared hosts filter outbound requests that carry no
            // User-Agent (or curl's default one) — set an explicit one so
            // this doesn't silently get blocked at the network layer.
            CURLOPT_USERAGENT      => 'AcmeATS-EmailService/1.0 (+' . (defined('APP_URL') ? APP_URL : '') . ')',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . RESEND_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            self::logToFile($to, $subject, 'Resend send failed (HTTP ' . $status . '): ' . ($curlErr ?: $response));
            return false;
        }

        return true;
    }

    /** Interview invite — the one required transactional email in the spec. */
    public static function sendInterviewEmail(array $interview, array $candidate, array $job): bool
    {
        $to = trim($candidate['email'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

        $joinUrl = !empty($interview['room_code'])
            ? app_url('interview-room.php?code=' . urlencode($interview['candidate_token'] ?? ''))
            : trim((string) ($interview['meeting_url'] ?? ''));

        $when = !empty($interview['starts_at']) ? date('l, F j, Y', strtotime($interview['starts_at'])) : '';
        $time = !empty($interview['starts_at']) ? date('g:i A', strtotime($interview['starts_at'])) : '';
        $companyName = function_exists('setting') ? setting('company_name', 'Acme') : 'Acme';
        $logoUrl = function_exists('setting') && setting('logo_path') !== '' ? app_url(setting('logo_path')) : '';

        $html = self::interviewTemplate([
            'company'   => $companyName,
            'logo_url'  => $logoUrl,
            'candidate' => $candidate['name'] ?? 'there',
            'job_title' => $job['title'] ?? 'your application',
            'date'      => $when,
            'time'      => $time,
            'join_url'  => $joinUrl,
        ]);

        $subject = 'Your interview for ' . ($job['title'] ?? 'your application') . ' at ' . $companyName;
        return self::send([$to], $subject, $html);
    }

    private static function interviewTemplate(array $vars): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $logo = $vars['logo_url'] !== ''
            ? '<img src="' . $e($vars['logo_url']) . '" alt="' . $e($vars['company']) . '" width="40" height="40" style="border-radius:8px">'
            : '<div style="width:40px;height:40px;border-radius:8px;background:#17211b;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-family:sans-serif;font-weight:700">' . $e(substr($vars['company'], 0, 1)) . '</div>';

        return <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:32px 16px;background:#f7f8f5;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#17211b;">
  <table role="presentation" width="100%" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e7df;">
    <tr><td style="padding:28px 32px 0 32px;">{$logo}</td></tr>
    <tr><td style="padding:20px 32px 0 32px;">
      <h1 style="font-size:20px;margin:0 0 6px 0;">You're scheduled to interview for {$e($vars['job_title'])}</h1>
      <p style="color:#6d776f;line-height:1.6;margin:0 0 20px 0;">Hi {$e($vars['candidate'])}, here are your interview details.</p>
    </td></tr>
    <tr><td style="padding:0 32px;">
      <table role="presentation" width="100%" style="background:#f2f4f0;border-radius:12px;padding:16px;">
        <tr><td style="padding:6px 0;color:#6d776f;font-size:13px;">Date</td><td style="padding:6px 0;text-align:right;font-weight:600;">{$e($vars['date'])}</td></tr>
        <tr><td style="padding:6px 0;color:#6d776f;font-size:13px;">Time</td><td style="padding:6px 0;text-align:right;font-weight:600;">{$e($vars['time'])}</td></tr>
      </table>
    </td></tr>
    <tr><td style="padding:24px 32px 32px 32px;">
      <a href="{$e($vars['join_url'])}" style="display:block;text-align:center;background:#17211b;color:#ffffff;text-decoration:none;padding:14px 20px;border-radius:10px;font-weight:600;">Join Interview</a>
      <p style="color:#9aa39a;font-size:12px;margin:16px 0 0 0;">If the button doesn't work, copy this link: {$e($vars['join_url'])}</p>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private static function logToFile(array $to, string $subject, string $note): void
    {
        $dir = __DIR__ . '/../../storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $line = '[' . date('Y-m-d H:i:s') . '] to=' . implode(',', $to) . ' subject="' . $subject . '" — ' . $note . PHP_EOL;
        @file_put_contents($dir . '/mail.log', $line, FILE_APPEND);
    }
}
