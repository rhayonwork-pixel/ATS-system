Fix: interview-room.php printed its own source instead of running
=================================================================

NO MIGRATION NEEDED
-------------------
One-character fix plus a new guard.

THE SYMPTOM
-----------
Opening the interview room printed raw PHP source into the browser, followed
by:

    Warning: Undefined variable $accessDenied in ...interview-room.php on line 46
    Warning: Undefined variable $row in ...interview-room.php on line 50

THE CAUSE
---------
Line 1 of the file had become:

    <?phprequire_once __DIR__.'/includes/config.php';

The newline after <?php had been lost during one of my earlier scripted edits.
"<?php" is only an opening tag when the next character is whitespace (or the
end of the file). "<?phprequire_once" is not an opening tag at all, so PHP
treated everything from there as plain HTML and echoed it — which is why the
source appeared on screen.

Execution only resumed at the next valid opener on line 46,
<?php if($accessDenied): ?>, by which point none of the setup above had run.
That is exactly why $accessDenied and $row were undefined: the assignments were
printed rather than executed.

THE FIX
-------
The newline is restored:

    <?php
    require_once __DIR__.'/includes/config.php';

I swept every PHP file in the project for the same pattern. interview-room.php
was the only one affected.

WHY MY CHECKS MISSED IT
-----------------------
The lexer I use in place of a PHP runtime balances braces, strings, heredocs
and comments. A malformed open tag breaks none of those — the file is still
perfectly balanced, it simply never enters PHP mode. It reads as a template
problem, not a syntax error.

The checker now detects it:

    <?php  not followed by whitespace  ->  reported with the line number

Self-tested by reintroducing the fault into a copy: the check catches it and
exits non-zero. The whole project passes.

VERIFIED AFTER THE FIX
----------------------
  * every PHP file opens correctly and has no surplus ?> tags
  * interview-room.php: 52-file lexer pass, all sampled render paths balanced
  * the three-state structure is intact: live meeting, device check, control
    bar, call video, chat, notes, rail, participant grid, admission prompt and
    candidate panel each appear exactly once, all inside a <template>; only the
    entry screen is in the document on load

Nothing else changed.
