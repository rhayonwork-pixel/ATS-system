Candidate profile: 7-row grid and a progressive resume viewer
=============================================================

NO MIGRATION NEEDED
-------------------
Markup, CSS, JavaScript and two PHP endpoints. No table or column changed.
Every form on the page posts the same fields to the same handler as before.

WHAT WAS ACTUALLY BROKEN
------------------------
1. An extra </div> in candidate.php closed .candidate-profile-page after
   Row 2. Rows 3-7 were rendered outside it, so every rule written as
   ".candidate-profile-page .cprofile-grid-3" (the 3-column layout) never
   matched: Row 3 was one stacked column at every width, and rows 6-7 sat
   20px left of rows 3-5. The earlier "precision pass" notes in styles.css
   say the grid was verified; it was not reachable. The view is now one
   balanced container (net <div> depth 0, checked).

2. The resume viewer iframe re-downloaded the whole file on every view:
   download.php sent "max-age=0" with no ETag, and had no byte-range support,
   so nothing could appear until the last byte arrived.

THE LAYOUT
----------
Exactly the wireframe's seven rows, as the direct children of .cp-rows:
  1 Candidate Profile     name, contact, stage, AI score, rating, Advance /
                          Reject, progress stepper, jump links, and the stage
                          reviews folded into a closed <details> bar
  2 Resume/CV strip       View | Download; the viewer expands beneath
  3 Personal | Application | Recruiter notes
  4 AI Application Analysis
  5 Feedback | Suggest a different role   (locked card before screening)
  6 Interview History
  7 History / Activity

Breakpoints are @container queries on .page-container (content width, after
the sidebar), which lands the requested viewports as:
  1366 / 1440 / 1920  Row 3 three columns, Row 5 two columns
  768                 Row 3 two-up + notes full width, Row 5 stacked
  375 / 414           single column
Verified by measuring each row's cells in Edge at every one of those widths.

The stage reviews (screening review, interview review, Hired -> Employee)
predate the wireframe. They are real forms, so they were kept -- inside Row 1
as a native <details>, closed by default, with a "N to do" count. The old
JavaScript toggle it replaces opened itself on every page load (its "has a
value" check matched the hidden CSRF input).

Advance / Reject in Row 1 post to the existing 'stage' action, so the
no-skipping rule, the admin override and the audit entry all still apply.
Advance only ever moves one step. Reject asks for confirmation.

THE VIEWER
----------
Nothing is fetched until View is pressed.
  PDF   PDF.js 6.3.289 (legacy build, Apache-2.0), vendored in
        assets/vendor/pdfjs and loaded on first View. Saved as .js, not .mjs:
        some Apache setups serve .mjs with a MIME type browsers refuse for
        modules. Page 1 is drawn first; the rest render as they scroll near.
        If the module cannot load, it falls back to the browser's viewer.
  DOCX  document-preview.php returns the text as JSON (headings, paragraphs,
        list items) via includes/docx_extract.php, a small zip reader: this
        PHP build has no ZipArchive, but zlib's gzinflate() is always there.
  DOC   no preview (not a zip archive); Download only, and the strip says so.

download.php now answers byte ranges (206 / 416), ETag + Last-Modified with
304, releases the session before streaming, and writes one audit entry per
view (the request that starts at byte 0), not one per range request.

DELIBERATE DEPARTURES FROM THE BRIEF
------------------------------------
- Google Docs Viewer for DOCX: not used. It fetches the file itself from a
  public URL, which would mean making resumes reachable without a login and
  sending candidates' CVs to a third party. The server-side text preview
  covers the need without either.
- "Cache-Control: public, max-age=86400": not used. public lets shared
  caches (proxies, a Cloudflare tunnel) keep candidates' personal data, and
  a day-long max-age lets a logged-out user on a shared machine reopen a
  resume from cache with no login check. "private, no-cache" + ETag keeps
  the speed benefit (a repeat view is a ~200-byte 304, not the file) while
  every view still passes the session check.
- Images (<img loading=lazy srcset>): resumes are PDF / DOC / DOCX only
  (download.php validates the stored name against exactly those), so there
  is no image upload to render.

KNOWN LIMITS / TO CHECK BY HAND
-------------------------------
- Tested in Edge (Chromium) only; Firefox and Safari need a manual pass.
- PDF text is drawn to canvas, so it is not selectable in the preview.
- Older .cprofile-grid-* / .cprofile-documents rules in styles.css no longer
  match any markup; they are dead and can be removed in a cleanup pass.
