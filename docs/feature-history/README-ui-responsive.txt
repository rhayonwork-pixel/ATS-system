UI/UX and responsiveness pass
=============================

NO MIGRATION NEEDED
-------------------
This change is presentation only. No schema changes, no new tables, no altered
queries beyond adding filters and sorting to candidates.php. If you have already
imported migrations 1-6 there is nothing further to import.

1. GLOBAL CONTAINMENT
---------------------
Structural fixes rather than shrinking type:
  * box-sizing:border-box globally; inputs, textareas and selects inside cards
    are width:100% with min-width:0
  * min-width:0 on flex and grid children so long content shrinks instead of
    forcing the page sideways
  * overflow-wrap:anywhere on headings, names, table cells and details
  * img{max-width:100%}, pre/code scroll inside themselves
  * .stats, .filters, .actions, .dashboard-head, .section-head and .list-row all
    wrap instead of overflowing
  * modals capped at min(90vh,720px) with internal scrolling
  * body{overflow-x:hidden} as a backstop, not as the fix

2. TABLES BECOME CARDS
----------------------
Below 820px, candidates.php and audit_trail.php rows restack into labelled
cards using the data-label attributes already on each cell. It is the same
markup either way, so nothing is duplicated, the search still matches, and
there is no horizontal page scroll. Wider screens keep the table.

3. CANDIDATE PROFILE (candidate.php)
------------------------------------
  * Header: photo, name, and the star rating directly beside the name. The
    rating writes to the same candidates.rating column as before — no second
    score was introduced. Unrated candidates read "Rating: not yet rated"
    rather than showing a misleading zero.
  * Rating is interactive once screening has started, and read-only before
    that, matching the existing gate.
  * Candidate Feedback and Suggest a Different Role now sit side by side in one
    two-column grid on desktop and stack on smaller screens. They were
    previously a rating card next to feedback, with suggestions full width
    below.
  * ACTIVITY moved to the very bottom, after interview history, and is
    collapsible. Collapsing only hides the list; the audit records behind it are
    untouched. The open/closed state is remembered per candidate in that
    browser, so it does not reset on every visit.

Reading order is now: identity and rating, resume, contact and application,
application details, recruiter notes, AI analysis, reviews, feedback and
suggested role, interview history, then activity.

4. CANDIDATES LIST (candidates.php)
-----------------------------------
  * Profile photo beside every name, with initials as the fallback.
  * Filters: role, assigned HR/Recruiter, rating (including "not yet rated"),
    plus sorting by newest, oldest, name, highest rated, or role.
  * Filtering and sorting happen in SQL, so the row count and the results always
    agree rather than the browser hiding rows from an over-fetched list. The
    existing type-ahead search still works on top of that and now shows an
    honest empty state when it matches nothing.
  * Assigned HR/Recruiter column, reading applications.assigned_to.

5. AUDIT TRAIL (audit_trail.php)
--------------------------------
  * Four summary cards: total events, today, users with activity, security
    events. Counted across the whole log so they stay meaningful while filters
    are applied.
  * Action badges are colour-coded by category — security, approval, interview,
    candidate, job, account — derived from the action name, with anything
    unrecognised falling back to a neutral badge rather than disappearing.
  * Date and time split onto two lines so the column stops stretching.
  * Meaningful empty state instead of a bare row.
  * Existing filters, search and queries are unchanged.

6. ANALYTICS
------------
The data logic is untouched: recruiters still see only their own numbers,
Admins still see their team with per-person drill-down, and the ?user= check
still runs in the backend. Only the presentation changed — KPI cards reflow from
a fixed grid to auto-fit, and the bar chart resizes rather than scrolling
sideways on phones.

7. INTERVIEW ROOM
-----------------
Kept from the previous pass and tightened: participant grid sized by visible
tile count, per-viewer pinning, the Notes drawer beside End meeting, and the
control bar wrapping on small screens. All meeting controls stay reachable, the
notes panel never covers them, and tile names truncate inside their tile.

8. PROFILE (profile.php)
------------------------
Two columns on desktop, stacked below 760px, with full-width inputs and buttons
on phones and the photo aligned rather than floating. Role stays visibly
read-only.

9. DARK MODE
------------
Every new component has a dark rule. I checked this mechanically: no literal
light background was added in this pass without a matching body.dark override.
New surfaces use the existing tokens (--paper, --surface, --line, --ink,
--muted, --green, --orange) rather than fixed colours.

10. WHAT WAS NOT TOUCHED
------------------------
Authentication, the role hierarchy, permissions, seats, the job approval
workflow, urgent hiring, the password approval workflow, interview scheduling,
notes, scoring, candidate ratings, feedback, suggested roles, analytics
calculations, audit records, and the applicant portal all behave exactly as
before. No candidate or application records are duplicated.
