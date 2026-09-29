import { nanoid } from 'nanoid';
import type { Block } from './types';
import type { ThemeOptions } from './components/SettingsModal';

// Ready-made Hellom Page templates (Fase 4). No stock photos or fake prices: products come
// from the seller's Produk tab (catalog / featured product blocks), text is a starting point.
export type PageTemplate = {
  id: string;
  name: string;
  description: string;
  themeId: string;
  options: ThemeOptions;
  blocks: () => Block[];
};

const b = (type: Block['type'], content: Record<string, unknown>, styles?: Block['styles']): Block => ({ id: nanoid(10), type, content, styles });

export const PAGE_TEMPLATES: PageTemplate[] = [
  {
    id: 'creator',
    name: 'Link-in-bio kreator',
    description: 'Profil, tombol link, katalog produk, dan sosial media. Cocok untuk bio Instagram/TikTok.',
    themeId: 'blush',
    options: { font: 'rounded', buttonShape: 'pill', buttonStyle: 'solid' },
    blocks: () => [
      b('profile', { name: '', bio: 'Konten kreator • berbagi tips & produk digital', showVerified: true }),
      b('button', { text: 'Produk terbaru aku', actionType: 'link', linkUrl: '#produk', align: 'center', fullWidth: true }),
      b('button', { text: 'Chat aku di WhatsApp', actionType: 'whatsapp', whatsappMessage: 'Halo kak, aku lihat dari bio kamu', align: 'center', fullWidth: true, style: 'outline' }),
      b('catalog', { title: 'Produk digital aku', showAll: true, productIds: [], columns: 2, buttonText: 'Beli' }),
      b('social', { title: 'Ikuti aku', instagram: '', tiktok: '', youtube: '' }),
    ],
  },
  {
    id: 'ebook',
    name: 'Jualan e-book',
    description: 'Headline, manfaat, isi e-book, testimoni, FAQ, dan tombol beli.',
    themeId: 'sunset',
    options: { font: 'serif', buttonShape: 'rounded', buttonStyle: 'solid' },
    blocks: () => [
      b('hero', { title: 'Panduan praktis yang langsung bisa kamu pakai hari ini', subtitle: 'E-book ringkas, contoh nyata, dan template siap pakai.', buttonText: 'Beli e-book', showButton: true, linkUrl: '#produk' }, { paddingY: 'py-20' }),
      b('list', { title: 'Yang kamu dapatkan', items: [{ text: 'E-book PDF, bisa dibaca di HP' }, { text: 'Template & checklist siap pakai' }, { text: 'Update gratis kalau ada revisi' }] }),
      b('product', { productId: null, buttonText: 'Beli sekarang' }),
      b('testimonials', { title: 'Kata pembaca', items: [{ name: 'Nama pembeli', role: 'Pembeli', text: 'Tulis testimoni asli dari pembeli kamu di sini.', rating: 5 }] }),
      b('faq', { title: 'Pertanyaan umum', items: [{ q: 'Bagaimana cara menerima e-book?', a: 'Setelah bayar, link akses dikirim otomatis ke email kamu.' }, { q: 'Bisa dibuka di HP?', a: 'Bisa, format PDF bisa dibuka di HP maupun laptop.' }] }),
      b('countdown', { title: 'Harga promo berakhir dalam', subtitle: '', targetDate: new Date(Date.now() + 3 * 86400000).toISOString(), expiredText: 'Promo sudah berakhir' }),
    ],
  },
  {
    id: 'course',
    name: 'Kelas online',
    description: 'Untuk kelas/webinar/mentoring: video perkenalan, materi, profil pengajar, harga.',
    themeId: 'ocean',
    options: { font: 'sans', buttonShape: 'rounded', buttonStyle: 'solid' },
    blocks: () => [
      b('hero', { title: 'Kuasai skill baru dalam 4 minggu', subtitle: 'Kelas online terstruktur, bisa belajar kapan saja, ada grup diskusi.', buttonText: 'Daftar kelas', showButton: true, linkUrl: '#produk' }, { paddingY: 'py-20' }),
      b('video', { title: 'Kenalan dulu yuk', videoUrl: '' }),
      b('features', { title: 'Materi kelas', items: [{ title: 'Minggu 1', desc: 'Dasar-dasar' }, { title: 'Minggu 2', desc: 'Praktik langsung' }, { title: 'Minggu 3–4', desc: 'Proyek & review' }] }),
      b('profile', { name: 'Nama pengajar', bio: 'Tulis pengalaman singkat pengajar di sini.', showVerified: false }),
      b('product', { productId: null, buttonText: 'Daftar sekarang' }),
      b('faq', { title: 'FAQ', items: [{ q: 'Kelasnya live atau rekaman?', a: 'Tulis jawabannya di sini.' }, { q: 'Dapat sertifikat?', a: 'Tulis jawabannya di sini.' }] }),
    ],
  },
  {
    id: 'physical',
    name: 'Produk fisik',
    description: 'Etalase barang: galeri foto, katalog, ongkir, dan tombol WhatsApp.',
    themeId: 'minimal',
    options: { font: 'sans', buttonShape: 'rounded', buttonStyle: 'solid' },
    blocks: () => [
      b('profile', { name: '', bio: 'Produk handmade • kirim ke seluruh Indonesia', showVerified: true }),
      b('gallery', { title: '', columns: 3, images: [] }),
      b('catalog', { title: 'Katalog', showAll: true, productIds: [], columns: 2, buttonText: 'Beli' }),
      b('list', { title: 'Kenapa belanja di sini', items: [{ text: 'Dikirim 1×24 jam setelah bayar' }, { text: 'Nomor resi langsung dikirim ke email' }, { text: 'Bisa tanya dulu via WhatsApp' }] }),
      b('cta', { title: 'Mau tanya stok atau ukuran?', subtitle: 'Chat kami, balas cepat di jam kerja.', buttonText: 'Chat WhatsApp', actionType: 'whatsapp', whatsappMessage: 'Halo, saya mau tanya produk' }),
    ],
  },
  {
    id: 'service',
    name: 'Jasa / booking',
    description: 'Untuk jasa desain, konsultasi, foto, dll: layanan, portofolio, testimoni, pesan.',
    themeId: 'forest',
    options: { font: 'sans', buttonShape: 'rounded', buttonStyle: 'solid' },
    blocks: () => [
      b('hero', { title: 'Jasa profesional, hasil rapi, tepat waktu', subtitle: 'Ceritakan kebutuhan kamu saat pesan, kami hubungi dalam 1×24 jam.', buttonText: 'Pesan jasa', showButton: true, linkUrl: '#produk' }, { paddingY: 'py-20' }),
      b('catalog', { title: 'Paket layanan', showAll: true, productIds: [], columns: 2, buttonText: 'Pesan' }),
      b('gallery', { title: 'Portofolio', columns: 3, images: [] }),
      b('testimonials', { title: 'Klien kami', items: [{ name: 'Nama klien', role: 'Klien', text: 'Tulis testimoni asli dari klien kamu.', rating: 5 }] }),
      b('form', { title: 'Konsultasi gratis', subtitle: 'Isi form, kami balas via WhatsApp.', buttonText: 'Kirim', successMessage: 'Terima kasih! Kami segera menghubungi kamu.', sendToWhatsapp: true,
        fields: [{ id: 'name', label: 'Nama', type: 'text', required: true, system: true }, { id: 'phone', label: 'Nomor WhatsApp', type: 'tel', required: true, system: true }, { id: 'kebutuhan', label: 'Kebutuhan', type: 'textarea', required: true }] }),
    ],
  },
];
