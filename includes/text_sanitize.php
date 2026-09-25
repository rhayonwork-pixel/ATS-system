<?php
/**
 * Text sanitisation for job postings.
 *
 * A job posting is a legal-ish document that ends up on a public careers page,
 * in email and in exports. Emoji pasted out of a chat message or a LinkedIn ad
 * have no place in it, so everything entering a posting passes through here.
 *
 * WHY HAND-WRITTEN RANGES AND NOT A LIBRARY
 * -----------------------------------------
 * There is no Composer in this project (see CLAUDE.md) and the shared-hosting
 * target is why. These are plain PCRE ranges with the /u flag; the whole app is
 * UTF-8 end to end, so that is all this needs.
 *
 * WHAT IT MUST NOT DO
 * -------------------
 * It must never strip "non-ASCII". A job posting legitimately contains
 * "PHP 140,000 – 190,000" (en dash), "€", "₱", "°", "±", "Zoë", "Beijing 北京"
 * and every accented name in the Philippines and Europe. Removing those would
 * corrupt real data far more often than an emoji ever appears.
 *
 * It is also deliberately NOT a global output filter. phone_country_flag() in
 * includes/config.php builds regional-indicator flag emoji on purpose for the
 * apply form; running this over that would blank every flag. Call it on job
 * posting fields, nothing else.
 */

/**
 * Emoji and decorative-symbol ranges, as a PCRE character-class body.
 *
 * Deliberately excluded from this list: U+2013/U+2014 (en/em dash, salary
 * ranges), U+2018-U+201D (smart quotes), U+00B0 (degree), U+00D7 (times),
 * U+00B1 (plus-minus) and every currency sign. Those are punctuation, not
 * decoration, and job descriptions are full of them.
 */
const EMOJI_RANGES =
      '\x{1F000}-\x{1FAFF}'   // mahjong through symbols-and-pictographs-extended-A
    . '\x{1F004}\x{1F0CF}'    // stray legacy pictographs
    . '\x{2190}-\x{21FF}'     // arrows (used as decorative bullets in ads)
    . '\x{2300}-\x{23FF}'     // misc technical: ⌚ ⏰ ⏳
    . '\x{2460}-\x{24FF}'     // enclosed alphanumerics: ① ②
    . '\x{25A0}-\x{25FF}'     // geometric shapes — bullets handled before this runs
    . '\x{2600}-\x{27BF}'     // misc symbols + dingbats: ☀ ★ ✅ ✈ ✨ ➤
    . '\x{2B00}-\x{2BFF}'     // arrows and stars extended
    . '\x{FE0E}\x{FE0F}'      // variation selectors (text/emoji presentation)
    . '\x{200D}'              // zero-width joiner (👨‍👩‍👧 sequences)
    . '\x{20E3}'              // combining enclosing keycap (1️⃣)
    . '\x{E000}-\x{F8FF}';    // private use — where Wingdings bullets land

/** Invisible characters that survive a copy-paste and break string comparisons. */
const INVISIBLE_RANGES = '\x{200B}-\x{200C}\x{FEFF}\x{00AD}\x{2060}';

/**
 * Bullet glyphs that open a list item.
 *
 * These are STRIPPED, not converted. The structure is carried by the line
 * break and by the indentation, which both survive; the glyph itself is
 * decoration that a job posting should not store. A stored "- " would also be
 * rendered inside a real <li> on the careers page, giving "• - Item".
 */
const BULLET_GLYPHS = [
    "\u{2022}", "\u{2023}", "\u{25CF}", "\u{25CB}", "\u{25AA}", "\u{25AB}",
    "\u{25A0}", "\u{25A1}", "\u{25E6}", "\u{2043}", "\u{2219}", "\u{00B7}",
    "\u{2756}", "\u{27A4}", "\u{27A2}", "\u{279C}", "\u{2794}", "\u{2713}",
    "\u{2714}", "\u{2705}", "\u{2717}", "\u{2718}", "\u{F0B7}", "\u{F0A7}",
    "\u{F075}", "\u{F06C}",
    // U+00F0 is deliberately NOT here. Wingdings bullets land in the private
    // use area (U+F0B7 and friends); U+00F0 is the Icelandic letter eth, which
    // appears in real names -- listing it turned "Guðrún" into "Gu rún".
];

