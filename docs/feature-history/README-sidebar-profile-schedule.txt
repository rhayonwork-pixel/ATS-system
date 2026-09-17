Sidebar redesign, candidate profile containment, schedule interview
===================================================================

NO MIGRATION NEEDED
-------------------
Markup, CSS and JavaScript only. Every route, permission, query and handler is
unchanged — all 21 sidebar destinations were checked as still present.

1. CANDIDATE PROFILE OVERFLOW — THE ROOT CAUSE
----------------------------------------------
Flex and grid children default to min-width:auto, which means "never shrink
below my content". A long email address or an unbroken string therefore forced
its column wider than the parent, and the whole profile pushed past the card.

Fixed at the source: every profile container and its direct children now carry
min-width:0, so they shrink and their content wraps instead. On top of that:

  * width:100%, max-width:100%, box-sizing:border-box on the profile panels
  * headings, meta lines and section text use overflow-wrap:break-word, so
    words break only when they genuinely cannot fit
  * only email and URL links use overflow-wrap:anywhere, scoped to those
    selectors, because those strings have nowhere natural to break
  * action rows wrap and their buttons are nowrap, so a button can neither
    escape the card nor split its label

2. LARGER PROFILE PICTURES
--------------------------
    profile header      76px -> 92px   (+21%)
    list avatar chip    28px -> 34px   (+21%)
    candidates table    40px -> 48px   (+20%)
    interview card      44px -> 52px   (+18%)

Aspect ratio, border radius, object-fit:cover and the initials fallback are all
unchanged. The candidates table avatar column widened to 66px to absorb the
extra size, so nothing is pushed out. On phones the header avatar steps back to
72px.

3. SCHEDULE INTERVIEW
---------------------
The card is now a flex column with its own scroll region:

    .interview-schedule  max-height: calc(100vh - 32px); overflow: hidden
    .schedule-head       fixed, stays visible
    .schedule-scroll     flex:1; min-height:0; overflow-y:auto

Only the fields scroll. The heading stays put, and because the card is capped
at the viewport height the page itself never overflows. min-height:0 is the
part that makes it work — without it a flex child refuses to shrink below its
content and the scroll never engages.

More room: the column is now minmax(340px, 440px) rather than a flat 380px, and
the form is a proper two-column grid with 14px/16px gaps.

Readability, without shrinking anything: 44px inputs and selects, 14px input
text, 12.5px labels with 1.4 line-height, 88px minimum textarea, and a visible
focus ring. Labels and options wrap at word boundaries, never letter by letter.

Below 1100px the card stops being sticky and drops its cap, so on tablet and
phone the form flows with the page instead of scrolling inside a short box.
Below 620px it becomes a single column.

4. SIDEBAR REDESIGN
-------------------
Groups, exactly as specified:

    JOBS            Jobs · Create job · Job approvals
    PEOPLE          Employees · Attendance
    ADMINISTRATION  HR/Recruiters · Password resets · Applicant portal ·
                    Audit trail · Settings

Recruiting (Overview, Hiring pipeline, Candidates, Interviews, Analytics) stays
as flat links, since those are the daily destinations and burying them behind a
group would cost a click every time.

Job approvals was added to the JOBS group. Your list named only Jobs and Create
Job, but that page exists and is permission-gated; dropping it from the nav
would have made it unreachable.

Every item is still permission-gated exactly as before, and a group renders
only when the person can see at least one of its children.

BEHAVIOUR
  * click a group header to expand or collapse, with a height transition
  * open/closed state is remembered per group in localStorage
  * a group containing the current page is rendered OPEN by PHP, so the active
    item is visible on arrival — this is server-side, so it is correct before
    any JavaScript runs
  * an active child shows a green left rule and the group stays open
  * left/right arrow keys collapse and expand a focused group
  * no page reload is involved

5. COLLAPSE CONTROL
-------------------
A dedicated toggle sits under the brand: an icon plus a "Collapse" label,
full width, with hover and focus states. The logo is now a link to the home
page and no longer doubles as the toggle. The collapsed state persists.

6. COLLAPSED MODE
-----------------
72px wide, icons centred, labels hidden. Children are NOT hidden away: a
collapsed group opens as a flyout panel beside the sidebar on hover or keyboard
focus, so every destination stays reachable. Icon-only items get a tooltip from
their title attribute. Unread counts become a small corner badge rather than
disappearing.

7. LOGO
-------
The mark sits in a fixed 34px box with width/height 100%, max dimensions and
object-fit:contain, so it scales to the box and keeps its ratio instead of
being clipped. The brand text truncates with an ellipsis rather than wrapping,
and is hidden entirely when collapsed.

8. RESPONSIVE
-------------
    >1000px   full 250px sidebar, or 72px when collapsed
    <=1000px  automatically icon-only with flyouts, content gains the width
    <=850px   off-canvas drawer: the sidebar leaves the flow entirely so the
              main content keeps the full viewport width. It slides in, closes
              on outside click or Escape, and shows full labels while open.

.app-main is flex:1 with min-width:0, so content always adapts to whatever the
sidebar currently occupies and the two can never exceed the viewport.

9. DARK MODE
------------
Toolbar, sidebar, group flyouts, active states, form fields and the custom
scrollbar all have explicit dark rules using the existing tokens.

VERIFIED
--------
  * php lexer: 52 files clean
  * branch-aware render: sidebar, candidates.php and interviews.php balanced
    on every sampled path
  * node --check on app.js and on every inline script in the project: all parse
  * all 21 sidebar routes still present
