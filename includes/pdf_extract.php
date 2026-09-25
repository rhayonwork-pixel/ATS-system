<?php
/**
 * Acme ATS — PDF job description reader.
 *
 * There is no Composer or vendor/ directory in this project and XAMPP does not
 * ship a PDF library, so this is a self-contained reader built on zlib (which
 * PHP has by default). It handles the PDFs recruiters actually receive: Word,
 * Google Docs, LibreOffice, browser "Print to PDF" and InDesign exports.
 *
 * What it does:
 *   - walks the real indirect objects rather than scanning for "stream"
 *   - inflates FlateDecode content streams
 *   - resolves each page's /Resources /Font map and reads /ToUnicode CMaps, so
 *     subset CID fonts (hex strings like <0024>Tj) decode to real characters
 *   - rebuilds reading order from the text matrix, inferring word and line
 *     breaks from cursor movement
 *
 * What it cannot do: scanned/image-only PDFs have no text layer at all. Those
 * are reported back to the user so they can type the posting in manually.
 */

/** Average glyph advance as a fraction of the em, and the gap that counts as a space. */
const PDF_AVG_ADVANCE = 0.48;
const PDF_SPACE_GAP   = 0.15;
const PDF_LINE_GAP    = 0.40;

/** Index every "N 0 obj ... endobj" into [num => [dictBytes, rawStreamBytes|null]]. */
function pdf_index_objects(string $data): array {
    $objs = [];
    if (!preg_match_all('/(\d+)\s+(\d+)\s+obj\b/', $data, $m, PREG_OFFSET_CAPTURE)) return $objs;
    foreach ($m[0] as $i => $hit) {
        $num = (int)$m[1][$i][0];
        $start = $hit[1] + strlen($hit[0]);
        $endobj = strpos($data, 'endobj', $start);
        if ($endobj === false) $endobj = strlen($data);
        $slice = substr($data, $start, $endobj - $start);
        if (preg_match('/stream\r\n|stream\n|stream\r/', $slice, $sm, PREG_OFFSET_CAPTURE)) {
            $dict = substr($slice, 0, $sm[0][1]);
            $sStart = $start + $sm[0][1] + strlen($sm[0][0]);
            $end = strpos($data, 'endstream', $sStart);
            if ($end === false) $end = $endobj;
            $objs[$num] = [$dict, substr($data, $sStart, $end - $sStart)];
        } else {
            $objs[$num] = [$slice, null];
        }
    }
    return $objs;
}

/** Decompress a stream when we know how, otherwise null. */
function pdf_inflate(string $dict, ?string $raw): ?string {
    if ($raw === null) return null;
    if (strpos($dict, 'FlateDecode') !== false) {
        $out = @gzuncompress($raw);
        if ($out === false) $out = @gzinflate($raw);
        if ($out === false) $out = @gzinflate(substr($raw, 2));
        return $out === false ? null : $out;
    }
    if (strpos($dict, '/Filter') === false) return $raw;
    return null;
}

/**
 * PDF 1.5+ may pack most objects inside compressed object streams
 * (/Type /ObjStm), leaving nothing scannable at the top level — a file written
 * by Word or Google Docs often has zero "/Type /Page" outside them, so the
 * reader would find no pages and return empty text.
 *
 * Each ObjStm inflates to a header of "objnum offset" pairs followed by the
 * object bodies. This unpacks them and merges them into the index.
 */
