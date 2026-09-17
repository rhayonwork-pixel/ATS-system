CV parser: three defects found by testing a real CV
===================================================

NO MIGRATION NEEDED
-------------------
Fixes to includes/pdf_extract.php and parse-cv.php.

Testing the Add Candidate module against an actual CV (Dave Joseph, a Word
export) rather than a synthetic one exposed three faults. The first was
serious.

1. OBJECT STREAMS — THE READER RETURNED NOTHING AT ALL
-------------------------------------------------------
The CV extracted 0 characters. Not garbled: empty.

Cause: it is a PDF 1.7 using COMPRESSED OBJECT STREAMS (/Type /ObjStm) and a
cross-reference stream. In that format most objects — including every page —
live inside compressed streams rather than at the top level of the file. The
reader scanned for "N 0 obj ... /Type /Page" and found:

    /Type /Page occurrences outside object streams:  0
    /Contents occurrences outside object streams:    0

so it found no pages and returned an empty string.

This was not specific to this CV. PDF 1.5 introduced object streams in 2003 and
Word, Google Docs and LibreOffice all produce them routinely, so a large share
of real uploads would have silently failed — including job descriptions going
through the importer on job-post.php, which uses the same reader.

FIX: pdf_expand_object_streams() inflates each /ObjStm, reads its header of
"objnum offset" pairs, and merges the contained objects into the index before
page discovery runs. The same CV now extracts 3,207 characters cleanly.

Regression-checked: the existing sample job description and the resumes already
in assets/uploads still parse, and the job-description importer still finds its
title and salary lines.

2. ALL-CAPS VALUES WERE STORED AS SHOUTING
-------------------------------------------
CV headings are usually set in capitals. The parser returned:

    full_name      DAVE JOSEPH
    current_title  VIRTUAL ASSISTANT

which would then shout from every candidate list, table and email.

FIX: tidy_case() title-cases a value only when it is entirely uppercase, leaves
mixed case exactly as written, keeps small words lowercase inside a phrase, and
preserves genuine acronyms (IT, HR, QA, UX, BPO, CRM, VA). Now "Dave Joseph"
and "Virtual Assistant".

3. EXPERIENCE LEVEL WAS BANDED ON THE WRONG NUMBER
---------------------------------------------------
The CV says "over 2 years in the BPO industry and 1 year as a Virtual
Assistant" — roughly three years. The parser took the FIRST match, read "2", and
banded it as entry level.

FIX: scan every "N years" in the document and take the LARGEST, ignoring
anything above 40 (which is a year like 2024, not a duration). "over", "more
than", "nearly", "about" and a trailing "+" all mean the true figure is higher,
so they add one before banding. Now correctly "mid".

Also extended the heading blacklist so ABOUT ME, WORK EXPERIENCE, EDUCATIONAL
BACKGROUND, SKILLS and SOFTWARE / TOOLS can never be mistaken for a name.

RESULT ON THE REAL CV
---------------------
    full_name         Dave Joseph
    current_title     Virtual Assistant
    experience_level  mid
    education         Bachelor of Science in Information Technology
    email             (empty — not present in the CV)
    phone             (empty — not present in the CV)

The empty email and phone are correct, not a failure: this CV contains no
contact details anywhere. Worth knowing operationally — add_candidate.php
requires an email, so whoever adds this candidate has to supply it by hand. The
form will show the field as required and unfilled, which is the right outcome.

WHAT THIS DOES NOT FIX
----------------------
Image-only PDFs still cannot be read. There is no text layer to extract and no
OCR available in this stack — Tesseract is not installed and cannot be reached
from PHP here. The parser already detects this case and returns "Could not
extract data — please enter the details manually", which remains the honest
answer. If OCR matters, it needs a decision about installing Tesseract on the
server or calling an external service.

VERIFIED
--------
  * the real CV: 0 -> 3,207 characters extracted
  * all five field checks pass on it, including the two that must stay empty
  * existing PDFs re-checked for regression
  * php lexer: 55 files clean
