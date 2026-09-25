# Job posting module — enhancement specification

**Target file:** `job-post.php` (plus its assets and services — see *Files in scope*)
**Status:** ready for implementation
**Audience:** frontend developer working in this repository

---

## 0. Read this first — corrections to the original request

Four things in the request do not match the codebase. They are resolved here so
nobody implements against the wrong assumption.

| Request says | Reality | Decision |
|---|---|---|
| `job-posting.php` | The file is **`job-post.php`**. There is no `job-posting.php`. | Build in `job-post.php`. Do not create a second page. |
| Extract **company name** | Single-tenant system. A job has no company column; the company is one row in `settings` (`company_name`) and is the same for every posting. | **Drop company name as a parsed field.** Parse *department* instead, which is a real column with a real FK. If multi-tenant is coming, that is a separate schema change, not a parsing task. |
| "File upload capability already exists but needs enhancement" | Upload + parse + dropzone + loading states + error toasts **already shipped**. See `docs/feature-history/README-job-pdf-import.txt`. | This spec is a **delta**. Each requirement below states what exists and what changes. Do not rebuild working parts. |
| Parse "text input or file upload" | Only **file upload** exists. | R1.2 added it, and it was then removed by a later request. Upload (PDF/DOCX) and the structured form are the only two entry points. |

### One convention this module is currently breaking

`add_candidate.php` already solved "mark a field the system filled in", and its
comment is explicit:

> *No emoji: a bracketed `[Auto-filled]` badge, a tinted field and a left accent rule. The badge is aria-hidden and a visually hidden sentence carries the meaning instead, so a screen reader hears "Auto-filled from CV, please verify" rather than a decorative glyph.*
> — `add_candidate.php`, `markFilled()`

`job-post.php` shipped a **`✨ Auto-filled`** badge, which contradicted that
convention and was itself an emoji. **Both the badge and the tint have since
been removed from `job-post.php` entirely**: a parsed field is now visually
identical to a typed one and the status light (R4) is the only per-field
signal.

`add_candidate.php` is unchanged and still uses its `[Auto-filled]` tag,
because it has no status lights — removing the tag there would leave no review
cue at all. If that page gains the lights, the tag can go too and the two pages
will match.

### Files in scope

```
job-post.php                          page: markup, POST handling, field map
assets/js/job-post-import.js          dropzone, AJAX, auto-fill, toasts
assets/css/job-post-import.css        import UI styling
app/Services/JobDescriptionParser.php validation, storage, engine selection
tools/extract_job_data.py             Python parser (optional engine)
config/job_parse_rules.json           shared vocabulary for BOTH engines
includes/pdf_extract.php              PHP fallback reader + jd_parse_fields()
```

### Non-negotiable constraints

- **No package manager.** No Composer, no npm, no build step. Anything added is
  hand-written or already bundled. (`CLAUDE.md`)
- **Two engines, one vocabulary.** Python is optional; the PHP reader is the
  production path on shared hosting. Any new synonym, keyword or pattern goes in
  `config/job_parse_rules.json`, never in one engine only.
- **Server is authoritative.** Every client-side validation and sanitisation is
  a convenience; the same rule must exist server-side.
- **Progressive enhancement.** The page works with JavaScript off today. Keep it
  that way: no requirement below may make the form unusable without scripting.

---

## 1. R1 — Parsing and field extraction

### 1.1 Supported fields (the complete list)

These are the only fields the parser may fill. Each maps to a real column.

| Parsed key | Form input | Column | Type | Notes |
|---|---|---|---|---|
| `title` | `title` | `jobs.title` | VARCHAR(180) | required to save |
| `department` | `department_id` | `jobs.department_id` | FK | matched by name; unmatched → see 1.3 |
| `location` | `location` | `jobs.location` | VARCHAR(160) | |
| `employment_type` | `employment_type` | `jobs.employment_type` | ENUM | `full_time\|part_time\|contract\|internship` |
| `salary` | `salary_info` | `jobs.salary_info` | VARCHAR(255) | free text — ranges, currencies, "negotiable" |
| `description` | `description` | `jobs.description` | LONGTEXT | |
| `responsibilities` | `responsibilities` | `jobs.responsibilities` | TEXT | one item per line |
| `qualifications` | `qualifications` | `jobs.qualifications` | TEXT | one item per line |
| `skills` | `requirements` | `jobs.requirements` | TEXT | shown publicly as requirements |
| `preferred_skills` | `preferred_skills` | `jobs.preferred_skills` | TEXT | |
| `experience` | `experience_required` | `jobs.experience_required` | VARCHAR(255) | |
| `education` | `education_required` | `jobs.education_required` | VARCHAR(255) | |
| `benefits` | `benefits` | `jobs.benefits` | TEXT | migration 019 |
| `deadline` | `application_deadline` | `jobs.application_deadline` | DATE | ISO only; see 1.3 |

