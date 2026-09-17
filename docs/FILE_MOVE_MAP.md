# File Move Map

## Moved / renamed

| Old path | New path |
|---|---|
| `database/migration-add-candidate.sql` | `database/migrations/001_manual_candidate_entry.sql` |
| `database/migration-rbac-approvals.sql` | `database/migrations/002_rbac_and_approvals.sql` |
| `database/migration-apply-questions.sql` | `database/migrations/003_apply_form_questions.sql` |
| `database/migration-resume-storage.sql` | `database/migrations/004_resume_storage_versioning.sql` |
| `database/migration-profile-photo.sql` | `database/migrations/005_candidate_profile_photo.sql` |
| `database/migration-candidate-crm.sql` | `database/migrations/006_candidate_crm_notes_feedback.sql` |
| `database/migration-ai-stage-reviews.sql` | `database/migrations/007_ai_stage_reviews.sql` |
| `database/migration-meeting-type.sql` | `database/migrations/008_interview_meeting_type.sql` |
| `database/migration-candidate-interviews.sql` | `database/migrations/009_candidate_interview_access.sql` |
| `database/migration-enhancements.sql` | `database/migrations/010_job_tags_room_settings.sql` |
| `database/migration-urgent-hiring.sql` | `database/migrations/011_job_urgent_hiring_flag.sql` |
| `database/migration-notifications-ui.sql` | `database/migrations/012_notification_categories.sql` |
| `database/migration-profiles-password-reset.sql` | `database/migrations/013_staff_profiles_password_resets.sql` |
| `database/migration-seats-interviews.sql` | `database/migrations/014_seats_and_interview_lifecycle.sql` |
| `database/migration-waiting-room.sql` | `database/migrations/015_candidate_waiting_room.sql` |
| `database/migration-employee-conversion.sql` | `database/migrations/016_hired_to_employee_conversion.sql` |
| `database/migration-remove-payroll-leave.sql` | `database/migrations/017_remove_payroll_leave.sql` |
| `database/interviews.sql` | `database/migrations/legacy_interviews_upgrade.sql` |
| `database/attendance-payroll.sql` | `database/migrations/legacy_attendance_payroll.sql` |
| `database/README-*.txt` (38 files) | `docs/feature-history/` |
| `database/RESUME-VIEW-UPLOAD-DIAGNOSTIC.md` | `docs/RESUME-VIEW-UPLOAD-DIAGNOSTIC.md` |
| `database/deliverables/theme-tokens.css`, `components.css`, `notification-component.md` | `docs/design-notes/` (superseded by the live `assets/` versions — kept for reference, not loaded by any page) |

Numbering note: the original files carried no ordering info in their
filenames or (after extraction) reliable timestamps, so the sequence
was reconstructed from each migration's own comments and table
dependencies. That reconstruction was wrong on the first attempt — real
testing against a MySQL database caught two ordering bugs (`notifications`
and `jobs.created_by` are created in what's now migration 002, but two
other migrations that need them were originally numbered earlier). The
order shown above (001–018) has since been run end to end against a real
database and confirmed clean; it has not been verified against your
actual original development history, which is a different thing — if you
know the true history differed, the numbers are just sort keys with
nothing else referencing them.

## New (not moved from anywhere — created in this reorganization)

- `config/env.php`, `config/app.php`, `config/database.php`,
  `config/constants.php`, `config/resend.php`
- `app/Models/{User,Job,Candidate,Interview,Notification,Settings,Scorecard}.php`
- `app/Services/{EmailService,InterviewSignalService,UploadService,NotificationService,TokenService,ResumeParserService}.php`
- `app/Controllers/{AuthController,InterviewController}.php`
- `api/interview/{_bootstrap,save-offer,get-offer,save-answer,get-answer,save-ice,get-ice,status,end,schedule,notes,scorecard}.php`
- `database/migrations/018_webrtc_interview_signals.sql`
- `database/seeds/{admin_seed,demo_candidates,hr_seed}.sql`
- `.env.example`
- Everything in `docs/` except the moved files listed above

## Unchanged

Every other file at the project root — `dashboard.php`, `jobs.php`,
`candidate.php`, `login.php`, `logout.php`, `settings.php`,
`interview-access.php`, `room-presence.php`, `notifications-api.php`,
all of `includes/` except `includes/config.php` (see below), all of
`assets/`. `interviews.php` and `interview-room.php` were edited in place
(not moved) — see `docs/SYSTEM_ARCHITECTURE.md` for what changed in each.

## `includes/config.php` — edited, not moved

Session hardening, `APP_TIMEZONE`, the `DB_*` constants, and the `db()`
function were extracted into `config/app.php` / `config/database.php` /
`config/constants.php`. `includes/config.php` now requires those three
files and keeps every other function (`csrf_token()`, `flash()`,
`audit()`, `setting()`, `save_resume_upload()`, etc.) exactly as they
were. Every page's `require_once 'includes/config.php'` still works
unchanged.
