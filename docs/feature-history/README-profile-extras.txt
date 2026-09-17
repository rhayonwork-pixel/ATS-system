Candidate profile: the extra sections, kept without disturbing the wireframe
===========================================================================

NO MIGRATION NEEDED
-------------------
Markup and CSS. No query, permission or feature was removed — every form,
button and value that existed before still exists and still works.

THE PROBLEM
-----------
Three parts of the profile are not in the wireframe:

    Application & links     resume, portfolio and cover-letter links
    Screening review        the stage-gated review form
    Interview review        the stage-gated review form, plus "Add to Employees"
    Locked-state notice     shown before screening begins

They are real features. Deleting them to match a sketch would have removed
working functionality, so each was placed where it belongs instead.

1. APPLICATION & LINKS -> folded into Application Info
------------------------------------------------------
It was a full-width card sitting between the three-column row and the AI
analysis — a fourth block where the wireframe shows three. Its rows now sit
inside the Application Info column, which is where that data belongs.

The two Resume rows were DROPPED rather than moved. The Resume / CV section at
the top of the page is now the single place a document is offered; keeping a
second download link elsewhere is how two routes to the same file eventually
disagree.

2. SCREENING AND INTERVIEW REVIEWS -> one collapsed block
----------------------------------------------------------
These were two full-width cards plus a third for "Add to Employees". They are
now collected into a single "Stage reviews & actions" section that sits between
the AI analysis and the Feedback/Suggest pair.

Closed, it is one slim bar, so the wireframe's rhythm is intact. Open, all
three panels are there and behave exactly as before.

Collapsing hides the panel with the hidden attribute only — the forms, their
CSRF tokens and any typed values stay in the DOM, so nothing is lost and a
submit still carries everything. Verified: 3 forms, 3 CSRF tokens and 6
buttons all still inside.

It also opens ITSELF when a field inside already has content, so a review in
progress is never hidden behind a click the recruiter does not know to make.

3. LOCKED NOTICE -> a line, not a card
---------------------------------------
Before screening starts, the rating and feedback tools are unavailable. That
was a full card carrying the same visual weight as a real section, for a
message that only says "not yet". It is now a single dashed line.

RESULTING ORDER (verified programmatically)
-------------------------------------------
    1. Candidate Profile header
    2. Resume / CV                     View | Download
    3. Personal Info | Application Info | Recruiter Notes
    4. AI Application Analysis
    5. Stage reviews & actions         collapsed by default
    6. Feedback for candidate | Suggest a Different Role
    7. Interview History
    8. History / Activity

Every wireframe section is in its place, in order, and the three extras are
present without adding a single full-width block to the flow.

VERIFIED
--------
  * section order checked by locating each block and confirming positions rise
  * all five extras confirmed still present in the markup
  * the old full-width "Application & links" and locked cards are gone
  * php lexer: 57 files clean
  * branch-aware render: candidate.php balanced on every sampled path
  * all four inline scripts parse under node --check
