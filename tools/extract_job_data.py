#!/usr/bin/env python3
"""
extract_job_data.py — turn a PDF job description into structured job-posting fields.

Called by app/Services/JobDescriptionParser.php:

    python extract_job_data.py <file.pdf> [--rules config/job_parse_rules.json]
                                          [--max-pages 12] [--pretty]

It ALWAYS prints one JSON object to stdout and never anything else, so the PHP
side can decode stdout directly. Diagnostics go to stderr.

    {
      "ok": true,
      "engine": "pdfplumber",
      "fields":      { "title": "...", "responsibilities": "line\nline", ... },
      "fields_html": { "responsibilities": "<ul><li>...</li></ul>", ... },
      "confidence":  { "title": 0.9, ... },
      "warnings":    ["..."],
      "error_code":  null | "ERR_MISSING_FIELDS",
      "stats": { "pages": 2, "chars": 4210, "lines": 88 }
    }

Failures come back the same shape with "ok": false and one of:

    ERR_FILE_FORMAT  the bytes are not a PDF
    ERR_UNREADABLE   a valid PDF with no usable text layer (a scan)
    ERR_NO_ENGINE    no PDF library installed here -- the caller should fall
                     back to its own extractor, this is not a user-facing error
    ERR_INTERNAL     anything unforeseen; the message is logged, not shown

WHY PYTHON AT ALL
-----------------
The bundled PHP extractor (includes/pdf_extract.php) recovers text but not
layout: it cannot see that a line is bold, larger, indented or a bullet, and
those are exactly the signals that tell a heading from a sentence. pdfplumber
and PyMuPDF both expose per-word font size, font name and x/y, so headings and
list structure survive. When neither library is installed this script exits
with ERR_NO_ENGINE and PHP does the simpler job on its own.

The parsing RULES are not in this file. They live in config/job_parse_rules.json
and are shared with the PHP fallback, so a new synonym teaches both engines.
"""

from __future__ import annotations

import argparse
import html
import json
import os
import re
import sys
import unicodedata

MAGIC = b"%PDF-"
MAX_BYTES = 5 * 1024 * 1024

# Windows Python writes stdout in the console codepage (cp1252 here), which
# mangles an en dash in a salary range into a byte PHP's json_decode rejects.
# The caller reads these bytes as UTF-8, so say so explicitly.
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")


# --------------------------------------------------------------------------
# Output helpers. Everything exits through here so stdout stays valid JSON.
# --------------------------------------------------------------------------
def emit(payload: dict, pretty: bool = False, code: int = 0) -> None:
    sys.stdout.write(json.dumps(payload, ensure_ascii=False, indent=2 if pretty else None))
    sys.stdout.write("\n")
    sys.stdout.flush()
    sys.exit(code)


def fail(error_code: str, message: str, pretty: bool = False, engine: str | None = None) -> None:
    emit({"ok": False, "engine": engine, "error_code": error_code, "message": message,
          "fields": {}, "fields_html": {}, "confidence": {}, "warnings": [], "stats": {}},
         pretty, code=0)   # exit 0: the JSON carries the failure, a non-zero code would hide it


# --------------------------------------------------------------------------
# Line model
# --------------------------------------------------------------------------
class Line:
    """One visual line, with the layout signals that survive extraction."""

    __slots__ = ("text", "size", "bold", "x0", "x1", "page", "top")

    def __init__(self, text: str, size: float = 0.0, bold: bool = False,
                 x0: float = 0.0, page: int = 0, top: float = 0.0, x1: float = 0.0):
        self.text = text
        self.size = size
        self.bold = bold
        self.x0 = x0          # left edge: indentation, which is how most PDFs mark a list
        self.x1 = x1          # right edge: a full-width line is prose that wraps
        self.page = page
        self.top = top

    def __repr__(self) -> str:                                    # pragma: no cover
        return f"Line({self.text!r}, size={self.size:.1f}, bold={self.bold}, x0={self.x0:.0f})"


