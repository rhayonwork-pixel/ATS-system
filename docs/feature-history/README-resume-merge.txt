Candidate profile: one Resume / CV section, not two
===================================================

NO MIGRATION NEEDED
-------------------
Markup and CSS. No query, permission or feature changed.

THE DUPLICATE
-------------
The profile was rendering two Resume / CV blocks, which was my mistake in the
previous change. When I moved the resume to the top of the page I added a new
compact bar for the actions, but left the original documents section in place
underneath it. Both carried the same heading, and with no file uploaded both
said the same thing twice.

WHAT I DID
----------
Removed the first block entirely — markup and CSS, not hidden — and moved
everything it held into the second one:

    View (PDF)              the toggle that opens the viewer
    Download                the authenticated download link
    filename                original name as uploaded
    format and size         e.g. PDF · 284 KB
    version count           when more than one has been uploaded
    "text indexed"          when the resume text was extracted for search

The remaining section is now the single Resume / CV block. Its heading was
"Resume"; it is now "Resume / CV" so it matches what you labelled it.

Verified after the change: exactly one element carries the docs-title heading,
and zero references to the old bar survive in either the markup or the
stylesheet.

BEHAVIOUR KEPT
--------------
  * The viewer stays collapsed until View is pressed, and the iframe's src is
    still withheld until then — so opening a profile does not fetch the PDF, or
    write a download entry into the audit trail, for a document nobody looked
    at.
  * The button toggles between "View (PDF)" and "Hide", restoring its original
    label rather than assuming it.
  * Word documents get no View button, since browsers cannot render them
    inline. Download only, with the panel explaining why.
  * The version list, the "Current" badge, and the empty state are unchanged.

SECTION ORDER
-------------
Still matches the wireframe, checked programmatically:

    Header · Stepper · Resume / CV · Personal / Application / Recruiter Notes ·
    AI Analysis · Feedback | Suggest · Interview History · Activity

RESPONSIVE
----------
The heading and its actions sit on one row and wrap to two below 560px, where
View and Download become equal-width full-width buttons.

VERIFIED
--------
  * one Resume / CV section, zero .resume-bar references
  * php lexer: 57 files clean
  * branch-aware render: candidate.php balanced on every sampled path
  * all inline scripts parse under node --check
