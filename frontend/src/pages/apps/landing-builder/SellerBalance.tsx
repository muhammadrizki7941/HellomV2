import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AlertTriangle, ArrowDownToLine, CheckCircle2, Clock3, Loader2, ShieldCheck, Wallet, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  cancelSellerWithdrawal,
  getSellerFinanceSummary,
  getSellerLedger,
  getSellerWithdrawals,
  requestSellerWithdrawal,
  sendEmailVerification,
} from '@/lib/hellomApi';
import type { SellerFinanceSummary, SellerLedgerRow, SellerWithdrawalRow } from '@/lib/hellomApi';
import PayoutAccountSheet from './PayoutAccountSheet';

// "Saldo Penjualan": money from landing-page sales. Mobile-first.
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-';

const WITHDRAWAL_BADGE: Record<string, string> = {
  requested: 'bg-amber-100 text-amber-800',
  processing: 'bg-sky-100 text-sky-800',
  paid: 'bg-emerald-100 text-emerald-800',
  failed: 'bg-rose-100 text-rose-800',
  cancelled: 'bg-zinc-100 text-zinc-600',
};

export default function SellerBalance() {
  const [summary, setSummary] = useState<SellerFinanceSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [view, setView] = useState<'ledger' | 'withdrawals'>('ledger');
  const [ledger, setLedger] = useState<SellerLedgerRow[]>([]);
  const [ledgerPage, setLedgerPage] = useState({ current: 1, last: 1 });
  const [withdrawals, setWithdrawals] = useState<SellerWithdrawalRow[]>([]);
  const [showWithdraw, setShowWithdraw] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const [sendingVerification, setSendingVerification] = useState(false);
  const [searchParams] = useSearchParams();
  // ?rekening=1 (checklist, emails) opens the KTP & rekening sheet.
  const [showPayout, setShowPayout] = useState(searchParams.get('rekening') === '1');

  // Back from the verification link (?email_verified=1).
  useEffect(() => {
    if (searchParams.get('email_verified') === '1') setToast('Email kamu sudah terverifikasi ✅');
    if (searchParams.get('rekening') === '1') setShowPayout(true);
  }, [searchParams]);

  const verifyEmail = async () => {
    setSendingVerification(true);
    try {
      await sendEmailVerification();
      setToast('Link verifikasi dikirim ke email kamu. Cek juga folder Spam.');
    } catch (err) {
      setToast(err instanceof Error ? err.message : 'Link belum bisa dikirim');
    } finally {
      setSendingVerification(false);
    }
  };

  const load = useCallback(async () => {
    try {
      const [s, l, w] = await Promise.all([getSellerFinanceSummary(), getSellerLedger(1), getSellerWithdrawals(1)]);
      setSummary(s);
      setLedger(l.data);
      setLedgerPage({ current: l.current_page, last: l.last_page });
      setWithdrawals(w.data);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Saldo belum bisa dimuat');
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    if (!toast) return undefined;
    const t = window.setTimeout(() => setToast(null), 3500);
    return () => window.clearTimeout(t);
  }, [toast]);

  const loadMoreLedger = async () => {
    const next = await getSellerLedger(ledgerPage.current + 1);
    setLedger((rows) => [...rows, ...next.data]);
    setLedgerPage({ current: next.current_page, last: next.last_page });
  };

  const cancel = async (w: SellerWithdrawalRow) => {
    if (!window.confirm(`Batalkan penarikan ${rupiah(w.amount)}? Dana kembali ke saldo tersedia.`)) return;
    try {
      await cancelSellerWithdrawal(w.id);
      setToast('Penarikan dibatalkan, dana kembali ke saldo tersedia.');
      await load();
    } catch (err) {
      setToast(err instanceof Error ? err.message : 'Gagal membatalkan');
    }
  };

  if (!summary) {
    return (
      <div className="space-y-4" aria-busy={!error}>
        {error ? (
          <div className="rounded-2xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-700">
            {error}
            <button type="button" onClick={() => void load()} className="ml-2 font-semibold underline">Coba lagi</button>
          </div>
        ) : (
          <>
            <div className="h-40 animate-pulse rounded-3xl bg-zinc-200" />
            <div className="grid grid-cols-3 gap-3">{[0, 1, 2].map((i) => <div key={i} className="h-20 animate-pulse rounded-2xl bg-zinc-100" />)}</div>
            <div className="h-64 animate-pulse rounded-2xl bg-zinc-100" />
          </>
        )}
      </div>
    );
  }

  const { balance, payout_account: account, rules } = summary;

  return (
    <div className="space-y-4 pb-24 lg:pb-0">
      {/* Available balance */}
      <section className="rounded-3xl bg-zinc-900 p-5 text-white">
        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">
          <Wallet className="h-4 w-4" /> Saldo tersedia
        </div>
        <p className="mt-2 text-3xl font-bold">{rupiah(balance.available)}</p>
        <p className="mt-1 text-xs text-zinc-400">Siap ditarik ke {account.bank_name || account.bank_code || 'rekening kamu'}</p>
        <button
          type="button"
          onClick={() => setShowWithdraw(true)}
          disabled={balance.available < rules.min_withdrawal || !account.can_withdraw || balance.is_frozen}
          className="mt-4 hidden min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-yellow-400 px-4 text-base font-bold text-black disabled:opacity-40 lg:flex"
        >
          <ArrowDownToLine className="h-5 w-5" /> Tarik dana
        </button>
      </section>

      <section className="grid grid-cols-3 gap-2">
        {[
          { label: 'Tertahan', value: balance.pending, hint: rules.hold_days > 0 ? `cair ${rules.hold_days} hari setelah lunas` : 'cair otomatis' },
          { label: 'Diproses', value: balance.processing, hint: `maks. ${rules.sla_hours} jam` },
          { label: 'Sudah ditarik', value: balance.withdrawn, hint: 'total' },
        ].map((s) => (
          <div key={s.label} className="rounded-2xl bg-white p-3 ring-1 ring-zinc-100">
            <p className="text-[11px] font-semibold text-zinc-500">{s.label}</p>
            <p className="mt-1 text-sm font-bold text-zinc-900">{rupiah(s.value)}</p>
            <p className="mt-0.5 text-[10px] leading-4 text-zinc-400">{s.hint}</p>
          </div>
        ))}
      </section>

      {balance.is_frozen && (
        <div className="flex gap-2 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          Saldo kamu sedang ditahan oleh tim Hellom. Hubungi dukungan untuk info lebih lanjut.
        </div>
      )}

      {/* Payout account */}
      <section className="rounded-2xl bg-white p-4 ring-1 ring-zinc-100">
        <div className="flex items-start gap-3">
          {account.can_withdraw ? <ShieldCheck className="mt-0.5 h-5 w-5 text-emerald-600" /> : <AlertTriangle className="mt-0.5 h-5 w-5 text-amber-500" />}
          <div className="min-w-0 flex-1 text-sm">
            <p className="font-semibold text-zinc-900">Rekening penarikan</p>
            {account.bank_code ? (
              <p className="mt-0.5 text-zinc-600">{account.bank_name || account.bank_code} · {account.account_number_masked} · a.n. {account.account_name}</p>
            ) : (
              <p className="mt-0.5 text-zinc-600">Belum ada rekening.</p>
            )}
            {account.blocked_reason && <p className="mt-1 text-amber-700">{account.blocked_reason}</p>}
            <button type="button" onClick={() => setShowPayout(true)} className="mt-2 inline-flex min-h-11 items-center font-semibold text-zinc-900 underline">
              {account.verified ? 'Lihat / ganti rekening' : 'Lengkapi KTP & rekening'}
            </button>
            {!account.email_verified && (
              <button
                type="button"
                disabled={sendingVerification}
                onClick={() => void verifyEmail()}
                className="mt-2 flex min-h-11 items-center gap-2 font-semibold text-zinc-900 underline disabled:opacity-50"
              >
                {sendingVerification && <Loader2 className="h-4 w-4 animate-spin" />} Kirim link verifikasi email
              </button>
            )}
          </div>
        </div>
        <p className="mt-3 border-t border-zinc-100 pt-3 text-xs leading-5 text-zinc-500">
          Biaya layanan Hellom {rules.platform_fee_percent}% per penjualan (sudah termasuk biaya pembayaran). Minimal penarikan {rupiah(rules.min_withdrawal)}
          {rules.withdrawal_fee_flat > 0 ? `, biaya transfer ${rupiah(rules.withdrawal_fee_flat)}` : ', tanpa biaya transfer'}. Diproses paling lambat {rules.sla_hours} jam.
        </p>
      </section>

      {/* History */}
      <section className="rounded-2xl bg-white ring-1 ring-zinc-100">
        <div className="flex border-b border-zinc-100">
          {([['ledger', 'Riwayat saldo'], ['withdrawals', 'Penarikan']] as const).map(([key, label]) => (
            <button
              key={key}
              type="button"
              onClick={() => setView(key)}
              className={cn('min-h-12 flex-1 border-b-2 text-sm font-semibold', view === key ? 'border-yellow-400 text-zinc-900' : 'border-transparent text-zinc-500')}
            >
              {label}
            </button>
          ))}
        </div>

        {view === 'ledger' && (
          <ul className="divide-y divide-zinc-100">
            {ledger.length === 0 && (
              <li className="p-6 text-center text-sm text-zinc-500">Belum ada transaksi. Penjualan pertamamu akan muncul di sini.</li>
            )}
            {ledger.map((row) => (
              <li key={row.id} className="flex items-start justify-between gap-3 p-4">
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-zinc-900">{row.order?.product_name ?? row.type_label}</p>
                  <p className="text-xs text-zinc-500">{row.type_label} · {dateTime(row.created_at)}</p>
                  {row.type === 'sale' && row.order && (
                    <p className="mt-1 text-xs text-zinc-500">
                      Harga {rupiah(row.order.price)} · Biaya {rupiah(row.order.fee)} · <span className="font-semibold text-zinc-700">Bersih {rupiah(row.order.net)}</span>
                    </p>
                  )}
                  {row.type === 'sale' && row.available_at && new Date(row.available_at) > new Date() && (
                    <p className="mt-1 text-xs text-amber-700">Tertahan sampai {dateTime(row.available_at)}</p>
                  )}
                </div>
                <p className={cn('shrink-0 text-sm font-bold', row.amount >= 0 ? 'text-emerald-600' : 'text-zinc-900')}>
                  {row.amount >= 0 ? '+' : '−'}{rupiah(Math.abs(row.amount))}
                </p>
              </li>
            ))}
            {ledgerPage.current < ledgerPage.last && (
              <li className="p-3 text-center">
                <button type="button" onClick={() => void loadMoreLedger()} className="min-h-11 px-4 text-sm font-semibold text-zinc-700">Muat lebih banyak</button>
              </li>
            )}
          </ul>
        )}

        {view === 'withdrawals' && (
          <ul className="divide-y divide-zinc-100">
            {withdrawals.length === 0 && <li className="p-6 text-center text-sm text-zinc-500">Belum ada penarikan.</li>}
            {withdrawals.map((w) => (
              <li key={w.id} className="p-4">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-sm font-semibold text-zinc-900">{rupiah(w.amount)}</p>
                    <p className="text-xs text-zinc-500">{w.bank_name || w.bank_code} {w.account_number_masked} · {dateTime(w.created_at)}</p>
                    {w.fee_amount > 0 && <p className="text-xs text-zinc-500">Diterima {rupiah(w.net_amount)}</p>}
                    {w.failure_reason && <p className="mt-1 text-xs text-rose-600">{w.failure_reason}</p>}
                  </div>
                  <span className={cn('shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold', WITHDRAWAL_BADGE[w.status])}>{w.status_label}</span>
                </div>
                {w.status === 'requested' && (
                  <button type="button" onClick={() => void cancel(w)} className="mt-2 min-h-11 text-sm font-semibold text-rose-600">Batalkan</button>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Sticky primary action on phones */}
      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 p-3 backdrop-blur lg:hidden" style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom))' }}>
        <button
          type="button"
          onClick={() => setShowWithdraw(true)}
          disabled={balance.available < rules.min_withdrawal || !account.can_withdraw || balance.is_frozen}
          className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 px-4 text-base font-bold text-white disabled:opacity-40"
        >
          <ArrowDownToLine className="h-5 w-5" /> Tarik dana
        </button>
      </div>

      {showWithdraw && (
        <WithdrawSheet
          summary={summary}
          onClose={() => setShowWithdraw(false)}
          onDone={async (message) => {
            setShowWithdraw(false);
            setToast(message);
            await load();
          }}
        />
      )}

      {showPayout && (
        <PayoutAccountSheet
          onClose={() => setShowPayout(false)}
          onSaved={async (message) => {
            setShowPayout(false);
            setToast(message);
            await load();
          }}
        />
      )}

      {toast && (
        <div role="status" className="fixed inset-x-4 bottom-24 z-40 mx-auto max-w-sm rounded-2xl bg-zinc-900 px-4 py-3 text-center text-sm text-white shadow-lg lg:bottom-6">
          {toast}
        </div>
      )}
    </div>
  );
}

function WithdrawSheet({ summary, onClose, onDone }: { summary: SellerFinanceSummary; onClose: () => void; onDone: (message: string) => void }) {
  const { balance, rules, payout_account: account } = summary;
  const [raw, setRaw] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const amount = Number(raw.replace(/\D/g, '')) || 0;
  const tooSmall = amount > 0 && amount < rules.min_withdrawal;
  const tooBig = amount > balance.available;

  const submit = async () => {
    setSaving(true);
    setError(null);
    try {
      await requestSellerWithdrawal(amount);
      onDone(`Penarikan ${rupiah(amount)} diajukan. Kami proses paling lambat ${rules.sla_hours} jam.`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Penarikan gagal diajukan');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" onClick={onClose}>
      <div className="w-full max-w-md rounded-t-3xl bg-white p-5 sm:rounded-3xl" onClick={(e) => e.stopPropagation()} style={{ paddingBottom: 'calc(1.25rem + env(safe-area-inset-bottom))' }}>
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-bold">Tarik dana</h2>
          <button type="button" onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100"><X className="h-5 w-5" /></button>
        </div>
        <p className="text-sm text-zinc-500">Tersedia {rupiah(balance.available)} · ke {account.bank_name || account.bank_code} {account.account_number_masked}</p>

        <label className="mt-4 block text-sm font-medium text-zinc-700">
          Nominal
          <div className="mt-1 flex items-center rounded-2xl border border-zinc-300 px-4 focus-within:border-zinc-900">
            <span className="text-base text-zinc-500">Rp</span>
            <input
              inputMode="numeric"
              autoFocus
              value={amount ? amount.toLocaleString('id-ID') : ''}
              onChange={(e) => setRaw(e.target.value)}
              placeholder={rules.min_withdrawal.toLocaleString('id-ID')}
              className="min-h-12 w-full bg-transparent px-2 text-base font-semibold outline-none"
            />
          </div>
        </label>
        <div className="mt-2 flex gap-2">
          {[rules.min_withdrawal, 100000, balance.available].filter((v, i, a) => v >= rules.min_withdrawal && v <= balance.available && a.indexOf(v) === i).map((v) => (
            <button key={v} type="button" onClick={() => setRaw(String(v))} className="min-h-11 rounded-full border border-zinc-200 px-3 text-sm font-medium">
              {v === balance.available ? 'Semua' : rupiah(v)}
            </button>
          ))}
        </div>

        <dl className="mt-4 space-y-1 rounded-2xl bg-zinc-50 p-3 text-sm">
          <div className="flex justify-between"><dt className="text-zinc-500">Biaya transfer</dt><dd>{rupiah(rules.withdrawal_fee_flat)}</dd></div>
          <div className="flex justify-between font-semibold"><dt>Kamu terima</dt><dd>{rupiah(Math.max(0, amount - rules.withdrawal_fee_flat))}</dd></div>
        </dl>

        {tooSmall && <p className="mt-2 text-sm text-amber-700">Minimal penarikan {rupiah(rules.min_withdrawal)}.</p>}
        {tooBig && <p className="mt-2 text-sm text-amber-700">Melebihi saldo tersedia.</p>}
        {error && <p className="mt-2 text-sm text-rose-600">{error}</p>}

        <button
          type="button"
          disabled={saving || amount < rules.min_withdrawal || tooBig}
          onClick={() => void submit()}
          className="mt-4 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 text-base font-bold text-white disabled:opacity-40"
        >
          {saving ? <Loader2 className="h-5 w-5 animate-spin" /> : <CheckCircle2 className="h-5 w-5" />}
          Ajukan penarikan
        </button>
        <p className="mt-3 flex items-center justify-center gap-1 text-xs text-zinc-400"><Clock3 className="h-3.5 w-3.5" /> Diproses paling lambat {rules.sla_hours} jam</p>
      </div>
    </div>
  );
}
