# Database Structure

## Setup order

1. Import `database/schema.sql` — core tables + demo seed data.
2. Run every file in `database/migrations/` **in numeric order (001–018)**.
3. See `database/README.txt` for exactly why it's schema.sql + ordered
   migrations rather than one fully-flattened file, and what the two
   `legacy_*.sql` files are for.

## Tables (after schema.sql + all migrations)

**Core ATS**: `users`, `departments`, `jobs`, `candidates`, `applications`,
`interviews`, `offers`, `employees`, `referrals`, `audit_logs`, `settings`.

**Attendance/payroll base**: `work_schedules`, `employee_schedules`,
`holidays`, `attendance_records`, `attendance_breaks`,
`attendance_corrections` — note `migrations/017_remove_payroll_leave.sql`
removes the payroll/leave-specific tables; attendance itself stays.

**Candidate CRM & AI (from schema.sql)**: `candidate_notes`,
`candidate_feedback`, `candidate_role_suggestions`, `stage_reviews`,
`candidate_ai_analysis`.

**Added by migrations 001–017**: candidate `profile_image`, apply-form
extra fields, resume versioning columns, `interviews.meeting_type`,
candidate interview-access columns (`room_code`, `candidate_token`),
job `tags`/`applicant_limit`/urgent-hiring flag, `notifications` (created
in migration 012 alongside RBAC/permissions/approvals tables), staff
profile + password-reset-approval columns, interview seats + lifecycle
columns, waiting-room columns, hired→employee conversion linkage.

**Added by this reorganization (`018_webrtc_interview_signals.sql`,
also appended directly to `schema.sql` for fresh installs)**:

```sql
interview_signals (
  interview_id PK/FK → interviews.id,
  offer_sdp, offer_updated_at,
  answer_sdp, answer_updated_at,
  connected_at, ended_at, created_at
)

interview_ice_candidates (
  id PK, interview_id FK → interviews.id,
  role ENUM('host','candidate'), candidate (JSON, as text),
  created_at
)
```

One row per interview in `interview_signals` holds the current SDP
offer/answer; `interview_ice_candidates` is an append-only queue so each
side can ask "anything new from the *other* role since candidate id N?"
without re-reading candidates it already has.

## Connection

Single PDO connection: `Database::connection()` in `config/database.php`,
reached via the `db()` compatibility function every existing page already
calls. Reads `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS` from `.env`
(see `.env.example`), falling back to the original hardcoded XAMPP
defaults if `.env` is absent.
