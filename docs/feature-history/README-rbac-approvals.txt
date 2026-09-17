Super Admin, permissions and job posting approvals
===================================================

SETUP
-----
1. Import database/schema.sql (and attendance-payroll.sql) first, as before.
2. Import database/migration-rbac-approvals.sql once.
   It is idempotent — re-running it is safe and changes nothing the second time.
3. Sign in as superadmin@acme.test / password and change that password.

Accounts after the migration
----------------------------
  superadmin@acme.test   Super Admin   (new)
  admin@acme.test        Admin         (keeps every permission, limit of 5)
  recruiter@acme.test    HR/Recruiter  (keeps Job management + Job posting)

Nothing that worked before stops working: existing Admins are granted all five
permissions and existing recruiters keep their job access, so the migration is
not a behaviour change for anyone already using the system.

WHY THE SCHEMA LOOKS THE WAY IT DOES
------------------------------------
jobs.status was NOT repurposed. Twelve files (index, jobs, job-detail, apply,
dashboard, analytics, pipeline, refer, candidate, admin ...) treat
status='open' as "visible on the public careers site". Replacing that enum with
the new workflow states would have silently changed what applicants can see.

Instead:
    Published            = jobs.status   'open'
    Closed / Paused      = jobs.status   'closed' / 'paused'
    Draft, Pending,
    Approved, Rejected,
    Changes requested    = jobs.approval_status

A posting only becomes public when an Admin sets status='open'. Recruiters can
never write that value — job-post.php, my-jobs.php, admin.php and
applicant-portal.php all refuse it — so the approval gate is real rather than
a UI convention.

users.active was left alone for the same reason (auth.php and login.php both
filter on active=1). The richer lifecycle lives in users.account_status and
active is kept mirrored to it: active=1 only when account_status='active'.

PERMISSIONS
-----------
Five grantable permissions, stored in user_permissions:
    manage_accounts    job_management    job_posting
    audit_trail        applicant_portal

Super Admin grants these to Admins. An Admin grants a subset
(job_management, job_posting, audit_trail) to the HR/Recruiters they created.
A Super Admin implicitly holds everything and never consults the table.

Publishing is deliberately NOT a permission. can_publish_jobs() requires an
Admin-level role AND job_posting, so granting "Job posting" to a Recruiter can
never accidentally let them publish.

ACCOUNT LIMITS
--------------
users.hr_account_limit caps how many HR/Recruiter accounts an Admin may create.
Pending, active and suspended accounts occupy a slot; rejected and disabled
accounts release one. The limit is enforced in the POST handler in users.php,
not just in the UI, and the Super Admin cannot lower a limit below the number
of accounts already in use.

New HR/Recruiter accounts are created as 'pending' with active=0 and cannot
sign in until a Super Admin approves them in users.php.

PDF JOB DESCRIPTIONS
--------------------
includes/pdf_extract.php is a self-contained PDF text reader — there is no
Composer in this project and XAMPP ships no PDF library. It needs the zlib and
mbstring extensions, both of which XAMPP enables by default.

It handles Word, Google Docs, LibreOffice, browser "Print to PDF" and InDesign
exports, including subset CID fonts via their /ToUnicode CMaps.

Known limits:
  * Scanned / image-only PDFs have no text layer. The user is told to type the
    posting in manually, as designed.
  * A few writers (notably WPS Docs) position every glyph individually, and
    word spaces can collapse. The reader detects this and warns the user to
    check the wording before saving. Fixing it fully needs font /Widths
    parsing, which was left out to keep this file small.

Uploads land in assets/uploads/job-descriptions/ with an .htaccess that blocks
script execution.

FILES ADDED
-----------
  includes/permissions.php     role hierarchy, permission checks, limits, notifications
  includes/pdf_extract.php     PDF text extraction + job description field parsing
  super-admin.php              Admin permissions, limits, oversight
  users.php                    HR/Recruiter provisioning and approval
  job-approvals.php            Admin review queue and review screen
  job-post.php                 Job composer, PDF import, submit for approval
  my-jobs.php                  Recruiter's own postings and their states
  applicant-portal.php         Applicant portal oversight and settings
  notifications.php            Approval hand-off inbox

FILES CHANGED
-------------
  includes/auth.php      Super Admin passes every role gate; loads permissions
  includes/config.php    audit() now takes an optional details array
  includes/sidebar.php   navigation driven by permissions
  admin.php              Job management permission gate; publishing gate
  audit_trail.php        audit_trail permission gate; Super Admin role filter
  settings.php           Admin-level gate
  login.php              explains pending/suspended accounts
  apply.php              honours the portal-wide "accepting applications" switch
  assets/styles.css      new components, light and dark

SECURITY NOTES
--------------
  * Every page checks permissions server-side before reading or writing. Hiding
    a sidebar link is never the only thing stopping access.
  * Unauthorised requests get a real 403 and a styled explanation, not a
    redirect, so URL guessing fails loudly.
  * All POST handlers keep the existing CSRF check.
  * An Admin can only manage HR/Recruiter accounts they created.
  * Rotate the demo passwords before putting this anywhere real.
