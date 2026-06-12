export const NAV_LINKS = [
  { label: 'aplikasi', href: '#produk' },
  { label: 'fitur', href: '#services' },
  { label: 'harga', href: '#harga' },
  { label: 'kontak', href: '#cta' },
] as const;

export const HERO_BADGE = {
  text: 'solusi POS & bisnis',
} as const;

export const HERO_CONTENT = {
  headingLines: ['Satu platform.', 'Semua produk digital', 'siap kamu gunakan hari ini.'],
  description:
    'Hellomspace menyediakan ratusan produk digital - dari aplikasi kasir, ebook, ekstensi, hingga custom software. Pilih skema pembayaran yang cocok: bulanan, tahunan, lifetime, atau lisensi custom.',
  intro: 'Bisnis digital',
  outro: 'yang rapi, jelas, dan siap scale.',
  ctaPrimary: 'Mulai Sekarang',
  ctaSecondary: 'Lihat produk unggulan',
} as const;

export const TICKER_ITEMS = [
  'POS Restoran',
  'Landing Builder',
  'Template Premium',
  'Source Code',
  'Membership App',
  'Billing SaaS',
  'Digital Course',
  'White Label',
] as const;

export const STATS_DATA = [
  { num: '87+', label: 'Produk digital terverifikasi' },
  { num: '4', label: 'Metode pembayaran lokal terintegrasi' },
  { num: '24/7', label: 'Support teknis dari tim developer' },
] as const;

export const SERVICES_DATA = [
  {
    code: 'POS',
    name: 'Aplikasi Kasir & Manajemen Restoran',
    desc: 'Sistem POS lengkap dengan fitur meja, pesanan online, laporan omzet harian, dan integrasi payment gateway lokal seperti QRIS, DANA, dan transfer bank.',
  },
  {
    code: 'WEB',
    name: 'Landing Page Konversi Tinggi',
    desc: 'Halaman penjualan siap pakai dengan template teroptimasi untuk produk makanan, fashion, dan jasa lokal. Termasuk form pemesanan, countdown timer, dan integrasi WhatsApp.',
  },
  {
    code: 'DIGITAL',
    name: 'Produk Digital & Lisensi',
    desc: 'Katalog ebook, template website, aplikasi mobile, dan source code yang bisa langsung dibeli dan digunakan. Lisensi komersial included untuk penggunaan bisnis.',
  },
  {
    code: 'BRAND',
    name: 'Kits Branding & Promosi',
    desc: 'Visual identity, template media sosial, dan materi promosi yang konsisten untuk bangun citra profesional di Instagram, Facebook, dan marketplace.',
  },
  {
    code: 'EDU',
    name: 'Kursus & Pelatihan Praktis',
    desc: 'Video tutorial dan panduan lengkap untuk mengaplikasikan produk Hellomspace, mulai dari setup POS hingga strategi pemasaran digital untuk UMKM.',
  },
] as const;

export const WHY_POINTS = [
  {
    num: '01',
    title: 'Produk dari praktisi, bukan teori',
    desc: 'Setiap aplikasi dan template dibuat oleh pengusaha yang langsung menggunakannya untuk bisnisnya sendiri. Tidak ada fitur yang hanya indah di demo tapi sia-sia di lapangan.',
  },
  {
    num: '02',
    title: 'Harga transparan tanpa biaya tersembunyi',
    desc: 'Harga yang terlihat adalah harga yang dibayar. Tidak ada biaya setup, biaya per pengguna, atau biaya per transaksi yang tidak dijelaskan dari awal.',
  },
  {
    num: '03',
    title: 'Update gratis seumur hidup',
    desc: 'Setelah membeli, Anda akan menerima semua update dan fitur baru secara gratis seumur hidup. Tidak perlu bayar lagi untuk versi terbaru.',
  },
  {
    num: '04',
    title: 'Komunitas pengguna aktif',
    desc: 'Bergabung dengan ribuan pengguna Hellomspace yang aktif berbagi tips, template, dan solusi di grup komunitas eksklusif untuk mendapatkan hasil maksimal.',
  },
] as const;