function pdf_expand_object_streams(array $objs): array {
    $added = [];
    foreach ($objs as $num => $pair) {
        [$dict, $raw] = $pair;
        if (strpos($dict, '/ObjStm') === false) continue;

        $buf = pdf_inflate($dict, $raw);
        if ($buf === null || $buf === '') continue;
        if (!preg_match('/\/N\s+(\d+)/', $dict, $mN)) continue;
        if (!preg_match('/\/First\s+(\d+)/', $dict, $mF)) continue;

        $count = (int)$mN[1];
        $first = (int)$mF[1];
        $header = preg_split('/\s+/', trim(substr($buf, 0, $first))) ?: [];

        for ($i = 0; $i < $count; $i++) {
            if (!isset($header[2 * $i], $header[2 * $i + 1])) break;
            $objNum = (int)$header[2 * $i];
            $offset = (int)$header[2 * $i + 1];
            // Each body runs to the next object's offset, or to the end.
            $end = isset($header[2 * $i + 3])
                 ? $first + (int)$header[2 * $i + 3]
                 : strlen($buf);
            $body = substr($buf, $first + $offset, max(0, $end - ($first + $offset)));
            if ($body !== '' && !isset($objs[$objNum])) {
                $added[$objNum] = [$body, null];
            }
        }
    }
    return $added ? ($objs + $added) : $objs;
}

/** Given the offset of "<<", return the full dictionary including nested ones. */
function pdf_balanced_dict(string $data, int $i): string {
    if (substr($data, $i, 2) !== '<<') return '';
    $depth = 0; $j = $i; $len = strlen($data);
    while ($j < $len - 1) {
        $two = substr($data, $j, 2);
        if ($two === '<<') { $depth++; $j += 2; continue; }
        if ($two === '>>') { $depth--; $j += 2; if ($depth === 0) return substr($data, $i, $j - $i); continue; }
        $j++;
    }
    return substr($data, $i);
}

/** Value for /Key — a nested dictionary, an array, a reference, or a bare token. */
function pdf_dict_entry(string $dict, string $key): string {
    if (!preg_match('/\/' . preg_quote($key, '/') . '\s*/', $dict, $m, PREG_OFFSET_CAPTURE)) return '';
    $i = $m[0][1] + strlen($m[0][0]);
    if (substr($dict, $i, 2) === '<<') return pdf_balanced_dict($dict, $i);
    if (substr($dict, $i, 1) === '[') {
        $j = strpos($dict, ']', $i);
        return $j === false ? '' : substr($dict, $i, $j - $i + 1);
    }
    $rest = substr($dict, $i);
    if (preg_match('/^\d+\s+\d+\s+R/', $rest, $mm)) return $mm[0];
    if (preg_match('/^\/?[^\s\/>\[\]]+/', $rest, $mm)) return $mm[0];
    return '';
}

/** Parse a /ToUnicode CMap into [code => character] plus the code byte width. */
function pdf_parse_tounicode(string $buf): array {
    $map = [];
    $width = 2;
    if (preg_match('/begincodespacerange(.*?)endcodespacerange/s', $buf, $cs)
        && preg_match('/<([0-9A-Fa-f]+)>/', $cs[1], $f)) {
        $width = max(1, (int)(strlen($f[1]) / 2));
    }
    $toStr = static function (string $hex): string {
        if ($hex === '' || strlen($hex) % 2) return '';
        $bin = @hex2bin($hex);
        if ($bin === false) return '';
        $s = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
        return is_string($s) ? $s : '';
    };
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $buf, $blocks)) {
        foreach ($blocks[1] as $blk) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $blk, $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $p) $map[hexdec($p[1])] = $toStr($p[2]);
            }
        }
    }
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $buf, $blocks)) {
        foreach ($blocks[1] as $blk) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $blk, $rows, PREG_SET_ORDER)) {
                foreach ($rows as $r) {
                    $lo = hexdec($r[1]); $hi = hexdec($r[2]); $base = $r[3] === '' ? 0 : hexdec($r[3]);
                    $hi = min($hi, $lo + 65535);
                    for ($c = $lo; $c <= $hi; $c++) {
                        $map[$c] = $base ? mb_chr($base + ($c - $lo), 'UTF-8') : '';
                    }
                }
            }
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]/s', $blk, $rows, PREG_SET_ORDER)) {
                foreach ($rows as $r) {
                    $lo = hexdec($r[1]);
                    if (preg_match_all('/<([0-9A-Fa-f]*)>/', $r[3], $items)) {
                        foreach ($items[1] as $k => $item) $map[$lo + $k] = $toStr($item);
                    }
                }
            }
        }
    }
    return [$map, $width];
}