# --------------------------------------------------------------------------
# Extraction engines, best first
# --------------------------------------------------------------------------
def extract_pdfplumber(path: str, max_pages: int) -> tuple[list[Line], list[tuple[str, str]]]:
    import pdfplumber                                              # noqa: PLC0415

    lines: list[Line] = []
    pairs: list[tuple[str, str]] = []
    with pdfplumber.open(path) as pdf:
        for pno, page in enumerate(pdf.pages[:max_pages]):
            # HR "job description form" PDFs are tables: a label column and a
            # value column. Read those as pairs FIRST and then keep their words
            # out of the line flow -- grouping table cells by their y position
            # would otherwise glue "Position Title" to "Accounting Assistant"
            # and to whatever sat beside them.
            boxes = []
            try:
                for table in page.find_tables():
                    boxes.append(table.bbox)
                    for row in table.extract():
                        cells = [clean_text(c or "") for c in row]
                        cells = [c for c in cells if c]
                        if len(cells) == 2 and len(cells[0]) <= 48:
                            pairs.append((cells[0], cells[1]))
                        elif len(cells) == 1:
                            # A full-width row inside a table is a heading or a
                            # list item; put it back in the line flow.
                            lines.append(Line(cells[0], page=pno, top=0.0))
            except Exception:                                      # table finder is best-effort
                boxes = []

            def in_table(w: dict) -> bool:
                for x0, top, x1, bottom in boxes:
                    if float(w["x0"]) >= x0 - 1 and float(w["x1"]) <= x1 + 1                        and float(w["top"]) >= top - 1 and float(w["bottom"]) <= bottom + 1:
                        return True
                return False

            words = [w for w in page.extract_words(extra_attrs=["size", "fontname"],
                                                   use_text_flow=False) if not in_table(w)]
            if not words:
                continue
            # Group words into rows by their top coordinate. A tolerance is
            # needed because glyphs on one line rarely share an exact top.
            rows: dict[int, list[dict]] = {}
            for w in words:
                key = int(round(float(w["top"]) / 3.0))
                rows.setdefault(key, []).append(w)
            for key in sorted(rows):
                row = sorted(rows[key], key=lambda w: float(w["x0"]))
                text = " ".join(w["text"] for w in row)
                sizes = [float(w.get("size") or 0) for w in row]
                fonts = " ".join(str(w.get("fontname") or "") for w in row).lower()
                lines.append(Line(
                    text=text,
                    size=max(sizes) if sizes else 0.0,
                    bold=("bold" in fonts or "black" in fonts or "heavy" in fonts or "semib" in fonts),
                    x0=float(row[0]["x0"]),
                    x1=float(row[-1]["x1"]),
                    page=pno,
                    top=float(row[0]["top"]),
                ))
    return lines, pairs


def extract_pymupdf(path: str, max_pages: int) -> tuple[list[Line], list[tuple[str, str]]]:
    import fitz                                                    # noqa: PLC0415

    lines: list[Line] = []
    doc = fitz.open(path)
    try:
        for pno, page in enumerate(doc):
            if pno >= max_pages:
                break
            data = page.get_text("dict")
            for block in data.get("blocks", []):
                for ln in block.get("lines", []):
                    spans = ln.get("spans", [])
                    if not spans:
                        continue
                    text = " ".join(s.get("text", "") for s in spans)
                    sizes = [float(s.get("size") or 0) for s in spans]
                    fonts = " ".join(str(s.get("font") or "") for s in spans).lower()
                    # PyMuPDF sets bit 4 of span["flags"] for a bold face.
                    flagged = any(int(s.get("flags") or 0) & 16 for s in spans)
                    bbox = ln.get("bbox", [0, 0, 0, 0])
                    lines.append(Line(
                        text=text,
                        size=max(sizes) if sizes else 0.0,
                        bold=flagged or "bold" in fonts or "black" in fonts,
                        x0=float(bbox[0]),
                        x1=float(bbox[2]),
                        page=pno,
                        top=float(bbox[1]),
                    ))
    finally:
        doc.close()
    return lines, []


def extract_pypdf(path: str, max_pages: int) -> tuple[list[Line], list[tuple[str, str]]]:
    """Last resort: text only, no layout. Headings then rest on wording alone."""
    from pypdf import PdfReader                                    # noqa: PLC0415

    reader = PdfReader(path)
    lines: list[Line] = []
    for pno, page in enumerate(reader.pages[:max_pages]):
        for raw in (page.extract_text() or "").splitlines():
            lines.append(Line(text=raw, page=pno))
    return lines, []


