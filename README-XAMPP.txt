ACME ATS - XAMPP / PURE PHP PROTOTYPE

WHAT CHANGED
- Public careers navigation is only on the landing page.
- Recruiter Hub on the landing page opens the private staff login.
- HR/Admin pages use a dedicated left sidebar and no public top navigation.
- Admin is focused on job listings, departments, publishing, applications, and audit history. There is no Add User screen.
- Public jobs are loaded from MySQL and can be published/paused/closed by Admin.
- Applications create candidate + application records in MySQL.
- Application status uses the real application ID.
- Interviews now use application_id consistently and support video/Zoom, phone, panel, and onsite interviews.
- Fixed the undefined require_role() problem by loading the authentication layer correctly.
- Fixed the duplicate/incompatible interview schema design.
- Added attendance/payroll tables to the main schema so a fresh database install includes the HR modules.
- Improved database errors so a stopped MySQL service gives a readable setup message instead of an opaque PDO fatal error.
- Removed unused Next.js/TypeScript project files so this package is PHP/MySQL focused.

INSTALL IN XAMPP
1. Extract this folder to C:\xampp\htdocs\acme-ats
2. Start Apache and MySQL from XAMPP Control Panel.
3. Open http://localhost/phpmyadmin
4. Create/import the database by importing:
   database/schema.sql
   The script creates database `acme_ats` and the demo departments/users/jobs.
5. Open:
   http://localhost/acme-ats/

DEMO STAFF LOGIN
Admin:     admin@acme.test
Password:  password
Recruiter: recruiter@acme.test
Password:  password

IMPORTANT DATABASE ERROR
If you see:
SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it
then MySQL is not accepting connections on 127.0.0.1:3306. Start MySQL in XAMPP first. If your MySQL uses another port, change DB_PORT in includes/config.php.

INTERVIEW DATABASE
The main schema now contains the correct application-based interviews table. Do not import the old interviews.sql on top of a fresh schema unless you specifically need the legacy migration notes.

PUBLIC / PRIVATE FLOW
Landing page -> Recruiter Hub -> Staff Login -> HR/Admin sidebar
Landing page -> Find roles -> Job detail -> Application
Applicants never need an account to apply.

MERGED FROM THE OLD (STATIC) PROTOTYPE — WHAT'S NEW
The old HTML/JS prototype had a richer candidate profile, candidate
directory, analytics dashboard, and refer-a-friend flow, but they only
used hardcoded mock data with no backend. Those UI ideas have now been
rebuilt on top of this PHP/MySQL codebase, fully wired to real data:
- candidates.php — live candidate directory pulled from MySQL, with
  search and a stage filter.
- candidate.php — full profile per application: change pipeline stage,
  rate the candidate (stars), add recruiter notes, save structured
  interview feedback (fit + notes), and suggest a different open role —
  all persisted to the database.
- analytics.php — real metrics computed from your data: applications by
  department, a pipeline conversion funnel, average time to hire, offer
  acceptance rate, average interviews per hire, and open roles by
  department.
- refer.php — the refer-a-friend form now actually saves to the
  `referrals` table instead of just showing a toast.
- application-status.php — candidates now see a stage timeline, and if
  rejected, any recruiter feedback and suggested alternate role.

New tables added for this (see database/migration-candidate-crm.sql if
you have an existing database — a fresh import of schema.sql already
includes them): candidate_notes, candidate_feedback,
candidate_role_suggestions, plus a `notes` column on referrals.

INSTALL / UPGRADE NOTE
- Fresh install: just import database/schema.sql — it already includes
  every table below.
- Existing database: run these, in this order (all safe to re-run):
    1. database/migration-candidate-crm.sql
    2. database/migration-apply-questions.sql
    3. database/migration-ai-stage-reviews.sql

CANDIDATE PROFILE (candidate.php) — rebuilt
Wide, full-width profile layout (no sidebar-dashboard clutter) with:
- Personal Information + Application Information side by side
- Resume & Application card: resume preview/download, portfolio link
  (or "No portfolio provided"), cover letter, "why us" answer, and how
  they heard about you — everything captured by apply.php in one place