Derived, not parsed: `tags` (from skills), `applicant_limit`, `is_urgent`.

**Do not add fields without a migration.** A parsed value with nowhere to go is
dropped silently, which is how "benefits" behaved before migration 019.

### 1.2 ~~New: paste-text entry~~ — BUILT AND THEN REMOVED

> **Superseded.** This was implemented and then deprecated by request: the
> module now offers only the document upload and the structured form. The
> section below is kept for the reasoning, which still applies if it ever comes
> back. Do not implement it.

#### (historical) paste-text entry

Add a third way in, beside *Upload PDF* and *Fill in manually*: **Paste
description**, a textarea plus a "Read this text" button.

Architecture — pasted text has **no layout signals at all** (no font size, no
indentation, no tables), so the Python engine has no advantage over the PHP one:

- Route pasted text to **`jd_parse_fields()`** in `includes/pdf_extract.php`
  directly. Do **not** shell out to Python for it.
- Add `JobDescriptionParser::parseText(string $text): array` returning the exact
  same contract as `parse()` (`ok`, `engine`, `error_code`, `fields`,
  `fields_html`, `confidence`, `warnings`), with `engine => 'php:text'`.
- `upload-parser.php` accepts either `job_pdf` (file) or `job_text` (string), and
  answers with the same JSON shape. One endpoint, one response contract.
- Limits: 200 KB of text, rejected above that with `ERR_FILE_FORMAT`.
- Empty or under 120 characters → `ERR_UNREADABLE` (same threshold as a scan).

### 1.3 Incomplete and ambiguous data — the policy

Three tiers. This already exists for dates and departments; generalise it.

1. **Confident → fill.** The value goes in the input and the field's status
   light turns green. No badge, no tint — it looks exactly like a typed field.
2. **Found but unusable → hold back and show.** Do not put a value the field
   cannot represent. Leave the input empty and render the raw text as a hint
   beneath it: *"The PDF said '03/04/2026' — please set the date yourself."*
   Applies to: ambiguous numeric dates, a department name with no matching row,
   an employment type not in the enum, a value over the column length.
3. **Not found → leave empty.** No placeholder text pretending to be a value, no
   "N/A", no guessed default. The field simply stays empty and neutral (R4).

**A confident wrong value is worse than a blank field.** Every tier-2 and tier-3
outcome must be visible in the review banner count, which reports how many
fields were filled, not how many were attempted.

### 1.4 Acceptance criteria — R1

- [ ] A DOCX and a PDF of the same posting fill the same fields.
- [ ] `upload-parser.php` returns one JSON shape for every accepted file type.
- [ ] A file with no text layer returns `ERR_UNREADABLE`; a non-document returns `ERR_FILE_FORMAT`.
- [ ] No parsed value ever exceeds its column length (truncation happens before the form, not at the database).
- [ ] An unmatched department leaves the select empty **and** renders the PDF's wording as a hint.
- [ ] An ambiguous numeric date leaves the date input empty **and** shows the raw phrase.
- [ ] Three sample shapes (labelled sections, narrative prose, HR form table) each fill ≥ 1 field and never mis-file one section's text into another.
- [ ] Every new synonym or keyword added during tuning lives in `config/job_parse_rules.json` and is exercised by both engines.
- [ ] The no-JavaScript path fills the identical field set as the AJAX path.

---

## 2. R2 — Emoji removal and text sanitisation

### 2.1 Approach: hand-written Unicode ranges, not a library

No Composer, so no library. Write one function, once:

```php
// includes/config.php  (or a new includes/text_sanitize.php)
function strip_emoji(string $text): string
function normalise_bullets(string $text): string
function sanitize_job_text(string $text): string   // the one callers use
```

`sanitize_job_text()` = strip → normalise bullets → collapse whitespace → trim.

### 2.2 What is removed

Remove these ranges (PCRE with the `/u` flag; the codebase is UTF-8 throughout):

| Range | Contains |
|---|---|
| `U+1F300–U+1F9FF` | emoticons, pictographs, transport, supplemental symbols |
| `U+1FA00–U+1FAFF` | extended-A pictographs |
| `U+2600–U+27BF` | misc symbols and dingbats (**except** the bullets in 2.3) |
| `U+2B00–U+2BFF` | arrows and geometric extras |
| `U+FE0E`, `U+FE0F` | variation selectors |
| `U+200D` | zero-width joiner (emoji sequences) |
| `U+1F3FB–U+1F3FF` | skin-tone modifiers |
| `U+1F1E6–U+1F1FF` | regional indicators (flag pairs) |
| `U+2190–U+21FF` | arrows used decoratively in job ads |

