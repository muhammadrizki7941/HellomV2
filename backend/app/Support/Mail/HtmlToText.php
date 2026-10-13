<?php

namespace App\Support\Mail;

/**
 * Plain-text twin of an HTML email (multipart/alternative). Mail filters score HTML-only
 * messages as spammy; the text part also reads well in watches and old clients.
 * Links keep their URL: "Verifikasi Email (https://…)".
 */
final class HtmlToText
{
    public static function convert(string $html): string
    {
        // Hidden preheader and head/style/script are not content.
        $html = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<div[^>]*display:\s*none[^>]*>.*?</div>#is', '', $html) ?? $html;

        // Links: "label (url)" unless the label already is the URL; mailto → address only.
        $html = preg_replace_callback('#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function (array $m) {
            $url = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (str_starts_with($url, 'mailto:')) {
                return $label !== '' ? $label : substr($url, 7);
            }
            if ($label === '' || $label === $url) {
                return $url;
            }

            return "{$label} ({$url})";
        }, $html) ?? $html;

        // Block elements → line breaks; table cells → spaced columns.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|h[1-6]|tr|table|li|ul|ol)>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', '- ', $html) ?? $html;
        $html = preg_replace('#</td>\s*<td\b[^>]*>#i', ': ', $html) ?? $html;
        $html = preg_replace('#<h[1-6]\b[^>]*>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $lines = array_map(fn ($line) => trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line), explode("\n", str_replace("\r", '', $text)));
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text) . "\n";
    }
}