/** Build "resource name => [unicodeMap, byteWidth]" for one page's fonts. */
function pdf_font_maps(array $objs, string $resources): array {
    $out = [];
    $blob = pdf_dict_entry($resources, 'Font');
    if ($blob === '') return $out;
    if (strncmp($blob, '<<', 2) !== 0) {
        if (!preg_match('/(\d+)\s+\d+\s+R/', $blob, $m) || !isset($objs[(int)$m[1]])) return $out;
        $blob = $objs[(int)$m[1]][0];
    }
    if (!preg_match_all('/\/([A-Za-z0-9#+._-]+)\s+(\d+)\s+\d+\s+R/', $blob, $fonts, PREG_SET_ORDER)) return $out;
    foreach ($fonts as $f) {
        $fo = $objs[(int)$f[2]] ?? null;
        if (!$fo) continue;
        $fd = $fo[0];
        $width = strpos($fd, '/Type0') !== false ? 2 : 1;
        $map = [];
        if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $fd, $tu) && isset($objs[(int)$tu[1]])) {
            $buf = pdf_inflate($objs[(int)$tu[1]][0], $objs[(int)$tu[1]][1]);
            if ($buf !== null) [$map, $width] = pdf_parse_tounicode($buf);
        }
        $out[$f[1]] = [$map, $width];
    }
    return $out;
}

function pdf_decode_hex(string $hex, array $map, int $width): string {
    $hex = preg_replace('/\s+/', '', $hex);
    if (strlen($hex) % 2) $hex .= '0';
    $step = $width * 2;
    $out = '';
    for ($i = 0; $i < strlen($hex); $i += $step) {
        $chunk = substr($hex, $i, $step);
        if ($chunk === '') break;
        $code = hexdec($chunk);
        if ($map) {
            $out .= $map[$code] ?? '';
        } elseif ($code >= 32 && $code < 0x3000) {
            $out .= mb_chr($code, 'UTF-8');
        }
    }
    return $out;
}

function pdf_unescape(string $s): string {
    $s = preg_replace_callback('/\\\\(\d{1,3})/', static fn($m) => chr(octdec($m[1]) % 256), $s);
    return strtr($s, ['\\n' => "\n", '\\r' => "\n", '\\t' => ' ', '\\(' => '(', '\\)' => ')', '\\\\' => '\\']);
}

function pdf_decode_literal(string $s, array $map, int $width): string {
    $s = pdf_unescape($s);
    if ($width === 2 && $map) return pdf_decode_hex(bin2hex($s), $map, 2);
    if ($map) {
        $out = '';
        for ($i = 0; $i < strlen($s); $i++) {
            $code = ord($s[$i]);
            $out .= $map[$code] ?? $s[$i];
        }
        return $out;
    }
    return $s;
}

/**
 * Walk one content stream and rebuild its text in reading order.
 *
 * $spaceGap is the cursor movement, as a fraction of the em, that counts as a
 * word break. It is a parameter rather than a constant because the right value
 * depends on the writer: a PDF that positions every glyph individually (Chrome
 * and InDesign both do) leaves letter-sized gaps everywhere, and at the default
 * threshold every one of them becomes a space — "S e n i o r". pdf_extract_text()
 * detects that and re-runs with a wider gap.
 */