- Recruiter Notes — unchanged, still fully functional
- AI Application Analysis — a *simulated* AI panel (clearly labelled
  "AI Simulation / Prototype", not a real model call). Clicking
  "Analyze Application" runs a short animated checklist, then computes
  a score breakdown (Skills Match, Experience, Education, Application
  Quality, Communication) from real signals in the application —
  cover-letter/why-us text length and variety, keyword overlap with
  the job's tags, resume/portfolio presence — so different candidates
  get different results, not a fixed number. Stored in
  candidate_ai_analysis (one row per application, overwritten on
  re-analyze) so it's reusable by future automation. Includes the
  required "review before hiring decision" disclaimer.
- Screening Review and Interview Review — two fully separate sections
  (stage_reviews table, one row per application per stage_type), each
  with its own rating/feedback/notes/date/reviewer. Only appear once
  the application has reached that stage (or already has a saved
  review for it).
- Rating, "Feedback for candidate," and "Suggest a different role" are
  now hidden until the application has moved past the Applied stage —
  a locked-state card explains why when they're hidden.

DASHBOARD / ANALYTICS / CANDIDATE — visual redesign
Restyled (not rebuilt) using patterns from a reference HR-tool screenshot:
rounded stat cards with real week-over-week deltas, a 14-day applications
trend area chart (inline SVG, real data), an "Upcoming interviews" panel,
avatar-initial chips, and colour-coded stage badges (stage_badge() helper
in includes/config.php — same six colours used consistently across
dashboard.php, candidates.php, and candidate.php).
- dashboard.php: added the trend chart, upcoming interviews list, and an
  "Applications to review" panel with avatar stacks per open role. All
  existing stats and the recent-applications table still work the same.
- analytics.php: added working Department/Position filters (real GET
  params that filter the underlying queries) and a stage-distribution
  donut chart (CSS conic-gradient, no chart library). Existing funnel,
  department, and time-to-hire metrics are unchanged.
- candidates.php: added stage quick-filter tabs with live counts (on top
  of the existing dropdown filter, which still works) and avatar chips.
- candidate.php: added a visual stage stepper under the candidate name.
  Everything from the previous update — notes, AI analysis, screening/
  interview reviews — is untouched.
No new tables were needed for this pass.

RESUME UPLOADS
apply.php now takes a real resume file (PDF/DOC/DOCX, up to 5MB)
instead of a text URL, matching the old prototype's file input. Files
are validated and saved to assets/uploads/resumes/ with a random,
sanitized filename; the path is stored in candidates.resume_path. That
folder has script execution disabled via .htaccess for safety. Make
sure assets/uploads/resumes/ is writable by the web server
(chmod -R 775 assets/uploads on Linux hosts) and that PHP's
upload_max_filesize / post_max_size in php.ini are at least 5M.

APPLICATION FORM (apply.php)
Rebuilt as a full-width, two-column, sectioned form merged from the
old prototype's fields: Personal Information, Cover Letter, "Why do
you want to work here?" (company name is dynamic), a structured "How
did you hear about us?" dropdown (with an Other free-text reveal), and
an optional Portfolio URL with client + server-side URL validation.
Includes a live application-progress tracker, character counters,
resume upload preview/remove, and a live review summary before
submit. Cover letter and "why us" answers, plus the portfolio link,
now also show on the candidate's profile in the recruiter panel
(candidate.php).

MAJOR UPGRADE: PAYROLL/LEAVE REMOVED, HIRE→EMPLOYEE FLOW, PIPELINE
FILTERS, MEETING REVIEWS SYNCED TO CANDIDATE PROFILE
- Payroll and Leave are completely removed: payroll.php, leave.php,
  their sidebar links, and their database tables (pay_periods,
  payroll_entries, payroll_deductions, payslips, employee_compensation,
  leave_requests) are gone. Run database/migration-remove-payroll-leave.sql
  on an existing database to drop those tables (destructive for those
  tables only — nothing else is touched).
- Hire → Employee: candidate.php now shows an "Add to Employees" card
  once a candidate's stage is Hired. It lets you set the hired position
  and department (applied position is preserved separately) and creates
  a linked employees row — employees.php shows both positions and links
  back to the full recruitment history. New columns:
  employees.application_id, employees.applied_position. More department
  options were added (Finance, Marketing, Operations, Sales, Customer
  Support). Migration: database/migration-employee-conversion.sql
- Pipeline: confirmed drag-and-drop already persisted via AJAX (no bug
  there); added working Job/Department filters and a search box, plus
  richer cards (avatar, AI score badge if analyzed, quick View/Schedule
  links). Same underlying stage-move endpoint as before.