Also strip: zero-width space (`U+200B`), BOM (`U+FEFF`), and any C0 control
except `\n` and `\t`.

### 2.3 What is preserved or converted

Deleting these would damage real job descriptions:

- **Convert to a plain hyphen at line start:** `•` `●` `▪` `◦` `‣` `⁃` `∙` `·` `➤` `➜` `✓` `✔` → `- `.
  These carry list structure. A leading `- ` is what the textareas and
  `job-detail.php` already treat as a list item.
- **Keep:** `–` `—` (salary ranges: *PHP 140,000 – 190,000*), `’` `‘` `“` `”`,
  `°`, `×`, `±`, `€ £ ¥ ₱ $`, `%`, `&`, `#`, `@`, `+`, `/`, accented Latin and
  any non-Latin script. **Never strip by "non-ASCII"** — that would destroy
  currency symbols and every non-English posting.
- **Mid-line** bullet glyphs become a space, not a hyphen, so *"Java • Python"*
  does not become *"Java - Python"* mid-sentence. Only a **line-leading** glyph
  becomes `- `.

### 2.4 Scope — where it runs, and where it must not

| Where | When | Authority |
|---|---|---|
| `posted_job_fields()` in `job-post.php` | on every POST | **authoritative** |
| `JobDescriptionParser` output | before fields reach the form | prevents emoji entering via a PDF |
| `job-post-import.js` | on paste and on auto-fill | immediate feedback only |

**Must not be applied to:** `phone_country_flag()` in `includes/config.php`,
which *generates* regional-indicator flag emoji for the apply form on purpose.
Scope the sanitiser to job posting fields. Do not make it a global output filter.

**Client-side rule:** sanitise on `paste` and on auto-fill, never on `input`.
Stripping a character as someone types moves the caret and feels broken.

### 2.5 Acceptance criteria — R2

- [ ] `strip_emoji()` has unit-style coverage for: an emoji-only string, an emoji sequence with ZWJ and skin tone, a flag pair, `✅ Must have 5 years`, and a string with none (returns identical input).
- [ ] *PHP 140,000 – 190,000* survives sanitisation unchanged (en dash intact).
- [ ] A line beginning `• Design and build services` becomes `- Design and build services`.
- [ ] `Java • Python • Go` becomes `Java Python Go`, not `Java - Python - Go`.
- [ ] Saving a posting whose textarea contains emoji stores clean text in MySQL, verified by querying the row, **with JavaScript disabled** (proves the server rule).
- [ ] A PDF containing emoji produces emoji-free field values in the form.
- [ ] `apply.php`'s phone country selector still renders its flags.
- [ ] Typing (not pasting) is never interrupted: caret position is unchanged after each keystroke in a field containing accented characters.

---

## 3. R3 — Clear All

### 3.1 Behaviour

A `Clear all fields` button in the form's action row, styled as a low-emphasis
destructive action (`.btn.ghost`, danger text colour — not a filled red button;
it sits beside Save/Submit/Publish and must not compete with them).

**Must clear:** every user-editable input, textarea and select in the job form;
all auto-filled states and badges; the review banner; all field hints from R1.3;
the `source_pdf` hidden token; the dropzone's filename display and its file
input; any visible toasts.

**Must NOT clear or touch:** the CSRF token; `job_id` when editing an existing
posting; the saved posting in the database; a PDF already attached to a **saved**
posting.

### 3.2 The orphaned upload problem

Clearing a form that has an unsaved parsed PDF leaves a file in
`storage/job_attachments/` that nothing references. Follow the existing
precedent: `prune_orphan_documents()` in `includes/documents.php` deletes
unattached uploads older than a day.

- Add the equivalent for job attachments: delete files in
  `storage/job_attachments/` with no matching `jobs.original_pdf_path`, older
  than 24 hours.
- Call it from the same place the existing prune is called, or on
  `upload-parser.php` requests at a low sample rate. Do **not** delete
  synchronously on Clear All — a token may still be in another open tab.

### 3.3 Confirmation, and a better safety net

