import { useCallback, useEffect, useState } from 'react';
import { Loader2, Wallet, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { canPos, closePosStaffCash, getPosMyCash, openPosStaffCash } from '@/lib/hellomApi';
import type { PosMyCash, PosStaffCashLog } from '@/lib/hellomApi';

// Cashier's own cash drawer on the orders screen: open with the counted opening cash, close with
// the counted cash; the server compares it with opening cash + cash sales of that session.
// Needs the "Buka/tutup kas" permission (POS › Staff) and a staff record at this outlet.
const rupiah = (v: number) => `Rp ${Math.round(v || 0).toLocaleString('id-ID')}`;
const toNumber = (raw: string) => Number(raw.replace(/\D/g, '')) || 0;
const time = (iso?: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-');

export default function CashDrawerButton() {
  const allowed = canPos('cash_control');
  const [cash, setCash] = useState<PosMyCash | null>(null);
  const [open, setOpen] = useState(false);

  const load = useCallback(async () => {
    if (!allowed) return;
    try {
      setCash(await getPosMyCash());
    } catch {
      setCash(null);
    }
  }, [allowed]);

  useEffect(() => {
    void load();
    const onOrders = () => void load();
    window.addEventListener('pos-orders-updated', onOrders);
    return () => window.removeEventListener('pos-orders-updated', onOrders);
  }, [load]);

  if (!allowed || !cash?.staff_id) return null;
  const isOpen = cash.open !== null;

  return (
    <>
      <button
        type="button"
        onClick={() => { void load(); setOpen(true); }}
        className={cn(
          'flex min-h-9 items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
          isOpen ? 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' : 'border-amber-300 bg-amber-400 text-gray-900 hover:bg-amber-500'
        )}
      >
        <Wallet className="h-4 w-4" />
        {isOpen ? `Kas terbuka · ${rupiah(cash.open!.live_expected_cash)}` : 'Buka kas'}
      </button>
      {open && <CashDialog cash={cash} onClose={() => setOpen(false)} onChanged={load} />}
    </>
  );
}

function CashDialog({ cash, onClose, onChanged }: { cash: PosMyCash; onClose: () => void; onChanged: () => Promise<void> }) {
  const [amount, setAmount] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [closed, setClosed] = useState<PosStaffCashLog | null>(null);
  const drawer = cash.open;
  const counted = toNumber(amount);
  const difference = drawer && amount !== '' ? counted - drawer.live_expected_cash : null;

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && !saving) onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [saving, onClose]);

  const submit = async () => {
    if (!cash.staff_id || amount === '') return;
    setSaving(true);
    setError(null);
    try {
      if (drawer) {
        if (!window.confirm(`Tutup kas dengan uang di laci ${rupiah(counted)}?`)) { setSaving(false); return; }
        const result = await closePosStaffCash(cash.staff_id, { closing_cash: counted, notes: notes.trim() || undefined });
        setClosed(result.cash_log);
      } else {
        await openPosStaffCash(cash.staff_id, { opening_cash: counted, notes: notes.trim() || undefined });
        onClose();
      }
      await onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Belum tersimpan. Coba lagi.');
    } finally {
      setSaving(false);
    }
  };

  const diffText = (d: number) => (d === 0 ? 'Pas' : d > 0 ? `Lebih ${rupiah(d)}` : `Kurang ${rupiah(-d)}`);
  const diffClass = (d: number) => (d === 0 ? 'text-emerald-700' : d > 0 ? 'text-amber-700' : 'text-red-600');

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center sm:p-4" onClick={() => !saving && onClose()}>
      <div role="dialog" aria-modal="true" aria-labelledby="cash-title" onClick={(e) => e.stopPropagation()}
        className="w-full max-w-md rounded-t-3xl bg-white p-5 shadow-xl sm:rounded-3xl" style={{ paddingBottom: 'calc(1.25rem + env(safe-area-inset-bottom))' }}>
        <div className="flex items-center justify-between">
          <h2 id="cash-title" className="text-lg font-bold text-gray-900">
            {closed ? 'Kas ditutup' : drawer ? 'Tutup kas' : 'Buka kas'}
          </h2>
          <button type="button" onClick={onClose} disabled={saving} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100">
            <X className="h-5 w-5" />
          </button>
        </div>

        {closed ? (
          <>
            <dl className="mt-3 space-y-1.5 rounded-2xl bg-gray-50 p-4 text-sm">
              <Row label="Kas awal" value={rupiah(closed.opening_cash)} />
              <Row label={`Penjualan tunai (${closed.total_transactions} transaksi)`} value={rupiah(closed.total_cash_sales)} />
              <Row label="Seharusnya di laci" value={rupiah(closed.expected_cash ?? 0)} strong />
              <Row label="Uang dihitung" value={rupiah(closed.closing_cash ?? 0)} />
              <div className={cn('flex justify-between border-t border-gray-200 pt-2 font-bold', diffClass(closed.difference_cash ?? 0))}>
                <dt>Selisih</dt><dd>{diffText(closed.difference_cash ?? 0)}</dd>
              </div>
            </dl>
            <button type="button" onClick={onClose} className="mt-4 flex min-h-12 w-full items-center justify-center rounded-2xl bg-gray-900 text-base font-bold text-white">Selesai</button>
          </>
        ) : (
          <>
            {drawer ? (
              <dl className="mt-2 space-y-1.5 rounded-2xl bg-gray-50 p-4 text-sm">
                <Row label="Dibuka" value={time(drawer.started_at)} />
                <Row label="Kas awal" value={rupiah(drawer.opening_cash)} />
                <Row label={`Penjualan tunai (${drawer.live_transactions} transaksi)`} value={rupiah(drawer.live_cash_sales)} />
                <Row label="Seharusnya di laci" value={rupiah(drawer.live_expected_cash)} strong />
              </dl>
            ) : (
              <p className="mt-1 text-sm text-gray-600">
                Hitung uang di laci sebelum mulai melayani, lalu masukkan sebagai kas awal.
                {cash.last_closed && <span className="mt-1 block text-xs text-gray-500">Tutup kas terakhir: {time(cash.last_closed.closed_at)} · {diffText(cash.last_closed.difference_cash ?? 0)}</span>}
              </p>
            )}

            <label className="mt-4 block text-sm font-medium text-gray-700">
              {drawer ? 'Uang di laci sekarang (hasil hitung)' : 'Kas awal'}
              <div className="mt-1 flex min-h-12 items-center rounded-2xl border border-gray-300 px-4 focus-within:border-gray-900">
                <span className="text-base text-gray-500">Rp</span>
                <input
                  inputMode="numeric"
                  autoFocus
                  value={amount === '' ? '' : toNumber(amount).toLocaleString('id-ID')}
                  onChange={(e) => setAmount(e.target.value.replace(/\D/g, ''))}
                  placeholder="0"
                  className="min-w-0 flex-1 bg-transparent px-2 py-3 text-base text-gray-900 outline-none"
                />
              </div>
            </label>
            {difference !== null && (
              <p className={cn('mt-2 text-sm font-semibold', diffClass(difference))}>Selisih: {diffText(difference)}</p>
            )}
            <label className="mt-3 block text-sm font-medium text-gray-700">
              Catatan (opsional)
              <input value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={1000}
                placeholder={drawer ? 'Mis. uang kembalian kurang Rp2.000' : 'Mis. shift pagi'}
                className="mt-1 min-h-12 w-full rounded-2xl border border-gray-300 px-4 text-base outline-none focus:border-gray-900" />
            </label>
            {error && <p role="alert" className="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
            <button type="button" onClick={() => void submit()} disabled={saving || amount === ''}
              className={cn('mt-4 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl text-base font-bold disabled:opacity-40',
                drawer ? 'bg-gray-900 text-white' : 'bg-amber-400 text-gray-900')}>
              {saving && <Loader2 className="h-5 w-5 animate-spin" />} {drawer ? 'Tutup kas' : 'Buka kas'}
            </button>
          </>
        )}
      </div>
    </div>
  );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={cn('flex justify-between gap-3', strong ? 'font-semibold text-gray-900' : 'text-gray-600')}>
      <dt>{label}</dt><dd className="shrink-0">{value}</dd>
    </div>
  );
}
