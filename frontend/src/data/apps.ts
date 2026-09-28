// Katalog aplikasi Hellom untuk halaman publik /aplikasi.
// Tambah aplikasi baru cukup dengan menambah satu objek ke HELLOM_APPS.
import type { LucideIcon } from 'lucide-react';
import { PenTool, ShoppingCart } from 'lucide-react';

export type AppCategory = {
  key: string;
  label: string;
};

export type HellomApp = {
  slug: string;
  name: string;
  icon: LucideIcon;
  /** Satu kalimat, tampil di kartu (maks 3 baris). */
  summary: string;
  category: AppCategory['key'];
  status: 'available' | 'coming_soon';
  /** Poin manfaat singkat di halaman detail. */
  benefits: string[];
  /** Ringkasan paket/harga; harga pasti tampil di dashboard (diatur super admin). */
  pricing?: { label: string; note: string };
  /** Cuplikan tampilan bawaan (mock UI) bila belum ada screenshot. */
  preview: 'pos' | 'builder';
  /** Opsional: URL screenshot (WebP/AVIF) dengan ukuran asli. */
  screenshots?: Array<{ src: string; alt: string; width: number; height: number }>;
  /** Tujuan tombol "Coba Sekarang" untuk pengunjung yang belum masuk / sudah masuk. */
  tryHref: { guest: string; member: string };
};

export const APP_CATEGORIES: AppCategory[] = [
  { key: 'kasir', label: 'Kasir & Pesanan' },
  { key: 'jualan', label: 'Website & Jualan' },
];

export const HELLOM_APPS: HellomApp[] = [
  {
    slug: 'pos',
    name: 'Kasir POS Hellom',
    icon: ShoppingCart,
    summary: 'Catat pesanan, terima pembayaran, dan pantau omzet usahamu dari satu layar.',
    category: 'kasir',
    status: 'available',
    benefits: [
      'Catat dan rekap pesanan harian tanpa ribet',
      'Laporan penjualan & keuangan otomatis',
      'Pelanggan bisa pesan sendiri lewat QR meja',
      'Kelola staf, absensi, dan beberapa outlet',
      'Poin & hadiah untuk pelanggan setia',
    ],
    pricing: {
      label: 'Paket bulanan, tahunan, atau sekali bayar',
      note: 'Harga terbaru dan pilihan paket tampil setelah kamu masuk.',
    },
    preview: 'pos',
    tryHref: { guest: '/login?app=pos&subscribe=1', member: '/dashboard/apps/pos?subscribe=1' },
  },
  {
    slug: 'landing-page-builder',
    name: 'Landing Page Builder',
    icon: PenTool,
    summary: 'Bikin halaman jualan online sendiri tanpa coding, tinggal susun lalu terbitkan.',
    category: 'jualan',
    status: 'available',
    benefits: [
      'Susun halaman dengan geser-dan-lepas',
      'Komponen siap pakai: form, testimoni, FAQ, hitung mundur',
      'Terbitkan dan bagikan link-nya dalam hitungan menit',
      'Terima pesanan dan pembayaran langsung dari halamanmu',
    ],
    pricing: {
      label: 'Gratis untuk memulai',
      note: 'Cukup daftar akun Hellom, lalu langsung bikin halaman pertamamu.',
    },
    preview: 'builder',
    tryHref: { guest: '/login?app=landing_builder', member: '/dashboard/apps/landing-builder' },
  },
];

export function findApp(slug: string): HellomApp | undefined {
  return HELLOM_APPS.find((app) => app.slug === slug);
}

export const APP_STATUS_LABEL: Record<HellomApp['status'], string> = {
  available: 'Tersedia',
  coming_soon: 'Segera Hadir',
};
