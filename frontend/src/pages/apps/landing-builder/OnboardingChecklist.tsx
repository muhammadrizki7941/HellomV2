import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, ChevronRight, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { sendEmailVerification } from '@/lib/hellomApi';
import type { LandingOnboarding } from '@/lib/hellomApi';

type Item = { key: string; label: string; hint: string; done: boolean; optional?: boolean; action: React.ReactNode };

/** "Siapkan toko kamu" on the overview: what is left before the shop can sell and pay out. */
export default function OnboardingChecklist({ data, onStartWizard, onOpenProducts, onOpenSettings, onNotice }: {
  data: LandingOnboarding;
  onStartWizard: () => void;
  onOpenProducts: () => void;
  onOpenSettings: () => void;
  onNotice: (text: string) => void;
}) {
  const [sending, setSending] = useState(false);
  const c = data.checklist;

  const resend = async () => {
    setSending(true);
    try {
      const result = await sendEmailVerification();
      onNotice(result.email_verified ? 'Email kamu sudah terverifikasi.' : 'Link verifikasi dikirim. Cek kotak masuk (atau folder spam).');
    } catch (err) {
      onNotice(err instanceof Error ? err.message : 'Email belum terkirim. Coba lagi sebentar lagi.');
    } finally {
      setSending(false);
    }
  };

  const actionClass = 'inline-flex min-h-11 shrink-0 items-center gap-1 rounded-xl px-3 text-sm font-semibold text-zinc-900 hover:bg-zinc-100';
  const payoutHint: Record<string, string> = {
    none: 'Isi data KTP & rekening supaya saldo penjualan bisa ditarik.',
    unverified: 'Lengkapi data KTP & rekening supaya saldo bisa ditarik.',
    pending: 'Sedang ditinjau tim Hellom (biasanya 1×24 jam).',
    rejected: 'Data ditolak. Buka untuk melihat alasannya dan kirim ulang.',
    verified: 'Rekening terverifikasi.',
  };

  const items: Item[] = [
    { key: 'username', label: 'Pilih username toko', hint: c.username ? data.public_url.replace(/^https?:\/\//, '') : 'Alamat toko yang mudah diingat.', done: c.username,
      action: <button type="button" onClick={onStartWizard} className={actionClass}>Atur <ChevronRight className="h-4 w-4" /></button> },
    { key: 'first_product', label: 'Tambah produk pertama', hint: c.first_product ? `${data.products_count} produk aktif.` : 'E-book, kelas, file, atau barang fisik.', done: c.first_product,
      action: <button type="button" onClick={onOpenProducts} className={actionClass}>Tambah <ChevronRight className="h-4 w-4" /></button> },
    { key: 'page_published', label: 'Terbitkan halaman', hint: c.page_published ? 'Halaman kamu sudah online.' : 'Pilih template, lalu terbitkan dalam 1 menit.', done: c.page_published,
      action: <button type="button" onClick={onStartWizard} className={actionClass}>Mulai <ChevronRight className="h-4 w-4" /></button> },
    { key: 'email_verified', label: 'Verifikasi email', hint: c.email_verified ? 'Email terverifikasi.' : 'Wajib sebelum menarik dana.', done: c.email_verified,
      action: <button type="button" onClick={() => void resend()} disabled={sending} className={actionClass}>{sending && <Loader2 className="h-4 w-4 animate-spin" />} Kirim link</button> },
    { key: 'payout', label: 'Data diri & rekening', hint: payoutHint[c.payout_status] ?? payoutHint.none, done: c.payout_status === 'verified',
      action: <Link to="/dashboard/apps/landing-builder?tab=saldo&rekening=1" className={actionClass}>{c.payout_status === 'pending' ? 'Lihat' : 'Lengkapi'} <ChevronRight className="h-4 w-4" /></Link> },
    { key: 'pixel', label: 'Pasang pixel iklan', hint: c.pixel ? 'Pixel aktif.' : 'Opsional: ukur hasil iklan Meta, Google, atau TikTok.', done: c.pixel, optional: true,
      action: <button type="button" onClick={onOpenSettings} className={actionClass}>Pasang <ChevronRight className="h-4 w-4" /></button> },
  ];
  const done = items.filter((i) => i.done).length;
  if (done === items.length) return null;

  return (
    <section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="checklist-title">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h2 id="checklist-title" className="text-base font-bold text-zinc-900">Siapkan toko kamu</h2>
          <p className="text-sm text-zinc-500">{done} dari {items.length} selesai</p>
        </div>
        <div className="h-2 w-24 overflow-hidden rounded-full bg-zinc-100" role="progressbar" aria-valuemin={0} aria-valuemax={items.length} aria-valuenow={done} aria-label="Progres">
          <div className="h-full rounded-full bg-yellow-400" style={{ width: `${(done / items.length) * 100}%` }} />
        </div>
      </div>
      <ul className="mt-3 divide-y divide-zinc-100">
        {items.map((item) => (
          <li key={item.key} className="flex items-center gap-3 py-2">
            <span className={cn('flex h-6 w-6 shrink-0 items-center justify-center rounded-full border', item.done ? 'border-green-600 bg-green-600 text-white' : 'border-zinc-300')} aria-hidden="true">
              {item.done && <Check className="h-4 w-4" />}
            </span>
            <div className="min-w-0 flex-1">
              <p className={cn('text-sm font-semibold', item.done ? 'text-zinc-400 line-through' : 'text-zinc-900')}>
                {item.label}{item.optional && <span className="ml-1 text-xs font-normal text-zinc-400">(opsional)</span>}
                <span className="sr-only">{item.done ? ' — selesai' : ' — belum'}</span>
              </p>
              <p className="truncate text-xs text-zinc-500">{item.hint}</p>
            </div>
            {!item.done && item.action}
          </li>
        ))}
      </ul>
    </section>
  );
}
