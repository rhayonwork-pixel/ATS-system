# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Acme ATS — an applicant tracking system written in plain PHP 8 + MySQL, designed to run on
XAMPP and deploy to InfinityFree shared hosting or through a Cloudflare Tunnel. There is no
Composer, no `vendor/`, no npm, and no build step. Every dependency is either bundled in
`includes/` (for example `pdf_extract.php`, a hand-written PDF text extractor) or reached
over plain cURL (Resend for email). Do not introduce a package manager or a library that
requires one without checking first — the shared-hosting target is why none exist.

## Setup and common commands

There is no test runner, linter, or task runner. Syntax checking is the whole tooling story:

```bash
php -l somefile.php                       # the project's only "test" — run after every PHP edit
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;   # lint everything
node --check assets/app.js                # for the JavaScript files
```

Local setup (XAMPP):

1. Place the project at `C:\xampp\htdocs\acme-ats`, start Apache and MySQL.
2. Create database `acme_ats`, import `database/schema.sql`, then run every file in
   `database/migrations/` in numeric order 001 through 018. Every statement is re-runnable.
   The two `legacy_*.sql` files are only for upgrading a pre-`schema.sql` prototype; a
   fresh install skips them.
3. Copy `.env.example` to `.env` and fill in `APP_URL` and the `DB_*` values. Every value has
   a default matching the old hardcoded XAMPP settings, so the app boots with no `.env`.
4. Open `http://localhost/acme-ats/`. Demo logins: `admin@acme.test` and
   `recruiter@acme.test`, password `password`.

`APP_URL` is the single source of truth for absolute links (interview invite emails, join
buttons). Never hardcode `localhost` — change `.env`, not PHP, when the host changes.
Leaving `RESEND_API_KEY` blank keeps email disabled; `EmailService` logs to
`storage/logs/mail.log` and returns success rather than failing the flow.

## Architecture

The system is deliberately two-layered, and the seam matters more than anything else here.

**Layer 1 — the ~45 root-level page controllers.** Each root `*.php` file (`dashboard.php`,
`candidate.php`, `interviews.php`, `interview-room.php`, and so on) is self-contained: it
handles its own POST, runs its own SQL, and renders its own HTML. Apache serves them directly
at their filenames. `routes/web.php` is a documentation map of these, not an enforced router —
there is no front controller. This style is intentional and was preserved rather than
rewritten; see `docs/SYSTEM_ARCHITECTURE.md` for the reasoning.

**Layer 2 — `app/` and `config/`, added later and only partly wired in.** Check before
assuming a class is live:

- `config/` is fully live. Every page reaches it through `includes/config.php`, which is now
  a thin loader over `config/app.php` (session hardening, timezone, `APP_URL`, `app_url()`),
  `config/database.php` (the single PDO via `Database::connection()`, with `db()` kept as a
  compatibility shim), and `config/constants.php` (upload dirs, size caps, allowed types).
- `app/Services/EmailService` and `InterviewSignalService` are live in real flows.
  `UploadService`, `NotificationService`, `TokenService`, and `ResumeParserService` are thin
  wrappers over existing `includes/` functions, offered to new code — existing pages still
  call the loose functions directly.
- `app/Controllers/InterviewController` is live behind `api/interview/schedule.php`.
  `AuthController` is a **reference implementation only**; `login.php` still runs its own
  login flow. Editing `AuthController` changes nothing about how anyone signs in.
- `app/Models/*` are thin typed table wrappers. Optional; root pages do not use them.

When adding a feature, match the layer you are editing. Do not half-migrate a root page into
`app/` — either leave it in its own file or move the whole flow, because a page that reads
state from two layers is where the bugs live.

### Shared code in `includes/`

`includes/config.php` is the universal entry point. It provides the session, `db()`, `e()`,
`csrf_token()`, `check_csrf()`, `flash()`, `audit_log()`, `stage_badge()`, and the upload
helpers.

`includes/auth.php` calls `require_login()` at the bottom of the file, so **including it at
all forces a login**. Pages reachable without an account (`apply.php`, `job-detail.php`,
`interview-room.php`, `application-status.php`, the password-reset pair) must include
`config.php` directly and never `auth.php`. This is why `interview_lib.php` depends only on
`config.php` — candidates who join a room have no account.

