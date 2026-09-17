Notification header: zero-overlap layout and panel overhaul
===========================================================

NO MIGRATION NEEDED
-------------------
The category/priority migration from the previous change is still the latest
one. This is markup, CSS and JavaScript.

THE OVERLAP WAS MINE
--------------------
I introduced it in the previous pass:

    .notif-rail { position: absolute; top: 18px; right: clamp(18px,4vw,44px) }

That is exactly the fragile pattern the brief says to remove. Taken out of
flow, the bell floated over whatever the page happened to put in that corner —
page headings, toolbars, tab rows.

THE FIX — A REAL HEADER
-----------------------
Private pages now have a persistent .app-topbar instead of the mobile-only bar:

    [menu] [brand]   Page title ..................   [theme] [bell] [avatar]

    .app-topbar   display:flex; align-items:center; gap:12px; sticky
    .topbar-title flex:1 1 auto; min-width:0; truncates with an ellipsis
    .header-utils display:flex; justify-content:flex-end; gap:.75rem;
                  margin-inline-start:auto; flex-shrink:0

The title is the ONLY flexible child, so it shrinks and truncates while the
utility cluster keeps its size. Nothing is absolutely positioned, so nothing
can overlap. The 0.75rem gap gives the 12-16px clearance asked for, and the
utilities sit inside the topbar's own padding rather than over the page.

The bell trigger is a 42px rounded-square button: 1px border, 12px radius, 10px
padding, with hover, active and focus-visible states. The unread badge is
absolutely positioned INSIDE the button, anchored to the icon corner, so it
never changes the button's layout size.

The theme toggle and a profile avatar were moved into the same cluster, since
the brief asks for the bell to sit alongside them rather than alone.

A REAL BUG THIS UNCOVERED
-------------------------
An older rule turned the sidebar into an off-screen drawer at max-width:850px,
while the newer rules expect an in-flow icon rail from 768px up — and the menu
toggle is hidden above 767px. Between 768 and 850px the sidebar was therefore
off-screen with no way to open it. The old rule is now 767px, matching every
other drawer breakpoint. Dead .mobile-appbar rules were deleted rather than
left behind.

THE PANEL
---------
    position:absolute; top:calc(100% + 8px); right:0   anchored to the trigger
    width:min(400px, calc(100vw - 32px))
    max-height:min(75vh, 520px); overflow-y:auto
    z-index:1050
    box-shadow:0 10px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.1)

Header: "Notifications" at 1rem/600, an unread count pill ("3 New") that hides
at zero, and a "Mark all as read" text button at .8125rem in the accent colour,
above a divider.

Chips: All / Applications / Interviews / System. Active chip is filled; the
rest are outlined. The row scrolls horizontally rather than wrapping.

Item cards:
  * left   circular category badge — blue application, purple interview,
           green message, amber system, each with a dark-mode pair
  * centre bold title, preview clamped to two lines with -webkit-line-clamp
           and an ellipsis, then relative time and actor
  * right  8px accent dot while unread
  * hover  the surface highlights and the row actions fade in; on touch devices
           they are always visible, since there is no hover there
  * the whole card is clickable and routes to its target, with Enter doing the
    same for keyboard users. Dismiss stops propagation so it cannot navigate.

Empty state: a check glyph in a muted circle, "You're all caught up!" and "No
new notifications at this time." — or a category-specific line when a filter is
active.

INTERACTION AND ACCESSIBILITY
-----------------------------
  * click the bell to toggle; click-away and Escape close it, with focus
    returning to the trigger
  * aria-haspopup, aria-expanded and aria-label on the trigger; the panel is
    role="dialog" aria-label="Notifications Panel"
  * chips are role="tab" with aria-selected
  * the list is aria-live="polite" with aria-busy during a fetch
  * focus-visible rings on the trigger, chips, actions and cards
  * cards are tabbable (tabindex="0")

RESPONSIVE
----------
    >= 768px   flyout anchored to the trigger's right edge
    < 768px    fixed bottom sheet, full width, max-height 82vh, with a
               semi-transparent backdrop that closes it and body scroll locked.
               The page title gives way to the brand, and the drawer toggle
               appears.

THEME
-----
Everything uses --bg-surface, --bg-surface-elevated, --text-primary,
--text-secondary, --border-color and --accent-primary, with explicit dark
pairs for the four category badges and the scrollbar thumb.

VERIFIED
--------
  * php lexer: 53 files clean
  * header.php tag balance correct (the extra <div> is .app-shell, closed in
    footer.php)
  * node --check on app.js; sidebar flyout suite 17/17; pill suite 14/14
  * no absolute positioning remains on the trigger or its container
