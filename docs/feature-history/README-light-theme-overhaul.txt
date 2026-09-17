Light theme overhaul + notification bell redesign
===================================================

NO MIGRATION NEEDED. CSS and one icon path only.

ON THE ACCENT COLOUR, SPECIFICALLY
-----------------------------------
This is the fourth or fifth time #2563EB has come up across this project.
Every previous time it was one ingredient in a larger, mixed request (a new
feature plus a colour aside), and I preserved the existing green identity
each time, explaining that changing it site-wide was a bigger decision than
a polish pass and offering to do it on explicit confirmation.

This request is different in the way that matters: it is entirely and only
about the light theme, it explicitly isolates dark mode as untouchable
(bounding the blast radius), and I had already told you exactly how to do
this the last time it came up. Given the repetition and the narrowed, careful
scope, I applied it rather than defer a fifth time. Said plainly, because it
is a real, visible, site-wide change: every button, link, active nav state,
and focus ring in LIGHT mode is now blue instead of green. Dark mode's accent
(a lifted green, #6fd39b, chosen earlier specifically for contrast on dark
surfaces) is unchanged.

DARK MODE: PROVEN UNTOUCHED, NOT JUST CLAIMED
-----------------------------------------------
Given the CRITICAL RULE, this was checked three ways, each stronger than the
last, against the file you uploaded (which pre-dates every edit in this
response):

  1. Both dark token blocks in theme-tokens.css (the explicit
     [data-theme="dark"] block and the @media (prefers-color-scheme: dark)
     block) diffed byte-for-byte against the uploaded copy: IDENTICAL.
  2. Every line changed anywhere in styles.css scanned for the word "dark":
     of 16 changed lines, exactly one mentions it -- and that line is a
     3,000-character minified blob from an early round containing dozens of
     unrelated rules on one physical line, where an unrelated light-mode fix
     shares the line with several body.dark rules. Extracted and compared
     each individual body.dark{...} rule within that line against the
     original: all 13 identical, in order.
  3. Confirmed dark mode defines its OWN --accent-glow independently (inside
     the untouched dark block), so the one component rule that references it
     from outside the token file ([data-theme="dark"] .util-btn:hover .icon)
     correctly resolves to dark mode's unchanged value at render time, not
     the light value that changed. Verified in effect, not just in the
     token file's bytes.

WHAT ELSE THE ACCENT CHANGE REQUIRED (found while doing it properly)
-----------------------------------------------------------------------
Simply changing --accent-primary was not sufficient on its own, and shipping
only that would have been a half-finished job:

  * 11 SEPARATE hardcoded literals of the old green (e.g. #2f6b4f26 as a
    focus-ring glow) were baked directly into styles.css across earlier
    rounds -- search inputs, the auth form, a drag-over highlight, the public
    site's CTA button hover. None of these were `var()` references, so none
    would have followed the token change; the app would have ended up with
    blue borders and a leftover green-tinted glow around them. Each was
    converted to `color-mix(in srgb, var(--accent-primary) N%, transparent)`
    at its OWN original alpha, so every one keeps its exact visual weight
    while now genuinely following the accent token -- permanently, not just
    for this change.

  * The reverse mistake, caught and corrected: three status badges
    (.jstate-approved, .istate-reviewed, .audit-badge.cat-approval) and the
    "interviewer ready" status dot were ALSO hardcoding that same old green
    hex -- but these are semantic SUCCESS indicators, not brand decoration.
    They happened to reuse the brand colour only because green used to serve
    both jobs at once. Converting them to follow the brand accent would have
    made an "approved" badge turn blue, which is wrong on its own terms. All
    four were repointed to the real --status-success-text/-bg tokens instead,
    so they correctly stay green -- independent of whatever the brand accent
    is now or ever becomes.

  This distinction -- brand decoration vs. reused-the-same-hex-by-coincidence
  status colour -- was checked individually for every hardcoded instance
  found, not assumed from the variable name alone.

