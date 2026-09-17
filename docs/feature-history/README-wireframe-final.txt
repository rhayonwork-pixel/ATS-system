Candidate profile: now matches the wireframe exactly
====================================================

NO MIGRATION NEEDED
-------------------
Markup and CSS. No feature removed — the two workflow panels that are not in
the wireframe are still on the page and still work.

TWO THINGS DEVIATED
-------------------
1. THE STEPPER WAS RENDERED TWICE.
   The header card already draws a stepper inline (Applied — Screening —
   Interview — Offer — Hired). A second, boxed stepper sat below it as its own
   block, so the pipeline appeared twice in a row.

   Your wireframe shows a single "Candidate Profile" header, so the standalone
   one was removed along with its PHP preamble and its CSS. The inline stepper
   in the header stays.

   Verified: zero references remain to pipeline-stepper, $stepStages,
   $stageKeys, $currentIdx or $isRejected in the page, and the component's CSS
   block is gone rather than left orphaned.

2. STAGE REVIEWS SAT INSIDE THE FLOW.
   The collapsed "Stage reviews & actions" block was between AI Analysis and
   the Feedback/Suggest pair — a section your wireframe does not have, in the
   middle of sections it does.

   It has moved BELOW History / Activity. The seven wireframe sections now run
   uninterrupted, and the screening review, interview review and "Add to
   Employees" actions are still there, one click away at the foot of the page.

   Verified after the move: 3 forms, 3 CSRF tokens and 3 submit buttons still
   inside, with all three panels present.

FINAL ORDER
-----------
    1. Candidate Profile        header, with the inline stepper
    2. Resume / CV              View | Download
    3. Personal Info | Application Info | Recruiter Notes
    4. AI Application Analysis
    5. Feedback for candidate | Suggest a Different Role
    6. Interview History
    7. History / Activity
    ----
    8. Stage reviews & actions  collapsed, below the wireframe flow

Positions 1 to 7 are your wireframe, in order, with nothing between them.

A NOTE ON POSITION 5
--------------------
Before a candidate moves past Applied, that slot renders the "unlocks once this
candidate moves past Applied" line instead of the Feedback/Suggest pair. It is
the same slot in the same place, showing the alternate state — which matches
the wireframe's own "(if proceed to interview)" caption.

VERIFIED
--------
  * final order confirmed by locating each block and checking positions rise
  * php lexer: 57 files clean
  * branch-aware render: candidate.php balanced on every sampled path
  * all four inline scripts parse under node --check
  * no dead CSS left for the removed stepper
