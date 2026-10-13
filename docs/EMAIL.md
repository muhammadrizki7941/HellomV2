# Email Hellom: kirim lewat Brevo supaya tidak masuk spam

Semua email platform (verifikasi, reset kata sandi, struk, penjualan, penarikan, salinan notifikasi
owner) dikirim oleh `App\Services\Hellom\PlatformMailService` dengan SMTP yang diatur di
**Super admin › Pengaturan › Email**. Kodenya sudah rapi (versi teks, Reply-To, `Auto-Submitted`,
satu-klik berhenti langganan untuk email info & tips, riwayat + kirim ulang). Yang menentukan masuk
inbox atau spam adalah **domain pengirim**: Gmail & Outlook memeriksa SPF, DKIM, dan DMARC.

## Kenapa sekarang masuk spam

SMTP Gmail mengirim dengan tanda tangan `gmail.com`. Kalau alamat "From" adalah
`…@hellomspace.com`, DMARC hellomspace.com gagal → spam (atau From diganti jadi alamat Gmail).
Akun Gmail biasa juga dibatasi ±500 email/hari. Solusinya: penyedia email transaksional yang
menandatangani atas nama **hellomspace.com** — kita pakai **Brevo** (gratis 300 email/hari).

## Langkah 1 — Akun & domain di Brevo

1. Daftar di <https://www.brevo.com> (paket Free cukup untuk mulai).
2. **Senders, Domains & Dedicated IPs › Domains › Add a domain** → `hellomspace.com`.
3. Pilih *Authenticate the domain yourself*; Brevo menampilkan record DNS (biasanya 1 TXT
   `brevo-code`, 2 CNAME DKIM `brevo1._domainkey` & `brevo2._domainkey`, dan saran DMARC).
4. **Senders › Add a sender**: `noreply@hellomspace.com` (nama: *Hellom*). Kalau mau balasan
   pelanggan dibaca, buat juga `halo@hellomspace.com` (mailbox sungguhan) untuk Reply-To.

## Langkah 2 — Record DNS (di panel domain / Cloudflare)

| Tipe | Nama | Isi | Catatan |
|---|---|---|---|
| TXT | `@` | `brevo-code:xxxxxxxx` | dari Brevo (verifikasi kepemilikan) |
| CNAME | `brevo1._domainkey` | `b1.hellomspace-com.dkim.brevo.com` | salin persis dari Brevo |
| CNAME | `brevo2._domainkey` | `b2.hellomspace-com.dkim.brevo.com` | salin persis dari Brevo |
| TXT | `@` | `v=spf1 include:spf.brevo.com ~all` | **satu** record SPF saja — kalau sudah ada (mis. Google Workspace), gabungkan: `v=spf1 include:_spf.google.com include:spf.brevo.com ~all` |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@hellomspace.com; adkim=r; aspf=r` | mulai `p=none` (pantau) |

Cloudflare: CNAME DKIM harus **DNS only** (awan abu-abu), bukan proxied.
Tunggu 5–60 menit, lalu tekan **Authenticate** di Brevo sampai semua centang hijau.

Setelah 2 minggu tanpa masalah (laporan DMARC bersih), naikkan ke
`v=DMARC1; p=quarantine; pct=100; rua=mailto:dmarc@hellomspace.com`.

## Langkah 3 — Isi SMTP di Hellom

Brevo › **SMTP & API › SMTP**: buat *SMTP key*. Lalu di **Super admin › Pengaturan › Email**:

| Field | Isi |
|---|---|
| Aktif | ya |
| Host | `smtp-relay.brevo.com` |
| Port / Enkripsi | `587` / `tls` |
| Username | login SMTP dari Brevo (mis. `9a1b2c001@smtp-brevo.com`) |
| Password | SMTP key (bukan password akun Brevo) |
| From | `noreply@hellomspace.com` · nama `Hellom` |
| Reply-To | `halo@hellomspace.com` · nama `Tim Hellom` |
| Email owner | alamat yang menerima salinan notifikasi super admin |

Simpan → **Cek domain pengirim** di halaman yang sama harus hijau semua (SPF, DKIM, DMARC,
kecocokan SMTP & pengirim) → **Kirim email tes** ke Gmail kamu.

## Langkah 4 — Pastikan lolos

1. Gmail: buka email tes › ⋮ › **Show original** → `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`
   (domain hellomspace.com).
2. <https://www.mail-tester.com>: kirim email tes ke alamat yang diberikan → target ≥ 9/10.
3. **Riwayat email** (halaman Pengaturan Email) menampilkan setiap email: terkirim / gagal / berhenti
   langganan. Yang gagal bisa **Kirim ulang**.

## Catatan teknis

- Antrean: email penjualan, webhook pembayaran, sambutan, dan notifikasi dikirim lewat queue
  (`QUEUE_CONNECTION=database`, PM2 `hellom-queue`) dengan 3× percobaan (1 & 5 menit). Reset kata
  sandi & undangan tim tetap langsung (pengguna menunggu hasilnya).
- Pengaturan SMTP baru langsung dipakai worker (`Mail::purge`), tanpa restart.
- Email info & tips (`mailCategory = 'marketing'`, mis. pengumuman) punya header
  `List-Unsubscribe` + `List-Unsubscribe-Post` (syarat Gmail/Yahoo untuk pengirim massal) dan
  tautan di footer → `users.email_preferences.marketing = false`. Email transaksional selalu terkirim.
- Riwayat (`email_logs`) disimpan 90 hari (`model:prune` harian 03:40); isi email hanya disimpan
  (terenkripsi) untuk yang gagal, agar bisa dikirim ulang.
- Lokal/tes: jangan pernah mengirim email sungguhan — tes memakai mailer `array` atau
  `NoopPlatformMailService`; e2e memakai SMTP tiruan `tests/e2e/mocks.mjs`.