/**
 * Remove emoji and decorative symbols. Punctuation, currency, mathematics and
 * every script stay exactly as they were.
 */
/**
 * strip_emoji() with the bullet glyphs spared, so strip_list_markers() can
 * decide whether each one opens a list item or separates words. Most bullet
 * shapes live inside the emoji ranges, so removing them here first would erase
 * the list structure before anything could read it.
 */
function strip_emoji_keep_bullets(?string $text): string
{
    $text = (string)$text;
    if ($text === '') return '';

    // Park the bullets on a sentinel, strip, then restore. The sentinel is
    // plain ASCII: control characters were tried first, and strip_emoji()
    // removes those too, which left the index digit behind and turned every
    // bullet into a stray "0".
    $map = [];
    foreach (BULLET_GLYPHS as $i => $glyph) {
        $token = 'zZbUlLeT' . $i . 'Zz';
        $map[$token] = $glyph;
        $text = str_replace($glyph, $token, $text);
    }
    $text = strip_emoji($text);
    return strtr($text, $map);
}

function strip_emoji(?string $text): string
{
    $text = (string)$text;
    if ($text === '') return '';

    // Skin-tone modifiers and regional indicators (flags) are stripped as whole
    // sequences first, so a flag never decays into two stray letters.
    $text = preg_replace('/[\x{1F1E6}-\x{1F1FF}]{2}/u', '', $text) ?? $text;
    $text = preg_replace('/[\x{1F3FB}-\x{1F3FF}]/u', '', $text) ?? $text;

    $text = preg_replace('/[' . EMOJI_RANGES . ']/u', '', $text) ?? $text;
    $text = preg_replace('/[' . INVISIBLE_RANGES . ']/u', '', $text) ?? $text;

    // Control characters, except the two that carry structure.
    $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $text) ?? $text;

    return $text;
}

/**
 * Strip list markers, keeping the shape of the list.
 *
 * WHAT COUNTS AS A MARKER
 *   -  *  +  |  and every glyph in BULLET_GLYPHS
 *   1.  1)  (1)  12.        numbered
 *   a.  a)  (a)  iv.  IX)   lettered and roman
 * A marker only counts at the START of a line and must be followed by
 * whitespace, so "e-commerce", "1.5 million", "Full-time" and "24/7" are never
 * touched. Several stacked markers ("- 1. thing") are removed together.
 *
 * WHAT SURVIVES
 *   * the line break between items
 *   * the INDENT, as a nesting level: a child item stays visibly under its
 *     parent. Ragged indentation (3 spaces here, 5 there, a tab somewhere
 *     else) is snapped to two spaces per level, so the output is regular
 *     rather than a copy of whatever the document happened to contain.
 *   * blank lines between paragraphs (collapsed to one)
 *
 * A line that was ONLY a marker disappears rather than becoming a blank line.
 */
