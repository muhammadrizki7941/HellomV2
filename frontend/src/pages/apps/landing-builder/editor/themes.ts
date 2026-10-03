import type { CSSProperties } from 'react';
import { getImageUrl } from '@/lib/hellomApi';
import type { LandingBackground, LandingTheme } from '@/lib/hellomApi';
import { FONT_BY_ID } from './fonts';

/**
 * Ready-made looks (Fase 5): one tap sets background, fonts, colors and button style; everything
 * stays editable afterwards. Data only — the server renders it (ThemeStyle) and keeps text readable.
 */
export interface ThemePreset {
  id: string;
  name: string;
  note: string;
  theme: LandingTheme;
}

export const THEME_PRESETS: ThemePreset[] = [
  { id: 'bersih', name: 'Bersih', note: 'Putih, rapi, cocok untuk semua', theme: {
    primary: '#18181b', buttonText: '#ffffff', text: '#18181b', background: '#ffffff', headingFont: 'jakarta', bodyFont: 'jakarta',
    bg: { type: 'solid', color: '#ffffff' }, button: { shape: 'rounded', fill: 'solid', shadow: 'none', hover: 'lift' } } },
  { id: 'hellom', name: 'Kuning Hellom', note: 'Ceria dan mudah dikenali', theme: {
    primary: '#facc15', buttonText: '#000000', text: '#1c1917', background: '#fffbeb', headingFont: 'archivo', bodyFont: 'inter',
    bg: { type: 'solid', color: '#fffbeb' }, button: { shape: 'pill', fill: 'solid', shadow: 'soft', hover: 'grow' } } },
  { id: 'neon', name: 'Neon malam', note: 'Gelap dengan gradien neon bergerak', theme: {
    primary: '#22d3ee', buttonText: '#0f172a', text: '#f8fafc', background: '#0f172a', headingFont: 'spacegrotesk', bodyFont: 'inter',
    bg: { type: 'animated', animation: 'aurora', color: '#0f172a', from: '#1e1b4b', via: '#7c3aed', to: '#db2777', angle: 140 },
    button: { shape: 'pill', fill: 'glass', shadow: 'none', hover: 'shine' } } },
  { id: 'brutal', name: 'Neo-brutal', note: 'Kontras tegas, bayangan keras', theme: {
    primary: '#ffffff', buttonText: '#000000', text: '#000000', background: '#fde047', headingFont: 'archivo', bodyFont: 'spacegrotesk',
    bg: { type: 'pattern', pattern: 'dots', color: '#fde047', patternColor: '#000000', patternOpacity: 0.14 },
    button: { shape: 'square', fill: 'solid', shadow: 'hard', borderWidth: 3, hover: 'lift' } } },
  { id: 'mewah', name: 'Hitam emas', note: 'Mewah untuk jasa & properti', theme: {
    primary: '#d4af37', buttonText: '#000000', text: '#f5f5f4', background: '#0a0a0a', headingFont: 'playfair', bodyFont: 'lora',
    bg: { type: 'solid', color: '#0a0a0a' }, button: { shape: 'square', fill: 'outline', borderWidth: 1, shadow: 'none', hover: 'grow' } } },
  { id: 'pastel', name: 'Pastel lembut', note: 'Beauty, skincare, fashion', theme: {
    primary: '#db2777', buttonText: '#ffffff', text: '#3f3f46', background: '#fdf2f8', headingFont: 'dmserif', bodyFont: 'dmsans',
    bg: { type: 'gradient', from: '#fce7f3', to: '#e0e7ff', angle: 160 }, button: { shape: 'pill', fill: 'solid', shadow: 'soft', hover: 'lift' } } },
  { id: 'kreator', name: 'Kreator', note: 'Gelap dengan aksen menyala', theme: {
    primary: '#f43f5e', buttonText: '#ffffff', text: '#fafafa', background: '#09090b', headingFont: 'bebas', bodyFont: 'inter',
    bg: { type: 'animated', animation: 'blobs', color: '#09090b', from: '#f43f5e', via: '#7c3aed', to: '#f59e0b' },
    button: { shape: 'rounded', fill: 'solid', shadow: 'soft', hover: 'shine' } } },
  { id: 'kuliner', name: 'Hangat (kuliner)', note: 'Makanan & minuman, UMKM', theme: {
    primary: '#c2410c', buttonText: '#ffffff', text: '#431407', background: '#fff7ed', headingFont: 'poppins', bodyFont: 'poppins',
    bg: { type: 'pattern', pattern: 'waves', color: '#fff7ed', patternColor: '#ea580c', patternOpacity: 0.1 },
    button: { shape: 'rounded', fill: 'solid', shadow: 'soft', hover: 'lift' } } },
  { id: 'editorial', name: 'Editorial', note: 'Tipografi besar, banyak ruang', theme: {
    primary: '#1c1917', buttonText: '#ffffff', text: '#1c1917', background: '#f5f5f0', headingFont: 'playfair', bodyFont: 'inter',
    bg: { type: 'solid', color: '#f5f5f0' }, button: { shape: 'square', fill: 'outline', borderWidth: 1, shadow: 'none', hover: 'none' } } },
  { id: 'alam', name: 'Alam', note: 'Earthy, tenang, natural', theme: {
    primary: '#3f6212', buttonText: '#ffffff', text: '#1a2e05', background: '#f4f1ea', headingFont: 'lora', bodyFont: 'dmsans',
    bg: { type: 'gradient', from: '#f4f1ea', to: '#e7efd9', angle: 180 }, button: { shape: 'pill', fill: 'solid', shadow: 'none', hover: 'lift' } } },
  { id: 'retro', name: 'Retro Y2K', note: 'Warna-warni dan playful', theme: {
    primary: '#4f46e5', buttonText: '#ffffff', text: '#1e1b4b', background: '#a5f3fc', headingFont: 'montserrat', bodyFont: 'montserrat',
    bg: { type: 'gradient', from: '#a5f3fc', via: '#f0abfc', to: '#fde68a', angle: 120 }, button: { shape: 'pill', fill: 'solid', shadow: 'hard', borderWidth: 2, hover: 'grow' } } },
  { id: 'profesional', name: 'Profesional', note: 'Bisnis & korporat', theme: {
    primary: '#1d4ed8', buttonText: '#ffffff', text: '#0f172a', background: '#f8fafc', headingFont: 'inter', bodyFont: 'inter',
    bg: { type: 'solid', color: '#f8fafc' }, button: { shape: 'rounded', fill: 'solid', shadow: 'none', hover: 'none' } } },
];

