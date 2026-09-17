Floating transparent utility bar
================================

NO MIGRATION NEEDED
-------------------
Header markup, CSS and one small script.

WHAT WAS REMOVED
----------------
The topbar built in the previous pass is gone: page title, brand mark, solid
background, bottom border and sticky surface. Its CSS was deleted rather than
overridden — no .app-topbar, .topbar-title or .topbar-brand rule survives, so
there is nothing left to leak chrome back in.

Removing the title cost nothing: I checked all 19 private pages first and every
one already renders its own <h1> in the body. No relocation was needed.

WHAT REMAINS
------------
    [hamburger]                          [theme]  [bell]  [avatar]

Only those three controls, right-aligned with:

    display:flex; justify-content:flex-end; align-items:center;
    gap:.75rem; margin-inline-start:auto;

ZERO CHROME
-----------
    .util-bar {
      background: transparent;
      border: none; border-bottom: none; box-shadow: none; outline: none;
      position: sticky; top: 0; z-index: 1000;
    }

No !important was needed, because the old rules were deleted rather than
fought.

STICKY, NOT ABSOLUTE — AND WHY
------------------------------
The brief offered either. Sticky is the right one here.

Both pin the bar to the top and let content scroll beneath it. The difference
is that `position:absolute` takes the bar out of the flow, so its 44px-tall
strip sits invisibly on top of whatever the page puts there — every page
heading, every "Create" button in a .dashboard-head. The brief anticipates this
("ensure the page content below is padded"), but that means adding padding to
every page and keeping it in sync with the bar's height forever.

Sticky reserves its own height once, pins on scroll, and content still scrolls
underneath. Same visual result, no occlusion, nothing to keep in sync.

One extra guard: the bar itself is pointer-events:none with its children set
back to auto, so the empty transparent strip can never intercept a click meant
for the page beneath it.

INTERACTIVE STATES
------------------
  rest    no background tile, no border, no bounding box
  hover   translucent circular halo — rgba(0,0,0,.06) light,
          rgba(255,255,255,.12) dark — over 180ms
  active  transform: scale(.96)
  focus   2px accent outline at 2px offset

Every control is a 44x44 circle with a 20px glyph inside, so the target is
comfortable while the icon stays small.

LEGIBILITY OVER SCROLLING CONTENT
---------------------------------
Icons, the theme glyph and the badge all carry
filter: drop-shadow(0 1px 2px rgba(0,0,0,.15)), so they read against a white
card, a dark chart or anything else passing behind.

The avatar keeps a 2px ring in the surface colour
(box-shadow: 0 0 0 2px var(--bg-surface)) with a dark-mode pair, so it stays
distinct from page content.

SCROLL BEHAVIOUR — CONFIGURABLE, DEFAULT OFF
--------------------------------------------
State A is the default: fully transparent at every scroll position, which is
what the brief asks for and is legible because of the drop shadows.

State B is one flag away. In assets/app.js:

    const UTIL_BAR_BLUR_ON_SCROLL = false;   // set true for State B

With it on, .is-scrolled is added past 20px, giving
backdrop-filter: blur(10px) over a 72% surface tint, with a solid-ish fallback
for engines without backdrop-filter. The listener is rAF-throttled and passive,
so it costs at most one class check per frame, and it does not attach at all
while the flag is false.

RESPONSIVE
----------
    >= 1024px      24px gutter from the viewport edge
    768-1023px     gaps tighten to .5rem
    < 768px        hamburger appears far left with the same transparent
                   treatment; the three utilities stay grouped right; padding
                   drops to 14px. Four 44px controls plus gaps fit inside
                   320px, so there is no horizontal overflow.

ACCESSIBILITY
-------------
  * aria-label="Toggle color theme"
  * aria-label="View notifications - 3 unread" (count included when non-zero)
  * aria-label="Open user profile menu"
  * aria-label="Open navigation" on the hamburger, with aria-expanded and
    aria-controls
  * focus-visible rings on all four, sized to be visible against any backdrop
  * prefers-reduced-motion drops the transition and the active scale

VERIFIED
--------
  * php lexer: 53 files clean
  * every private page has its own <h1>, so no page lost its title
  * .util-bar carries background:transparent, border:none, border-bottom:none,
    box-shadow:none, outline:none, position:sticky, z-index:1000 — checked by
    parsing the rule, not by eye
  * no .app-topbar / .topbar-title / .topbar-brand rules remain
  * node --check on app.js; sidebar flyout suite 17/17; pill suite 14/14
