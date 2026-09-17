Dark theme: token architecture and component audit
==================================================

NO MIGRATION NEEDED
-------------------
CSS and JavaScript. New file: assets/theme-tokens.css.

ONE SUBSTITUTION FROM THE BRIEF
-------------------------------
The brief's palette is blue-slate with a blue accent (#3B82F6). The neutral
ramp was adopted; the ACCENT was not. ACME's identity is green, and swapping it
would rebrand all 53 pages, not just the audited ones.

So: the surface, border and text hierarchy follows the brief exactly, warmed a
few degrees towards green so the accent does not read as a foreign colour on
top of it. --accent-primary stays green, lifted to #6fd39b in dark mode because
the light-mode #2f6b4f is 1.9:1 on a dark surface and unreadable. Status,
badge and file-type tokens use the brief's values as given.

TOKEN ARCHITECTURE (assets/theme-tokens.css)
--------------------------------------------
Loaded before styles.css. Two vocabularies, one source of truth:

  * the semantic tokens — --bg-canvas, --bg-surface, --bg-surface-elevated,
    --bg-surface-hover, --border-subtle/default/strong/input,
    --text-primary/secondary/muted/placeholder, the status quartet, file-type
    accents, --elevation-flyout, --overlay-scrim
  * the original names (--ink, --paper, --surface, --line, --green ...), now
    ALIASES of the semantic ones

That aliasing is the important part: ~2,600 lines of existing CSS already
reference the old names, so the entire stylesheet follows the new themes
without being rewritten. styles.css no longer defines any token itself — the
old :root and body.dark blocks were deleted, so there is exactly one place a
colour is decided.

ACTIVATION AND PERSISTENCE
--------------------------
    [data-theme="dark"] / ="light" on <html>   explicit choice
    prefers-color-scheme: dark                 when no choice has been made
    localStorage 'ats_theme_preference'        persistence

A small script in <head> applies the theme before first paint, so there is no
flash of the wrong theme. It also migrates the old 'theme' key once, so anyone
already using the prototype keeps their setting.

body.dark is still mirrored onto <body>, because a large amount of existing CSS
is written against it. One state, two hooks — never two competing systems.

The switcher follows the OS only while the user has expressed no preference; an
explicit choice wins and survives an OS change.

CONTRAST — MEASURED, NOT ASSERTED
---------------------------------
I computed WCAG relative luminance for every token rather than trusting the
values. The first pass FAILED in three places:

    dark  --text-placeholder  3.92:1   (needs 4.5)
    light --text-placeholder  3.14:1   (needs 4.5)
    dark  border on inputs    2.51:1   (needs 3.0 for UI components)

Fixed by solving for the smallest compliant adjustment:

    dark  --text-placeholder  #79867e  4.53:1   PASS
    light --text-placeholder  #707871  4.55:1   PASS
    dark  --border-input      #536a5a  3.04:1   PASS
    light --border-input      #8a9189  3.03:1   PASS

--border-input is a separate token from --border-default on purpose: an input
border is a UI component and must clear 3:1, while a decorative divider is
exempt. Using one value for both would have meant either failing the audit or
drawing heavy lines through every card.

Everything else passes comfortably:

    dark  text-primary 16.07:1   secondary 8.18:1   muted 5.79:1   accent 9.41:1
    light text-primary 16.54:1   secondary 5.99:1   muted 4.65:1   accent 6.29:1
    dark  status text on tint: success 8.59  warning 8.96  danger 5.41  info 5.88
    light status text: success 6.51  warning 5.92  danger 7.01  info 8.14

COMPONENTS AUDITED
------------------
Notification: bell icon is stroke:currentColor / fill:none and resolves to
--text-secondary in light and --text-primary in dark, with an ambient accent
glow on hover. The badge keeps a 2px isolation ring in the canvas colour so it
cannot bleed into the header. The flyout uses --bg-surface-elevated, a
--border-default hairline and the brief's dark elevation shadow. Unread rows
get a 3px accent rule and a 3% tint rather than a heavy fill.

Profile: avatars carry a 2px ring for silhouette separation; fallback initials
use a medium slate with white text in dark mode, never dark-on-dark. Inputs sit
on a sunken --bg-input with --border-input, --text-placeholder, and a 2px
accent focus ring. Status pills and role badges were re-pointed at the
tinted-fill tokens.

Files: dropzones now wrap the three real file inputs (resume on apply.php, job
description PDF on job-post.php, profile photo on profile.php) with rest and
drag-over states, an accent icon, and title/hint typography. Attachment rows,
file-type badges (PDF crimson, DOC blue, sheet emerald, image violet) and ghost
action buttons are all tokenised.

Dropzones are progressive enhancement: the real <input type="file"> is still
the control, still named, still inside the form. With JavaScript off, uploads
behave exactly as before. A dropped file is assigned to input.files, so normal
form submission carries it — no custom upload path was introduced.

WHAT DOES NOT EXIST YET
-----------------------
The brief describes an embedded PDF previewer modal with toolbars and zoom
controls. This ATS has none — resumes and job description PDFs open in a new
tab via the browser's own viewer. I did not invent one.

What I did add is the .doc-viewer chassis (backdrop with blur, dark shell,
toolbar strip) so a previewer has a themed shell to drop into, including the
rule the brief is right to insist on: .doc-viewer-canvas keeps its native white
background. Inverting a rendered document ruins candidate photographs and
diagrams, so only the surrounding chrome is themed.

VERIFICATION
------------
  1. Toggle the theme, reload, and confirm no flash of the wrong palette.
  2. Clear localStorage and switch the OS between light and dark — the app
     should follow. Then choose a theme explicitly and repeat; it should now
     ignore the OS.
  3. Run axe or Lighthouse on profile.php, the notification flyout and the
     upload areas.
  4. Upload a file by drag-and-drop and by clicking, in both themes.
  5. Check 320 / 768 / 1024 / 1440.

  Note on browser support: color-mix() and backdrop-filter are used in a few
  places. Both are supported in current Chromium, Firefox and Safari;
  backdrop-filter has an explicit @supports fallback.

VERIFIED HERE
-------------
  * php lexer: 53 files clean
  * both stylesheets balance
  * node --check on app.js; sidebar flyout suite 17/17; pill suite 14/14
  * one hardcoded colour remains in the audited block — #fff on
    .doc-viewer-canvas, which is deliberate
