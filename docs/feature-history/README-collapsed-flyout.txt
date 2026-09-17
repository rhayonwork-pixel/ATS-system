Collapsed sidebar: dropdowns as floating flyouts
================================================

NO MIGRATION NEEDED
-------------------
Sidebar CSS and JavaScript only. Routes, permissions, groups, active-page
detection and the logo/arrow controls are unchanged.

WHY THE OLD FLYOUT WAS TRAPPED
------------------------------
The collapsed dropdown was an absolutely positioned child of .nav-group with
left:calc(100% + 8px). That can never work here, because:

    .sidebar { overflow-y: auto; overflow-x: hidden }

A scroll container clips its descendants regardless of z-index. The panel was
being cut off at the sidebar's 72px edge, so the children were effectively
unreachable.

THE FIX — A REAL FLOATING PANEL
-------------------------------
The flyout is no longer a descendant of the sidebar. app.js builds a
.nav-flyout element, appends it to <body>, and positions it with fixed
coordinates taken from the clicked icon's getBoundingClientRect(). Nothing
about the sidebar's overflow, width, stacking context or transforms applies to
it any more.

This is structural, not a positioning hack: no negative margins, no overflow
overrides on the sidebar, no z-index escalation.

The children are CLONED from the real navigation markup, so hrefs, labels,
icons, unread badges and the active class all come across exactly as rendered
by PHP. No link is rebuilt or faked, so routes and permissions cannot drift out
of step with the sidebar.

POSITIONING
-----------
  * left = icon's right edge + 8px, top = icon's top edge
  * if the panel would run past the bottom, it shifts up to sit 12px above it
  * if there is no room on the right, it flips to the left of the icon
  * capped at calc(100vh - 24px) with its own scroll for long groups
  * below 700px it becomes a bottom sheet instead of squeezing beside the
    sidebar

INTERACTION
-----------
    click a group icon        opens its flyout, sidebar stays collapsed
    click the same icon       closes it
    click a different icon    closes the first, opens the new one
    click outside             closes
    Escape                    closes and returns focus to the icon
    click a child link        closes on the way out
    expand the sidebar        closes (the state change makes it stale)
    scroll or resize          closes, since the anchor has moved

Only one flyout exists at a time — openFlyout() calls closeFlyout() first.

EXPANDED MODE IS UNTOUCHED
--------------------------
When the sidebar is expanded the dropdowns still expand inline beneath their
parent with the same height transition, the same remembered state, and the same
server-rendered open group for the active page. The toggle checks the current
mode and picks inline or flyout accordingly — one control, two behaviours, not
two systems.

ACTIVE STATE
------------
The active child keeps its highlight inside the flyout because the class is
cloned with the link. The parent icon gains .flyout-open while its panel is
showing, so it stays visibly selected.

VERIFIED BY EXECUTION
---------------------
Rather than only checking that the file parses, I ran the sidebar controller
against a small DOM stub under node and asserted the behaviour. 17/17:

    sidebar collapses
    flyout is created, and is attached to <body>, not inside the sidebar
    both child links are present
    the active child keeps its state
    child links keep their real hrefs (admin.php, job-post.php)
    the parent icon is highlighted
    the panel is positioned beside the sidebar (left = 80px for a 72px rail)
    clicking the same icon closes it, and clears the parent highlight
    it reopens
    expanding the sidebar closes it
    expanded mode uses the inline dropdown and creates no flyout
    a group holding the active page starts open
    the inline toggle closes and reopens

One real bug was caught this way before packaging: closeFlyout() is called from
setCollapsed() during initialisation, but the flyout state was declared with
let further down the file — a temporal dead zone error that would have thrown
before any listener was attached, breaking the whole sidebar. The declarations
now come first.

DARK MODE
---------
The panel, its title rule, hover and active states all have explicit dark
variants using the existing tokens, with a deeper shadow on dark backgrounds.
