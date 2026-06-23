import React, { createContext, useContext, useMemo, useState } from 'react';

export type Lang = 'id' | 'en';

type Entry = { id: string; en: string };

/**
 * Self-contained dictionary for the Landing Builder UI chrome.
 * The app has no global i18n system, so this stays scoped to the builder.
 * User content (block defaults) stays editable and is not translated here.
 */
const DICT: Record<string, Entry> = {
  // --- Toolbar / chrome ---
  'chrome.draft': { id: 'Mode Draf', en: 'Draft Mode' },
  'chrome.theme': { id: 'Tema', en: 'Theme' },
  'chrome.ai': { id: 'AI Magic', en: 'AI Magic' },
  'chrome.settings': { id: 'Pengaturan', en: 'Settings' },
  'chrome.preview': { id: 'Pratinjau', en: 'Preview' },
  'chrome.edit': { id: 'Edit', en: 'Edit' },
  'chrome.save': { id: 'Simpan', en: 'Save' },
  'chrome.publish': { id: 'Terbitkan', en: 'Publish' },
  'chrome.saving': { id: 'Menyimpan...', en: 'Saving...' },
  'chrome.hideSidebar': { id: 'Sembunyikan panel', en: 'Hide panel' },
  'chrome.showSidebar': { id: 'Tampilkan panel', en: 'Show panel' },
  'chrome.language': { id: 'Bahasa', en: 'Language' },

  // --- Canvas empty states ---
  'canvas.empty': {
    id: 'Klik block di panel kiri untuk mulai membuat halaman',
    en: 'Click a block in the left panel to start building',
  },
  'canvas.emptyMobile': {
    id: 'Tap tombol "+ Tambah Block" di bawah untuk mulai',
    en: 'Tap the "+ Add Block" button below to start',
  },

  // --- Toolbox ---
  'toolbox.components': { id: 'Komponen', en: 'Components' },
  'toolbox.structure': { id: 'Struktur', en: 'Structure' },
  'toolbox.search': { id: 'Cari block...', en: 'Search blocks...' },
  'toolbox.empty': { id: 'Block tidak ditemukan.', en: 'No blocks found.' },
  'toolbox.add': { id: 'Tambah Block', en: 'Add Block' },
  'toolbox.editBlock': { id: 'Edit Block', en: 'Edit Block' },
  'toolbox.close': { id: 'Tutup', en: 'Close' },
  'toolbox.structureEmpty': {
    id: 'Belum ada block. Tambah dari tab Komponen.',
    en: 'No blocks yet. Add some from the Components tab.',
  },
  'toolbox.structureHint': {
    id: 'Klik untuk memilih, seret untuk mengurutkan.',
    en: 'Click to select, drag to reorder.',
  },
  'toolbox.dragHint': {
    id: 'Seret komponen ke canvas, atau klik untuk menambah.',
    en: 'Drag a component onto the canvas, or click to add.',
  },
  'canvas.dropHere': { id: 'Lepas block di sini', en: 'Drop block here' },
  'canvas.dropSlot': { id: 'Letakkan block di sini', en: 'Place a block here' },
  'canvas.dropEmpty': {
    id: 'Seret komponen ke sini untuk mulai',
    en: 'Drag a component here to start',
  },

  // --- Categories ---
  'cat.all': { id: 'Semua', en: 'All' },
  'cat.popular': { id: 'Sering Digunakan', en: 'Frequently Used' },
  'cat.order': { id: 'Form Pemesanan Online', en: 'Online Order Form' },
  'cat.sales': { id: 'Sales Page', en: 'Sales Page' },
  'cat.other': { id: 'Lainnya', en: 'Others' },

  // --- Property panel ---
  'pp.empty': {
    id: 'Pilih block di canvas untuk mengedit propertinya',
    en: 'Select a block on the canvas to edit its properties',
  },
  'pp.editBlock': { id: 'Edit Block', en: 'Edit Block' },
  'pp.layout': { id: 'Tata Letak', en: 'Layout' },
  'pp.padding': { id: 'Jarak Atas-Bawah', en: 'Vertical Spacing' },
  'pp.small': { id: 'Kecil', en: 'Small' },
  'pp.medium': { id: 'Sedang', en: 'Medium' },
  'pp.large': { id: 'Besar', en: 'Large' },
  'pp.textAlign': { id: 'Perataan Teks', en: 'Text Alignment' },
  'pp.colors': { id: 'Warna', en: 'Colors' },
  'pp.bgColor': { id: 'Warna Latar', en: 'Background Color' },
  'pp.textColor': { id: 'Warna Teks', en: 'Text Color' },
  'pp.buttonColor': { id: 'Warna Tombol', en: 'Button Color' },
  'pp.bgImage': { id: 'Gambar Latar', en: 'Background Image' },
  'pp.uploadImage': { id: 'Unggah Gambar', en: 'Upload Image' },
  'pp.changeImage': { id: 'Ganti Gambar', en: 'Change Image' },
  'pp.removeBgImage': { id: 'Hapus gambar latar', en: 'Remove background image' },

  // --- Common content fields ---
  'pp.title': { id: 'Judul', en: 'Title' },
  'pp.subtitle': { id: 'Subjudul', en: 'Subtitle' },
  'pp.body': { id: 'Isi Teks', en: 'Body Text' },
  'pp.image': { id: 'Gambar', en: 'Image' },
  'pp.bannerImage': { id: 'Gambar Banner', en: 'Banner Image' },
  'pp.buttonText': { id: 'Teks Tombol', en: 'Button Text' },
  'pp.caption': { id: 'Keterangan', en: 'Caption' },
  'pp.add': { id: 'Tambah', en: 'Add' },

  // --- Block-specific labels ---
  'pp.product.name': { id: 'Nama Produk', en: 'Product Name' },
  'pp.product.price': { id: 'Harga', en: 'Price' },
  'pp.product.desc': { id: 'Deskripsi', en: 'Description' },
  'pp.product.payment': { id: 'Aksi Pembayaran', en: 'Payment Action' },
  'pp.product.linkUrl': { id: 'URL Link Eksternal', en: 'External Link URL' },
  'pp.video.url': { id: 'URL Embed Video', en: 'Video Embed URL' },
  'pp.video.hint': { id: 'Gunakan link embed YouTube/Vimeo.', en: 'Use a YouTube/Vimeo embed link.' },
  'pp.cta.action': { id: 'Aksi CTA', en: 'CTA Action' },
  'pp.cta.type': { id: 'Tipe Aksi', en: 'Action Type' },
  'pp.cta.wa': { id: 'Arahkan ke WhatsApp', en: 'Redirect to WhatsApp' },
  'pp.cta.link': { id: 'Buka link custom', en: 'Open custom link' },
  'pp.cta.waNumber': { id: 'Nomor WhatsApp', en: 'WhatsApp Number' },
  'pp.cta.waMessage': { id: 'Pesan WhatsApp', en: 'WhatsApp Message' },
  'pp.cta.linkUrl': { id: 'URL Link', en: 'Link URL' },
  'pp.form.fields': { id: 'Field Form', en: 'Form Fields' },
  'pp.form.fieldLabel': { id: 'Label field', en: 'Field label' },
  'pp.form.required': { id: 'Wajib diisi', en: 'Required' },
  'pp.form.success': { id: 'Pesan Sukses', en: 'Success Message' },
  'pp.features.items': { id: 'Daftar Fitur', en: 'Feature Items' },
  'pp.pdf.file': { id: 'File PDF', en: 'PDF File' },
  'pp.pdf.access': { id: 'Akses Katalog', en: 'Catalog Access' },
  'pp.pdf.free': { id: 'Gratis - user langsung download', en: 'Free - instant download' },
  'pp.pdf.paid': { id: 'Berbayar - user checkout dulu', en: 'Paid - checkout first' },
  'pp.pdf.price': { id: 'Harga Katalog', en: 'Catalog Price' },

  // --- New blocks ---
  'pp.button.align': { id: 'Posisi Tombol', en: 'Button Position' },
  'pp.align.left': { id: 'Kiri', en: 'Left' },
  'pp.align.center': { id: 'Tengah', en: 'Center' },
  'pp.align.right': { id: 'Kanan', en: 'Right' },
  'pp.divider.style': { id: 'Gaya Garis', en: 'Line Style' },
  'pp.divider.solid': { id: 'Penuh', en: 'Solid' },
  'pp.divider.dashed': { id: 'Putus-putus', en: 'Dashed' },
  'pp.divider.dotted': { id: 'Titik-titik', en: 'Dotted' },
  'pp.divider.thickness': { id: 'Ketebalan (px)', en: 'Thickness (px)' },
  'pp.divider.width': { id: 'Lebar (%)', en: 'Width (%)' },
  'pp.testi.items': { id: 'Daftar Testimoni', en: 'Testimonials' },
  'pp.testi.name': { id: 'Nama', en: 'Name' },
  'pp.testi.role': { id: 'Jabatan / Asal', en: 'Role / From' },
  'pp.testi.text': { id: 'Isi Testimoni', en: 'Testimonial Text' },
  'pp.testi.rating': { id: 'Rating (1-5)', en: 'Rating (1-5)' },
  'pp.faq.items': { id: 'Daftar Pertanyaan', en: 'Questions' },
  'pp.faq.q': { id: 'Pertanyaan', en: 'Question' },
  'pp.faq.a': { id: 'Jawaban', en: 'Answer' },
  'pp.list.items': { id: 'Daftar Item', en: 'List Items' },
  'pp.list.text': { id: 'Teks item', en: 'Item text' },
  'pp.slider.images': { id: 'Gambar Slider', en: 'Slider Images' },
  'pp.slider.imageUrl': { id: 'URL Gambar', en: 'Image URL' },
  'pp.slider.autoplay': { id: 'Putar otomatis', en: 'Autoplay' },
  'pp.slider.upload': { id: 'Unggah gambar (maks 1 MB)', en: 'Upload image (max 1 MB)' },
  'pp.slider.uploadHint': {
    id: 'Tempel link gambar atau unggah file. Ukuran maksimal 1 MB per gambar.',
    en: 'Paste an image link or upload a file. Max 1 MB per image.',
  },
  'pp.slider.tooLarge': {
    id: 'Ukuran gambar melebihi 1 MB. Pakai gambar yang lebih kecil.',
    en: 'Image exceeds 1 MB. Please use a smaller image.',
  },
  'pp.countdown.target': { id: 'Tanggal & Waktu Target', en: 'Target Date & Time' },
  'pp.countdown.hint': {
    id: 'Hitung mundur akan berhenti di tanggal ini.',
    en: 'The countdown stops at this date.',
  },
  'pp.gif.url': { id: 'URL GIF', en: 'GIF URL' },
  'pp.html.code': { id: 'Kode HTML', en: 'HTML Code' },
  'pp.html.hint': {
    id: 'Tempel HTML kustom. Hati-hati, kode dijalankan apa adanya.',
    en: 'Paste custom HTML. Be careful — code runs as-is.',
  },

  // --- Block catalog labels / descriptions ---
  'block.hero.label': { id: 'Hero', en: 'Hero' },
  'block.hero.desc': { id: 'Bagian pembuka besar', en: 'Big opening section' },
  'block.banner.label': { id: 'Banner', en: 'Banner' },
  'block.banner.desc': { id: 'Banner gambar penuh', en: 'Full-width image banner' },
  'block.features.label': { id: 'Fitur', en: 'Features' },
  'block.features.desc': { id: 'Grid keunggulan produk', en: 'Product highlights grid' },
  'block.product.label': { id: 'Produk', en: 'Product' },
  'block.product.desc': { id: 'Kartu produk + checkout', en: 'Product card + checkout' },
  'block.video.label': { id: 'YouTube', en: 'YouTube' },
  'block.video.desc': { id: 'Video YouTube / embed', en: 'YouTube / embed video' },
  'block.text.label': { id: 'Teks', en: 'Text' },
  'block.text.desc': { id: 'Blok teks sederhana', en: 'Simple text block' },
  'block.content.label': { id: 'Konten', en: 'Content' },
  'block.content.desc': { id: 'Judul + paragraf', en: 'Heading + paragraph' },
  'block.image.label': { id: 'Gambar', en: 'Image' },
  'block.image.desc': { id: 'Gambar tunggal', en: 'Single image' },
  'block.pdf.label': { id: 'PDF', en: 'PDF' },
  'block.pdf.desc': { id: 'Unduh / jual file', en: 'Download / sell a file' },
  'block.cta.label': { id: 'CTA', en: 'CTA' },
  'block.cta.desc': { id: 'Ajakan bertindak', en: 'Call to action' },
  'block.form.label': { id: 'Form Pemesanan', en: 'Order Form' },
  'block.form.desc': { id: 'Form pemesanan sederhana', en: 'Simple order form' },
  'block.social.label': { id: 'Sosial Media', en: 'Social' },
  'block.social.desc': { id: 'Link sosial media', en: 'Social media links' },
  'block.button.label': { id: 'Button', en: 'Button' },
  'block.button.desc': { id: 'Tombol aksi', en: 'Action button' },
  'block.divider.label': { id: 'Divider', en: 'Divider' },
  'block.divider.desc': { id: 'Garis pemisah', en: 'Separator line' },
  'block.testimonials.label': { id: 'Testimoni', en: 'Testimonials' },
  'block.testimonials.desc': { id: 'Blok testimoni', en: 'Testimonial block' },
  'block.faq.label': { id: 'FAQ', en: 'FAQ' },
  'block.faq.desc': { id: 'Pertanyaan umum', en: 'Common questions' },
  'block.list.label': { id: 'List Item', en: 'List Item' },
  'block.list.desc': { id: 'Daftar / list item', en: 'Bullet / checklist' },
  'block.slider.label': { id: 'Gambar Slider', en: 'Image Slider' },
  'block.slider.desc': { id: 'Slider gambar', en: 'Image carousel' },
  'block.countdown.label': { id: 'Countdown', en: 'Countdown' },
  'block.countdown.desc': { id: 'Hitung mundur promo', en: 'Promo countdown' },
  'block.gif.label': { id: 'GIF Animation', en: 'GIF Animation' },
  'block.gif.desc': { id: 'Animasi GIF', en: 'Animated GIF' },
  'block.html.label': { id: 'HTML', en: 'HTML' },
  'block.html.desc': { id: 'Custom HTML', en: 'Custom HTML' },
};

interface LangContextValue {
  lang: Lang;
  setLang: (lang: Lang) => void;
  t: (key: string) => string;
}

const LangContext = createContext<LangContextValue>({
  lang: 'id',
  setLang: () => undefined,
  t: (key) => key,
});

export const useLang = () => useContext(LangContext);

const STORAGE_KEY = 'hellom_landing_builder_lang';

export const LanguageProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [lang, setLangState] = useState<Lang>(() => {
    if (typeof window === 'undefined') return 'id';
    const stored = window.localStorage.getItem(STORAGE_KEY);
    return stored === 'en' ? 'en' : 'id';
  });

  const value = useMemo<LangContextValue>(() => ({
    lang,
    setLang: (next) => {
      setLangState(next);
      try {
        window.localStorage.setItem(STORAGE_KEY, next);
      } catch {
        /* ignore */
      }
    },
    t: (key) => DICT[key]?.[lang] ?? key,
  }), [lang]);

  return <LangContext.Provider value={value}>{children}</LangContext.Provider>;
};
