import { BlockType } from './types';

export const THEMES = [
  {
    id: 'industrial',
    name: 'Hellom Industrial',
    colors: {
      backgroundColor: '#ffffff',
      textColor: '#18181b',
      buttonColor: '#facc15', // Yellow-400
      buttonTextColor: '#000000',
      accentColor: '#facc15'
    }
  },
  {
    id: 'ocean',
    name: 'Ocean Breeze',
    colors: {
      backgroundColor: '#f0f9ff', // Sky-50
      textColor: '#0c4a6e', // Sky-900
      buttonColor: '#0ea5e9', // Sky-500
      buttonTextColor: '#ffffff',
      accentColor: '#0ea5e9'
    }
  },
  {
    id: 'forest',
    name: 'Forest Calm',
    colors: {
      backgroundColor: '#fcfdf5', // Lime-50 (approx)
      textColor: '#1a2e05', // Lime-950
      buttonColor: '#4d7c0f', // Lime-700
      buttonTextColor: '#ffffff',
      accentColor: '#4d7c0f'
    }
  },
  {
    id: 'luxury',
    name: 'Midnight Luxury',
    colors: {
      backgroundColor: '#09090b', // Zinc-950
      textColor: '#fafafa', // Zinc-50
      buttonColor: '#d4af37', // Gold
      buttonTextColor: '#000000',
      accentColor: '#d4af37'
    }
  }
];

export const defaultContent: Record<BlockType, any> = {
  hero: {
    title: "Headline yang Menarik Perhatian",
    subtitle: "Jelaskan nilai utama produk Anda di sini. Buat pengunjung tertarik untuk mengetahui lebih lanjut.",
    buttonText: "Mulai Sekarang",
    showButton: true
  },
  features: {
    title: "Fitur Unggulan",
    items: [
      { title: "Fitur 1", desc: "Deskripsi singkat fitur 1." },
      { title: "Fitur 2", desc: "Deskripsi singkat fitur 2." },
      { title: "Fitur 3", desc: "Deskripsi singkat fitur 3." }
    ]
  },
  cta: {
    title: "Siap untuk Memulai?",
    subtitle: "Bergabunglah sekarang dan rasakan perbedaannya.",
    buttonText: "Daftar Gratis",
    actionType: "whatsapp",
    whatsappNumber: "",
    whatsappMessage: "Halo, saya tertarik dan ingin daftar.",
    linkUrl: "#"
  },
  content: {
    title: "Tentang Kami",
    body: "Tulis cerita brand Anda di sini. Mengapa orang harus memilih produk Anda? Apa yang membuat Anda berbeda?"
  },
  banner: {
    imageUrl: "https://picsum.photos/seed/banner/1200/400",
    title: "Promo Spesial Hari Ini",
    subtitle: "Diskon hingga 50% untuk semua produk.",
    textColor: "#ffffff",
    overlayOpacity: 0.5
  },
  product: {
    imageUrl: "https://picsum.photos/seed/product/400/400",
    name: "Nama Produk Premium",
    price: "Rp 199.000",
    description: "Deskripsi singkat produk yang menjelaskan keunggulan dan spesifikasi utama.",
    buttonText: "Beli Sekarang",
    productUrl: "#"
  },
  video: {
    videoUrl: "https://www.youtube.com/embed/dQw4w9WgXcQ", // Default placeholder
    title: "Video Perkenalan"
  },
  text: {
    body: "Tulis teks paragraf Anda di sini. Anda bisa menggunakan blok ini untuk artikel, pengumuman, atau informasi detail lainnya."
  },
  image: {
    imageUrl: "https://picsum.photos/seed/image/800/600",
    caption: "Caption gambar opsional"
  },
  pdf: {
    fileUrl: "#",
    fileName: "Dokumen_Penting.pdf",
    title: "Download Katalog Produk",
    description: "Unduh katalog lengkap kami dalam format PDF.",
    accessType: "free",
    price: "Rp 0",
    buttonText: "Download Gratis",
    paidButtonText: "Beli Katalog",
    paymentType: "gateway"
  },
  social: {
    facebook: "",
    instagram: "",
    tiktok: "",
    threads: ""
  },
  form: {
    title: "Form Pendaftaran",
    subtitle: "Isi data Anda, tim kami akan menghubungi secepatnya.",
    buttonText: "Kirim Pendaftaran",
    successMessage: "Terima kasih, data Anda sudah terkirim.",
    fields: [
      { id: "name", label: "Nama Lengkap", type: "text", required: true, system: true },
      { id: "phone", label: "Nomor HP", type: "tel", required: true, system: true },
      { id: "email", label: "Email", type: "email", required: false, system: true }
    ]
  },
  button: {
    text: "Klik di Sini",
    actionType: "link", // 'link' | 'whatsapp'
    linkUrl: "#",
    whatsappNumber: "",
    whatsappMessage: "Halo, saya tertarik dengan penawaran Anda.",
    align: "center" // 'left' | 'center' | 'right'
  },
  divider: {
    style: "solid", // 'solid' | 'dashed' | 'dotted'
    thickness: 1,
    width: 100 // percent
  },
  testimonials: {
    title: "Apa Kata Pelanggan?",
    items: [
      { name: "Sarah W.", role: "Pembeli", text: "Pelayanan cepat dan produknya sesuai ekspektasi. Recommended!", rating: 5 },
      { name: "Budi P.", role: "Pelanggan Setia", text: "Sudah langganan dari lama, kualitasnya konsisten dan harganya pas.", rating: 5 },
      { name: "Dewi A.", role: "Reseller", text: "Tim support ramah dan responsif. Bikin nyaman belanja.", rating: 4 }
    ]
  },
  faq: {
    title: "Pertanyaan yang Sering Diajukan",
    items: [
      { q: "Bagaimana cara memesan?", a: "Klik tombol pesan, isi data, lalu lakukan pembayaran sesuai instruksi." },
      { q: "Berapa lama pengiriman?", a: "Estimasi 1-3 hari kerja tergantung lokasi tujuan." },
      { q: "Apakah bisa refund?", a: "Bisa, sesuai dengan syarat dan ketentuan yang berlaku." }
    ]
  },
  list: {
    title: "Yang Anda Dapatkan",
    items: [
      { text: "Akses penuh tanpa batas waktu" },
      { text: "Update gratis selamanya" },
      { text: "Dukungan prioritas 24/7" }
    ]
  },
  slider: {
    autoplay: true,
    images: [
      { url: "https://picsum.photos/seed/slide1/1200/600", caption: "" },
      { url: "https://picsum.photos/seed/slide2/1200/600", caption: "" },
      { url: "https://picsum.photos/seed/slide3/1200/600", caption: "" }
    ]
  },
  countdown: {
    title: "Promo Berakhir Dalam",
    subtitle: "Jangan sampai kehabisan, segera ambil penawaran ini!",
    targetDate: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString()
  },
  gif: {
    gifUrl: "https://media.giphy.com/media/3o7TKsQ8gqVrUqmXSU/giphy.gif",
    caption: "Caption GIF opsional"
  },
  html: {
    html: "<div style=\"text-align:center;padding:24px;\">\n  <h3>Blok HTML Kustom</h3>\n  <p>Tempel kode HTML Anda di sini.</p>\n</div>"
  }
};