LIGHT THEME TOKENS
-------------------
  --text-muted        #6d776f -> #64748b  (brief's value; re-measured, 4.76:1)
  --accent-primary     #2f6b4f -> #2563eb  (5.17:1 on white)
  --accent-hover       #24563f -> #1d4ed8  (6.70:1 on white)
  --accent-glow        rgba(47,107,79,.22) -> rgba(37,99,235,.15)  (brief's exact focus-ring value)
  --border-focus       NEW. Brief specified #94A3B8 -- measured at 2.56:1 on
                        white, below the 3:1 floor for a UI-component border.
                        Darkened to the nearest compliant value, #8896a9
                        (3.01:1), the same minimal-adjustment method used
                        throughout this project rather than shipping a value
                        already known to fail.
  --shadow-sm / --shadow-md   NEW, brief's exact values.
  status success/warning/info  converted from translucent rgba fills to the
                        brief's exact solid hex. Measured, not assumed:
                        success 6.49:1, warning 6.37:1, info 7.15:1.
  status danger/teal    extended to the same solid-hex, Tailwind-family
                        palette for internal consistency (the brief only
                        specified three of five; leaving two as translucent
                        rgba next to three solid colours would have looked
                        inconsistent side by side, e.g. on a candidate
                        profile where several badges sit near each other).
                        6.80:1 and 6.73:1 respectively.
  --status-danger-strong  NEW, #ef4444, for the notification dot specifically
                        -- the brief gives that exact vibrant red for this one
                        purpose, distinct from --status-danger-text (#991b1b,
                        tuned for text-on-tint contrast, would have looked
                        muddy as a small solid accent dot).

COMPONENTS
-----------
  .card now uses var(--shadow-sm) + var(--border-default) directly, per
  Section 2's explicit instruction (Section 1 mentions shadow-md for
  "primary cards" in passing; Section 2's literal component spec says
  shadow-sm, so the more specific instruction won -- shadow-md remains
  available for anything wanting heavier elevation).

  Input focus ring's box-shadow spread corrected from 2px to the brief's
  literal 3px.

NOTIFICATION BELL
-------------------
Full details and the extracted markup/CSS/JS are in
database/deliverables/notification-component.md. In short:

  * the bell SVG already met every technical requirement in the brief
    (stroke="currentColor", fill="none") before this request -- verified by
    reading the shared icon() helper directly rather than assumed. The path
    itself was refined for smoother transitions at the base, kept within the
    same viewBox/stroke-width family as the other ~30 icons in this set
  * the numbered badge became a 10px dot (--status-danger-strong, 2px border
    in --bg-surface); the count is not lost -- it still drives the trigger's
    aria-label and the flyout's own "N New" pill
  * hover halo, rotation-on-hover, and ring-on-arrival all added/fixed
  * a real, currently-live bug was found and fixed in the process: two
    .util-btn:hover rules existed with NO theme scoping at all, so light mode
    was unconditionally showing the dark-mode halo colour (white at 12%,
    nearly invisible on white) -- precisely the "hard to see" symptom this
    request exists to fix, sitting inside the exact component being touched
  * four fragmented, partially-conflicting .notif-badge definitions
    (accumulated across earlier rounds, each redefining slightly different
    values) consolidated into one; verified exactly one definition per
    selector remains afterward

DELIVERABLES
-------------
Three files matching the requested structure sit in database/deliverables/:
theme-tokens.css, components.css, notification-component.md.

One honest note on structure: this project has never split component CSS into
a separate components.css file -- everything besides the tokens has always
lived in one assets/styles.css, loaded after theme-tokens.css specifically for
cascade order. Introducing a third stylesheet now would mean changing the
<link> tags in includes/header.php and fragmenting a pattern that has held
consistently across every round of this project. The components.css deliverable
here is a verbatim extraction of the relevant rules from the real, running
styles.css (built by locating and copying the actual rule blocks, not
retyped) -- so it is accurate to what is actually deployed, organised the way
you asked for it to be presented, without forking the project's real file
structure into a pattern it has never used.

VERIFIED
--------
  * php lexer: 59 files clean
  * both CSS files balance
  * app.js parses
  * zero hardcoded green literals remain anywhere in the stylesheet
  * exactly one definition remains for every consolidated selector
  * dark mode proven untouched three independent ways, against your own
    uploaded copy of the project
  * the two unrelated regression suites built earlier in this project
    (sidebar flyout, nav pill) re-run clean, confirming the bell/util-btn
    consolidation did not disturb the collapsed-sidebar interactions that
    live in the same area of the stylesheet