export const PRODUCTS_DATA = [
  {
    badge: 'Direkomendasikan',
    badgeStyle: 'bg-yellow-400/10 text-yellow-400',
    title: 'POS Restoran Nusantara',
    desc: 'Sistem kasir khusus resto Indonesia dengan fitur pemesanan meja, komplit menu berbahan lokal, dan integrasi GoFood/GrabFood langsung ke printer dapur.',
    cta: 'lihat demo ->',
  },
  {
    badge: 'Terbaru',
    badgeStyle: 'bg-emerald-400/10 text-emerald-400',
    title: 'Template Menu Digital QRIS',
    desc: 'Halaman menu yang bisa diakses lewat scan QR di meja, lengkap dengan foto makanan, deskripsi bahan, dan tombol pesan langsung ke WhatsApp nomor resto.',
    cta: 'coba gratis ->',
  },
  {
    badge: 'Lifetime',
    badgeStyle: 'bg-purple-400/10 text-purple-400',
    title: 'Aplikasi Loyalitas Pelanggan',
    desc: 'Sistem stempel digital, referral program, dan notifikasi promo terpersonalisasi berdasarkan riwayat pelanggan. Siap untuk 10 outlet sekaligus.',
    cta: 'lihat fitur ->',
  },
] as const;

export const PRICING_DATA = [
  { label: 'Starter Paket', value: 'Gratis', note: 'POS dasar + 3 produk digital gratis' },
  { label: 'Paket Bisnis', value: 'Rp 199.000/bln', note: 'POS lengkap + produk digital premium + support prioritas' },
  { label: 'Paket Enterprise', value: 'Custom', note: 'Solusi custom + integrasi sistem + akun tidak terbatas' },
] as const;

export const TESTIMONIALS_DATA = [
  {
    quote:
      'Setelah pakai POS Hellom, omzet kantin kami naik 40% karena pelanggan bisa pesan lewat QR di meja tanpa perlu menunggu pelayan. Stok juga lebih terkontrol karena otomatis terupdate tiap transaksi.',
    name: 'Budi Santoso',
    role: 'Owner Kantin Sekolah, Surabaya',
    initials: 'BS',
    rating: 5,
  },
  {
    quote:
      'Template landing page jualan kue Hellomspace bikin omzet online kami naik 3x dalam sebulan. Sudah termasuk form pesanan dan integrasi pembayaran, cukup ganti foto produk dan harga.',
    name: 'Lestari',
    role: 'Pemilik Rumah Kue Lebaran, Bandung',
    initials: 'L',
    rating: 5,
  },
  {
    quote:
      'Saya beli aplikasi loyalty program dari Hellomspace untuk kedai kopi saya. Sekarang ada 200+ pelanggan aktif yang collect stempel setiap hari. Fitur referralnya juga bawa pelanggan baru tanpa iklan berbayar.',
    name: 'Raka',
    role: 'Owner Kedai Kopi Sunda, Jakarta',
    initials: 'R',
    rating: 5,
  },
] as const;

export const CTA_CONTENT = {
  headingLines: ['Siap membangun bisnis?', 'Pilih solusi', 'yang tepat untuk Anda.'],
  description:
    'Tidak perlu lagi gabung banyak platform atau belajar banyak sistem. Hellomspace hadir sebagai satu platform lengkap untuk segala kebutuhan digital bisnis Anda - dari operasional harian hingga penjualan online.',
  ctaPrimary: 'Mulai Gratis Sekarang',
  ctaSecondary: 'Lihat Demo Produk ->',
} as const;

export const FOOTER_LINKS = [
  { label: 'produk', href: '#produk' },
  { label: 'fitur', href: '#services' },
  { label: 'harga', href: '#harga' },
  { label: 'testimoni', href: '#testimoni' },
  { label: 'kontak', href: '#cta' },
] as const;

export const FOOTER_CONTENT = {
  tagline: 'Etalase digital yang siap jual, bukan sekadar tampil.',
  copyright: 'Copyright 2026 Hellom. Produk digital, SaaS, dan layanan untuk brand modern.',
} as const;
