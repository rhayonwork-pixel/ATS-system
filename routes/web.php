<?php
/**
 * routes/web.php — documentation only, not an enforced router.
 *
 * Every page below is served directly by Apache as a plain .php file at
 * the project root (e.g. GET /dashboard.php) — that's still exactly how
 * this app is routed. Introducing a real front-controller/router would
 * mean rewriting every <a href>, every form action, and the .htaccess
 * rules that currently point straight at these files — a change with
 * real breakage risk that couldn't be verified without a live server in
 * this environment (see docs/SYSTEM_ARCHITECTURE.md). This file exists so
 * the "routes/" folder the architecture calls for has a real, accurate
 * map of what already routes where, rather than an empty placeholder.
 */

return [
    'GET  /login.php'            => 'Staff/candidate sign-in',
    'GET  /logout.php'           => 'Sign out',
    'GET  /forgot-password.php'  => 'Password reset request',
    'GET  /reset-password.php'   => 'Password reset form',
    'GET  /dashboard.php'        => 'Post-login landing page',
    'GET  /jobs.php'             => 'Job listing (HR)',
    'GET  /job-post.php'         => 'Create/edit a job',
    'GET  /job-detail.php'       => 'Public job detail + apply',
    'GET  /job-approvals.php'    => 'Job posting approval queue',
    'GET  /apply.php'            => 'Candidate application form',
    'GET  /candidates.php'       => 'Candidate list (HR)',
    'GET  /candidate.php'        => 'Candidate profile',
    'GET  /add_candidate.php'    => 'Manual candidate entry',
    'GET  /pipeline.php'         => 'Pipeline / stage board (shell + skeletons)',
    'GET  /pipeline.php?action=board_data' => 'Pipeline board JSON, fetched by assets/pipeline.js',
    'POST /pipeline.php action=move_stage' => 'Commit one stage move (approval modal Confirm only)',
    'GET  /interviews.php'       => 'Interview scheduling (HR)',
    'GET  /interview-room.php'   => 'Live interview room (HR + candidate)',
    'GET  /interview-access.php' => 'Waiting-room polling endpoint',
    'GET  /interview-notes.php'  => 'Interview notes view',
    'GET  /interview-status.php' => 'Interview status view',
    'GET  /room-presence.php'    => 'Presence heartbeat polling endpoint',
    'GET  /notifications.php'    => 'Notification center',
    'GET  /notifications-api.php'=> 'Notification polling endpoint',
    'GET  /employees.php'        => 'Employee directory',
    'GET  /attendance.php'       => 'Attendance (self-service)',
    'GET  /attendance-admin.php' => 'Attendance (admin)',
    'GET  /attendance-export.php'=> 'Attendance export',
    'GET  /analytics.php'        => 'Analytics dashboard',
    'GET  /analytics-report.php' => 'Analytics report export',
    'GET  /audit_trail.php'      => 'Audit log viewer',
    'GET  /settings.php'         => 'App settings',
    'GET  /profile.php'          => 'Staff profile',
    'GET  /users.php'            => 'User management',
    'GET  /super-admin.php'      => 'Super Admin console',
    'GET  /password-resets.php'  => 'Password reset approvals',
    'GET  /refer.php'            => 'Employee referral form',
    'GET  /my-jobs.php'          => 'Recruiter\'s assigned jobs',
    'GET  /applicant-portal.php' => 'Candidate self-service portal',
    'GET  /application-status.php'=> 'Public application status lookup (progress stepper + My Applications)',
    'GET  /application-status.php?action=detail' => 'HTML fragment: what a candidate submitted (id + email checked)',
    'POST /application-status.php'=> 'action=withdraw — candidate withdraws their own application',
    'GET  /my-document.php'      => 'Candidate-facing document download (id + email prove ownership)',
    'GET  /download.php'         => 'Protected file download (resumes, uploads)',
    'POST /upload-parser.php'    => 'Parse an uploaded job description PDF into form fields (AJAX)',
    'GET  /job-attachment.php'   => 'Serve the PDF a posting was imported from (staff only)',
    'GET  /parse-cv.php'         => 'Resume parsing endpoint',
    'GET  /admin.php'            => 'Admin console',
    'GET  /payroll.php'          => 'Payroll (legacy — see migrations/017)',
    'GET  /leave.php'            => 'Leave management (legacy — see migrations/017)',
];
