Candidates page: toolbar restructure
====================================

NO MIGRATION NEEDED
-------------------
Markup structure and CSS only. Every query, filter, sort, link and permission
is unchanged.

PAGE HIERARCHY
--------------
    PAGE HEADER
      Candidates + live count + "View pipeline" (page-level action)

    STAGE TABS
      All / Applied / Screening / Interview / Offer / Hired / Rejected

    SEARCH & ACTION TOOLBAR   (one card)
      LEFT     search box with icon
      CENTRE   Role · HR/Recruiter · Rating · Sort
      RIGHT    Apply · Clear

    CANDIDATE TABLE

The search box previously sat as the first item of a plain filter row with no
visual grouping. It now has an intentional place: its own toolbar card,
positioned first, ahead of the secondary controls.

WHY THE BUTTONS CAN NO LONGER BE SQUEEZED
-----------------------------------------
Only the search is allowed to flex:

    .search-wrapper  { flex: 1 1 320px; min-width: 0 }   grows and shrinks
    .toolbar-filters { flex: 0 1 auto }                  keeps its size
    .toolbar-actions { flex-shrink: 0; margin-left:auto } never shrinks

So spare width goes to the search field, and Apply/Clear keep their full label
and padding at every width. Nothing is positioned manually — it is one flex row
with wrapping.

CONSISTENT CONTROLS
-------------------
Search input, every select and every toolbar button share:

    height 42px (46px on touch devices)
    border-radius 10px
    1px var(--line) border
    the same focus ring: green border + 3px soft outline

The search field has 38px of left padding for its icon and 14px on the right,
so the text is not crowded against the glyph. The icon is pointer-events:none,
so clicking it still focuses the input.

Two icons were added to the shared set in config.php — 'search' and 'filter' —
drawn in the same 24-box, stroke-only style as the existing ones.

REFLOW
------
    desktop      [ search .......... ][ role ][ recruiter ][ rating ][ sort ]  [ Apply ][ Clear ]
    <=1100px     [ search full width ]
                 [ role ][ recruiter ][ rating ][ sort ]  [ Apply ][ Clear ]
    <=820px      [ search full width ]
                 [ role ][ recruiter ]
                 [ rating ][ sort ]
                 [ Apply ][ Clear ]   (equal width, full row)
    <=520px      one control per row

Stage tabs scroll horizontally rather than wrapping into a ragged block, and
each tab is nowrap so a label never breaks.

ACCESSIBILITY
-------------
  * <form role="search"> around the toolbar
  * a visually hidden <label> and an aria-label on every control
  * type="search" on the input, so browsers offer a clear button
  * visible focus rings on inputs, selects and buttons
  * 46px targets on coarse pointers

DARK MODE
---------
The toolbar card, the input and the selects all have explicit dark
backgrounds and use the existing tokens. No hard-coded light colours.

HOUSEKEEPING
------------
The previous .candidate-filters / .filter-field rules were removed rather than
left behind, so there are not two competing layout systems in the stylesheet.

PRESERVED
---------
Type-ahead search (data-filter) with its empty state, SQL filtering by role,
recruiter and rating, all five sort orders, the stage scoping, the candidate
count, profile links, resume links, stage badges, ratings, and both themes.

VERIFIED
--------
  * php lexer: 52 files clean
  * branch-aware render: candidates.php balanced on every sampled path
  * every filter input name and data hook still present in the markup
  * no orphaned CSS left for the removed classes
