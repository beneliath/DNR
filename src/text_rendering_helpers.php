<?php

declare(strict_types=1);

/**
 * Render user-entered plain text for read-only UI surfaces, wrapping absolute
 * HTTP(S) URLs in safe external links while preserving line breaks.
 */
function renderTextWithLinks($text) {
    $text = (string) $text;
    $url_matches = [];
    $match_count = preg_match_all(
        "~\\bhttps?://[^\\s<>\"']+~i",
        $text,
        $url_matches,
        PREG_OFFSET_CAPTURE
    );
    if ($match_count === false || $match_count === 0) {
        return nl2br(htmlspecialchars(
            $text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ));
    }

    $html = '';
    $text_offset = 0;
    $paired_closers = [')' => '(', ']' => '[', '}' => '{'];
    foreach ($url_matches[0] as $url_match) {
        $matched_url = (string) $url_match[0];
        $url_offset = (int) $url_match[1];
        $url = $matched_url;
        $trailing_text = '';

        while ($url !== '') {
            $last_character = substr($url, -1);
            $is_sentence_punctuation = str_contains('.,;:!?', $last_character);
            $is_unmatched_closer = isset($paired_closers[$last_character])
                && substr_count($url, $last_character)
                    > substr_count($url, $paired_closers[$last_character]);
            if (!$is_sentence_punctuation && !$is_unmatched_closer) {
                break;
            }
            $url = substr($url, 0, -1);
            $trailing_text = $last_character . $trailing_text;
        }

        $html .= htmlspecialchars(
            substr($text, $text_offset, $url_offset - $text_offset),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $escaped_url = htmlspecialchars(
            $url,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $html .= '<a href="' . $escaped_url
            . '" target="_blank" rel="noopener noreferrer">'
            . $escaped_url
            . '</a>';
        $html .= htmlspecialchars(
            $trailing_text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $text_offset = $url_offset + strlen($matched_url);
    }

    $html .= htmlspecialchars(
        substr($text, $text_offset),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return nl2br($html);
}
