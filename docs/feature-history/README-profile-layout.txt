Candidate profile: reordered to the wireframe
=============================================

NO MIGRATION NEEDED
-------------------
Section order, one new layout row, and CSS. No query, permission or feature was
changed — everything that was on the page is still there.

THE ORDER NOW MATCHES THE WIREFRAME
-----------------------------------
    1.  Candidate Profile header
    2.  Pipeline stepper
    3.  Resume / CV bar          View | Download
    4.  Personal Info    |  Application Info  |  Recruiter Notes
    7.  AI Application Analysis  (full width)
    8.  Feedback for candidate   |  Suggest a Different Role
    10. Interview History
    11. History / Activity

Verified by locating each section in the rendered file and checking the
positions increase — not by reading it back.

WHAT MOVED
----------
  * Resume/CV was a large embedded viewer sitting between the review sections
    and Interview History. It is now a compact bar directly under the header,
    which is where the wireframe puts it.
  * Recruiter Notes was a full-width section on its own. It is now the third
    column beside Personal and Application info.
  * The two-column row became a three-column row.

Everything else kept its place; the wireframe's order already matched.

THE RESUME BAR
--------------
A single strip: file icon, name, format, size, version count, then View and
Download.

View expands the viewer inline beneath the bar. Download goes through the same
authenticated download.php controller as before.

One deliberate detail: the iframe's src is REMOVED on load and only set when
View is pressed. Without that, every visit to a candidate profile would fetch
the whole PDF whether or not anyone looked at it — wasteful on a list of
candidates being skimmed, and it would put a download.php audit entry in the
trail for a document nobody opened.

Word documents get no View button, since the browser cannot render them. The
bar shows Download only, and the panel beneath explains why — the same honest
handling as before, just moved.

WHERE THE WIREFRAME LEFT ROOM FOR JUDGEMENT
-------------------------------------------
Three existing sections are not in the wireframe: "Application & links", the
screening/interview review blocks, and the locked-state notice shown before
screening begins. Rather than delete working features to match a sketch, they
sit between the three-column row and the AI analysis, which is where they
already read naturally. Say the word if you want them folded in elsewhere or
removed.

RESPONSIVE
----------
    >= 820px (container)   three equal columns
    600-819px              two columns, recruiter notes full width beneath
    < 600px                single column, in document order

Container queries, so the layout reacts to the width left after the sidebar
rather than the viewport. Cards in the row are height:100% so their tops and
bottoms line up. The resume bar wraps its actions to full width under 560px.

VERIFIED
--------
  * php lexer: 57 files clean
  * branch-aware render: candidate.php balanced on every sampled path
  * tag counts balance: 140/140 div, 4/4 section
  * all three inline scripts parse under node --check
  * section order confirmed programmatically against the wireframe
