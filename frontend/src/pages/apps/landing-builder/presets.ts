import type { BuilderPreference } from '@/lib/hellomApi';

/**
 * Editor presets (link-in-bio, Fase 1). The onboarding question "Sebelumnya terbiasa pakai
 * apa?" picks one, so the editor speaks the words and shows the blocks a seller already knows
 * from that kind of tool. Hellom's own wording and design — other products are named only as
 * plain-text choices (no logos/assets).
 */
export interface EditorPreset {
  id: BuilderPreference;
  /** Choice shown in the onboarding question. */
  label: string;
  description: string;
  /** Words the editor uses for its building pieces. */
  terms: { item: string; items: string; add: string; addNew: string; list: string };
  /** Gallery cards (editor/blockMeta GALLERY_ITEMS keys) offered first in "+ Tambah". */
  featured: string[];
  /** Starting template (templates.ts) suggested on a new page. */
  templateId: string;
  /** Step-by-step help: longer tour, hints on empty states. */
  guided: boolean;
  /** Desktop editor: block list or phone preview leads (link-in-bio editor, Fase 2). */
  lead: 'list' | 'preview';
}

export const EDITOR_PRESETS: Record<BuilderPreference, EditorPreset> = {
  lynk: {
    id: 'lynk',
    label: 'lynk.id',
    description: 'Jualan produk digital & link dari satu halaman',
    terms: { item: 'blok', items: 'blok', add: 'Tambah blok', addNew: 'Tambah blok baru', list: 'Daftar blok' },
    featured: ['product', 'product_physical', 'catalog', 'button', 'profile', 'whatsapp', 'video', 'testimonials'],
    templateId: 'ebook',
    guided: false,
    lead: 'list',
  },
  linktree: {
    id: 'linktree',
    label: 'Linktree',
    description: 'Kumpulan link ke semua tempat kamu',
    terms: { item: 'link', items: 'link', add: 'Tambah link', addNew: 'Tambah link baru', list: 'Daftar link' },
    featured: ['button', 'profile', 'video', 'embed', 'whatsapp', 'image', 'text', 'spacer'],
    templateId: 'creator',
    guided: false,
    lead: 'list',
  },
  orderhero: {
    id: 'orderhero',
    label: 'OrderHero',
    description: 'Terima pesanan menu/produk lewat WhatsApp',
    terms: { item: 'bagian', items: 'bagian', add: 'Tambah bagian', addNew: 'Tambah bagian baru', list: 'Isi halaman' },
    featured: ['catalog', 'product_physical', 'whatsapp', 'banner', 'slider', 'button', 'faq', 'form'],
    templateId: 'physical',
    guided: false,
    lead: 'preview',
  },
  none: {
    id: 'none',
    label: 'Belum pernah / lainnya',
    description: 'Kami pandu langkah demi langkah',
    terms: { item: 'bagian', items: 'bagian', add: 'Tambah bagian', addNew: 'Tambah bagian baru', list: 'Isi halaman' },
    featured: ['profile', 'button', 'whatsapp', 'product', 'image', 'text', 'video', 'faq'],
    templateId: 'creator',
    guided: true,
    lead: 'preview',
  },
};

export const PRESET_ORDER: BuilderPreference[] = ['lynk', 'linktree', 'orderhero', 'none'];

/** Preset used before the seller answers (and for unknown values). */
export const DEFAULT_PRESET = EDITOR_PRESETS.none;

export function presetFor(preference: BuilderPreference | null | undefined): EditorPreset {
  return (preference && EDITOR_PRESETS[preference]) || DEFAULT_PRESET;
}

/** Builder dictionary keys whose wording follows the preset (Bahasa Indonesia only). */
export function presetTerms(preset: EditorPreset): Record<string, string> {
  return {
    'toolbox.add': preset.terms.add,
    'mobile.addNewBlock': preset.terms.addNew,
    'mobile.blockList': preset.terms.list,
    'toolbox.search': `Cari ${preset.terms.item}...`,
    'toolbox.empty': `${capitalize(preset.terms.item)} tidak ditemukan.`,
    // Guided mode points beginners to a ready-made template first.
    'canvas.empty': preset.guided
      ? `Halaman masih kosong. Pilih "Template" di atas untuk mulai cepat, atau ${preset.terms.add.toLowerCase()} dari panel kiri.`
      : `${preset.terms.add} dari panel kiri untuk mulai.`,
    'mobile.emptyList': preset.guided
      ? `Halaman masih kosong. Tap "Template" untuk mulai cepat, atau "${preset.terms.addNew}".`
      : `Belum ada ${preset.terms.item}. Tap "${preset.terms.addNew}" untuk mulai.`,
  };
}

function capitalize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1);
}