ENGINES = (("pdfplumber", extract_pdfplumber), ("pymupdf", extract_pymupdf), ("pypdf", extract_pypdf))


def extract_lines(path: str, max_pages: int) -> tuple[list[Line], list[tuple[str, str]], str]:
    errors = []
    for name, fn in ENGINES:
        try:
            got_lines, got_pairs = fn(path, max_pages)
            return got_lines, got_pairs, name
        except ImportError:
            continue
        except Exception as exc:                                   # a library that is present but chokes
            errors.append(f"{name}: {exc}")
            continue
    if errors:
        raise RuntimeError("; ".join(errors))
    raise ModuleNotFoundError("no PDF library available")


# --------------------------------------------------------------------------
# Normalising
# --------------------------------------------------------------------------
def clean_text(s: str) -> str:
    s = unicodedata.normalize("NFKC", s)
    s = s.replace(" ", " ").replace("ﬁ", "fi").replace("ﬂ", "fl")
    s = re.sub(r"[ \t]+", " ", s)
    return s.strip()


def norm_key(s: str) -> str:
    """A heading/label reduced to comparable form: lowercase, no edge punctuation."""
    s = clean_text(s).lower()
    s = re.sub(r"^[^a-z0-9]+", "", s)
    s = re.sub(r"[^a-z0-9)]+$", "", s)
    s = re.sub(r"\s+", " ", s)
    return s.strip()


class Rules:
    def __init__(self, data: dict):
        self.sections: dict[str, list[str]] = data.get("sections", {})
        self.labels: dict[str, list[str]] = data.get("labels", {})
        self.employment: dict[str, list[str]] = data.get("employment_type", {})
        self.preferred_markers: list[str] = data.get("preferred_markers", [])
        self.required_markers: list[str] = data.get("required_markers", [])
        self.date_patterns: list[str] = data.get("date_patterns", [])
        self.bullets: list[str] = data.get("bullet_marks", [])
        self.noise: list[str] = data.get("noise_patterns", [])
        self.title_stopwords: list[str] = data.get("title_stopwords", [])
        self.limits: dict = data.get("limits", {})
        # Exact-match lookup: heading text -> field.
        self.section_index = {syn: field for field, syns in self.sections.items() for syn in syns}
        self.label_index = {syn: field for field, syns in self.labels.items() for syn in syns}

    def limit(self, key: str, default: int) -> int:
        return int(self.limits.get(key, default))


def is_noise(line: str, rules: Rules) -> bool:
    low = norm_key(line)
    if not low:
        return True
    return any(re.match(p, low, re.I) for p in rules.noise)


BULLET_RE = None     # built once from the rules, below
NUMBER_RE = re.compile(r"^\s*(\d{1,2}|[a-z]|[ivxlc]+)\s*[.)\]]\s+(.+)$", re.I)


def strip_bullet(line: str) -> tuple[str, bool]:
    """Return (text without its bullet glyph, was_a_bullet)."""
    m = BULLET_RE.match(line)
    if m:
        rest = m.group(1).strip()
        # A lone "-" inside a sentence is not a bullet; require something after it.
        if rest:
            return rest, True
    return line.strip(), False


def strip_number(line: str) -> tuple[str, bool]:
    m = NUMBER_RE.match(line)
    if m:
        # "2024" or "10:00" are not list markers; the regex already caps at 2 digits,
        # but guard against a year-like run followed by a dot.
        return m.group(2).strip(), True
    return line.strip(), False


# --------------------------------------------------------------------------
# Heading detection
# --------------------------------------------------------------------------
def heading_field(line: Line, rules: Rules, body_size: float) -> str | None:
    """
    Which field does this line open, if any?

    Wording decides first, because a synonym match is unambiguous. Layout is
    only used to REJECT a match that is clearly a sentence (very long), never
    to invent a section that the wording does not support -- a bold sentence in
    the middle of a paragraph would otherwise split the document at random.
    """
    text = clean_text(line.text)
    if not text or len(text) > rules.limit("max_heading_chars", 70):
        return None

    key = norm_key(text)
    if not key:
        return None

    # "Responsibilities:" and "Responsibilities" are the same heading.
    key = key.rstrip(":").strip()
    if key in rules.section_index:
        return rules.section_index[key]

    # "Key Responsibilities of the Role" — a heading that carries extra words.
    # Only accepted with a layout signal, so a sentence mentioning the word does
    # not hijack the section.
    looks_like_heading = (
        line.bold
        or (body_size and line.size >= body_size * 1.12)
        or text.isupper()
        or text.endswith(":")
    )
    if looks_like_heading and len(key.split()) <= 6:
        for syn, field in rules.section_index.items():
            if len(syn) >= 6 and (key.startswith(syn) or key.endswith(syn)):
                return field
    return None


