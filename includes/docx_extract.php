<?php
/**
 * DOCX text extraction for the resume preview, with no dependencies.
 *
 * A .docx file is a zip archive; the body text is word/document.xml. The
 * ZipArchive extension is not guaranteed on shared hosting (it is missing on
 * this project's XAMPP build), so this reads the zip's central directory by
 * hand and inflates that one entry with zlib's gzinflate(), which is.
 *
 * Output is plain structure, not formatting: headings, paragraphs and list
 * items, in document order. That is what a recruiter needs to skim a resume;
 * the Download button is there for the original layout.
 *
 * Legacy binary .doc files are not supported -- they are not zip archives.
 */

const DOCX_MAX_XML_BYTES = 20 * 1024 * 1024;   // refuse anything that inflates past this (zip bomb guard)
const DOCX_MAX_BLOCKS    = 600;
const DOCX_MAX_CHARS     = 80000;

/** Raw bytes of one entry in a zip archive, or null. Deflate and stored only; no Zip64. */
function docx_zip_entry(string $path, string $wanted): ?string {
    $size = @filesize($path);
    if (!$size || $size < 22) return null;
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    try {
        // End-of-central-directory record: in the last 22 bytes + up to 64KB of comment.
        $tailLen = (int)min($size, 22 + 65535);
        fseek($fh, $size - $tailLen);
        $tail = fread($fh, $tailLen);
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || strlen($tail) < $eocd + 22) return null;
        $e = unpack('vdisk/vcdDisk/vcountDisk/vcount/VcdSize/VcdOffset', substr($tail, $eocd + 4, 16));
        if ($e['cdOffset'] === 0xFFFFFFFF || $e['cdOffset'] + $e['cdSize'] > $size) return null;   // Zip64 / corrupt

        fseek($fh, $e['cdOffset']);
        $cd = fread($fh, $e['cdSize']);
        $pos = 0;
        for ($i = 0; $i < $e['count'] && $pos + 46 <= strlen($cd); $i++) {
            if (substr($cd, $pos, 4) !== "PK\x01\x02") return null;
            // Central directory header from offset 10: method, mod time, mod date, crc, sizes, lengths.
            $h = unpack('vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen', substr($cd, $pos + 10, 24));
            $local = unpack('Voff', substr($cd, $pos + 42, 4))['off'];
            // The zip spec says "/", but some older Windows tools write "\".
            $name = str_replace('\\', '/', substr($cd, $pos + 46, $h['nlen']));
            $pos += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
            if ($name !== $wanted) continue;

            if ($h['usize'] > DOCX_MAX_XML_BYTES || $h['csize'] > $size) return null;
            fseek($fh, $local);
            $lh = fread($fh, 30);
            if (strlen($lh) < 30 || substr($lh, 0, 4) !== "PK\x03\x04") return null;
            $l = unpack('vnlen/vxlen', substr($lh, 26, 4));
            fseek($fh, $local + 30 + $l['nlen'] + $l['xlen']);
            $data = $h['csize'] > 0 ? fread($fh, $h['csize']) : '';
            if ($h['method'] === 0) return $data;
            if ($h['method'] === 8) {
                $out = @gzinflate($data, DOCX_MAX_XML_BYTES);
                return $out === false ? null : $out;
            }
            return null;   // some other compression method
        }
        return null;
    } finally {
        fclose($fh);
    }
}

/**
 * Structured text of a .docx: a list of ['type' => 'h'|'p'|'li', 'text' => string].
 * Returns null when the file cannot be read as a Word document.
 */
function docx_extract_blocks(string $path): ?array {
    $xml = docx_zip_entry($path, 'word/document.xml');
    if ($xml === null || $xml === '') return null;

    $dom = new DOMDocument();
    // No network, no DTD loading; PHP 8 never expands external entities.
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS)) return null;
    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    $blocks = []; $chars = 0;
    foreach ($dom->getElementsByTagNameNS($W, 'p') as $p) {
        // Text boxes nest paragraphs inside a paragraph; each is read once, as itself.
        $text = '';
        $walk = function (DOMNode $n) use (&$walk, &$text, $p, $W) {
            foreach ($n->childNodes as $c) {
                if (!$c instanceof DOMElement) continue;
                if ($c->namespaceURI === $W && $c->localName === 'p' && $c !== $p) continue;
                if ($c->namespaceURI === $W) {
                    if ($c->localName === 't')   { $text .= $c->textContent; continue; }
                    if ($c->localName === 'tab') { $text .= "\t"; continue; }
                    if ($c->localName === 'br' || $c->localName === 'cr') { $text .= "\n"; continue; }
                }
                $walk($c);
            }
        };
        $walk($p);
        $text = trim(preg_replace("/[ \t]+/", ' ', $text));
        if ($text === '') continue;

        $type = 'p';
        $pPr = null;
        foreach ($p->childNodes as $c) { if ($c instanceof DOMElement && $c->localName === 'pPr') { $pPr = $c; break; } }
        if ($pPr) {
            $style = $pPr->getElementsByTagNameNS($W, 'pStyle')->item(0);
            $val = $style ? (string)$style->getAttributeNS($W, 'val') : '';
            if (preg_match('/^(heading|title)/i', $val)) $type = 'h';
            elseif ($pPr->getElementsByTagNameNS($W, 'numPr')->length) $type = 'li';
        }

        $blocks[] = ['type' => $type, 'text' => $text];
        $chars += strlen($text);
        if (count($blocks) >= DOCX_MAX_BLOCKS || $chars >= DOCX_MAX_CHARS) {
            $blocks[] = ['type' => 'p', 'text' => '… (preview truncated — download the file for the rest)'];
            break;
        }
    }
    return $blocks;
}