function pdf_content_text(string $buf, array $fonts, float $spaceGap = PDF_SPACE_GAP): string {
    $token = '/
        \/(?P<font>[A-Za-z0-9\#+._-]+)\s+(?P<size>[\d.]+)\s+Tf
      | (?P<a>-?[\d.]+)\s+(?P<b>-?[\d.]+)\s+-?[\d.]+\s+-?[\d.]+\s+(?P<mx>-?[\d.]+)\s+(?P<my>-?[\d.]+)\s+Tm
      | (?P<tx>-?[\d.]+)\s+(?P<ty>-?[\d.]+)\s+(?:Td|TD)
      | \[(?P<arr>(?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ
      | \((?P<lit>(?:[^()\\\\]|\\\\.)*)\)\s*(?:Tj|\')
      | <(?P<hex>[0-9A-Fa-f\s]*)>\s*(?:Tj|\')
      | (?P<nl>T\*)
    /sx';
    $flags = PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL;
    if (!preg_match_all($token, $buf, $matches, $flags)) return '';

    $parts = [];
    $font = [[], 1];
    $size = 12.0; $scale = 1.0;
    $x = null; $y = null; $prevY = null; $pen = null;

    $em = static function () use (&$size, &$scale) { return max(0.01, $size * $scale); };

    $place = static function (string $txt) use (&$parts, &$pen, &$prevY, &$x, &$y, $em, $spaceGap) {
        if ($txt === '') return;
        if ($prevY !== null && $y !== null && abs($y - $prevY) > $em() * PDF_LINE_GAP) {
            $parts[] = "\n";
        } elseif ($pen !== null && $x !== null && ($x - $pen) > $em() * $spaceGap) {
            $parts[] = ' ';
        }
        $parts[] = $txt;
        if ($x !== null) { $x += mb_strlen($txt) * $em() * PDF_AVG_ADVANCE; $pen = $x; }
        $prevY = $y;
    };

    foreach ($matches as $m) {
        if (($m['font'] ?? null) !== null) {                       // /F1 11 Tf
            $font = $fonts[$m['font']] ?? [[], 1];
            $size = (float)$m['size'];
            if ($size <= 0) $size = 12.0;
        } elseif (($m['mx'] ?? null) !== null) {                   // a b c d e f Tm
            $a = (float)$m['a']; $b = (float)$m['b'];
            $x = (float)$m['mx']; $y = (float)$m['my'];
            $s = sqrt($a * $a + $b * $b);
            $scale = $s > 0 ? $s : 1.0;
            $pen = $x;
        } elseif (($m['tx'] ?? null) !== null) {                   // tx ty Td/TD
            if ($x === null) { $x = 0.0; $y = 0.0; }
            $x += (float)$m['tx'] * $scale;
            $y += (float)$m['ty'] * $scale;
        } elseif (($m['arr'] ?? null) !== null) {                  // [ ... ] TJ
            $txt = '';
            $inner = '/\((?P<s>(?:[^()\\\\]|\\\\.)*)\)|<(?P<h>[0-9A-Fa-f\s]*)>|(?P<n>-?\d+\.?\d*)/';
            if (preg_match_all($inner, $m['arr'], $items, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
                foreach ($items as $it) {
                    if (($it['s'] ?? null) !== null) {
                        $txt .= pdf_decode_literal($it['s'], $font[0], $font[1]);
                    } elseif (($it['h'] ?? null) !== null) {
                        $txt .= pdf_decode_hex($it['h'], $font[0], $font[1]);
                    } elseif (($it['n'] ?? null) !== null) {
                        // A large negative kern is how most writers encode a space.
                        if ((float)$it['n'] <= -150 && $txt !== '' && substr($txt, -1) !== ' ') $txt .= ' ';
                    }
                }
            }
            $place($txt);
        } elseif (($m['lit'] ?? null) !== null) {                  // ( ... ) Tj
            $place(pdf_decode_literal($m['lit'], $font[0], $font[1]));
        } elseif (($m['hex'] ?? null) !== null) {                  // < ... > Tj
            $place(pdf_decode_hex($m['hex'], $font[0], $font[1]));
        } elseif (($m['nl'] ?? null) !== null) {                   // T*
            $parts[] = "\n"; $prevY = null; $pen = null;
        }
    }
    return implode('', $parts);
}

function pdf_cleanup_text(string $t): string {
    $t = preg_replace('/[^\P{C}\n\t]+/u', '', $t) ?? $t;
    $t = preg_replace('/[ \t]{2,}/', ' ', $t);
    $t = preg_replace('/\n[ \t]+/', "\n", $t);
    $t = preg_replace('/[ \t]+\n/', "\n", $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    return trim($t);
}

/**
 * Extract the text layer of a PDF file.
 * Returns ['text' => string, 'error' => string|null, 'quality' => 'good'|'poor'].
 */
/**
 * How much of this text is loose single letters.
 *
 * "S e n i o r B a c k e n d" is almost all one-character tokens; ordinary
 * prose is under a tenth (a, I, and initials). This is the signal that the
 * space threshold was too small for the file in hand.
 */
function pdf_letter_spacing_ratio(string $text): float {
    $tokens = preg_split('/\s+/u', trim($text)) ?: [];
    $tokens = array_values(array_filter($tokens, static fn($t) => $t !== ''));
    if (count($tokens) < 20) return 0.0;
    $singles = 0;
    foreach ($tokens as $t) if (mb_strlen($t) === 1) $singles++;
    return $singles / count($tokens);
}

function pdf_extract_text(string $path): array {
    if (!is_readable($path)) return ['text' => '', 'error' => 'The uploaded file could not be read.', 'quality' => 'poor'];
    $data = file_get_contents($path);
    if ($data === false || strncmp($data, '%PDF', 4) !== 0) {
        return ['text' => '', 'error' => 'That file does not look like a PDF.', 'quality' => 'poor'];
    }
    if (!function_exists('gzuncompress')) {
        return ['text' => '', 'error' => 'PHP zlib support is required to read PDFs. Enable the zlib extension in php.ini.', 'quality' => 'poor'];
    }

    $objs = pdf_expand_object_streams(pdf_index_objects($data));
    $pages = [];
    foreach ($objs as $num => [$dict, $raw]) {
        if (!preg_match('/\/Type\s*\/Page\b/', $dict)) continue;
        if (preg_match('/\/Type\s*\/Pages\b/', $dict)) continue;
        $pages[$num] = $dict;
    }

    $out = [];
    $streams = [];          // [inflated content, font maps] per stream, for the retry below
    foreach ($pages as $dict) {
        $res = pdf_dict_entry($dict, 'Resources');
        if ($res !== '' && strncmp($res, '<<', 2) !== 0) {
            if (preg_match('/(\d+)\s+\d+\s+R/', $res, $m) && isset($objs[(int)$m[1]])) $res = $objs[(int)$m[1]][0];
            else $res = '';
        }
        $fonts = pdf_font_maps($objs, $res);
        $contents = pdf_dict_entry($dict, 'Contents');
        if ($contents === '') continue;
        if (!preg_match_all('/(\d+)\s+\d+\s+R/', $contents, $refs)) continue;
        foreach ($refs[1] as $ref) {
            $o = $objs[(int)$ref] ?? null;
            if (!$o) continue;
            $buf = pdf_inflate($o[0], $o[1]);
            if ($buf !== null && $buf !== '') {
                $streams[] = [$buf, $fonts];
                $out[] = pdf_content_text($buf, $fonts);
            }
        }
    }

    $text = pdf_cleanup_text(implode("\n", $out));

    // Letter-spaced output ("S e n i o r B a c k e n d") means the word-break
    // threshold was too small for this writer.
    //
    // The cause is that this reader estimates each glyph's advance as a fixed
    // fraction of the em, because it does not read per-character /Widths. In a
    // PDF that positions every glyph with its own Tm/Td -- Chrome's "Print to
    // PDF" and InDesign both do -- a wide letter advances further than the
    // estimate, so the leftover looks like a word break and a space is
    // inserted between every pair of letters.
    //
    // Re-reading the same content streams with a larger threshold fixes it, but
    // the right value varies by document, so a few are tried and the best kept:
    // fewest loose single letters, while still keeping a believable number of
    // spaces. Too large a gap swallows real word breaks and glues sentences
    // together, which the density floor below rejects.
    //
    // None of this runs for a PDF that read correctly the first time.
    $ratio = pdf_letter_spacing_ratio($text);
    if ($ratio > 0.30 && $streams) {
        $density = static function (string $t): float {
            $len = max(1, mb_strlen($t));
            return (substr_count($t, ' ') + substr_count($t, "\n")) / $len;
        };
        $best = $text; $bestRatio = $ratio;
        foreach ([0.45, 0.6, 0.8, 1.0, 1.2] as $gap) {
            $retry = [];
            foreach ($streams as [$buf, $fonts]) $retry[] = pdf_content_text($buf, $fonts, $gap);
            $candidate = pdf_cleanup_text(implode("\n", $retry));
            if ($candidate === '' || $density($candidate) < 0.08) continue;   // words glued together
            $candidateRatio = pdf_letter_spacing_ratio($candidate);
            if ($candidateRatio < $bestRatio) { $best = $candidate; $bestRatio = $candidateRatio; }
        }
        $text = $best; $ratio = $bestRatio;
    }

    if ($text === '' || mb_strlen($text) < 40) {
        return [
            'text' => $text,
            'error' => 'Unable to extract text from this PDF. Please upload a text-based PDF or enter the job information manually.',
            'quality' => 'poor',
        ];
    }

    // Two opposite failure modes, both worth flagging before the recruiter
    // saves: almost no spaces (words run together), and too many (every letter
    // separated, if the retry above could not repair it).
    $spaces = substr_count($text, ' ') + substr_count($text, "\n");
    $tooFew = ($spaces / max(1, mb_strlen($text))) < 0.06;
    $quality = ($tooFew || $ratio > 0.30) ? 'poor' : 'good';

    return ['text' => $text, 'error' => null, 'quality' => $quality];
}

// ---------------------------------------------------------------------------
// Turning raw text into job posting fields
// ---------------------------------------------------------------------------

/** Section heading synonyms → the field they fill. */
/**
 * Shared parsing vocabulary.
 *
 * The synonym lists below are the FALLBACK. The live values come from
 * config/job_parse_rules.json, which tools/extract_job_data.py reads too, so a
 * new heading taught to one engine is understood by both. The hardcoded arrays
 * stay as a safety net for an install where that file is missing or malformed —
 * parsing then degrades to the original vocabulary instead of failing.
 */
function jd_rules(): array {
    static $rules = null;
    if ($rules !== null) return $rules;
    $rules = [];
    $path = __DIR__ . '/../config/job_parse_rules.json';
    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) $rules = $decoded;
    }
    return $rules;
}

/** One rules group, with the caller's defaults for anything the file omits. */
function jd_rule_group(string $group, array $default): array {
    $fromFile = jd_rules()[$group] ?? null;
    if (!is_array($fromFile) || !$fromFile) return $default;
    foreach ($default as $field => $synonyms) {
        $fromFile[$field] = array_values(array_unique(array_merge($fromFile[$field] ?? [], $synonyms)));
    }
    return $fromFile;
}

function jd_section_map(): array {
    return jd_rule_group('sections', [
        'description'      => ['job description', 'job summary', 'position summary', 'role summary', 'about the role', 'about this role', 'overview', 'job overview', 'summary', 'purpose of the role'],
        'responsibilities' => ['responsibilities', 'key responsibilities', 'main responsibilities', 'duties', 'duties and responsibilities', 'what you will do', "what you'll do", 'role responsibilities', 'essential functions', 'key duties'],
        'qualifications'   => ['qualifications', 'requirements', 'minimum qualifications', 'basic qualifications', 'job requirements', 'who you are', 'what we are looking for', "what we're looking for", 'candidate profile'],
        'skills'           => ['required skills', 'skills', 'technical skills', 'key skills', 'must have', 'must-have', 'core competencies', 'competencies'],
        'preferred_skills' => ['preferred skills', 'preferred qualifications', 'nice to have', 'nice-to-have', 'bonus points', 'desirable', 'good to have', 'plus'],
        'experience'       => ['experience', 'work experience', 'experience required', 'years of experience', 'professional experience'],
        'education'        => ['education', 'educational requirements', 'education requirements', 'academic requirements'],
        'benefits'         => ['benefits', 'what we offer', 'perks', 'compensation and benefits'],
    ]);
}

/** Inline "Label: value" synonyms → the field they fill. */
function jd_label_map(): array {
    return jd_rule_group('labels', [
        'title'           => ['job title', 'position title', 'position', 'role', 'title', 'job position', 'vacancy'],
        'department'      => ['department', 'dept', 'business unit', 'team', 'division', 'function'],
        'location'        => ['location', 'work location', 'job location', 'place of work', 'based in', 'office'],
        'employment_type' => ['employment type', 'job type', 'employment status', 'work type', 'contract type', 'type'],
        'salary'          => ['salary', 'salary range', 'compensation', 'pay range', 'rate', 'budget', 'salary package'],
        'experience'      => ['experience', 'experience required', 'years of experience', 'minimum experience'],
        'education'       => ['education', 'educational attainment', 'education level'],
    ]);
}

function jd_normalise_heading(string $line): string {
    $l = mb_strtolower(trim($line));
    $l = preg_replace('/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/u', '', $l) ?? $l;
    return preg_replace('/\s+/', ' ', $l) ?? $l;
}

/** Does this line look like a section heading rather than body copy? */
function jd_is_heading(string $line, array &$field): bool {
    $trimmed = trim($line);
    if ($trimmed === '' || mb_strlen($trimmed) > 60) return false;
    $key = jd_normalise_heading($trimmed);
    if ($key === '') return false;
    foreach (jd_section_map() as $fieldName => $synonyms) {
        if (in_array($key, $synonyms, true)) { $field = [$fieldName]; return true; }
    }
    return false;
}

function jd_employment_type(string $raw): ?string {
    $v = mb_strtolower($raw);
    if (preg_match('/intern/', $v)) return 'internship';
    if (preg_match('/part[\s-]?time/', $v)) return 'part_time';
    if (preg_match('/contract|contractual|freelance|consultant|temporary|project[\s-]based/', $v)) return 'contract';
    if (preg_match('/full[\s-]?time|permanent|regular/', $v)) return 'full_time';
    return null;
}

/**
 * Parse extracted PDF text into job posting fields.
 * Everything returned is a suggestion — the workflow shows it for editing
 * before anything is saved.
 */
function jd_parse_fields(string $text): array {
    $out = [
        'title' => '', 'department' => '', 'location' => '', 'employment_type' => '',
        'salary' => '', 'description' => '', 'responsibilities' => '', 'qualifications' => '',
        'skills' => '', 'preferred_skills' => '', 'experience' => '', 'education' => '', 'benefits' => '',
    ];
    // The vocabulary comes from config/job_parse_rules.json, which may name
    // fields this function predates ("deadline", "how_to_apply"). Every field
    // either map can produce is initialised, so a new synonym group cannot
    // reach an undefined key further down.
    foreach (array_merge(array_keys(jd_section_map()), array_keys(jd_label_map())) as $fieldName) {
        $out[$fieldName] = $out[$fieldName] ?? '';
    }

    $lines = preg_split('/\R/u', $text) ?: [];
    // Each line is kept in two forms: trimmed for detection (a heading is a
    // heading whether or not it is indented) and original for content, because
    // the leading indent is the nesting level of a sub-item and dropping it
    // flattens every nested list into one level.
    $rawLines = array_map(static fn($l) => rtrim((string)$l), $lines);
    $lines = array_map(static fn($l) => trim((string)$l), $lines);

    $labels = jd_label_map();
    $sections = [];
    $current = null;
    $firstMeaningful = null;

    foreach ($lines as $lineIndex => $line) {
        if ($line === '') { if ($current) $sections[$current][] = ''; continue; }

        // Section heading?
        $field = [];
        if (jd_is_heading($line, $field)) { $current = $field[0]; $sections[$current] = $sections[$current] ?? []; continue; }

        // "Label: value" on one line
        $matchedLabel = false;
        if (preg_match('/^\s*([A-Za-z][A-Za-z\/ &\'-]{1,34}?)\s*[:\x{2013}\x{2014}-]\s*(.+)$/u', $line, $m)) {
            $key = jd_normalise_heading($m[1]);
            $value = trim($m[2]);
            foreach ($labels as $fieldName => $synonyms) {
                if (in_array($key, $synonyms, true) && $value !== '' && $out[$fieldName] === '') {
                    $out[$fieldName] = $value;
                    $matchedLabel = true;
                    break;
                }
            }
            // "Responsibilities: a, b, c" — a heading and its content on one line
            if (!$matchedLabel) {
                $sectionField = [];
                if (jd_is_heading($m[1], $sectionField)) {
                    $current = $sectionField[0];
                    $sections[$current] = $sections[$current] ?? [];
                    if ($value !== '') $sections[$current][] = $value;
                    continue;
                }
            }
        }
        if ($matchedLabel) continue;

        if ($current) { $sections[$current][] = $rawLines[$lineIndex] ?? $line; continue; }
        if ($firstMeaningful === null && mb_strlen($line) <= 90) $firstMeaningful = $line;
    }

    foreach ($sections as $name => $body) {
        $joined = trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $body)) ?? '');
        // Strip bullet glyphs but keep one item per line, which is how the
        // existing jobs.requirements field is already stored and rendered.
        $joined = preg_replace('/^[\s]*[\x{2022}\x{25CF}\x{25AA}\x{00B7}\x{2023}\x{2043}o\*\-\x{2013}\x{2014}]\s+/mu', '', $joined) ?? $joined;
        $key = $name === 'skills' ? 'skills' : $name;
        if (($out[$key] ?? '') === '') $out[$key] = trim($joined);
    }

    // Title fallback: the first substantial line before any section started.
    if ($out['title'] === '' && $firstMeaningful !== null) {
        $candidate = trim($firstMeaningful, " \t:-–—");
        // Skip an obvious company banner like "ACME CORPORATION".
        if (preg_match('/\b(corporation|corp|inc|incorporated|company|ltd|llc|gmbh|pte|holdings)\b/i', $candidate) === 0) {
            $out['title'] = $candidate;
        }
    }

    if ($out['employment_type'] !== '') {
        $out['employment_type'] = jd_employment_type($out['employment_type']) ?? '';
    }
    if ($out['employment_type'] === '') {
        $guess = jd_employment_type($text);
        if ($guess) $out['employment_type'] = $guess;
    }
    if ($out['salary'] === '' && preg_match('/(?:salary|compensation|pay)[^\n]{0,20}?((?:php|usd|₱|\$|€|£)\s?[\d,.]+\s*(?:[-–—to]+\s*(?:php|usd|₱|\$|€|£)?\s?[\d,.]+)?[^\n]{0,24})/iu', $text, $m)) {
        $out['salary'] = trim($m[1]);
    }

    // Nothing labelled "description"? Use the opening prose as the summary.
    if ($out['description'] === '') {
        $lead = [];
        foreach ($lines as $line) {
            if ($line === '') { if ($lead) break; continue; }
            $f = [];
            if (jd_is_heading($line, $f)) break;
            if ($line === $out['title']) continue;
            if (preg_match('/^\s*[A-Za-z][A-Za-z\/ &\'-]{1,34}?\s*:/u', $line)) continue;
            $lead[] = $line;
            if (count($lead) >= 8) break;
        }
        $out['description'] = trim(implode("\n", $lead));
    }

    return array_map(static fn($v) => is_string($v) ? trim($v) : $v, $out);
}

/** Turn a parsed "skills" blob into the comma list jobs.tags expects. */
function jd_skills_to_tags(string $skills): string {
    $parts = preg_split('/[,\n;\/]+/u', $skills) ?: [];
    $parts = array_filter(array_map(static fn($p) => trim($p, " \t.-–—•"), $parts), static fn($p) => $p !== '' && mb_strlen($p) <= 28);
    return implode(', ', array_slice(array_unique($parts), 0, 6));
}