A confirm dialog is required, but `window.confirm()` is not acceptable: it is
unstyled, unfocusable-from-CSS, and blocks the main thread. Reuse the project's
existing modal pattern (`.modal-overlay` / `.modal-box`, as in
`application-status.php`'s withdraw dialog), which already handles backdrop
click, Escape and focus return.

- Skip the dialog when the form is already empty — confirming nothing is noise.
- After clearing, show a toast with an **Undo** action for 10 seconds, restoring
  the previous values from an in-memory snapshot. Undo is what actually prevents
  data loss; a confirm dialog mostly trains people to click through.
- Focus moves to the first field; an `aria-live` region announces
  *"All fields cleared. Undo available for ten seconds."*

### 3.4 Acceptance criteria — R3

- [ ] Clear All empties every editable field, including selects and checkboxes, in one click.
- [ ] The confirm modal traps focus, closes on Escape and on backdrop click, and returns focus to the button.
- [ ] The dialog does not appear when every field is already empty.
- [ ] After clearing, no field shows an auto-filled badge, hint or tint, and the review banner is hidden.
- [ ] The hidden `source_pdf` token is empty afterwards; saving then creates a posting with `original_pdf_path IS NULL`.
- [ ] Editing a saved posting and clicking Clear All does **not** delete the saved row or its attachment; the page still knows which job it is editing.
- [ ] Undo restores every field to its pre-clear value, including the auto-filled states.
- [ ] Orphan sweep removes an unreferenced attachment older than 24 hours and leaves referenced ones alone.

---

## 4. R4 — Per-field status indicator (the "light")

### 4.1 The state model — resolving the request's open questions

> *"should the field collapse, show a placeholder, or display a neutral colour?"*

**Neutral colour. Never collapse, never insert placeholder text.** A field that
collapses when emptied makes the form jump under the cursor, and placeholder text
that looks like a value is the tier-3 mistake from R1.3. The field stays exactly
where it is and goes quiet.

Four states, one per field wrapper:

| State | Class | Indicator | Announced |
|---|---|---|---|
| **Empty** | *(none)* | Neutral dot, `--border-strong`. No badge. | nothing |
| **Filled by the user** | `.is-filled` | Solid dot, `--status-success-text`. No badge. | nothing |
| **Auto-filled, unreviewed** | `.auto-filled` | 2px `#3B82F6` border, pale blue wash, `Auto-filled` badge with an inline SVG tick (no emoji). Clears on focus. | "Auto-filled from your document, please verify" |
| **Error** | `.is-error` | Dot in `--status-danger-text`, message beneath the field | the message |

### 4.2 Rules

- **Colour never carries the meaning alone** (WCAG 1.4.1). Every non-empty state
  pairs its dot with either a badge, a message, or the visible content of the
  field itself. The dot is `aria-hidden`; a visually hidden sentence carries the
  state, exactly as `add_candidate.php:markFilled()` does.
- **Auto-filled → filled on edit.** Typing in an auto-filled field clears the
  badge and the tint and moves it to `.is-filled` — already the behaviour in
  `job-post-import.js`; keep it and extend it to the dot.
- **Filled → empty on clear.** Deleting the content of one or two fields must
  return those fields to Empty. This is R4's core ask and is currently missing:
  clearing an auto-filled field leaves the badge behind until a full reload.
- **Debounce state changes by 300ms** on `input`, and never announce per
  keystroke. Announce only auto-fill and error transitions.
- **Review mode marks parsed fields**, using `.auto-filled` and an SVG-tick
  badge (never an emoji). It clears on focus, via a delegated `focusin` +
  `pointerdown` listener on the form so server-rendered fields clear too.
  `.autofill-tag` in `assets/styles.css:2748+` belongs to `add_candidate.php`
  and is not used here.

### 4.3 Acceptance criteria — R4

- [ ] Every field in the job form renders a status dot with an accessible name that is not colour-dependent.
- [ ] Clearing one auto-filled field returns that field — and only that field — to Empty: dot neutral, badge gone, tint gone.
- [ ] Clearing two fields in a row leaves the rest of the form's states untouched.
- [ ] Typing into an auto-filled field moves it to Filled within 300 ms and does not re-announce on every keystroke.
- [ ] No field collapses, resizes or reorders when its state changes.
- [ ] The `✨` character appears nowhere in the module (grep-able check, and consistent with R2).
- [ ] Contrast of every dot against its background is ≥ 3:1; text ≥ 4.5:1, in both light and dark themes.
- [ ] A screen reader announces "Auto-filled from your PDF, please verify" on an auto-filled field and says nothing extra on an empty one.

---

## 5. R5 — UI/UX and responsive design

### 5.1 Already shipped — keep, do not rebuild

Drag-and-drop zone with hover/drag feedback; loading state during upload and
parse; three distinct error toasts (`ERR_FILE_FORMAT`, `ERR_UNREADABLE`,
`ERR_MISSING_FIELDS`) plus session-expiry; `prefers-reduced-motion` handling;
theme-token-driven colours with no hardcoded palette.

### 5.2 To build

**Layout and hierarchy**
- Group the form into labelled sections that match how a posting is read:
  *Role basics → The posting → Compensation and benefits → Listing options*.
  Each section is a landmark (`<section aria-labelledby>`), not a bare `<h2>`.
- One spacing scale, declared once on the page root as `--jp-*` custom
  properties (4 px base), like `application-status.css` does. No ad-hoc margins.
- Sticky action bar at the bottom on screens ≥ 1025 px, so Save/Submit/Publish
  are reachable from anywhere in a long form. It must not overlap the last field
  (add matching bottom padding).

**Responsive breakpoints** — match the rest of the app:

| Range | Layout |
|---|---|
| ≤ 768 px | single column; entry chooser stacks; action bar becomes full-width stacked buttons, not sticky; dropzone reduced padding |
| 769–1024 px | two-column `form-grid` pairs; chooser side by side |
| ≥ 1025 px | current two-column grid; sticky action bar |

- No horizontal page scroll at 320 px.
- Touch targets ≥ 44 × 44 px, including the status dots if they are interactive
  (prefer: they are not interactive).

**Accessibility**
- Every input has a `<label for>`; required fields marked with both a visible
  indicator and `<span class="sr-only">required</span>` (existing convention).
- Visible focus on every control: `outline: 2px solid var(--accent-primary); outline-offset: 2px`.
- The entry chooser is a real tablist: `role="tablist"`, `aria-selected`,
  arrow-key navigation, panels with `role="tabpanel"` and `aria-labelledby`
  (already implemented — verify it survives the redesign).
- One `aria-live="polite"` region for the whole page. Do not add a second.

**Errors and feedback**
- Field-level errors render beneath the field they belong to, not only as a
  toast, and set `aria-invalid="true"` and `aria-describedby`.
- Server validation failures ("A job title is required", "Please choose a
  department") currently surface as a page-level notice. Route them to the field.
- On submit failure, focus moves to the first invalid field.

### 5.3 Acceptance criteria — R5

- [ ] No horizontal scroll at 320, 390, 768, 1024 and 1440 px.
- [ ] Every control is ≥ 44 px tall at every breakpoint.
- [ ] Tab order follows visual order through the chooser, the dropzone and the form.
- [ ] Every interactive element shows a visible focus ring.
- [ ] Text contrast ≥ 4.5:1 and non-text ≥ 3:1, verified in **both** themes with alpha layers composited.
- [ ] A failed save focuses the first invalid field and describes the error beside it.
- [ ] The sticky action bar never covers content at its own breakpoint.
- [ ] `prefers-reduced-motion: reduce` disables the shimmer, toast slide and state transitions.
- [ ] The page still submits and imports correctly with JavaScript disabled.

---

## 6. Verification

There is no test runner in this project. The checks are:

```bash
php -l <file>                 # after every PHP edit — the project's only "test"
node --check <file.js>        # after every JS edit
python -c "import ast; ast.parse(open('tools/extract_job_data.py').read())"
```

Behaviour is verified by driving real Chrome over the DevTools Protocol against
`php -S localhost:8000` and the local MySQL — the harness used for the existing
import feature (see `docs/feature-history/README-job-pdf-import.txt`). Each
acceptance criterion above should map to one assertion.

Test corpus: at minimum one PDF per shape (labelled sections / narrative / HR
form table), one scanned PDF with no text layer, one non-PDF renamed `.pdf`, one
document containing emoji, and one pasted plain-text description.

**Clean up after yourself:** delete test job rows, uploaded fixtures in
`storage/job_attachments/` and test audit rows when a run finishes.

---

## 7. Open questions for the requester

1. **Emoji in the public listing** — should `job-detail.php` and `jobs.php`
   sanitise on *output* as well, to clean postings created before this change,
   or is a one-off migration over existing rows preferred?
2. **Undo window** — is a 10-second undo acceptable in place of a second
   confirmation step, or is a hard confirm required by policy?
3. **Multi-tenancy** — is "company name" a real requirement? If several
   companies will post, that is a schema change and should be scheduled before
   parsing work assumes it.
4. **Scanned PDFs** — out of scope here (a scan is reported, not read). Is OCR
   wanted later? It needs a service, which the shared-hosting target cannot run.
5. **Status dot placement** — beside the label (recommended) or inside the input
   on the trailing edge? The latter collides with select chevrons and date
   pickers.
6. **The sample ZIP** — still not received. Field-mapping accuracy cannot be
   tuned against real documents until it arrives; expect a follow-up pass on
   `config/job_parse_rules.json` once it does.
