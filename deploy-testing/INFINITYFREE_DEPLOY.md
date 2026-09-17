# InfinityFree Deployment (verified against InfinityFree's current setup)

## 1. Account + hosting

1. Sign up at infinityfree.net and confirm your email.
2. In the client area, click **Create Account**, pick a free subdomain
   (`yourname.infinityfreeapp.com`, `.epizy.com`, `.freecluster.eu`, or
   `.rf.gd`) — or attach your own domain — and finish setup. Note the
   generated account username, shown as **`if0_XXXXXXXX`** — every
   database and DB user you create is prefixed with it.
3. Once status shows active, open **Control Panel** from the client area.

## 2. Upload

In the Control Panel, open **File Manager** → `htdocs/`. Delete the
default placeholder files, then upload this entire project (keeping the
folder structure — `app/`, `config/`, `api/`, `includes/`, `database/`,
everything) either through File Manager's upload or an FTP client
(FileZilla, using the FTP credentials shown in the Control Panel).

## 3. Database

1. In the Control Panel, open **MySQL Databases**.
2. Create a database — you'll get a full name like
   `if0_XXXXXXXX_acme_ats` (InfinityFree always prefixes it; you can't
   drop the prefix).
3. Click **phpMyAdmin** next to it. Import `database/schema.sql`, then
   every file in `database/migrations/` **in numeric order, 001 through
   018** (see `database/README.txt` — this exact order has been tested
   end to end against a real MySQL server).
4. Note the **MySQL Hostname** shown on that same page — on InfinityFree
   this is a per-server hostname like `sql200.infinityfree.com`
   (older accounts may show `.epizy.com` or `.byetcluster.com` instead —
   use whatever your Control Panel actually displays, not the example
   here). **It is not `localhost`.** This is the single most common
   deployment mistake — always copy it from the panel.

## 4. Configure `.env`

Copy `.env.example` to `.env` in the project root and fill in the real
values from steps 2–3:
```
APP_URL=https://yourname.infinityfreeapp.com
APP_ENV=production
APP_DEBUG=false

DB_HOST=sql200.infinityfree.com
DB_PORT=3306
DB_NAME=if0_XXXXXXXX_acme_ats
DB_USER=if0_XXXXXXXX
DB_PASS=your-db-password

RESEND_API_KEY=re_your_key_here
RESEND_FROM=no-reply@yourdomain.com
```
No PHP file needs to change between environments — only `.env`.

## 5. HTTPS

Free HTTPS (via Cloudflare) is available in the Control Panel under the
SSL/domain section — enable it for your subdomain or custom domain, then
make sure `APP_URL` uses `https://`.

## 6. Folder permissions

`storage/logs/`, `storage/cache/`, `storage/temp/`, and
`assets/uploads/{resumes,profile-images,company}/` need to be writable.
InfinityFree's File Manager generally creates new folders at 755, which
PHP can already write into on shared hosting — if uploads or
`storage/logs/mail.log` aren't appearing, set those folders to 755 (not
777) via the File Manager permissions dialog.

## 7. Known limitation — outbound requests (affects the Resend email)

InfinityFree's free tier runs outbound traffic through its own filtering
layer, and PHP `curl`/outbound HTTP calls to external APIs are reported
(on InfinityFree's own support forum, repeatedly, through 2025–2026) to
sometimes fail with a DNS resolution error or a 403, even though the
same code works fine on XAMPP or another host. This directly affects
`EmailService`'s call to the Resend API. Before relying on it in
production:

1. Deploy, add your `RESEND_API_KEY`, and schedule one test interview.
2. Check `storage/logs/mail.log` — if Resend's API returned an error
   (not just "key not set"), that's logged there with the HTTP status
   and response.
3. If outbound calls are being blocked, InfinityFree's forum is the
   right place to ask them to allow-list `api.resend.com` — this is a
   known, recurring, account-specific issue on their end, not something
   fixable from this codebase. If it stays unreliable, the practical
   fallback is running the email-sending step from somewhere with
   guaranteed outbound access (a small always-on job elsewhere calling
   the same Resend API, or a different host for just this project).

## 8. Verify

- Visit your domain — `login.php` should load without a "Database
  unavailable" page. If you see that page, re-check `DB_HOST` first.
- Log in with the seeded admin account, then change that password
  immediately.
- Schedule a test interview, confirm the invite behaves per step 7.
- Open `interview-room.php` from two different browsers/devices and
  confirm the WebRTC call actually connects — see
  `docs/INTERVIEW_SETUP.md` for the STUN-only/no-TURN limitation.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Database unavailable" page | Wrong `DB_HOST` — copy it from the MySQL Databases page, it's never `localhost` |
| 500 error, blank page | Check the Control Panel's error log; `APP_DEBUG=false` hides PHP errors from visitors on purpose |
| Uploads fail silently | Folder not writable — see step 6 |
| Interview email never arrives, `mail.log` shows a Resend error | Outbound request likely filtered — see step 7 |
| WebRTC never connects | Both sides on symmetric NAT — see the STUN/TURN note in `docs/INTERVIEW_SETUP.md` |
