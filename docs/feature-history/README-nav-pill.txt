Collapsed sidebar: floating pill label
======================================

NO MIGRATION NEEDED
-------------------
Sidebar CSS and JavaScript. No markup change was required — the pill reads the
label from the navigation item that is already there.

NOTE ON THE REFERENCE IMAGE
---------------------------
The image referred to in the brief did not arrive with the message, so this was
built from the written specification: circular backdrop on the targeted icon, an
off-surface pill beside it, 9px/20px padding, fully rounded, 14px medium weight,
nowrap, ~14px gap, vertically centred, 150ms fade-and-slide. Re-send the image
and the visual details can be tuned to match it more closely.

A REAL BUG THIS REPLACES
------------------------
The collapsed rail previously used ::after tooltips generated from the title
attribute. Those are children of .nav-item, which sits inside .sidebar — and
.sidebar has overflow-y:auto. A scroll container clips its descendants, so the
tooltips were being cut off at the 72px rail edge exactly as the group flyout
was before it. Both ::after rules have been deleted; zero remain.

ARCHITECTURE
------------
One .nav-pill element, created once and reused, appended to <body> and
positioned with fixed coordinates from the target's getBoundingClientRect().
Being outside the sidebar, no overflow, width, transform or stacking context
applies to it.

    z-index: 1050        above cards, tables and overlays
    pointer-events: none  purely a label, so clicks reach the icon beneath
    aria-hidden="true"    the link keeps its own accessible name; the pill would
                          otherwise duplicate it for screen readers

POSITIONING
-----------
  * left  = icon's right edge + 14px
  * top   = vertically centred on the icon, then clamped 8px inside the
            viewport top and bottom
  * if the pill would pass the right edge, it flips to the left of the icon
  * hidden on resize, on window scroll, and on sidebar scroll, since any of
    those moves the anchor

STATES
------
    hover           pill fades and slides in over 150ms ease-out
    keyboard focus  focusin shows it, focusout hides it, so Tab through the
                    rail gets the same affordance as the mouse
    active page     circular backdrop plus accent colour on the icon
    fast movement   mouseout with a relatedTarget outside the sidebar hides it
                    immediately, so nothing lingers when sweeping down the rail
    expanded        suppressed entirely; the inline labels take over
    flyout open     suppressed, so the label cannot sit on top of the submenu
    < 768px         suppressed; the drawer already shows real labels

COLOURS
-------
Inverted between themes, as specified:

    light mode   pill #1e293b on white text, icon backdrop rgba(0,0,0,.08)
    dark mode    pill #e8eaed with #1f1f1f text, backdrop rgba(255,255,255,.12)

Exposed as --pill-bg, --pill-text and --pill-icon-bg so they can be retuned in
one place.

prefers-reduced-motion drops the slide and shortens the fade.

VERIFIED BY EXECUTION
---------------------
Run against a DOM stub under node rather than only checked for syntax. 14/14:

    expanded rail shows no pill
    pill appears when collapsed
    pill is on <body>, not inside the sidebar
    pill shows the item label
    pill is aria-hidden
    pill sits beside the icon at right edge + 14px
    pill follows keyboard focus
    leaving the rail hides it
    pill shows for a group icon
    opening a flyout hides it
    it stays hidden while the flyout is open
    expanding hides it
    the expanded rail still shows none
    exactly one pill element is reused

The existing flyout suite still passes 17/17, so the two features coexist.

ONE BUG CAUGHT BEFORE PACKAGING
-------------------------------
setCollapsed() runs during initialisation and calls hidePill(), but pillEl was
declared with `let` further down the file — a temporal dead zone error that
would have thrown before any listener attached, breaking the whole sidebar.
This is the second time that pattern has appeared in this file; the declaration
now sits at the top beside flyoutEl, with a comment saying why.

INTEGRATION
-----------
  includes/sidebar.php   unchanged
  assets/styles.css      pill, circular backdrop and token block appended;
                         both ::after tooltip rules removed
  assets/app.js          pill controller inside the existing sidebar IIFE,
                         sharing its state so nothing is duplicated
