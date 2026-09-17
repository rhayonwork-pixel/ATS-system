Candidates page: layout that follows the sidebar state
======================================================

NO MIGRATION NEEDED
-------------------
CSS and one markup wrapper. No PHP logic, query, permission or dark-mode rule
was touched.

WHAT I FOUND BEFORE CHANGING ANYTHING
-------------------------------------
The shell is already correct:

    .app-shell { display: flex }
    .app-main  { flex: 1; min-width: 0 }

So the content area ALREADY resizes on its own whenever the sidebar changes
width. No JavaScript width or margin arithmetic is needed, and adding any would
fight flexbox and reintroduce the jank you asked to avoid. I did not add it.

Two real faults existed instead.

FAULT 1 — breakpoints measured the wrong thing
----------------------------------------------
Every candidates rule was a @media query, and a media query measures the
VIEWPORT. At a 1200px window the content area is about 950px with the sidebar
expanded and about 1128px collapsed — but both got the same "desktop" rules.
That is why the layout broke on toggle: the table stayed in nine-column mode in
a space too narrow for it.

FIX: the page is now a CSS size container.

    .page-container { container-type: inline-size; container-name: page }

and its breakpoints are @container page (...) rules, which measure the width
the page actually has. Collapse the sidebar and the table gets more room;
expand it and the card layout arrives earlier — automatically, with no state
detection at all.

    @container page (max-width:900px)  search takes its own row
    @container page (max-width:780px)  table becomes stacked cards
    @container page (max-width:760px)  filters go 2-up, actions full width
    @container page (max-width:460px)  one control per row
    @container page (max-width:420px)  card labels stack above their values

FAULT 2 — two systems targeting the same table
----------------------------------------------
A viewport rule at 820px and the new container rule both converted the
candidates table into cards, which would fight at some widths. The viewport
block now covers the audit table only; the candidates table is governed solely
by @container. Verified programmatically: no @media block converts
.candidate-table any more, and the only remaining media rules touching it are a
pointer:coarse touch-target rule and an avatar size below 560px, where the
sidebar is already an out-of-flow drawer so viewport and container widths are
the same.

ALSO REMOVED
------------
.app-main carried `transition: margin-left 220ms` in two places, but no
margin-left is ever set on it — the transition animated nothing. Deleted. The
sidebar's own `transition: width 220ms` is what produces the smooth movement,
and the flex child follows it in step. Animating a margin as well would double
up and cause exactly the jitter that was meant to be avoided.

JAVASCRIPT
----------
None was added. The state hook you asked about already exists: app.js keeps
`body.sidebar-collapsed` in sync from setCollapsed(), the single place that
mutates the sidebar state. It is used by the no-container-query fallback below,
and is available if you want to hang anything else off it.

FALLBACK
--------
Container queries are supported in current Chrome, Edge, Safari and Firefox.
For anything older:

    @supports not (container-type: inline-size) { ... }

restores viewport rules corrected by body.sidebar-collapsed, so the page
degrades rather than breaking.

CONTAINMENT
-----------
  * .page-container and every direct child: width:100%, max-width:100%,
    min-width:0, box-sizing:border-box
  * the table keeps a 940px minimum and scrolls inside .table-wrap; only the
    wrapper scrolls, never the page
  * names wrap at word boundaries; emails, roles and recruiter names truncate
    with an ellipsis and keep the full value in a title attribute
  * in card mode the truncation is lifted, since there is room to wrap
  * buttons are nowrap and go full width in cards, so they cannot be pushed
    off-screen or split a label

WHY THE CONTAINER IS SCOPED TO THIS PAGE
----------------------------------------
container-type also applies `contain: layout`, which makes the element a
containing block for fixed-position descendants. Applying it to the shared
.content wrapper would have broken the interview room's fixed bottom-sheet rail
on tablets. It is therefore scoped to .candidates-page only.

VERIFIED
--------
  * php lexer: 52 files clean
  * branch-aware render: candidates.php balanced on every sampled path
  * all five @container blocks parse and balance; the container is declared
    before it is queried
  * no selector is targeted by both a container and a width media query
  * node --check on app.js; sidebar behaviour suite still 17/17