LABEL_RE = re.compile(r"^\s*([A-Za-z][A-Za-z/&'’ .-]{1,38}?)\s*[:–—-]\s+(.+)$")


def inline_label(line: str, rules: Rules) -> tuple[str, str] | None:
    """Parse "Employment Type: Full-time" into ("employment_type", "Full-time")."""
    m = LABEL_RE.match(clean_text(line))
    if not m:
        return None
    key = norm_key(m[1])
    value = clean_text(m[2])
    if not value:
        return None
    field = rules.label_index.get(key)
    return (field, value) if field else None


# --------------------------------------------------------------------------
# HTML rendering — bullets and numbers become real lists, prose becomes <p>
# --------------------------------------------------------------------------
def render_html(items: list[tuple[str, str, int]]) -> str:
    """items is a list of (kind, text, depth); kind is 'ul' | 'ol' | 'p'."""
    out: list[str] = []
    open_tag: str | None = None
    for kind, text, _depth in items:
        esc = html.escape(text, quote=False)
        if kind in ("ul", "ol"):
            if open_tag != kind:
                if open_tag:
                    out.append(f"</{open_tag}>")
                out.append(f"<{kind}>")
                open_tag = kind
            out.append(f"<li>{esc}</li>")
        else:
            if open_tag:
                out.append(f"</{open_tag}>")
                open_tag = None
            out.append(f"<p>{esc}</p>")
    if open_tag:
        out.append(f"</{open_tag}>")
    return "".join(out)


def list_baseline(lines: list[Line]) -> float:
    """
    The left margin of ordinary body text in a group of lines, as the most
    common x0. Anything indented past it is a list item -- which is how most
    real PDFs mark a bullet, because the bullet GLYPH is frequently not text at
    all. Chrome, Word and InDesign all draw list markers as vector art or as a
    separate font run, so a PDF exported from any of them contains indented
    text and no bullet character to find.
    """
    counts: dict[int, int] = {}
    for line in lines:
        counts[int(round(line.x0))] = counts.get(int(round(line.x0)), 0) + 1
    if not counts:
        return 0.0
    # The mode, with the leftmost column winning a tie: body text is both the
    # most common and the least indented thing on a page.
    best = max(counts.items(), key=lambda kv: (kv[1], -kv[0]))
    return float(best[0])


