<?php

namespace App\Support\Landing;

/**
 * Icons a button can show on its left (Fase 5): Hellom's own line glyphs (24×24) — a small general
 * set plus the social media glyphs. Mirrors frontend landing-builder/editor/buttonIcons.ts.
 */
final class ButtonIcons
{
    public const GENERAL = [
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'bag' => '<path d="M5 8h14l-1.2 12H6.2z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
        'cart' => '<path d="M3 4h2l2.2 10.2a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.8L19.5 8H6.2"/><circle cx="9.5" cy="19" r="1.3"/><circle cx="16.5" cy="19" r="1.3"/>',
        'download' => '<path d="M12 4v11M7.5 10.5L12 15l4.5-4.5"/><path d="M5 19.5h14"/>',
        'play' => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5l5 3.5-5 3.5z" fill="currentColor"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2.5"/><path d="M4 10h16M8.5 3v4M15.5 3v4"/>',
        'phone' => '<path d="M6.5 3.5l3 .5 1.3 3.7-1.9 1.4a11 11 0 0 0 6 6l1.4-1.9 3.7 1.3.5 3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.7a2 2 0 0 1 2-2.2z"/>',
        'map' => '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.4"/>',
        'star' => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z"/>',
        'gift' => '<rect x="4" y="9" width="16" height="11" rx="1.5"/><path d="M3 9h18M12 9v11M12 9c-2-4-6-4-6-1.5S9 9 12 9zm0 0c2-4 6-4 6-1.5S15 9 12 9z"/>',
        'book' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5"/>',
        'heart' => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7a4.3 4.3 0 0 1 7.5 2.8C19.5 15.4 12 20 12 20z"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.01"/>',
    ];

    /** @return array<string, string> key => inner SVG */
    public static function all(): array
    {
        return self::GENERAL + SocialLinks::ICONS;
    }

    public static function valid(string $key): bool
    {
        return isset(self::GENERAL[$key]) || isset(SocialLinks::ICONS[$key]);
    }

    public static function svg(string $key): string
    {
        $inner = self::all()[$key] ?? '';

        return $inner === '' ? '' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
    }
}
