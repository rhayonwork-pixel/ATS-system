Candidate profile: precision pass against the exact wireframe spec
=====================================================================

READ THIS FIRST -- THIS UPLOAD IS A THIRD, DIFFERENT SNAPSHOT
------------------------------------------------------------------
This zip diverges from the one I delivered last round. It is missing the
inline resume viewer and the versioned-document system entirely (its
Resume/CV section is an earlier, simpler View|Download toggle), and it has
no assets/css/candidate-refactor.css at all, though it does still link the
unexplained assets/light-theme-v3.css from two rounds ago. Its
storage/resumes/ folder also has different files in it than the copy I
worked on last time.

I worked on exactly what was uploaded, since the request was self-contained
and answerable on its own -- but there are now at least three versions of
this project that have each received different work from me across recent
rounds (my own sandbox, the copy from last round's inline-viewer conversion,
and this one). I have not attempted to merge them, since I cannot tell which
one is actually "current" on your end, and guessing wrong risks silently
discarding real work. Before the next request, it would genuinely help to
settle on ONE folder as the single working copy and always upload from that
one -- otherwise each round risks building on top of a different snapshot
than the last, which is very likely part of why things have felt like they
keep reverting.

Your real uploaded resumes and profile photos in this copy are confirmed
present and untouched.

WHAT WAS ALREADY CORRECT
----------------------------
The 7-row order and the underlying CSS Grid itself were already right --
verified programmatically before changing anything, not assumed:

    1. Candidate Profile header
    2. Resume/CV strip
    3. Personal Info | Application Info | Recruiter Notes  (real display:grid)
    4. AI Application Analysis
    5. Feedback for candidate | Suggest a Different Role  (real display:grid)
    6. Interview History
    7. History / Activity

Rows 3 and 5 were both already implemented with actual CSS Grid (not flexbox
standing in for it), and Row 3 was already using container queries -- which
react to the space actually left after the sidebar, a more correct measure
than the raw viewport width every other breakpoint on this page still uses.
None of that needed rebuilding.

WHAT DID NOT MATCH THIS ROUND'S MORE PRECISE SPEC, AND WHAT CHANGED
------------------------------------------------------------------------
Everything below is scoped to .candidate-profile-page specifically.
.card and .label are shared, app-wide classes used on the dashboard, the
candidates list, job cards, and the sidebar's own labels -- changing them
directly would have restyled the entire application, not just this page.

  1. Card shadow/border/padding -- were using this project's own earlier
     token-based values. Changed to the brief's exact literal numbers:
     box-shadow: 0 1px 3px rgba(0,0,0,.05); padding: 1.5rem; border-radius:
     12px; border-color from --border-default, whose current value
     (#E2E8F0) already matches the brief's own stated fallback exactly.

  2. Resume/CV strip padding -- was inheriting the full 1.5rem card padding
     on every side. Now padding: .75rem 1.5rem specifically, so it reads as
     the "distinct, slim" bar asked for, not another full-height card.

  3. Touch targets -- the View/Download buttons in Row 2 were min-height:38px,
     six pixels under the 44px minimum this round specifies. Fixed directly
     (this is a real, functional bug, not a preference): min-height and
     min-width both now 44px, everywhere, not only at the phone breakpoint
     that already had a `flex:1` treatment.

  4. Section headers -- "Personal information", "Application information",
     and the other card titles were rendering through this app's shared
     .label class: 11px, uppercase, muted grey -- the small "eyebrow" style
     used for things like sidebar section labels everywhere else in the app.
     The brief wants these bold and clearly visible (font-weight:600,
     var(--text-primary)). Rather than change .label globally and alter
     eyebrows across the whole application, a scoped override targets just
     these headers within the candidate profile's cards.

  5. Breakpoints -- Row 3 was using 600px/819px/820px thresholds; Row 5 was
     using a flat 900px cutoff on the raw viewport, inconsistent with Row 3's
     container-query approach. Both are retuned to the exact 768px/1024px
     the brief specifies, and Row 5 is now on the same @container strategy
     as Row 3 for consistency. The old, now-superseded breakpoint rules were
     REMOVED, not just shadowed by new ones added on top -- verified that
     .cprofile-grid-2 and .cprofile-grid-3 (the classes those old rules
     targeted) are not used anywhere else in the project before removing
     their rules, so nothing else could be affected.

VERIFIED
--------
  * php lexer: 60 files clean
  * independent paren-aware if/endif check: clean
  * branch-aware render, sampled across 65,536 combinations: all balanced
  * styles.css balances
  * confirmed no old, conflicting breakpoint rule survives for either row --
    checked by grep, not assumed after the edit
  * confirmed .cprofile-grid-2 / .cprofile-grid-3 have exactly one,
    unambiguous rule chain each, not two competing definitions relying on
    source-order to sort out which wins
  * all inline <script> blocks parse under node --check
  * your real uploaded resumes and profile photos confirmed untouched