- Meetings now have an explicit Meeting Type (Screening or Interview),
  selected when scheduling on interviews.php. The in-room scorecard on
  interview-room.php is now a 0–100 score + review + notes form (was a
  1–5 star rating) that saves directly into the matching Screening or
  Interview review on the candidate's profile (the same stage_reviews
  table used there) — not a separate, disconnected record. Meeting
  notes are also copied into the candidate's Recruiter Notes so they
  stay available for the AI analysis simulation. Migration:
  database/migration-meeting-type.sql

NOT INCLUDED IN THIS PASS
The full Google-Meet-style meeting UI (dedicated chat side-panel, notes
side-panel, minimize/maximize while browsing the rest of the ATS,
context-aware access during a call) was not built — interview-room.php
still uses its existing single-page video-room layout. The review
system above already flows into the candidate profile correctly; the
chat/notes-panel/minimize UI would be a separate, sizeable follow-up.

INSTALL / UPGRADE ORDER (existing databases)
Run these once, in order (all safe to re-run except the payroll/leave
removal, which is destructive for those specific tables):
  1. database/migration-candidate-crm.sql
  2. database/migration-apply-questions.sql
  3. database/migration-ai-stage-reviews.sql
  4. database/migration-employee-conversion.sql
  5. database/migration-meeting-type.sql
  6. database/migration-remove-payroll-leave.sql

PIPELINE, SIDEBAR & ACTIVITY HISTORY UPGRADE
- Pipeline double confirmation: dragging a candidate card no longer moves
  it immediately. Dropping opens "Move Candidate?" (current → new stage,
  a contextual message specific to that transition, a "View Candidate
  Profile" link that opens in a new tab without losing your place, and
  Cancel/Continue). Continue opens "Confirm Stage Change" with a required
  checkbox that enables "Confirm & Move". Only then is anything saved
  (via the existing AJAX endpoint) — cards never move optimistically, and
  a failed save leaves the card exactly where it was, with an error
  toast. New file: assets/pipeline.js.
- Stage-skip validation: moving a candidate more than one stage forward
  (e.g. Applied straight to Hired) is blocked with an explanation, both
  in the pipeline and on candidate.php's own stage selector. Admins get
  an "Override & Continue" option; rejecting or moving a candidate
  backward is always allowed. Enforced server-side, not just in the UI.
- Quick preview: clicking a candidate card (not dragging, not its View/
  Schedule links) slides in a preview panel — AI score, screening/
  interview review scores, resume link, note count/latest note — sourced
  from the same query as the card, no extra request. "Full Candidate
  Profile" still links to the real candidate.php.
- Activity history: candidate.php now has an Activity card built from
  the existing audit_logs table — stage moves (with from → to), notes,
  feedback, reviews, AI analysis runs, and employee conversion, each
  with actor and timestamp. Stage-move entries now store a `details`
  JSON (from/to/override) so this history is meaningful, not just "stage
  changed." No new table — reused audit_logs.
- Sidebar: added a collapse/expand toggle (72px icon-only ↔ 250px,
  animated, tooltips via title attributes, persisted in localStorage).
  The sidebar was already sticky/independently scrollable and already
  had a working mobile drawer — those didn't need changes.

