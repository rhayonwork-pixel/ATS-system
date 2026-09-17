Sidebar: logo expands, arrow collapses
======================================

NO MIGRATION NEEDED
-------------------
Sidebar header markup, CSS and the collapse state logic only. Every route,
permission, group, active state, tooltip and flyout is unchanged.

THE INTERACTION MODEL
---------------------
    EXPANDED    [ LOGO ] Acme/people              [ < ]
                logo = branding, does nothing
                arrow = COLLAPSE

    COLLAPSED   [ LOGO ]
                logo = EXPAND, hovering reveals a right-pointing arrow
                the collapse arrow is not rendered

The logo never collapses. The arrow never expands. Each control checks the
current state before acting, so clicking the logo while expanded, or the arrow
while collapsed, does nothing rather than toggling.

OLD BUTTON REMOVED
------------------
The standalone "Collapse" button added in the previous round is gone — from the
markup, from the JavaScript, and from the stylesheet. Its rules were deleted
rather than left as dead CSS. Verified: no reference to .sidebar-toggle,
.toggle-text or .brand-link survives anywhere in the project, and each of
data-sidebar-expand and data-sidebar-collapse appears exactly once in the
markup with exactly one handler.

ONE STATE, NOT TWO
------------------
Both controls call the same setCollapsed(collapsed, remember) function, which
is the only place in the codebase that touches the 'collapsed' class. It also:

  * mirrors the state onto body.sidebar-collapsed for layout
  * moves the logo in and out of the tab order (tabIndex 0 when collapsed,
    -1 when it is only branding)
  * updates aria-expanded, aria-disabled, aria-label and title on both controls
  * hides the collapse arrow outright when collapsed, so it cannot be focused
  * persists to localStorage under the existing ats-sidebar-collapsed key

There is no CSS-only collapse competing with it.

THE HOVER ARROW
---------------
The arrow is an absolutely positioned overlay inside the logo button, so
revealing it shifts nothing. On hover or keyboard focus the logo fades to 12%
and scales to 0.9 while the arrow fades in from a 3px offset, both over 180ms.
The logo stays recognisable underneath and returns immediately on leave.

The arrow uses the existing chevron icon rotated -90deg (points right, the
direction the sidebar will grow); the collapse arrow is the same icon rotated
+90deg. Both use var(--green) or var(--muted), so they are legible in either
theme.

ACCESSIBILITY
-------------
  * both controls are real <button> elements
  * "Expand sidebar" and "Collapse sidebar" as aria-label and title
  * the hover reveal also triggers on :focus-visible, so keyboard users get the
    same affordance
  * visible focus rings on both
  * 42px logo target, 46px on coarse pointers; 30px arrow, 38px on touch
  * the inactive control is removed from the tab order rather than being a
    focusable no-op

RESPONSIVE
----------
    >1000px       full model: logo expands, arrow collapses
    850-1000px    the sidebar is compact by LAYOUT, not by state. The logo is
                  branding only here and shows no hover arrow — advertising an
                  expand action the state machine would correctly refuse would
                  be a dead control.
    <=850px       mobile drawer, always expanded when open. Header is branding
                  only; the drawer has its own trigger.

LOGO INTEGRITY
--------------
The mark sits in a fixed square box at width/height 100% with max dimensions
and object-fit:contain, so it scales to the box, keeps its aspect ratio, and is
never stretched or clipped. It is the existing logo_html() output — no asset
was swapped.

PRESERVED
---------
Jobs / People / Administration groups with their expand-collapse behaviour and
remembered state, the server-rendered open group for the active page, collapsed
flyouts, tooltips, unread badges, role-based visibility, and both themes.

VERIFIED
--------
  * php lexer: 52 files clean
  * branch-aware render: sidebar balanced on every sampled path
  * node --check on app.js
  * one expand control, one collapse control, one state mutation site
  * all sidebar routes still present
