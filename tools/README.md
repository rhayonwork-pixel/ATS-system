# tools/ — the PDF job description parser

`extract_job_data.py` reads a PDF job description and prints the job-posting
fields as JSON. `app/Services/JobDescriptionParser.php` runs it as a child
process when a recruiter uploads a PDF on **job-post.php**.

It is **optional**. With no Python at all, the importer falls back to the
bundled PHP reader (`includes/pdf_extract.php`) and the feature still works —
less well on PDFs whose structure lives in layout rather than in words, and not
at all on job descriptions laid out as tables. This matters because the
shared-hosting deploy target (InfinityFree) has no Python and usually disables
`proc_open`.

## Setup (local / any host that can run a subprocess)

```bash
python -m venv tools/.venv
tools/.venv/Scripts/python.exe -m pip install -r tools/requirements.txt   # Windows
tools/.venv/bin/python -m pip install -r tools/requirements.txt           # macOS / Linux
```

That is the only path PHP looks for by default. Anything else goes in `.env`:

```
JD_PYTHON_BIN=C:/Python314/python.exe
```

The venv is git-ignored. Nothing else in this project uses Python, and nothing
about the PHP application changes when it is absent.

## Running it by hand

```bash
tools/.venv/Scripts/python.exe tools/extract_job_data.py path/to/jd.pdf --pretty
```

Output is always one JSON object, on stdout, whether it succeeded or not:

```json
{ "ok": true, "engine": "pdfplumber",
  "fields": { "title": "...", "responsibilities": "line\nline" },
  "fields_html": { "responsibilities": "<ul><li>…</li></ul>" },
  "confidence": { "title": 0.85 }, "warnings": [], "error_code": null }
```

Error codes: `ERR_FILE_FORMAT`, `ERR_UNREADABLE` (a scan — no text layer),
`ERR_MISSING_FIELDS` (text read, few fields recognised), `ERR_NO_ENGINE` (no
PDF library installed; PHP then falls back), `ERR_INTERNAL`.

## Engines

Tried in order, first one that imports wins:

| Engine | What it gives |
|---|---|
| `pdfplumber` | font size, weight, x/y **and table extraction** — the full set |
| `PyMuPDF` (`fitz`) | font size, weight, x/y; no table pass here |
| `pypdf` | plain text only; headings rest on wording alone |

## The rules live outside this script

Section headings, label synonyms, employment-type keywords, date patterns and
noise lines are all in **`config/job_parse_rules.json`**, which the PHP fallback
reads too. Teach a new heading there and both engines learn it. Adding it to one
of them only is a bug.

Keep every regex in that file to the subset PCRE and Python's `re` both
understand: no named groups, no `\p{...}`, no lookbehind.

## Two things worth knowing before you tune it

**Bullets are usually not characters.** Chrome's "Print to PDF", Word and
InDesign draw list markers as vector art or as a separate font run, so an
exported job description contains indented text and no `•` to find. The parser
treats indentation past the document's body margin as a list item, which is why
`list_baseline()` is computed once for the whole document rather than per
section — in a section where every line is a bullet, they all share a margin and
none would look indented.

**Wrapped lines are not new items.** A long bullet breaks across visual lines
with no marker and the same indentation. Lines that begin in lower case, or
follow a line that ran the full width of the text column, are joined back on.
Without that, every wrapped bullet becomes two half-sentences.

## Testing against new samples

There is no test runner in this project. The loop is:

```bash
tools/.venv/Scripts/python.exe tools/extract_job_data.py sample.pdf --pretty
```

Read the JSON, and when a field is wrong decide which of the three it is:

1. **a missing synonym** → add it to `config/job_parse_rules.json` (fixes both engines)
2. **a layout signal misread** → `heading_field()`, `block_items()` or `pick_title()`
3. **a genuinely ambiguous document** → leave it. The field arrives empty, the
   form says so, and the recruiter fills it in. A wrong value that looks
   confident is worse than a blank one, which is also why an ambiguous numeric
   date (`03/04/2026`) is handed back as text rather than guessed.