/** Background for small previews in the editor (animations shown as their still colors). */
export function backgroundPreview(bg: LandingBackground | undefined, fallback: string): CSSProperties {
  const color = bg?.color ?? fallback;
  switch (bg?.type) {
    case 'gradient':
    case 'animated': {
      const stops = [bg.from ?? color, bg.via, bg.to ?? bg.from ?? color].filter(Boolean).join(',');
      return { backgroundColor: color, backgroundImage: `linear-gradient(${bg.angle ?? 160}deg,${stops})` };
    }
    case 'pattern':
      return { backgroundColor: color, backgroundImage: `radial-gradient(${bg.patternColor ?? '#000000'}33 1.4px, transparent 1.6px)`, backgroundSize: '12px 12px' };
    case 'image':
      return bg.image ? { backgroundColor: color, backgroundImage: `linear-gradient(rgba(0,0,0,${bg.overlay ?? 0.35}),rgba(0,0,0,${bg.overlay ?? 0.35})),url("${getImageUrl(bg.image)}")`, backgroundSize: 'cover', backgroundPosition: 'center' } : { backgroundColor: color };
    default:
      return { backgroundColor: color };
  }
}

export const fontStack = (id: string | undefined) => FONT_BY_ID[id ?? 'sans']?.stack ?? FONT_BY_ID.sans.stack;
