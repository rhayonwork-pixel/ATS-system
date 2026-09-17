Candidate profile: every captured field now surfaces
====================================================

NO MIGRATION NEEDED
-------------------
Query and display only. No new column, no schema change, no feature altered.

WHAT I FOUND
------------
I audited both intake paths — apply.php (the applicant portal) and
add_candidate.php (manual entry) — against what candidate.php actually
displayed. The portal side was already complete: name, email, phone, resume,
portfolio, source, photo, cover letter and "why us" were all shown.

The gap was on the manual-entry and workflow side. SEVEN fields were being
written to the database and never appearing anywhere:

    current_title       captured by the form and the CV parser
    experience_level    likewise
    skills              likewise
    education           likewise
    candidates.notes    the recruiter note typed at intake
    record_status       whether the record is still a draft
    consent_at          when the applicant gave consent
    assigned_to         which recruiter owns the application

The display query simply did not select them.

WHAT CHANGED
------------
The query now selects every field either intake path writes, plus the job's
location and employment type and the assigned recruiter's name.

  Personal Info gains    current role, experience level, education, skills
                         (as chips), and the consent date
  Application Info gains location, employment type, assigned recruiter,
                         application status when it is not simply active, and a
                         "Draft — not yet submitted" marker
  Recruiter Notes gains  the intake note, shown above the note thread and
                         visually distinct from notes added later

Each is wrapped in a check, so a candidate without that data shows nothing
rather than an empty row.

ONE FIELD DELIBERATELY NOT DISPLAYED
------------------------------------
candidates.resume_text is the extracted resume text used for full-text search.
Printing several thousand words of it on the profile would bury everything
else, and the document itself is one click away in the resume bar above.

Instead the bar shows a small "text indexed" marker when extraction succeeded,
so a recruiter can tell at a glance whether this candidate will turn up in a
keyword search. That is the useful part of the information without the noise.

A SAFETY NET I ADDED
--------------------
The extended query references columns from later migrations. If someone opens a
profile before importing them, an unknown-column error would have taken down
the whole page.

The query is now wrapped: on a PDOException it falls back to the original field
set, so the profile still renders — just without the newer details. Better a
page missing three rows than a 500 on every candidate.

VERIFIED
--------
I checked every field both intake paths write against the rendered profile,
programmatically rather than by eye. Twenty-one of twenty-two now appear; the
twenty-second is resume_text, excluded on purpose as described above.

  * php lexer: 57 files clean
  * branch-aware render: candidate.php balanced on every sampled path
