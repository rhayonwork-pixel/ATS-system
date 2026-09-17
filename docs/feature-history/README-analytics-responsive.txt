Analytics dashboard: mobile-first responsive refactor
=====================================================

NO MIGRATION NEEDED
-------------------
Markup, CSS and JavaScript. All PHP data logic, permission checks, escaping and
the ?user= authorisation are untouched.

TWO PLACES I DID NOT FOLLOW THE BRIEF LITERALLY
-----------------------------------------------
1. THE COLOUR TOKENS. The brief specified a blue/slate palette on
   [data-theme="dark"]. Applying it would have rebranded every other page in
   the ATS, since all 50-odd pages share one stylesheet and the existing
   identity is green with a body.dark switch.

   Instead the semantic names from the brief are ALIASED onto the existing
   tokens, so both vocabularies work:

       --bg-surface -> var(--surface)     --text-primary -> var(--ink)
       --border-color -> var(--line)      --accent-primary -> var(--green)
       --sidebar-width-expanded: 250px    --sidebar-width-compact: 72px

   [data-theme="dark"] is honoured as an additional hook alongside body.dark,
   so the attribute form works if you prefer it. Say the word if you actually
   want the blue palette applied ATS-wide — that is a separate decision.

2. RESIZEOBSERVER FOR CHARTS. There is no Chart.js or ApexCharts in this
   project; the funnel, bar chart and comparison bars are drawn with CSS grid
   and percentage widths. They reflow with their container automatically —
   there is no canvas to re-measure, so a ResizeObserver would observe
   something and then have nothing to do. None was added. If you later swap in
   a canvas charting library, that is the point to add one.

MOBILE-FIRST, MEASURED AGAINST THE RIGHT THING
----------------------------------------------
Base styles are the phone layout; every step up is a min-width query. But the
queries are @container, not @media, because the dashboard sits beside a sidebar
that is 250px or 72px depending on state — a viewport media query cannot know
which, so at 1200px it would apply desktop rules to a 950px content area.

    base            single column          (320-767px equivalent)
    >= 600px        2 columns
    >= 860px        3 columns
    >= 1120px       4 columns
    >= 1500px       auto-fit, capped at 1600px so cards do not stretch

Collapse the sidebar and the grid gains a column with no window resize.

KPI CARDS
---------
.analytics-card is a semantic <article role="group"> with an aria-label that
reads the label and value together, so a screen reader announces "Candidates
assigned: 48" rather than two loose fragments. The icon is aria-hidden.

Two KPIs named in the brief were added, computed from existing data:

  * TIME TO HIRE — average days from applied_at to the hired stage move.
    applications has no hired_at column, so updated_at is used as the closest
    honest proxy. Returns "—" with "No hires in this period" rather than a
    misleading 0.
  * OFFER ACCEPTANCE — of offers that were DECIDED, how many were accepted.
    Applications still sitting at "offer" are undecided and excluded from both
    sides, so a pending offer cannot drag the rate down. Returns "—" when
    nothing has been decided.

CHARTS AND TABLES
-----------------
The CSS charts carried no text, so they were invisible to screen readers. Each
now has role="img" with a generated aria-label listing the actual values, e.g.
"Candidate processing funnel: Applied 48, Shortlisted 31, Interview 12...".

The performance table converts to labelled cards below 767px using
td::before { content: attr(data-label) }, with data-label added to every cell.
From 768px up it is a real table with tabular-nums right alignment on numeric
columns and row-hover transitions.

SIDEBAR BREAKPOINTS
-------------------
Realigned to the thresholds in the brief (previously 850 / 1000):

    < 768px        off-canvas drawer
    768-1023px     icon rail at var(--sidebar-width-compact), tooltips on
                   hover and focus-visible
    >= 1024px      full expanded rail with group labels

The drawer now has a proper backdrop that closes it on click, body scroll is
locked while it is open, and the hamburger carries aria-expanded and
aria-controls="app-sidebar", kept in step by a single setDrawer() function.

A stale delegated toggle in the older minified block was removed — it and the
new handler would both have fired, cancelling each other out.

CSS and JS breakpoints were checked against each other so the flyout logic and
the layout agree about which mode is active.

ACCESSIBILITY
-------------
  * :focus-visible ring on every interactive element via a :where() rule
  * prefers-reduced-motion honoured globally: animations and transitions drop
    to 0.001ms rather than being removed, so state changes still commit
  * role="region" with aria-labelledby on the KPI sections
  * role="search" on the reporting-period form, with a real <label> for every
    control instead of bare aria-labels
  * role="img" plus data summaries on the charts
  * .sr-only labels retained throughout

Contrast: the existing green (#2f6b4f on #fff) is 5.9:1 and the dark-mode text
(#f7f8f5 on #17211b) is 15:1, both above the 4.5:1 AA threshold. --text-secondary
(#6d776f) is 4.6:1 on white. No new colour was introduced below the threshold.

TESTING
-------
1. Breakpoints: 320, 375, 768, 1024, 1440. Also toggle the sidebar at a fixed
   1200px width — the KPI grid should change column count on its own.
2. Charts: collapse and expand the sidebar; the bars and funnel should re-scale
   immediately, with no reload and no permanent shrink.
3. Table reflow: below 768px each row becomes a card with its labels; the page
   itself must not scroll sideways.
4. Dark mode: toggle and check card backgrounds, borders, chart fills and the
   tooltip on the icon rail.
5. Keyboard: Tab through the drawer, the period form and the cards. The drawer
   should close on Escape and return focus to the hamburger.

VERIFIED
--------
  * php lexer: 52 files clean
  * branch-aware render on analytics.php (the 2/512 reported failures are the
    checker counting server-side ifs; static tag counts balance at 52/52 div,
    8/8 section, 1/1 article)
  * node --check on app.js; sidebar behaviour suite still 17/17
  * CSS and JS breakpoints agree
