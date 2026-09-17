Database setup
==============

1. Create a database (e.g. `acme_ats`) and import `schema.sql` first —
   this creates the core tables and seeds a demo department, two staff
   logins (admin@acme.test / recruiter@acme.test, password: "password"),
   one demo job, default settings, and the WebRTC signaling tables.

2. Then run every file in `migrations/` **in numeric order, 001 through
   018**. Each one was previously a separate `migration-*.sql` file in
   this folder with its own prose README; they have been renumbered and
   consolidated here so setup is "run schema.sql, then migrations/ in
   order" instead of hunting through scattered files. Nothing about what
   each migration does has changed — see the comment at the top of each
   file for what it adds and why. Every statement is safe to re-run.

   Two extra files, `migrations/legacy_interviews_upgrade.sql` and
   `migrations/legacy_attendance_payroll.sql`, only matter if you are
   upgrading a *very* old prototype database that predates `schema.sql`
   having those tables built in. A fresh install can skip both — schema.sql
   already includes what they add.

3. `seeds/` holds the same demo department/staff/job/settings rows that
   are already seeded inline by `schema.sql`, split out by concern
   (`admin_seed.sql`, `hr_seed.sql`, `demo_candidates.sql`) for anyone who
   wants to reset just one part of the demo data without re-importing the
   whole schema.

Why schema.sql + migrations/, not one fully-merged file
--------------------------------------------------------
`schema.sql` had already drifted out of sync with the 16 migration files
that had accumulated next to it — e.g. `candidates.profile_image`,
RBAC/permissions, interview seats, and the waiting-room columns all exist
only in migrations, not in the base `CREATE TABLE` statements.
Hand-merging 17 migrations' worth of `ALTER TABLE` statements into one
file is exactly the kind of change that can silently corrupt a schema,
so it wasn't done. The numbering above (001–018) has been run end to end
against a real MySQL 8.0 database, from a fresh `schema.sql` import
through every migration in order — not just inspected, actually executed
— and two real ordering bugs were caught and fixed this way (see
docs/FEATURE_VERIFICATION.md for what they were). If you'd like a single
fully-flattened schema file, the safest way to get one is still to run
schema.sql + migrations/001-018 against a throwaway database and export
that as your new schema.sql.

XAMPP
-----
1. Open phpMyAdmin, create/select the `acme_ats` database.
2. Import schema.sql, then each file in migrations/ in order (001 → 018).
3. Copy `.env.example` to `.env` and set DB_* to your local XAMPP defaults
   (root / no password / localhost is usually correct out of the box).

InfinityFree / Cloudflare Tunnel
---------------------------------
See docs/INFINITYFREE_DEPLOY.md and docs/CLOUDFLARE_TUNNEL_2026.md.
