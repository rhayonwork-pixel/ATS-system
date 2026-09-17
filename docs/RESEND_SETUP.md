# Resend Email Setup

Interview invites go through [Resend](https://resend.com)'s HTTP API —
no SMTP, no PHPMailer dependency, since InfinityFree shared hosting
doesn't reliably allow outbound SMTP but does allow outbound HTTPS.

## Setup

1. Sign up at resend.com, verify a sending domain (or use their test
   domain while developing).
2. Create an API key.
3. Copy `.env.example` to `.env` and fill in:
   ```
   RESEND_API_KEY=re_your_key_here
   RESEND_FROM=no-reply@yourdomain.com
   RESEND_FROM_NAME=Your Company Name
   ```
4. That's it — `app/Services/EmailService.php` picks it up automatically
   through `config/resend.php`. No code changes needed.

## Until you add a key

`RESEND_API_KEY` ships blank on purpose (per the "don't insert my API key"
requirement). With no key, `EmailService::send()` doesn't fail — it logs
what it would have sent to `storage/logs/mail.log` and returns success,
so scheduling an interview never breaks just because email isn't
configured yet. Check that file if you want to see the exact HTML that
would have gone out.

## What's sent today

Only the interview invite (`EmailService::sendInterviewEmail()`), fired
from `interviews.php` and `api/interview/schedule.php` right after an
interview is saved. It includes the company name/logo (from `settings`),
the interview date/time, and a "Join Interview" button linking to either
the built-in room (`interview-room.php?code=...`) or the external
meeting URL, using `APP_URL` — never a hardcoded `localhost`.

## Extending it

`EmailService::send($to, $subject, $html, $text)` is the general-purpose
entry point — call it directly for any other transactional email
(password reset, application received, offer letter, etc.) without
touching the Resend wiring itself.
