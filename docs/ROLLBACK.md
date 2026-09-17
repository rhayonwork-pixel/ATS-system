# Rollback Guide

Every change in this reorganization is either a **move** (safe to
reverse — nothing else references the new path in a way that breaks if
you move it back) or an **addition** (safe to delete — nothing existing
depends on it). Nothing existing was rewritten in place except the two
files listed at the bottom.

## Full rollback (back to the exact pre-reorganization state)

1. Delete: `app/`, `config/`, `api/interview/`, `.env.example`, `.env`
   (if you created one), `docs/` (optional — it's documentation, not
   code).
2. Move `database/migrations/001`–`017` and `legacy_*.sql` back to
   `database/migration-*.sql` / `database/*.sql` at the project root of
   `database/`, using the reverse of `docs/FILE_MOVE_MAP.md`. Delete
   `database/migrations/018_webrtc_interview_signals.sql` (there is
   nothing to roll it back to — it's new).
3. In `database/schema.sql`, delete the `interview_signals` and
   `interview_ice_candidates` `CREATE TABLE` statements appended at the
   end of the file (everything from the `-- 010_create_interview_signals.sql`
   comment onward).
4. Delete `database/seeds/` (its contents are duplicated from
   `schema.sql`, not additional data).
5. Revert `includes/config.php` to inline the session/timezone/`DB_*`/`db()`
   block again instead of requiring `config/app.php` and
   `config/database.php` — or just keep requiring them; they are
   functionally identical to what was inlined before, so this step is
   optional even in a full rollback.
6. Revert `interviews.php`: remove the `EmailService` require + the
   `try { ... EmailService::sendInterviewEmail ... }` block added right
   after the interview `INSERT`.
7. Revert `interview-room.php`: remove the `data-remote-video` element,
   the four new `data-*` attributes on the room element (`data-is-host`,
   `data-token`, `data-csrf` — `data-room-code` etc. were already there),
   the whole WebRTC block (from the `real WebRTC peer connection` comment
   through `window.ACME_ROOM.onUnmount.push(stopWebRTC)`), and restore the
   original `setTimeout(...)` fake-connect inside the `[data-join-room]`
   handler.

## Partial rollback (keep the reorg, drop just the new WebRTC calling)

If the STUN-only limitation (see `docs/INTERVIEW_SETUP.md`) turns out to
be a problem before you can add a TURN server: revert only step 7 above.
The waiting room, notes, and scorecard all keep working exactly as they
did before this reorganization either way — none of those were touched.

## Database rollback specifically

`interview_signals` and `interview_ice_candidates` have no foreign keys
pointing *into* them from anywhere else, so:
```sql
DROP TABLE IF EXISTS interview_ice_candidates;
DROP TABLE IF EXISTS interview_signals;
```
is a complete, safe removal — nothing else in the schema references them.