`candidate.php` follows a stricter three-file split that the rest of the app does not:
`candidate_dal.php` (parameterized queries only, returning bool or an id instead of throwing),
then `candidate_view_model.php` (all business rules; `build_candidate_view_model()` is called
exactly once per request and is the page's only data source), then `candidate.php` (markup).
Preserve that separation when touching the candidate profile.

### Roles and permissions

Hierarchy: `super_admin`, then `admin`, then `recruiter` (HR), then `hiring_manager` and
`employee`. Super Admin bypasses every role gate and implicitly holds every permission, so it
never needs listing in a `require_login([...])` call.

Granular permissions live in the `user_permissions` table and are checked with
`has_permission()` against the constants in `includes/permissions.php`. A Super Admin grants
permissions to Admins; an Admin grants a narrower subset onward to recruiters, bounded by a
seat limit (`users.hr_account_limit`, surfaced in the UI as "seats"). Publishing a job is
deliberately not a grantable permission: `can_publish_jobs()` requires admin level *and* the
job-posting permission. `user_permissions()` falls back to pre-RBAC behavior if migration 002
has not been imported, so an un-migrated install degrades instead of locking everyone out.

Every page and every POST handler runs the same check. Hiding a menu item is never what keeps
someone out — enforce in the handler, not just the view.

### Interview rooms and WebRTC

Shared hosting gives no WebSockets, so signaling is HTTP polling against MySQL.
`interview-room.php` (a single very large file holding the room UI and its JavaScript) polls
the offer, answer, and ICE endpoints under `api/interview/`, plus `status.php` and `end.php`.
Those all share `api/interview/_bootstrap.php`, which resolves identity through
`InterviewSignalService::resolve($roomCode, $token)` and requires a CSRF token for staff POSTs.
Candidates authenticate with a per-interview token (`interviews.candidate_token`, 32 hex
characters) rather than a session.

Two status columns coexist and mean different things. `interviews.status` is the original
scheduling status read by `interviews.php`, `dashboard.php`, and `pipeline.php`.
`interviews.meeting_state` is the newer meeting lifecycle, running scheduled, ready,
in_progress, ended, review_pending, reviewed. Scoring is only reachable from `review_pending`
onward, enforced in the POST handler in `interviews.php`. Live-room presence is a heartbeat:
`room_last_ping` within 12 seconds means live, and `includes/header.php` sweeps stale rows on
page load.

### File storage

Two resume locations exist. New uploads go to `storage/resumes/` with generated names, sealed
by `storage/.htaccess` plus 403 `index.php` stubs, and served only through `download.php`,
which checks the session first. `assets/uploads/resumes/` is the legacy path
(`UPLOAD_DIR_LEGACY_RESUMES`) kept readable for old rows. Avatars live in
`assets/uploads/profile-images/`. Never serve an uploaded file by building a direct URL to it.

### Database conventions

`schema.sql` has drifted from the migrations by design. RBAC, `candidates.profile_image`,
seats, and the waiting-room columns exist **only** in `migrations/`. Do not hand-merge
migrations into `schema.sql`; if a flattened schema is needed, run the schema plus migrations
001 through 018 against a throwaway database and export the result. New schema changes go in a
new numbered migration that is safe to re-run, guarded with `IF NOT EXISTS` or a dynamic
`ALTER` check the way the existing migrations do.

Application stages are the enum `new, screening, interview, offer, hired, rejected`, and
`stage_badge()` in `includes/config.php` is the one place that maps a stage to its color. It is
used consistently across the dashboard, list, and profile views.

## Front end

No framework and no chart library. Charts are hand-built inline SVG and CSS conic-gradients.
Stylesheets load in a fixed order from `includes/header.php`, cache-busted by `filemtime`:
`assets/css/theme-tokens.css`, `assets/css/components.css`, `assets/styles.css`, then
`assets/light-theme-v3.css`. Theme is applied before first paint from the
`ats_theme_preference` key in `localStorage`, setting both `data-theme` and `body.dark`
because a lot of older CSS is written against the class.

## Documentation to consult

`docs/SYSTEM_ARCHITECTURE.md` explains the two-layer split and what was verified versus what
still needs manual QA. `docs/DATABASE_STRUCTURE.md` covers the tables, `docs/INTERVIEW_SETUP.md`
the WebRTC setup, `docs/RESEND_SETUP.md` email, and `docs/INFINITYFREE_DEPLOY.md` plus
`docs/CLOUDFLARE_TUNNEL_2026.md` the two deploy targets. `docs/feature-history/` holds one
README per past feature and is the best record of why a given part of the UI behaves the way
it does.
