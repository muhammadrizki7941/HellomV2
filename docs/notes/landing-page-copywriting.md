# Landing Page Copywriting Update

Tanggal: 2026-05-15

## Scope

Revisi ini hanya menyentuh copy statis yang tampil di landing page `http://localhost:3000/`.
Tidak ada perubahan pada backend, API, state management, routing aplikasi inti, styling, atau query database.

## File yang Diubah

- `plans/UI/src/lib/landingData.ts`
- `plans/UI/src/components/landing/HeroSection.tsx`
- `plans/UI/src/components/landing/ServicesSection.tsx`
- `plans/UI/src/components/landing/WhySection.tsx`
- `plans/UI/src/components/landing/PricingSection.tsx`
- `plans/UI/src/components/landing/TestimonialsSection.tsx`
- `plans/UI/src/components/landing/CTASection.tsx`
- `plans/UI/src/components/landing/Navbar.tsx`
- `plans/UI/src/components/landing/Footer.tsx`

## Ringkasan Revisi

- Hero headline diubah dari positioning etalase menjadi positioning platform produk digital siap pakai.
- Hero body diperbarui agar menekankan katalog produk digital, skema pembayaran, dan kemudahan penggunaan.
- Semua copy yang merujuk ke `trial`, `free trial`, `14 hari gratis`, dan frasa sejenis dibersihkan dari homepage.
- Label stats diperbarui menjadi fokus ke jumlah produk, skema pembayaran, dan dukungan aktif.
- Headline section kategori, why, pricing, testimonial, dan CTA akhir disesuaikan dengan brief.
- Label paket pricing diubah menjadi `Gratis`, `Pro - Rp 199rb/bln`, dan `Custom / Enterprise`.
- CTA berbasis `gratis/trial` diganti menjadi CTA netral seperti `Mulai Sekarang`.

## Catatan Implementasi

- Pada kartu kategori `CS`, teks CTA sudah diubah menjadi `Konsultasi via WhatsApp ->`.
- Link CTA WhatsApp owner belum diubah di kartu kategori karena komponen saat ini hanya merender teks CTA statis, bukan elemen link khusus. Menjaga batas brief, saya tidak mengubah struktur komponen.
- CTA existing di section pricing dan CTA akhir sudah diperbarui copy-nya agar mengarah ke intent konsultasi/custom, tetapi target link teknisnya masih mengikuti struktur komponen yang ada.

## Validasi

- Pencarian ulang pada komponen landing page tidak lagi menemukan frasa `coba gratis`, `uji coba`, `free trial`, `14 hari gratis`, atau `trial`.
