Light theme audit and remediation
=================================

NO MIGRATION NEEDED
-------------------
Two new stylesheets and two <link> tags in includes/header.php. No PHP, no
markup, no JavaScript, no database change.

FILES
-----
  assets/css/light-theme-tokens.css      NEW — the light palette, one place
  assets/css/components-light-fixes.css  NEW — light-only component repairs
  includes/header.php                    loads both, last, after light-theme-v3.css

DARK MODE IS UNTOUCHED
----------------------
Every selector in both files is prefixed with html[data-theme="light"] (plus
html:not([data-theme="dark"]) in the tokens file, so a JS failure before the
theme attribute is set still lands on light). Neither file contains a dark
selector, a dark variable or a dark value.

Proven, not assumed. Three checks in a real browser against the running app:

  1. Rule matching. In dark mode, 0 of the 69 style rules in the two files
     match anything on dashboard.php, candidates.php or audit_trail.php. In
     light mode the same rules match 19-29 elements per page.
  2. Computed styles. Each page was rendered in dark twice — once with the two
     files blocked at the network layer, once normally — and body, .card,
     .stat, input, .btn, .sidebar, .nav-item.active, th and td had byte-identical
     computed background, colour, border, shadow, padding and outline, as did
     the resolved values of --bg-canvas, --bg-surface, --text-primary,
     --text-secondary, --border-default, --shadow-md, --accent-primary,
     --lt-primary and --overlay-scrim.
  3. Screenshots. Dark screenshots matched on 5 of 9 pages; the other 4 differ
     because those pages animate or show live data. A control run that used the
     SAME configuration for both passes produced pixel differences on 3 pages,
     which is what nondeterminism looks like. Computed styles were identical in
     every case, including on those 4 pages.

WHY A TOKENS FILE
-----------------
The app had two competing light palettes: the slate/blue set in
assets/css/theme-tokens.css (:root) and a warm teal set that
assets/light-theme-v3.css declared as --lt-* and then aliased over the semantic
tokens. v3's several hundred component rules all read --lt-*, so the tokens file
re-points those names at one documented palette. v3 keeps working unchanged and
renders the agreed values. Palette values belong in light-theme-tokens.css from
now on; do not reintroduce them into v3.

TOKENS THAT CHANGED (light only)
--------------------------------
  --bg-canvas        #F7F7F9 -> #F8FAFC
  --bg-surface       #FFFEFC -> #FFFFFF        cards are pure white, no cream cast
  --bg-surface-hover #EEF3F1 -> #F1F5F9
  --text-primary     #17211C -> #0F172A        17.85:1 on white
  --text-secondary   #34423A -> #475569         7.58:1
  --text-muted       #526159 -> #64748B         4.76:1 (still AA)
  --border-default   #D2DAD5 -> #E2E8F0
  --border-input     #7C8B83 -> #8290A5         3.24:1 — WCAG 1.4.11 wants 3:1
  --shadow-sm        card shadow -> 0 1px 2px rgba(0,0,0,.05)
  --shadow-md        hover shadow -> two-layer ambient card shadow
  --shadow-lg        NEW — modals, flyouts, drawers
  --overlay-scrim    rgba(23,33,28,.42) -> rgba(15,23,42,.45)
  --lt-overlay-scrim NEW — see the bug list below
  accent             unchanged teal #0F766E (brand), white on it is 5.47:1

WHAT THE AUDIT FOUND
--------------------
The audit ran against the real app (PHP built-in server + MySQL), logged in as
an admin, forced light mode and measured every visible element on 14 pages:
own background luminance, text contrast against the effective background,
input styling and card consistency.

Real bugs, now fixed:

  1. INVISIBLE BUTTON. The public site's "Recruiter hub" CTA rendered teal text
     on a teal fill — contrast 1.00, the label could not be read at all.
     Cause: light-theme-v3.css line 160 sets `a { color: var(--lt-primary) }`,
     which outranks `.nav-cta { color:#fff }` in styles.css.
  2. DANGER BUTTONS WERE TEAL. v3 paints every `.btn:not(.ghost):not(.secondary)`
     with the brand colour, and that selector is more specific than `.btn.danger`,
     so destructive actions looked like primary actions.
  3. A MODAL WITH NO SCRIM. v3's .candidate-preview-overlay rule reads
     var(--lt-overlay-scrim), a variable that was never declared anywhere. An
     invalid var() makes the whole declaration invalid, so that overlay had no
     dimming behind it at all.
  4. CONTRAST FAILURES. .score-pill 2.47:1, the audit-trail category chips
     4.18:1, .ov-stat-delta.down 4.45:1, and the marketing hero's kicker and
     footnote 4.06:1 and 4.41:1 on the teal panel.
  5. LEGACY PALETTE LEAKS. Hover states still used the pre-teal dark green
     (#24563f), and mint tints (#edf5e9, #eef1ec, #c8dfbf, #e9ede6) plus
     green-black shadow literals (#173b2818, #10291d38) were scattered across
     pills, notices, kanban columns, capacity bars and disabled buttons.
     styles.css alone had 294 hardcoded colour literals reachable in light mode.

Fixed in components-light-fixes.css, by section:
  1. dark/legacy artifacts (the five items above)
  2. cards, panels, tables — white surface, --border-default, --shadow-md,
     uniform 1.5rem padding; table body text moved to --text-primary
  3. inputs — white fill, 3.24:1 border, --shadow-sm, readable placeholder
  4. navigation — active page gets a soft accent wash plus a 3px accent bar
     instead of an inverted block
  5. modals and dropdowns — --shadow-lg, crisp border, one scrim token
  6. interactive states — hover, focus-visible, active, disabled

NOT DARK ARTIFACTS (deliberately left alone)
--------------------------------------------
The live interview room (.room-wrap, .video-tile, .device-preview,
.screen-placeholder, .room-active-card) is a dark surface in both themes, the
way video tools normally are. The audit excludes it. Say so if it should be
light as well; it is a product decision, not a leak.

The audit also flags .ai-tab-label ("Acme Assist") as 1.07:1 on every page.
That is a false positive: the white label sits on the dark launcher artwork,
which is an <img>, so the measured background is the page behind it. Verified
by eye in the screenshots.

VERIFICATION
------------
Real Chrome, real pages, light mode:
  * 14 pages audited before and after. Contrast failures went from 22 measured
    instances to 0, counting the .ai-tab-label false positive as expected.
  * 14 interactive-state assertions pass: focus ring is 2px solid accent at
    offset 2px (inset inside the sidebar, which clips its overflow); card hover
    raises --shadow-md to --shadow-lg; primary hover darkens to --accent-hover;
    disabled controls are opacity .5 on #F1F5F9 with not-allowed; inputs are
    white with a 1px visible border; cards are white with 24px padding; canvas
    is #F8FAFC; the notification flyout and the approval modal are white with a
    1px border and --shadow-lg; both scrims resolve to rgba(15,23,42,.45);
    danger buttons are red.
  * 390px: zero contrast failures, card padding uniform.
  * Both files parse with balanced braces and all 68 + 1 rules survive in the
    browser (nothing silently dropped by an invalid selector).

STILL OPEN (not touched)
------------------------
  * candidates.php truncates the email and role columns mid-word
    ("humper@gmail.", "Senior Produ..."). That is a table layout problem, not a
    theme one, and fixing it in a light-only stylesheet would leave dark mode
    broken. It needs a change in the shared table CSS.
  * Dead stylesheets: assets/light-theme-overhaul.css is not loaded by any page,
    and assets/theme-tokens.css and assets/components.css are stale duplicates
    of the files in assets/css/. Left in place; worth deleting once confirmed.
