<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitises HTML written by sellers (landing page "HTML kustom" blocks, rich text).
 * Allowlist only: safe formatting/structure elements, http(s)/mailto/tel links and
 * http(s) images. Scripts, event handlers, iframes, forms, styles with url(), and
 * javascript:/data: URLs are removed. Public pages run on the dashboard origin, so
 * this is what keeps seller content from reading visitors' login tokens.
 */
final class SafeHtml
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
                ->allowMediaSchemes(['https', 'http'])
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->withMaxInputLength(200_000)
        );
    }
}