function strip_list_markers(?string $text): string
{
    $text = (string)$text;
    if ($text === '') return '';

    $glyphs = '';
    foreach (BULLET_GLYPHS as $glyph) $glyphs .= preg_quote($glyph, '/');

    // One marker: a glyph/dash/pipe run, or a number/letter/roman followed by
    // . ) or ]. Written as one alternation so the whole thing is a single
    // group -- an earlier version closed the group twice and the pattern did
    // not compile at all, which preg_replace() reports only as a warning while
    // returning the subject unchanged.
    $marker = '(?:'
            . '[' . $glyphs . '*+|\\-\x{2013}\x{2014}>]+'
            . '|\(?(?:[0-9]{1,2}|[a-zA-Z]|[ivxlcIVXLC]{1,4})[.)\]]'
            . ')';

    $rawIndents = [];
    $rows = [];

    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        // Tabs are worth four columns; after this, indentation is countable.
        $line = str_replace("\t", '    ', $line);
        preg_match('/^[ ]*/', $line, $m);
        $indent = strlen($m[0]);
        $body = substr($line, $indent);

        // Strip stacked markers, up to three, each needing whitespace or end
        // of line after it.
        for ($i = 0; $i < 3; $i++) {
            $stripped = preg_replace('/^' . $marker . '[ ]+/u', '', $body, 1, $count);
            if ($count) { $body = $stripped; continue; }
            // A marker alone on its line, with nothing after it.
            $stripped = preg_replace('/^' . $marker . '[ ]*$/u', '', $body, 1, $count);
            if ($count) { $body = $stripped; }
            break;
        }

        $body = rtrim($body);
        if ($body === '') { $rows[] = null; continue; }     // blank or marker-only
        $rows[] = [$indent, $body];
        $rawIndents[$indent] = true;
    }

    // Snap the distinct indents that actually occur onto 0, 1, 2 … levels.
    $levels = array_keys($rawIndents);
    sort($levels);
    $levelOf = array_flip($levels);

    $out = [];
    foreach ($rows as $row) {
        if ($row === null) { $out[] = ''; continue; }
        [$indent, $body] = $row;
        $out[] = str_repeat('  ', $levelOf[$indent] ?? 0) . $body;
    }
    return implode("\n", $out);
}

/**
 * The function callers should use: strip, normalise, tidy whitespace.
 *
 * Whitespace handling keeps paragraph structure: single newlines survive,
 * runs of three or more collapse to two, and trailing spaces go. Tabs become
 * single spaces because a tab inside a textarea value is nearly always an
 * artefact of the PDF or DOCX it came from.
 */
function sanitize_job_text(?string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Emoji come out first here, because a decorative emoji can sit BEFORE the
    // real marker ("\u{1F680} - Ship it") and would otherwise hide it. The
    // glyphs that double as bullets are excluded from that pass and removed by
    // the stripper below, which knows the difference between a marker and a
    // separator.
    $text = strip_emoji_keep_bullets($text);
    $text = strip_list_markers($text);

    // Whitespace: runs INSIDE a line collapse, the leading indent does not --
    // it is the nesting level that strip_list_markers() just worked out.
    $text = preg_replace('/(\S)[ ]{2,}/u', '$1 ', $text) ?? $text;
    $text = preg_replace('/ +$/mu', '', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

    // Pipes left over from a copied table row. The opening one is already gone
    // (it read as a marker); a closing one is noise, and the ones between
    // columns are separators, which read as commas once the table is flat.
    $text = preg_replace('/[ ]*\|[ ]*$/mu', '', $text) ?? $text;
    $text = preg_replace('/[ ]*\|[ ]*/u', ', ', $text) ?? $text;

    // Any bullet glyph still standing is mid-line ("Java \u{2022} Python"), where
    // it separates rather than opens. A hyphen there is a range, so it becomes
    // a space.
    $glyphs = '';
    foreach (BULLET_GLYPHS as $glyph) $glyphs .= preg_quote($glyph, '/');
    $text = preg_replace('/[' . $glyphs . ']/u', ' ', $text) ?? $text;
    $text = preg_replace('/(\S)[ ]{2,}/u', '$1 ', $text) ?? $text;

    return trim($text);
}

/** Single-line fields: the same rules, with every newline flattened. */
function sanitize_job_line(?string $text, int $maxLength = 255): string
{
    $text = sanitize_job_text($text);
    $text = preg_replace('/\s*\n\s*/u', ' ', $text) ?? $text;
    $text = trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    return mb_substr($text, 0, $maxLength);
}

/** True when sanitising would change the text — used to tell the user we did. */
function job_text_needs_sanitizing(?string $text): bool
{
    return (string)$text !== '' && sanitize_job_text($text) !== (string)$text;
}
