<?php

namespace App\Support\Landing;

/**
 * Fonts for Hellom Page headings and body text (Fase 5). System stacks need no file; the others
 * are self-hosted Google Fonts (OFL), Latin subset, in frontend/public/fonts/landing (part of the
 * SPA build, served at /fonts/landing/…). Only the fonts a page uses are loaded.
 * Keep in sync with frontend landing-builder/editor/fonts.ts and scripts/fetch-landing-fonts.mjs.
 */
final class Fonts
{
    public const URL = '/fonts/landing/';

    /** id => [family, fallback stack, files: weight range => file] */
    public const ALL = [
        'sans' => [null, 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif', []],
        'serif' => [null, 'Georgia, Cambria, "Times New Roman", Times, serif', []],
        'rounded' => [null, 'ui-rounded, "SF Pro Rounded", "Nunito", system-ui, sans-serif', []],
        'mono' => [null, 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace', []],
        'jakarta' => ['Plus Jakarta Sans', 'system-ui, sans-serif', ['400 800' => 'jakarta.woff2']],
        'inter' => ['Inter', 'system-ui, sans-serif', ['400 800' => 'inter.woff2']],
        'poppins' => ['Poppins', 'system-ui, sans-serif', ['400' => 'poppins-400.woff2', '700' => 'poppins-700.woff2']],
        'montserrat' => ['Montserrat', 'system-ui, sans-serif', ['400 800' => 'montserrat.woff2']],
        'dmsans' => ['DM Sans', 'system-ui, sans-serif', ['400 800' => 'dmsans.woff2']],
        'spacegrotesk' => ['Space Grotesk', 'system-ui, sans-serif', ['400 700' => 'spacegrotesk.woff2']],
        'playfair' => ['Playfair Display', 'Georgia, serif', ['400 800' => 'playfair.woff2']],
        'lora' => ['Lora', 'Georgia, serif', ['400 700' => 'lora.woff2']],
        'dmserif' => ['DM Serif Display', 'Georgia, serif', ['400' => 'dmserif.woff2']],
        'bebas' => ['Bebas Neue', 'Impact, "Arial Narrow", sans-serif', ['400' => 'bebas.woff2']],
        'archivo' => ['Archivo Black', '"Arial Black", system-ui, sans-serif', ['400' => 'archivo.woff2']],
        'caveat' => ['Caveat', '"Comic Sans MS", cursive', ['400 700' => 'caveat.woff2']],
    ];

    public static function valid(?string $id): bool
    {
        return $id !== null && isset(self::ALL[$id]);
    }

    /** CSS font-family value. */
    public static function stack(string $id): string
    {
        [$family, $fallback] = self::ALL[$id] ?? self::ALL['sans'];

        return $family ? '"' . $family . '", ' . $fallback : $fallback;
    }

    /**
     * @font-face rules for the given fonts (deduplicated) + the first file to preload.
     *
     * @param list<string> $ids
     * @return array{css:string, preload:?string}
     */
    public static function faces(array $ids): array
    {
        $css = '';
        $preload = null;
        foreach (array_unique($ids) as $id) {
            [$family, , $files] = self::ALL[$id] ?? [null, '', []];
            foreach ($files as $weight => $file) {
                $url = self::URL . $file;
                $preload ??= $url;
                $css .= "@font-face{font-family:\"{$family}\";font-style:normal;font-weight:{$weight};font-display:swap;src:url({$url}) format(\"woff2\")}";
            }
        }

        return ['css' => $css, 'preload' => $preload];
    }
}
