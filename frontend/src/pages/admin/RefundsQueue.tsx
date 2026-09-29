import { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, FileText, Loader2, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { downloadAdminRefundProof, getAdminRefunds, markAdminRefundFailed, markAdminRefundPaid } from '@/lib/hellomApi';
import type { AdminRefund } from '@/lib/hellomApi';

// Keuangan Penjual › Refund: money already taken from the seller's balance, to be
// transferred by Hellom to the buyer's account (manual, with proof).
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const dateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-');

function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export default function RefundsQueue() {
  const [status, setStatus] = useState('requested');
  const [rows, setRows] = useState<AdminRefund[]>([]);
  const [page, setPage] = useState({ current: 1, last: 1 });
  const [busy, setBusy] = useState<number | null>(null);
  const [paying, setPaying] = useState<AdminRefund | null>(null);
  const [proof, setProof] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback((p = 1) => {
    getAdminRefunds(status, p).then((r) => { setRows(r.data); setPage({ current: r.current_page, last: r.last_page }); setError(null); })
      .catch((err) => setError(err instanceof Error ? err.message : 'Gagal memuat'));
  }, [status]);
  useEffect(() => { load(1); }, [load]);

  const run = async (id: number, fn: () => Promise<unknown>) => {
    setBusy(id);
    setError(null);
    try {
      await fn();
      load(page.current);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Aksi gagal');
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="min-h-10 rounded-xl border border-zinc-200 bg-white px-3 text-sm">
          <option value="requested">Perlu ditransfer</option>
          <option value="paid">Sudah dikembalikan</option>
          <option value="failed">Gagal</option>
          <option value="all">Semua</option>
        </select>
        <button type="button" onClick={() => load(page.current)} className="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 text-sm"><RefreshCw className="h-4 w-4" /> Muat ulang</button>
      </div>
      {error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}
      <div className="overflow-x-auto rounded-2xl border border-zinc-200 bg-white">
        <table className="w-full min-w-[860px] text-sm">
          <thead className="bg-zinc-50 text-left text-xs uppercase text-zinc-500">
            <tr><th className="px-4 py-3">Pesanan</th><th className="px-4 py-3">Nominal</th><th className="px-4 py-3">Transfer ke</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Aksi</th></tr>
          </thead>
          <tbody className="divide-y divide-zinc-100">
            {rows.length === 0 && <tr><td colSpan={5} className="px-4 py-8 text-center text-zinc-500">Tidak ada refund.</td></tr>}
            {rows.map((r) => (
              <tr key={r.id} className="align-top">
                <td className="px-4 py-3">
                  <p className="font-semibold">{r.order?.product_name}</p>
                  <p className="text-xs text-zinc-500">{r.organization.name} · {r.order?.reference}</p>
                  <p className="text-xs text-zinc-500">Pembeli: {r.order?.buyer_name} ({r.order?.buyer_email})</p>
                  <p className="mt-1 text-xs text-zinc-600">Alasan: {r.reason}</p>
                </td>
                <td className="px-4 py-3"><p className="font-semibold">{rupiah(r.amount)}</p><p className="text-xs text-zinc-400">dari {rupiah(r.order?.amount ?? 0)}</p></td>
                <td className="px-4 py-3">
                  <p>{r.bank_name || r.bank_code} <span className="text-xs text-zinc-400">({r.destination_type === 'ewallet' ? 'e-wallet' : 'bank'})</span></p>
                  <p className="font-mono">{r.account_number}</p>
                  <p className="text-xs text-zinc-500">a.n. {r.account_name}</p>
                </td>
                <td className="px-4 py-3">
                  <p className="font-medium">{r.status_label}</p>
                  <p className={cn('text-xs', r.status === 'requested' && r.age_hours >= 20 ? 'font-semibold text-rose-700' : 'text-zinc-400')}>{dateTime(r.created_at)} · {Math.floor(r.age_hours)} jam</p>
                  {r.failure_reason && <p className="text-xs text-rose-600">{r.failure_reason}</p>}
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap justify-end gap-2">
                    {busy === r.id && <Loader2 className="h-4 w-4 animate-spin text-zinc-400" />}
                    {r.status === 'requested' && (
                      <>
                        <button type="button" disabled={busy !== null} onClick={() => { setProof(null); setPaying(r); }} className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">Tandai sudah ditransfer</button>
                        <button type="button" disabled={busy !== null} onClick={() => {
                          const reason = window.prompt('Alasan gagal (dana kembali ke saldo penjual):');
                          if (reason && reason.trim().length >= 3) void run(r.id, () => markAdminRefundFailed(r.id, reason.trim()));
                        }} className="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 disabled:opacity-50">Gagal</button>
                      </>
                    )}
                    {r.has_proof && (
                      <button type="button" onClick={() => void downloadAdminRefundProof(r.id).then((b) => saveBlob(b, `bukti-${r.reference}`)).catch(() => setError('Bukti tidak bisa diunduh'))} className="inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-xs font-semibold"><FileText className="h-3.5 w-3.5" /> Bukti</button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {page.last > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <button type="button" disabled={page.current <= 1} onClick={() => load(page.current - 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">‹</button>
          <span>{page.current} / {page.last}</span>
          <button type="button" disabled={page.current >= page.last} onClick={() => load(page.current + 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">›</button>
        </div>
      )}

      {paying && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setPaying(null)}>
          <form
            onClick={(e) => e.stopPropagation()}
            onSubmit={(e) => {
              e.preventDefault();
              const form = new FormData();
              if (proof) form.append('proof', proof);
              const target = paying;
              setPaying(null);
              void run(target.id, () => markAdminRefundPaid(target.id, form));
            }}
            className="w-full max-w-md space-y-4 rounded-2xl bg-white p-5"
          >
            <div>
              <h2 className="text-lg font-bold">Refund sudah ditransfer?</h2>
              <p className="text-sm text-zinc-500">{rupiah(paying.amount)} ke {paying.bank_name || paying.bank_code} {paying.account_number} a.n. {paying.account_name}. Pesanan akan berstatus "Dikembalikan" dan pembeli diberi tahu.</p>
            </div>
            <label className="block space-y-1 text-sm">
              <span className="font-semibold">Bukti transfer (jpg/png/webp/pdf, maks 4 MB)</span>
              <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(e) => setProof(e.target.files?.[0] ?? null)} className="w-full text-sm" />
            </label>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setPaying(null)} className="rounded-xl border px-4 py-2 text-sm font-semibold">Batal</button>
              <button type="submit" className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white"><CheckCircle2 className="h-4 w-4" /> Simpan</button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
