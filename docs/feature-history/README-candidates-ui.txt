Candidates page: readability and responsiveness
===============================================

NO MIGRATION NEEDED
-------------------
CSS and markup structure only. Every query, filter, sort, link and permission
is untouched.

WHY THE LETTERS WENT DOWNWARD
-----------------------------
This was my own regression from the earlier responsiveness pass. I had added:

    .table td, .table th        { overflow-wrap: anywhere }
    h1,h2,h3,h4,strong,p,span,a { overflow-wrap: anywhere }

"anywhere" permits a break at ANY character. With nine columns competing for
width, the browser did exactly that: once a column got narrow enough it broke
inside words, one letter per line. That is the vertical text you saw.

FIX
---
    overflow-wrap: break-word   - breaks only a word that genuinely cannot fit
    word-break: normal          - never split inside a word otherwise
    hyphens: none               - no invented hyphenation in names

"anywhere" is now used only where the text has no spaces to break at and would
otherwise overflow: email addresses in card mode, audit detail strings, chat
messages, and code. Eighteen heading and label rules across the app were
softened the same way, so this cannot resurface on other pages.

No font size was reduced to solve any of this.

TABLE: SCROLL RATHER THAN CRUSH
-------------------------------
The table is table-layout:fixed with a 940px minimum and scrolls inside its own
container between the card breakpoint and full desktop. Columns therefore keep
a workable width instead of compressing:

    avatar 56px | name 24% | role 18% | stage 120px | rating 110px
    | assigned 14% | applied 110px | resume 92px | actions 132px

Text that has no natural break point truncates with an ellipsis and keeps the
full value in a title attribute, so hovering still shows it:

    email address, job title, assigned recruiter

Stage badges, ratings, dates and buttons are white-space:nowrap, so a button
label can never be split across lines.

The scroll is confined to .table-wrap with matched negative margin and padding,
so the page itself never scrolls sideways.

BELOW 820px
-----------
Rows become stacked cards using the existing data-label attributes. In a card
there is room to wrap, so the truncation is switched off and the email is
allowed to break anywhere rather than being cut short. Names step up to 16px
because they are the heading of each card.

Below 420px the label/value pairs stack instead of sitting side by side.

FILTER BAR
----------
The controls are now labelled fields in a flex layout that reflows predictably:

    desktop   [ search .......... ][ role ][ recruiter ][ rating ][ sort ][ Apply ]
    <=900px   [ search full width ]
              [ role ][ recruiter ]
              [ rating ][ sort ]      [ Apply ]
    <=520px   one control per row, Apply full width

Each select has a visually hidden <label> and an aria-label, so screen readers
announce what each dropdown does. Controls are 40px tall, 44px on touch
devices.

DARK MODE
---------
All new rules use the existing tokens (--paper, --surface, --line, --ink,
--muted, --green). Row hover has an explicit dark variant. Nothing hard-codes a
light colour.

ACCESSIBILITY
-------------
  * visible focus rings on candidate links and buttons
  * 40px minimum touch targets, 44px form controls on coarse pointers
  * full text preserved in title attributes wherever it is truncated
  * .sr-only labels on every filter

PRESERVED
---------
Candidate loading, SQL search/filter/sort, stage and status badges, ratings,
assigned recruiter, resume links, profile navigation, the type-ahead search and
its empty state, permissions, and both themes. No candidate information was
removed from the listing.

VERIFIED
--------
  * php lexer: 52 files clean
  * branch-aware render: candidates.php balanced on every sampled path
  * no rule remaining anywhere in the stylesheet can split a heading, name or
    label mid-word
  * remaining fixed minimum widths all sit under 220px or have small-screen
    overrides, so none can force horizontal page scroll
