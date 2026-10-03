// Fonts for headings and body (Fase 5). Same ids as App\Support\Landing\Fonts; the files are
// self-hosted (public/fonts/landing, scripts/fetch-landing-fonts.mjs, SIL Open Font License).

export interface FontInfo {
  id: string;
  label: string;
  /** CSS font-family value. */
  stack: string;
  kind: 'Sans' | 'Serif' | 'Display' | 'Tulisan tangan' | 'Sistem';
  files?: Array<[weight: string, file: string]>;
}

export const FONTS: FontInfo[] = [
  { id: 'jakarta', label: 'Plus Jakarta Sans', stack: '"Plus Jakarta Sans", system-ui, sans-serif', kind: 'Sans', files: [['400 800', 'jakarta.woff2']] },
  { id: 'inter', label: 'Inter', stack: 'Inter, system-ui, sans-serif', kind: 'Sans', files: [['400 800', 'inter.woff2']] },
  { id: 'poppins', label: 'Poppins', stack: 'Poppins, system-ui, sans-serif', kind: 'Sans', files: [['400', 'poppins-400.woff2'], ['700', 'poppins-700.woff2']] },
  { id: 'montserrat', label: 'Montserrat', stack: 'Montserrat, system-ui, sans-serif', kind: 'Sans', files: [['400 800', 'montserrat.woff2']] },
  { id: 'dmsans', label: 'DM Sans', stack: '"DM Sans", system-ui, sans-serif', kind: 'Sans', files: [['400 800', 'dmsans.woff2']] },
  { id: 'spacegrotesk', label: 'Space Grotesk', stack: '"Space Grotesk", system-ui, sans-serif', kind: 'Sans', files: [['400 700', 'spacegrotesk.woff2']] },
  { id: 'playfair', label: 'Playfair Display', stack: '"Playfair Display", Georgia, serif', kind: 'Serif', files: [['400 800', 'playfair.woff2']] },
  { id: 'lora', label: 'Lora', stack: 'Lora, Georgia, serif', kind: 'Serif', files: [['400 700', 'lora.woff2']] },
  { id: 'dmserif', label: 'DM Serif Display', stack: '"DM Serif Display", Georgia, serif', kind: 'Serif', files: [['400', 'dmserif.woff2']] },
  { id: 'bebas', label: 'Bebas Neue', stack: '"Bebas Neue", Impact, sans-serif', kind: 'Display', files: [['400', 'bebas.woff2']] },
  { id: 'archivo', label: 'Archivo Black', stack: '"Archivo Black", "Arial Black", sans-serif', kind: 'Display', files: [['400', 'archivo.woff2']] },
  { id: 'caveat', label: 'Caveat', stack: 'Caveat, cursive', kind: 'Tulisan tangan', files: [['400 700', 'caveat.woff2']] },
  { id: 'sans', label: 'Sistem (modern)', stack: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', kind: 'Sistem' },
  { id: 'serif', label: 'Sistem (elegan)', stack: 'Georgia, "Times New Roman", serif', kind: 'Sistem' },
  { id: 'rounded', label: 'Sistem (ramah)', stack: 'ui-rounded, "SF Pro Rounded", system-ui, sans-serif', kind: 'Sistem' },
  { id: 'mono', label: 'Sistem (teknis)', stack: 'ui-monospace, SFMono-Regular, Menlo, monospace', kind: 'Sistem' },
];

export const FONT_BY_ID: Record<string, FontInfo> = Object.fromEntries(FONTS.map((f) => [f.id, f]));

/** @font-face rules so the editor can show each font (loaded only when the panel is open). */
export const FONT_FACES_CSS = FONTS.flatMap((f) => (f.files ?? []).map(([weight, file]) =>
  `@font-face{font-family:"${f.label}";font-style:normal;font-weight:${weight};font-display:swap;src:url(/fonts/landing/${file}) format("woff2")}`)).join('');