APPLY.PHP, DARK MODE, URGENT HIRING & STATIC APPLICATION STATUS
- apply.php: phone is now a real international component — a searchable-
  by-scrolling country select (~62 countries, flag emoji generated from
  the ISO code, no image assets) drives a live "+63" style dial-code
  display and placeholder next to a plain number field. Validated client
  and server-side (plausible digit-count check, not a rigid per-country
  regex — a valid non-PH number is never rejected for "not looking
  Filipino"). Stored normalized as "+63 917 123 4567" in
  candidates.phone. The "Job Summary" sidebar card is gone; job title,
  location, and employment type now live in the page header instead so
  that information isn't lost.
- Public applicant counts: turned out to already exist on jobs.php and
  job-detail.php. Added to index.php's featured job cards too.
- Urgent Hiring: new jobs.is_urgent column (migration:
  database/migration-urgent-hiring.sql). Toggle it when creating a job
  or from the "Edit details" panel on any existing job in admin.php. A
  🔥 Urgent Hiring section appears above the normal Open Positions on
  index.php whenever there's at least one open, published, urgent job;
  badges also show on jobs.php and job-detail.php.
- application-status.php has no live/polling UI anymore — no "Live"
  badge, no pulsing dot, no auto-refresh. status-live.php was deleted
  (confirmed nothing else referenced it). All the real functionality is
  intact: ID+email lookup, email-only lookup that lists every
  application under that email, the post-submit "save this ID" banner,
  and the rejected-state feedback/suggested-role.
- Dark mode: fixed real contrast bugs rather than just re-skinning —
  .toast and .stage-tab.active were using var(--ink) as a *background*,
  which flips to a light color in dark mode, making white text
  disappear. .notice (and its .error/.success variants), .tag-pill,
  .disabled, .status-draft, and the AI-chat bubble had light, fixed
  pastel backgrounds paired with theme-variable text that went
  low-contrast in dark mode — all now have explicit dark-mode overrides
  instead. Added a proper layered dark palette (page background →
  card → --elevated surface for modals/preview panels → border →
  text), matching how the rest of the design system already uses CSS
  variables almost everywhere. The dark-mode toggle itself, its
  localStorage persistence, and its coverage across public pages,
  recruiter panel, and admin panel were already implemented before this
  request — most of the system already adapts correctly because nearly
  every component was already built on the --ink/--muted/--surface/
  --paper/--line variables; this pass found and fixed the exceptions.

INSTALL / UPGRADE ORDER (existing databases) — updated
Run once, in order (all safe to re-run except the payroll/leave
removal, which is destructive for those specific tables):
  1. database/migration-candidate-crm.sql
  2. database/migration-apply-questions.sql
  3. database/migration-ai-stage-reviews.sql
  4. database/migration-employee-conversion.sql
  5. database/migration-meeting-type.sql
  6. database/migration-urgent-hiring.sql
  7. database/migration-remove-payroll-leave.sql

APPLICATION WITHDRAWAL, RESUME-AT-TOP, INTERVIEWER AVAILABILITY
(Scoped from a much larger 32-section request — see note at the end of
this file for what was deliberately deferred.)
- Application withdrawal: candidates can withdraw their own application
  from application-status.php (double confirmation: a button opens a
  modal explaining the consequence before anything happens). Reuses the
  existing applications.status='withdrawn' value — no schema change —
  so every existing recruiter-side query that already filters to
  status='active' (candidates.php, pipeline.php, dashboard.php, etc.)
  automatically excludes withdrawn applications with no further changes.
  Logged to audit_logs. Hidden once hired or already rejected/withdrawn.
- Resume-at-top: candidate.php now shows a prominent Resume / CV panel
  (View/Download) immediately under the profile header, alongside the
  AI score badge next to the candidate's name. The fuller application
  details (cover letter, why-us, portfolio, source) stay in their own
  section below, without duplicating the resume buttons.
- Interviewer availability: scheduling an interview (interviews.php) now
  has a real interviewer picker — every admin/recruiter/hiring_manager
  user, sorted by lightest upcoming workload, showing each person's
  current upcoming-interview count. Picking an interviewer + time that's
  already taken shows a warning and disables Submit (client-side), and
  is rejected server-side too if somehow submitted anyway. The
  interviews list now shows who's assigned to each meeting.

WHAT WAS DEFERRED FROM THIS ROUND (scope note)
The last prompt requested ~20 major features across 32 sections. Rather
than build all of them shallowly, only the above three Priority-1 items
were implemented, chosen for being genuinely safe (no schema changes
that ripple through the whole app) and high value. Explicitly NOT
touched, in rough priority order from the original request:
- Hiring Process stage rename (Shortlisted/Contacted/Initial Interview/
  Failed/Offer/Job Offer Rejected/Hired) — deliberately held back. The
  current applications.stage ENUM (new/screening/interview/offer/hired/
  rejected) is load-bearing across the pipeline, analytics funnel, the
  candidate-profile stage-review gating, the dashboard, and the public
  status timeline. Renaming it needs a coordinated pass across all of
  those, not a quick relabel — flag if you want this done next and it
  can be scoped as its own focused pass.
- Super Admin account-approval workflow (pending/approved/rejected users)
- PDF → job posting extraction/import
- Recording playback in the interview room (no real recording capture
  exists yet to play back — would need actual media capture first)
- Document Library (resumes/contracts/recordings in one place)
- AI role-suggestion "match %" ranking and candidate-matching keyword
  scoring beyond the existing AI analysis
- AI Employee / recruitment-content assistant (job posts, interview
  questions, rejection emails, etc.)
- Recruiter productivity analytics + admin KPI drill-down
- Attendance timezone display improvements