def block_items(lines: list[Line], rules: Rules, page_right: dict[int, float] | None = None,
                baseline: float | None = None) -> list[tuple[str, str]]:
    """
    Turn a section's lines into typed items: ('ul' | 'ol' | 'p', text).

    Two things are happening here that a naive line-per-item split gets wrong:

      * A list item is recognised from an explicit marker OR from indentation
        past the section's own left margin (see list_baseline).
      * A long item wraps onto further visual lines with no marker and the same
        indentation. Those continuations are joined back on, so one bullet is
        one entry rather than two half-sentences. The signals are a line that
        begins in lower case, or a previous line that ran the full width of the
        text column -- the definition of wrapped prose.
    """
    page_right = page_right or {}
    usable = [l for l in lines if clean_text(l.text) and not is_noise(clean_text(l.text), rules)]
    if not usable:
        return []
    # The baseline is the DOCUMENT's body margin, passed in by the caller. Taking
    # it from the section alone would fail on the common case where every line
    # of the section is a bullet: they would all share the section's margin and
    # none would look indented.
    baseline = list_baseline(usable) if baseline is None else baseline

    items: list[tuple[str, str]] = []
    prev: Line | None = None
    prev_marked = False

    for line in usable:
        text = clean_text(line.text)
        body, was_bullet = strip_bullet(text)
        kind: str | None = "ul" if was_bullet else None
        marked = was_bullet
        if kind is None:
            body, was_number = strip_number(text)
            if was_number:
                kind, marked = "ol", True
        if kind is None:
            indented = line.x0 > baseline + 6
            kind = "ul" if indented else "p"

        # Continuation of the line before it?
        if prev is not None and items:
            same_column = abs(line.x0 - prev.x0) <= 2
            right_edge = page_right.get(line.page, 0.0)
            # "Full width" has to tolerate a ragged right margin: a wrapped
            # line ends wherever the last word fitted, several characters short
            # of the column edge. 8% of the column is about four characters.
            column = max(right_edge - baseline, 1.0)
            prev_full_width = bool(right_edge) and prev.x1 >= right_edge - column * 0.08
            starts_lower = body[:1].islower()
            prev_kind, prev_text, _prev_depth = items[-1]
            unterminated = not re.search(r"[.!?:;]$", prev_text)
            continuation = (
                not marked
                and same_column
                and (starts_lower or (prev_full_width and prev_kind == "p"))
                and (unterminated or prev_full_width)
            )
            if continuation:
                items[-1] = (prev_kind, f"{prev_text} {body}".strip(), items[-1][2])
                prev, prev_marked = line, False
                continue

        # Nesting depth, from how far past the body margin this line starts.
        # A sub-bullet in a PDF is just a more indented line; the marker glyph
        # is usually not even in the text (Chrome and Word draw it), so the
        # indent is the only evidence the item belongs under the one above.
        depth = 0
        if kind in ('ul', 'ol'):
            over = line.x0 - baseline
            if over > 6:
                depth = min(int(over // 18), 4)       # ~18pt per level, capped
        items.append((kind, body, depth))
        prev, prev_marked = line, marked
    return items


def items_to_text(items: list[tuple[str, str, int]]) -> str:
    """
    Plain text, one entry per line, nested items indented two spaces per level.

    No bullet character is written. This app stores job content as text and
    renders it with nl2br() or by splitting on newlines (see job-detail.php),
    so a stored "-" would be drawn a second time inside a real list item. The
    line break carries the item; the indent carries the hierarchy.
    """
    return "\n".join(("  " * depth) + text for _, text, depth in items).strip()


# --------------------------------------------------------------------------
# Field-specific readers
# --------------------------------------------------------------------------
def detect_employment_type(text: str, rules: Rules) -> str | None:
    low = " " + re.sub(r"\s+", " ", text.lower()) + " "
    # Order matters: an internship posting also says "full time hours".
    for field in ("internship", "part_time", "contract", "full_time"):
        for kw in rules.employment.get(field, []):
            if kw in low:
                return field
    return None


def normalise_date(value: str, rules: Rules) -> str | None:
    """A date inside a phrase, as YYYY-MM-DD, or None when there is none."""
    value = value.strip()
    if re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}", value):
        return value            # already ISO: re-reading it would find "26-10-31" inside it
    for pattern in rules.date_patterns:
        m = re.search(pattern, value, re.I)
        if m:
            return to_iso(m)
    return None


def detect_deadline(lines: list[Line], rules: Rules) -> str | None:
    """
    Find an application deadline, as YYYY-MM-DD when the date can be read
    without guessing, otherwise the raw phrase for a human to confirm.

    The search is anchored to a LINE THAT STARTS with a deadline word. An
    earlier version scanned the whole document for the substring "deadline",
    which happily matched "able to meet deadlines" inside a qualifications
    bullet and filed the next few words as the closing date. A wrong date
    written silently into a posting is worse than no date at all.
    """
    words = sorted(set(rules.labels.get("deadline", []) + rules.sections.get("deadline", [])),
                   key=len, reverse=True)
    for idx, line in enumerate(lines):
        key = norm_key(line.text)
        hit = next((w for w in words if key.startswith(w)), None)
        if not hit:
            continue
        # The value may sit after the label, or on the next line in a form.
        window = clean_text(line.text)
        tail = window[len(hit):] if len(window) > len(hit) else ""
        if not re.search(r"[0-9]", tail) and idx + 1 < len(lines):
            tail = clean_text(lines[idx + 1].text)
        for pattern in rules.date_patterns:
            m = re.search(pattern, tail, re.I)
            if m:
                return to_iso(m) or clean_text(m.group(0))
        tail = tail.strip(" :–—-")
        if tail:
            return tail[:120]
    return None


MONTHS = {m: i for i, m in enumerate(
    ["jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec"], start=1)}


def to_iso(m: re.Match) -> str | None:
    groups = [g for g in m.groups() if g]
    try:
        if len(groups) != 3:
            return None
        a, b, c = groups
        if a.isdigit() and len(a) == 4:                             # 2026-09-30
            y, mo, d = int(a), int(b), int(c)
        elif a.isalpha():                                           # September 30, 2026
            mo, d, y = MONTHS.get(a[:3].lower(), 0), int(b), int(c)
        elif b.isalpha():                                           # 30 September 2026
            d, mo, y = int(a), MONTHS.get(b[:3].lower(), 0), int(c)
        else:
            # Numeric: 30/11/2026 can only be day-first, and 11/30/2026 can only
            # be month-first, so one number over 12 settles it. 03/04/2026 is
            # genuinely ambiguous -- March 4th here, April 3rd there -- and is
            # refused rather than guessed, so the raw text reaches the human.
            first, second = int(a), int(b)
            y = int(c)
            if first > 12 and second <= 12:
                d, mo = first, second
            elif second > 12 and first <= 12:
                mo, d = first, second
            else:
                return None
        if not (1 <= mo <= 12 and 1 <= d <= 31):
            return None
        if y < 100 and len(str(c)) <= 2:      # "26" is 2026; a bare 31 is not a year
            y += 2000
        if y < 1000:
            return None
        return f"{y:04d}-{mo:02d}-{d:02d}"
    except (ValueError, TypeError):
        return None


def split_required_preferred(items: list[tuple[str, str]], rules: Rules
                             ) -> tuple[list[tuple[str, str]], list[tuple[str, str]]]:
    """
    One "Qualifications" list often mixes both kinds: "5+ years required" next
    to "AWS a plus". Entries carrying a preferred marker move across.
    """
    required, preferred = [], []
    for kind, text, depth in items:
        low = text.lower()
        if any(mk in low for mk in rules.preferred_markers):
            preferred.append((kind, text, depth))
        else:
            required.append((kind, text, depth))
    return required, preferred


TITLE_LEADIN_RE = re.compile(
    r"^(?:we(?:'|’)?re\s+hiring\s+(?:a|an)?|we\s+are\s+hiring\s+(?:a|an)?|now\s+hiring\s*[:-]?\s*(?:a|an)?"
    r"|hiring\s*[:-]\s*|job\s+opening\s*[:-]\s*|vacancy\s*[:-]\s*|position\s*[:-]\s*|open\s+role\s*[:-]\s*)\s*",
    re.I)


def tidy_title(text: str) -> str:
    """Drop an advertising lead-in so the title is the role, not the sentence."""
    cleaned = TITLE_LEADIN_RE.sub("", clean_text(text)).strip(" -–—:")
    return cleaned or clean_text(text)


def pick_title(lines: list[Line], rules: Rules, labelled: str | None) -> tuple[str, float]:
    if labelled:
        return tidy_title(labelled)[: rules.limit("max_title_chars", 180)], 0.95

    page1 = [l for l in lines if l.page == 0 and clean_text(l.text)]
    if not page1:
        return "", 0.0

    def usable(l: Line) -> bool:
        t = clean_text(l.text)
        k = norm_key(t)
        if not t or len(t) > 80 or len(k) < 3:
            return False
        if k in rules.section_index or k.rstrip(":") in rules.section_index:
            return False
        # A stopword only disqualifies a line it essentially IS ("Job
        # Description"). Matching anywhere would throw away "We're hiring a
        # Product Designer", where the useful title is the tail.
        if any(k == sw or (k.startswith(sw) and len(k) - len(sw) <= 3) for sw in rules.title_stopwords):
            return False
        if is_noise(t, rules):
            return False
        return not re.search(r"@|https?://|\bpage\b", t, re.I)

    candidates = [l for l in page1 if usable(l)]
    if not candidates:
        return "", 0.0

    # The biggest text on page one is the title in almost every template. Where
    # there is no size information (the pypdf path), fall back to the first
    # usable line, which is the same heuristic the PHP fallback uses.
    if any(c.size for c in candidates):
        best = max(candidates, key=lambda l: (round(l.size, 1), l.bold, -l.top))
        others = [c.size for c in candidates if c is not best]
        margin = best.size - (max(others) if others else 0)
        confidence = 0.85 if margin >= 1.0 else 0.6
        return tidy_title(best.text)[: rules.limit("max_title_chars", 180)], confidence
    return tidy_title(candidates[0].text)[: rules.limit("max_title_chars", 180)], 0.5


# --------------------------------------------------------------------------
# The parse
# --------------------------------------------------------------------------
SHORT_FIELDS = ("title", "department", "location", "employment_type", "salary",
                "experience", "education", "deadline")


def parse(lines: list[Line], rules: Rules, table_pairs: list[tuple[str, str]] | None = None) -> dict:
    body_size = 0.0
    sizes = sorted(round(l.size, 1) for l in lines if l.size)
    if sizes:
        body_size = sizes[len(sizes) // 2]                          # median = body text

    # The right-hand edge of the text column on each page. A line reaching it
    # is prose that wrapped, not a new paragraph.
    page_right: dict[int, float] = {}
    for l in lines:
        if clean_text(l.text):
            page_right[l.page] = max(page_right.get(l.page, 0.0), l.x1)

    baseline = list_baseline([l for l in lines if clean_text(l.text)])

    sections: dict[str, list[Line]] = {}
    labels: dict[str, str] = {}
    current: str | None = None
    preamble: list[Line] = []

    # A form's table rows are label/value pairs already -- the most reliable
    # signal in the whole document, so they are read before anything else and
    # the running text cannot overwrite them.
    for raw_label, raw_value in (table_pairs or []):
        field = rules.label_index.get(norm_key(raw_label).rstrip(":"))
        if field and clean_text(raw_value):
            labels.setdefault(field, clean_text(raw_value))

    for line in lines:
        text = clean_text(line.text)
        if not text or is_noise(text, rules):
            continue

        field = heading_field(line, rules, body_size)
        if field:
            current = field
            sections.setdefault(current, [])
            # "Benefits: health card, HMO" — a heading with its value attached.
            tail = text.split(":", 1)[1].strip() if ":" in text else ""
            if tail:
                sections[current].append(Line(tail, line.size, line.bold, line.x0, line.page, line.top, line.x1))
            continue

        pair = inline_label(text, rules)
        if pair:
            fname, value = pair
            # A label wins only once; later repeats are usually body copy that
            # happens to start with the same word.
            labels.setdefault(fname, value)
            if fname in SHORT_FIELDS:
                continue
        if current:
            sections[current].append(line)
        else:
            preamble.append(line)

    all_text = "\n".join(clean_text(l.text) for l in lines)

    # --- assemble ---------------------------------------------------------
    fields: dict[str, str] = {}
    fields_html: dict[str, str] = {}
    confidence: dict[str, float] = {}

    def put(name: str, items: list[tuple[str, str]], conf: float) -> None:
        text = items_to_text(items)
        if not text:
            return
        fields[name] = text
        fields_html[name] = render_html(items)
        confidence[name] = conf

    for name in ("responsibilities", "qualifications", "preferred_skills", "benefits", "description"):
        if name in sections:
            put(name, block_items(sections[name], rules, page_right, baseline), 0.9)

    # Qualifications that are really "preferred" move across, unless the PDF
    # already had its own preferred section.
    if "qualifications" in fields and "preferred_skills" not in fields:
        req, pref = split_required_preferred(
            block_items(sections.get("qualifications", []), rules, page_right, baseline), rules)
        if pref:
            put("qualifications", req, 0.85)
            put("preferred_skills", pref, 0.7)

    # A description was not headed in many narrative PDFs: the opening
    # paragraphs before the first heading are the description.
    if "description" not in fields and preamble:
        items = block_items(preamble[1:], rules, page_right, baseline)   # [0] is usually the title
        prose = [it for it in items if it[0] == "p" and len(it[1]) > 40]
        if prose:
            put("description", prose, 0.6)

    for name in SHORT_FIELDS:
        if name in labels:
            fields[name] = labels[name][: rules.limit("max_short_field_chars", 255)]
            confidence[name] = 0.9
        elif name in sections and sections[name]:
            joined = clean_text(" ".join(l.text for l in sections[name][:3]))
            if joined:
                fields[name] = joined[: rules.limit("max_short_field_chars", 255)]
                confidence[name] = 0.7

    title, tconf = pick_title(lines, rules, labels.get("title"))
    if title:
        fields["title"] = title
        confidence["title"] = tconf

    etype = detect_employment_type(labels.get("employment_type", ""), rules) \
        or detect_employment_type(all_text, rules)
    if etype:
        fields["employment_type"] = etype
        confidence["employment_type"] = 0.9 if labels.get("employment_type") else 0.6

    deadline = detect_deadline(lines, rules) or fields.get("deadline")
    if deadline:
        # A deadline read from a form's table row is still raw text ("Closing
        # Date | 30/11/2026"); put it through the same conversion as one found
        # in running text so both reach the form in one format.
        deadline = normalise_date(deadline, rules) or deadline
        fields["deadline"] = deadline
        confidence["deadline"] = 0.9 if re.fullmatch(r"\d{4}-\d{2}-\d{2}", deadline) else 0.5

    for key in ("location", "salary"):
        if key in fields:
            cap = rules.limit(f"max_{key}_chars", 255)
            fields[key] = fields[key][:cap]

    return {"fields": fields, "fields_html": fields_html, "confidence": confidence,
            "stats": {"lines": len(lines), "chars": len(all_text),
                      "pages": (max((l.page for l in lines), default=0) + 1)}}


# --------------------------------------------------------------------------
def main() -> None:
    ap = argparse.ArgumentParser(description="Extract job posting fields from a PDF.")
    ap.add_argument("pdf")
    ap.add_argument("--rules", default=None)
    ap.add_argument("--max-pages", type=int, default=12)
    ap.add_argument("--pretty", action="store_true")
    args = ap.parse_args()
    pretty = args.pretty

    path = args.pdf
    if not os.path.isfile(path):
        fail("ERR_FILE_FORMAT", "File not found.", pretty)
    if os.path.getsize(path) > MAX_BYTES:
        fail("ERR_FILE_FORMAT", "File is larger than 5MB.", pretty)
    with open(path, "rb") as fh:
        if fh.read(5) != MAGIC:
            fail("ERR_FILE_FORMAT", "Not a PDF (missing %PDF header).", pretty)

    rules_path = args.rules or os.path.join(
        os.path.dirname(os.path.abspath(__file__)), "..", "config", "job_parse_rules.json")
    try:
        with open(rules_path, "r", encoding="utf-8") as fh:
            rules = Rules(json.load(fh))
    except (OSError, ValueError) as exc:
        fail("ERR_INTERNAL", f"Rules file unreadable: {exc}", pretty)

    global BULLET_RE
    marks = "".join(re.escape(b) for b in rules.bullets if len(b) == 1)
    BULLET_RE = re.compile(rf"^\s*(?:[{marks}]|o)\s+(.+)$" if marks else r"^\s*[-*•]\s+(.+)$")

    try:
        lines, table_pairs, engine = extract_lines(path, args.max_pages)
    except ModuleNotFoundError:
        fail("ERR_NO_ENGINE", "No PDF library installed (pdfplumber, PyMuPDF or pypdf).", pretty)
    except Exception as exc:                                        # corrupt/encrypted file
        fail("ERR_UNREADABLE", f"The PDF could not be opened: {exc}", pretty)

    joined = "".join(clean_text(l.text) for l in lines)
    if len(joined) < rules.limit("min_text_chars", 120):
        fail("ERR_UNREADABLE",
             "This PDF has no text layer — it is probably a scan or an image.", pretty, engine)

    try:
        result = parse(lines, rules, table_pairs)
    except Exception as exc:                                        # pragma: no cover
        fail("ERR_INTERNAL", f"{type(exc).__name__}: {exc}", pretty, engine)

    warnings: list[str] = []
    error_code = None
    core = ("title", "responsibilities", "qualifications")
    found_core = [k for k in core if result["fields"].get(k)]
    if len(found_core) < 2:
        error_code = "ERR_MISSING_FIELDS"
        warnings.append("Only some fields could be identified — the rest need filling in by hand.")
    if not result["fields"].get("title"):
        warnings.append("No job title was recognised.")

    emit({"ok": True, "engine": engine, "error_code": error_code, "warnings": warnings, **result}, pretty)


if __name__ == "__main__":
    main()
