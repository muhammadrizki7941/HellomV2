import {
  ArrowRight, Box, CalendarClock, CalendarDays, Code, MessageCircle, MoveVertical, ClipboardList, Code2, FileText, GalleryHorizontal, HelpCircle, Image as ImageIcon,
  Images, LayoutGrid, Layout, List, Megaphone, Minus, MousePointer2, Quote, Share2, ShoppingBag, Store, Type, UserCircle, Video, Wand2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { Block, BlockType } from '../types';

/**
 * Names and groups of blocks in the link-in-bio editor (Bahasa Indonesia). Legacy blocks from
 * the long landing-page editor still render and can be edited on pages that use them, but are
 * no longer offered in the "+ Tambah" gallery (owner decision, Fase 2).
 */
export type BlockGroup = 'utama' | 'jualan' | 'media' | 'info';

export interface BlockMeta {
  label: string;
  description: string;
  icon: LucideIcon;
  group: BlockGroup;
  legacy?: boolean;
}

export const BLOCK_META: Record<BlockType, BlockMeta> = {
  profile: { label: 'Profil', description: 'Foto, nama, bio & lencana', icon: UserCircle, group: 'utama' },
  button: { label: 'Tombol link', description: 'Arahkan ke link atau WhatsApp', icon: ArrowRight, group: 'utama' },
  // Replaced by the Sosial media panel (Fase 4): old pages keep it, not offered in the gallery.
  social: { label: 'Ikon sosial media (lama)', description: 'Pindahkan ke panel Sosial media', icon: Share2, group: 'utama', legacy: true },
  text: { label: 'Teks', description: 'Paragraf atau pengumuman', icon: Type, group: 'utama' },
  divider: { label: 'Pemisah', description: 'Garis untuk memberi jarak', icon: Minus, group: 'utama' },
  product: { label: 'Produk digital', description: 'E-book, kelas, file + tombol beli', icon: ShoppingBag, group: 'jualan' },
  catalog: { label: 'Katalog produk', description: 'Daftar produk toko kamu', icon: Store, group: 'jualan' },
  pdf: { label: 'File / PDF', description: 'Bagikan atau jual dokumen', icon: FileText, group: 'jualan' },
  form: { label: 'Formulir', description: 'Kumpulkan kontak & pesanan', icon: ClipboardList, group: 'jualan' },
  countdown: { label: 'Hitung mundur', description: 'Waktu promo berakhir', icon: CalendarClock, group: 'jualan' },
  testimonials: { label: 'Testimoni', description: 'Kata pembeli tentang kamu', icon: Quote, group: 'jualan' },
  image: { label: 'Gambar', description: 'Satu foto atau poster', icon: ImageIcon, group: 'media' },
  banner: { label: 'Banner', description: 'Gambar lebar dengan judul', icon: Megaphone, group: 'media' },
  slider: { label: 'Carousel gambar', description: 'Beberapa gambar digeser', icon: Images, group: 'media' },
  gallery: { label: 'Galeri', description: 'Grid foto', icon: GalleryHorizontal, group: 'media' },
  video: { label: 'Video', description: 'Link YouTube atau TikTok', icon: Video, group: 'media' },
  faq: { label: 'Tanya jawab', description: 'Pertanyaan yang sering muncul', icon: HelpCircle, group: 'info' },
  // Legacy (long landing page): rendered and editable, not in the gallery.
  hero: { label: 'Hero', description: 'Judul besar + tombol', icon: Layout, group: 'info', legacy: true },
  features: { label: 'Keunggulan', description: 'Daftar fitur', icon: LayoutGrid, group: 'info', legacy: true },
  cta: { label: 'Ajakan (CTA)', description: 'Judul + tombol', icon: MousePointer2, group: 'info', legacy: true },
  content: { label: 'Konten', description: 'Judul + teks', icon: Type, group: 'info', legacy: true },
  list: { label: 'Daftar', description: 'Poin-poin', icon: List, group: 'info', legacy: true },
  gif: { label: 'GIF', description: 'Animasi GIF', icon: Wand2, group: 'media', legacy: true },
  html: { label: 'HTML', description: 'Kode HTML', icon: Code2, group: 'info', legacy: true },
  spacer: { label: 'Spasi', description: 'Jarak kosong antar bagian', icon: MoveVertical, group: 'utama' },
  whatsapp: { label: 'Tombol WhatsApp', description: 'Chat langsung dengan kamu', icon: MessageCircle, group: 'utama' },
  embed: { label: 'Embed', description: 'Spotify, TikTok, Instagram', icon: Code, group: 'media' },
};

export const GROUP_LABELS: Record<BlockGroup, string> = { utama: 'Dasar', jualan: 'Jualan', media: 'Media', info: 'Info' };

/** A card in "+ Tambah": a block type, sometimes with preset content (e.g. physical product). */
export interface GalleryItem extends BlockMeta {
  key: string;
  type: BlockType;
  content?: Record<string, unknown>;
}

/** Blocks offered in "+ Tambah" (legacy blocks are not offered). */
export const GALLERY_ITEMS: GalleryItem[] = [
  ...(Object.keys(BLOCK_META) as BlockType[]).filter((type) => !BLOCK_META[type].legacy).flatMap((type): GalleryItem[] => (
    type === 'product'
      ? [
        { ...BLOCK_META.product, key: 'product', type, content: { kind: 'digital' } },
        { ...BLOCK_META.product, key: 'product_physical', type, label: 'Produk fisik', description: 'Barang dikirim, ongkir otomatis', icon: Box, content: { kind: 'physical' } },
        { ...BLOCK_META.product, key: 'product_rental', type, label: 'Sewa / booking', description: 'Pembeli pilih tanggal/jam yang kosong', icon: CalendarDays, content: { kind: 'rental', buttonText: 'Pilih jadwal' } },
      ]
      : [{ ...BLOCK_META[type], key: type, type }]
  )),
];

/** Short text shown under a block in the list ("Tombol link · Chat WhatsApp"). */
export function blockSummary(block: Block): string {
  const c = block.content as Record<string, unknown>;
  const text = (value: unknown) => (typeof value === 'string' ? value.trim() : '');
  const first = [c.text, c.name, c.title, c.buttonText, c.caption, c.body, c.question].map(text).find(Boolean) ?? '';
  const count = (key: string) => (Array.isArray(c[key]) ? (c[key] as unknown[]).length : 0);
  const extra = block.type === 'slider' || block.type === 'gallery' ? `${count('images')} gambar`
    : block.type === 'faq' || block.type === 'testimonials' ? `${count('items')} item`
      : '';
  return [first.slice(0, 60), extra].filter(Boolean).join(' · ');
}
