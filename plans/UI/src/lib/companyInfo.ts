/**
 * Informasi legal & kontak bisnis untuk halaman publik (FAQ, Refund Policy,
 * Terms & Conditions, Kontak).
 *
 * PENTING: data di sini ditampilkan publik dan diperiksa saat verifikasi
 * payment gateway (iPaymu). Pastikan SEMUA terisi dengan data ASLI & valid.
 *
 * Catatan: email & nomor telepon/WhatsApp diambil otomatis dari Brand Settings
 * (admin) bila tersedia. Nilai di bawah dipakai sebagai cadangan + detail yang
 * tidak ada di Brand Settings (alamat, jam operasional, badan usaha).
 */
export const COMPANY_INFO = {
  /** Nama badan usaha / pemilik usaha terdaftar. */
  legalName: 'Hellom',
  /** Nama brand yang tampil ke publik. */
  brandName: 'Hellom',
  /** Domain utama tanpa protokol. */
  domain: 'hellomspace.com',

  /** Cadangan kontak bila Brand Settings kosong. Isi dengan data asli. */
  fallbackEmail: 'hellom.official01@gmail.com',
  fallbackPhone: '+62 857-8912-8156',
  /** Nomor WhatsApp aktif (boleh sama dengan telepon). */
  whatsapp: '+62 857-8912-8156',

  /** ALAMAT USAHA — WAJIB diisi dengan alamat asli & lengkap. */
  addressLine: 'Semuli Raya RT 002 / RW 004, Desa Semuli Raya, Kec. Abung Semuli',
  city: 'Kab. Lampung Utara',
  province: 'Lampung',
  postalCode: '34581', // Abung Semuli, Lampung Utara
  country: 'Indonesia',

  /** Jam operasional layanan pelanggan. */
  operatingHours: 'Senin – Jumat, 09.00 – 17.00 WIB',
  /** Tahun mulai beroperasi (untuk konteks di halaman legal). */
  establishedYear: '2026',
} as const;

/** True bila alamat sudah diisi dengan data asli (bukan placeholder default). */
export function isCompanyAddressConfigured(): boolean {
  const line = COMPANY_INFO.addressLine.trim();
  return line !== '' && !line.toLowerCase().startsWith('lengkapi');
}

/** Gabungkan alamat menjadi satu baris yang rapi (mengabaikan bagian kosong). */
export function formatCompanyAddress(): string {
  if (!isCompanyAddressConfigured()) {
    return '';
  }

  const { addressLine, city, province, postalCode, country } = COMPANY_INFO;
  return [addressLine, city, province, postalCode, country]
    .map((part) => String(part || '').trim())
    .filter(Boolean)
    .join(', ');
}
