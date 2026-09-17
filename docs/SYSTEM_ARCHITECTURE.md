# System Architecture — Acme ATS v1.0

## What changed, and what didn't

This reorganization added a real `app/` (Controllers/Models/Services), a
`config/` layer, `api/interview/*`, and consolidated `database/migrations/`
— **without moving or rewriting the ~45 existing top-level pages**
(`dashboard.php`, `jobs.php`, `candidate.php`, `interview-room.php`, etc.).
They still live at the project root and still work exactly as they did.

That was a deliberate call, not a shortcut taken quietly. Here's the
reasoning, so it's a call you can revisit rather than one made invisibly:

- These ~45 files are true "page controllers" already — each one handles
  its own POST, queries the database, and renders its own HTML in one
  file. That's not broken; it's a coherent, if old-fashioned, style.
- Physically moving them into `views/` and splitting each one into a
  Controller + a View template touches every relative `include`, every
  session check, every upload path, and every internal link — 45 files
  deep, with no live PHP/MySQL/browser available in this environment to
  actually run the result and catch a broken path. "Preserve every
  working feature" and "rewrite everything into MVC" pulled in opposite
  directions here, and the first one won.
- The new `app/` layer is real, not scaffolding: `config/database.php`,
  `config/app.php`, and `config/resend.php` are what actually run now
  (see below); `app/Models/*` and the two `app/Controllers/*` classes are
  genuine, callable code — `InterviewController` is what the new
  `api/interview/schedule.php` runs, live. `AuthController` is a
  documented reference implementation of the login rules, not yet the
  code path `login.php` runs (see the comment at the top of that file for
  why).

## Request flow today

```
Browser
  │
  ▼
dashboard.php / jobs.php / candidate.php / interviews.php / ... (unchanged)
  │                                            │
  │ require_once                               │ require_once
  ▼                                            ▼
includes/config.php  ────────────►  config/app.php, config/database.php,
  (session, db(), helpers,               config/constants.php
   still the same functions)             (new: env-driven, single PDO)
  │
  ▼
includes/*_lib.php, *_dal.php  (unchanged — candidate_dal.php,
  interview_lib.php, permissions.php, pdf_extract.php, etc.)
```

```
New, additive path (does not replace the above):

Browser (interview-room.php's JS)
  │  AJAX polling
  ▼
api/interview/{save-offer,get-offer,save-answer,get-answer,
               save-ice,get-ice,status,end}.php
  │
  ▼
app/Services/InterviewSignalService.php  →  interview_signals /
                                             interview_ice_candidates (new tables)

api/interview/schedule.php  →  app/Controllers/InterviewController.php
                                    │              │
                                    ▼              ▼
                          app/Models/Interview.php  app/Services/EmailService.php
                                    │                        │
                                    ▼                        ▼
                              (interviews table)       Resend API
```

## Folder guide

| Path | Status | Notes |
|---|---|---|
| `config/` | **New, live** | `.env`-driven app/DB/mail config. `includes/config.php` requires these. |
| `app/Models/` | **New, live** | Thin typed wrappers around tables. Optional — existing pages don't call these yet. |
| `app/Services/` | **New, live** | `EmailService` and `InterviewSignalService` are wired into real flows (see below); `UploadService`/`NotificationService`/`TokenService`/`ResumeParserService` wrap existing functions for new code to call. |
| `app/Controllers/` | **Mixed** | `InterviewController` is live (backs `api/interview/schedule.php`). `AuthController` is a reference implementation — see the file header. |
| `api/interview/` | **New, live** | The real WebRTC signaling endpoints, plus `schedule.php`/`notes.php`/`scorecard.php` JSON mirrors of existing HR actions. |
| `database/migrations/` | **Reorganized** | The 16 previously-scattered `migration-*.sql` files, renumbered 001–017 in dependency order, plus new `018_webrtc_interview_signals.sql`. |
| `database/seeds/` | **New** | Demo data split out of `schema.sql` by concern, for resetting just one part without a full reimport. |
| `docs/` | **New** | This file, plus the deployment guides, feature history, and design notes moved out of `database/`. |
| Root `*.php` | **Unchanged** | Every existing page, unmodified except `interviews.php` (interview-invite email added) and `interview-room.php` (real WebRTC wired in — see `INTERVIEW_SETUP.md`). |

## What was verified vs. what needs your QA

Every PHP file in this project — old and new — was checked with `php -l`
(syntax only) after every edit; the new JavaScript was checked with
`node --check`. No PHP interpreter had a live MySQL database or a browser
available in this environment, so none of the following were actually
exercised end-to-end. Please run through these before relying on them:

- A real two-browser interview call (camera/mic permission prompts, the
  actual peer connection reaching "connected", and hanging up cleanly).
- Interview-invite email delivery once you add a real `RESEND_API_KEY`.
- The full migration sequence (`schema.sql` → `migrations/001`–`018`)
  against a fresh MySQL database.
- InfinityFree upload and Cloudflare Tunnel, per the two deploy docs.

See `docs/FEATURE_VERIFICATION.md` for the full checklist against the
original feature list in the spec.
