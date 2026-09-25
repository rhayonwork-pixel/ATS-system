<?php
/**
 * DOCX -> job description text.
 *
 * A thin layer over includes/docx_extract.php, which already reads a .docx
 * without the zip extension (it walks the zip central directory by hand and
 * inflates word/document.xml with zlib) and already has the zip-bomb guards.
 * Nothing about that file is changed or duplicated here: docx_zip_entry() is
 * called for the XML and the constants it defines still bound the work.
 *
 * WHY A SECOND READER OVER THE SAME XML
 * -------------------------------------
 * docx_extract_blocks() serves the resume preview: paragraphs, headings and
 * list items in document order, which is what a recruiter skims. A job
 * description needs two things that preview does not:
 *
 *   1. TABLES AS LABEL/VALUE PAIRS. Every HR "job description form" is a
 *      two-column table -- "Position Title | Accounting Assistant". Read as a
 *      flat paragraph list those two cells become two unrelated lines and the
 *      pairing is lost, which is exactly the information the parser needs.
 *   2. LIST MARKERS. Word records that a paragraph is a list item (<w:numPr>)
 *      and draws the bullet itself, so the glyph is never in the text. The
 *      marker has to be put back as "- " for jd_parse_fields() to see a list.
 *
 * WHY DOCX IS THE EASY FORMAT
 * ---------------------------
 * A PDF has lost its structure by the time it is written -- headings, lists and
 * tables are all just positioned glyphs, which is why the PDF path infers them
 * from font size and indentation and does better with the Python engine. A
 * DOCX still says what everything is, so this runs in PHP on any host and is
 * more accurate than the PDF path will ever be.
 */

require_once __DIR__ . '/docx_extract.php';
require_once __DIR__ . '/text_sanitize.php';

/** Visible text of one <w:p>, following text boxes but not nested paragraphs. */
function docx_job_paragraph_text(DOMElement $p, string $W): string
{
    $text = '';
    $walk = function (DOMNode $n) use (&$walk, &$text, $p, $W): void {
        foreach ($n->childNodes as $c) {
            if (!$c instanceof DOMElement) continue;
            if ($c->namespaceURI === $W && $c->localName === 'p' && $c !== $p) continue;
            if ($c->namespaceURI === $W) {
                if ($c->localName === 't')   { $text .= $c->textContent; continue; }
                if ($c->localName === 'tab') { $text .= ' '; continue; }
                if ($c->localName === 'br' || $c->localName === 'cr') { $text .= "\n"; continue; }
            }
            $walk($c);
        }
    };
    $walk($p);
    return trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
}

/**
 * Read a .docx as job description text.
 *
 * Output matches what jd_parse_fields() expects: one line per item, list items
 * prefixed "- ", table rows as "Label: value", headings on their own line.
 *
 * Returns ['text' => string, 'error' => ?string, 'quality' => 'good'|'poor'],
 * the same shape pdf_extract_text() returns, so callers treat them alike.
 */
function docx_job_text(string $path): array
{
    if (!is_readable($path)) {
        return ['text' => '', 'error' => 'The uploaded file could not be read.', 'quality' => 'poor'];
    }
    if (!function_exists('gzinflate')) {
        return ['text' => '', 'error' => 'PHP zlib support is required to read Word files.', 'quality' => 'poor'];
    }

    $xml = docx_zip_entry($path, 'word/document.xml');
    if ($xml === null || $xml === '') {
        return ['text' => '', 'error' => 'That file is not a readable Word document.', 'quality' => 'poor'];
    }

    $dom = new DOMDocument();
    // No network and no DTD loading, matching docx_extract_blocks().
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS)) {
        return ['text' => '', 'error' => 'That Word document could not be parsed.', 'quality' => 'poor'];
    }
    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    $lines = [];
    $chars = 0;

    // Tables first, and their paragraphs are remembered so the body pass does
    // not read the same cells a second time as loose lines.
    $seen = new SplObjectStorage();
    foreach ($dom->getElementsByTagNameNS($W, 'tbl') as $table) {
        foreach ($table->getElementsByTagNameNS($W, 'tr') as $row) {
            $cells = [];
            foreach ($row->getElementsByTagNameNS($W, 'tc') as $cell) {
                $parts = [];
                foreach ($cell->getElementsByTagNameNS($W, 'p') as $p) {
                    $seen->attach($p);
                    $t = docx_job_paragraph_text($p, $W);
                    if ($t !== '') $parts[] = $t;
                }
                $value = trim(implode(' ', $parts));
                if ($value !== '') $cells[] = $value;
            }
            if (count($cells) === 2 && mb_strlen($cells[0]) <= 60) {
                // The shape of every HR job description form.
                $lines[] = rtrim($cells[0], ": \t") . ': ' . $cells[1];
            } else {
                foreach ($cells as $cell) $lines[] = $cell;
            }
            $chars += array_sum(array_map('strlen', $cells));
            if ($chars >= DOCX_MAX_CHARS) break 2;
        }
    }

    foreach ($dom->getElementsByTagNameNS($W, 'p') as $p) {
        if ($seen->contains($p)) continue;               // already read as a table cell
        $text = docx_job_paragraph_text($p, $W);
        if ($text === '') { $lines[] = ''; continue; }

        $pPr = null;
        foreach ($p->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === 'pPr') { $pPr = $c; break; }
        }
        // Word records that a paragraph is a list item (<w:numPr>) and draws
        // the marker itself, so no glyph is in the text. The marker is not put
        // back -- a job posting stores the item, not the bullet -- but the
        // NESTING is, as two spaces per <w:ilvl> level, so a sub-point still
        // reads as a sub-point. strip_list_markers() then snaps those to
        // regular levels alongside everything else.
        if ($pPr && $pPr->getElementsByTagNameNS($W, 'numPr')->length > 0) {
            $numPr = $pPr->getElementsByTagNameNS($W, 'numPr')->item(0);
            $ilvl = $numPr instanceof DOMElement
                ? $numPr->getElementsByTagNameNS($W, 'ilvl')->item(0) : null;
            $level = $ilvl instanceof DOMElement ? (int)$ilvl->getAttributeNS($W, 'val') : 0;
            if ($level > 0) $text = str_repeat('  ', min($level, 4)) . $text;
        }

        $lines[] = $text;
        $chars += strlen($text);
        if (count($lines) >= DOCX_MAX_BLOCKS || $chars >= DOCX_MAX_CHARS) break;
    }

    $text = sanitize_job_text(implode("\n", $lines));
    if (mb_strlen(trim($text)) < 40) {
        return ['text' => $text, 'error' => 'That Word document has no readable text.', 'quality' => 'poor'];
    }
    return ['text' => $text, 'error' => null, 'quality' => 'good'];
}
