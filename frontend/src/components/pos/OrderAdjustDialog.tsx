import { useEffect, useId, useMemo, useState } from 'react';
import { ArrowRightLeft, Loader2, Minus, Percent, Plus, Scissors, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  ApiError,
  changePosOrderPaymentMethod,
  getPosTables,
  movePosTableBill,
  setPosOrderDiscount,
  splitPosOrder,
  transferPosOrder,
  updatePosOrderItem,
} from '@/lib/hellomApi';
import type { PosOrderListItem, PosTableBill, PosTableRecord } from '@/lib/hellomApi';
import { PinField, PosDialogShell } from '@/components/pos/OwnerPin';

// Cashier changes after an order was placed (backend OrderAdjustmentService). Risky ones ask for the
// owner's PIN right here: manual discount, payment-method change, and reducing items once a receipt
// was printed. Every change lands in POS › Log Audit; the server reprices the order.

const rupiah = (v: number) => `Rp ${Math.round(v || 0).toLocaleString('id-ID')}`;
export const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash: 'Tunai', transfer: 'Transfer bank', qris: 'QRIS', gopay: 'GoPay', dana: 'DANA', other: 'Lainnya',
};

type Errors = Record<string, string | undefined>;
const fromApi = (err: unknown): Errors => {
  if (err instanceof ApiError) {
    const fields = Object.fromEntries(Object.entries(err.fieldErrors).map(([k, v]) => [k, v?.[0]]));
    return Object.keys(fields).length ? fields : { form: err.message };
  }
  return { form: err instanceof Error ? err.message : 'Belum berhasil. Coba lagi.' };
};

function Header({ id, title, subtitle, onClose, busy }: { id: string; title: string; subtitle?: string; onClose: () => void; busy: boolean }) {
  return (
    <div className="flex items-start justify-between gap-3">
      <div>
        <h2 id={id} className="text-lg font-bold text-gray-900">{title}</h2>
        {subtitle && <p className="text-xs text-gray-500">{subtitle}</p>}
      </div>
      <button type="button" onClick={onClose} disabled={busy} aria-label="Tutup" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100">
        <X className="h-5 w-5" />
      </button>
    </div>
  );
}

function ReasonField({ value, onChange, error, label = 'Alasan', placeholder }: { value: string; onChange: (v: string) => void; error?: string; label?: string; placeholder?: string }) {
  return (
    <label className="block text-sm font-medium text-gray-700">
      {label}
      <input name="reason" value={value} onChange={(e) => onChange(e.target.value)} maxLength={255} placeholder={placeholder} aria-invalid={error ? true : undefined}
        className={cn('mt-1 min-h-11 w-full rounded-2xl border px-4 text-base outline-none', error ? 'border-red-400 bg-red-50/40' : 'border-gray-300 focus:border-gray-900')} />
      {error && <span role="alert" className="mt-1 block text-xs font-normal text-red-600">{error}</span>}
    </label>
  );
}

function FormError({ text }: { text?: string }) {
  return text ? <p role="alert" className="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{text}</p> : null;
}

function SubmitButton({ busy, children, tone = 'dark', disabled }: { busy: boolean; children: React.ReactNode; tone?: 'dark' | 'danger'; disabled?: boolean }) {
  return (
    <button type="submit" disabled={busy || disabled}
      className={cn('mt-4 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl text-base font-bold text-white disabled:opacity-50', tone === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-gray-900')}>
      {busy && <Loader2 className="h-5 w-5 animate-spin" />} {children}
    </button>
  );
}

function useTables(excludeId?: number | null) {
  const [tables, setTables] = useState<PosTableRecord[] | null>(null);
  useEffect(() => {
    getPosTables().then((r) => setTables(r.tables.filter((t) => t.is_active && t.id !== excludeId))).catch(() => setTables([]));
  }, [excludeId]);
  return tables;
}

function TableSelect({ tables, value, onChange, error }: { tables: PosTableRecord[] | null; value: string; onChange: (v: string) => void; error?: string }) {
  return (
    <label className="block text-sm font-medium text-gray-700">
      Meja tujuan
      <select name="table_id" value={value} onChange={(e) => onChange(e.target.value)} disabled={!tables} aria-invalid={error ? true : undefined}
        className={cn('mt-1 min-h-11 w-full rounded-2xl border bg-white px-3 text-base', error ? 'border-red-400' : 'border-gray-300')}>
        <option value="">{tables ? 'Pilih meja' : 'Memuat meja…'}</option>
        {tables?.map((t) => (
          <option key={t.id} value={t.id}>{t.name || t.code}{t.open_bill_id ? ' — ada tagihan, akan digabung' : ''}</option>
        ))}
      </select>
      {error && <span role="alert" className="mt-1 block text-xs font-normal text-red-600">{error}</span>}
    </label>
  );
}

type Tab = 'items' | 'discount' | 'split' | 'table';

/** "Atur pesanan": items, manual discount, split bill, move table — for unpaid orders. */
export function OrderManageDialog({ order, onClose, onChanged }: { order: PosOrderListItem; onClose: () => void; onChanged: (message: string) => void }) {
  const titleId = useId();
  const [tab, setTab] = useState<Tab>('items');
  const [busy, setBusy] = useState(false);
  const tabs: Array<[Tab, string, React.ReactNode]> = [
    ['items', 'Item', <Minus key="i" className="h-4 w-4" />],
    ['discount', 'Diskon', <Percent key="d" className="h-4 w-4" />],
    ['split', 'Pisah bill', <Scissors key="s" className="h-4 w-4" />],
    ['table', 'Pindah meja', <ArrowRightLeft key="t" className="h-4 w-4" />],
  ];
  const done = (message: string) => { onChanged(message); onClose(); };

  return (
    <PosDialogShell titleId={titleId} onClose={onClose} busy={busy}>
      <div data-order-manage>
        <Header id={titleId} title={`Atur pesanan #${order.order_number}`} subtitle={`${order.table_label || order.service_type} · ${rupiah(order.final_amount)}`} onClose={onClose} busy={busy} />
        <div role="tablist" className="mt-3 grid grid-cols-4 gap-1 rounded-2xl bg-gray-100 p-1">
          {tabs.map(([key, label, icon]) => (
            <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => !busy && setTab(key)}
              className={cn('flex min-h-10 flex-col items-center justify-center gap-0.5 rounded-xl text-xs font-semibold sm:flex-row sm:gap-1.5', tab === key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600')}>
              {icon}{label}
            </button>
          ))}
        </div>
        <div className="mt-4">
          {tab === 'items' && <ItemsTab order={order} busy={busy} setBusy={setBusy} done={done} />}
          {tab === 'discount' && <DiscountTab order={order} busy={busy} setBusy={setBusy} done={done} />}
          {tab === 'split' && <SplitTab order={order} busy={busy} setBusy={setBusy} done={done} />}
          {tab === 'table' && <TableTab order={order} busy={busy} setBusy={setBusy} done={done} />}
        </div>
      </div>
    </PosDialogShell>
  );
}

type TabProps = { order: PosOrderListItem; busy: boolean; setBusy: (b: boolean) => void; done: (message: string) => void };

function ItemsTab({ order, busy, setBusy, done }: TabProps) {
  const [qty, setQty] = useState<Record<number, number>>(() => Object.fromEntries(order.items.map((i) => [i.id, i.quantity])));
  const [reason, setReason] = useState('');
  const [pin, setPin] = useState('');
  const [errors, setErrors] = useState<Errors>({});
  const [needPin, setNeedPin] = useState((order.receipt_prints ?? 0) > 0);
  const changes = order.items.filter((i) => qty[i.id] !== i.quantity);
  const reducing = changes.some((i) => qty[i.id] < i.quantity);
  const remaining = order.items.reduce((sum, i) => sum + (qty[i.id] ?? 0), 0);
  const estimate = order.items.reduce((sum, i) => sum + i.unit_price * (qty[i.id] ?? 0), 0);
  const set = (id: number, value: number) => setQty((q) => ({ ...q, [id]: Math.max(0, Math.min(999, value)) }));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (reducing && !reason.trim()) { setErrors({ reason: 'Tulis alasan mengurangi/menghapus item.' }); return; }
    if (reducing && needPin && pin.length !== 6) { setErrors({ approval_pin: 'Struk sudah dicetak. Minta PIN pemilik.' }); return; }
    setBusy(true);
    setErrors({});
    try {
      // Removals last, so "remove the last item" is caught after the others are saved.
      for (const item of [...changes].sort((a, b) => (qty[a.id] === 0 ? 1 : 0) - (qty[b.id] === 0 ? 1 : 0))) {
        const reduce = qty[item.id] < item.quantity;
        await updatePosOrderItem(order.id, item.id, { qty: qty[item.id], reason: reduce ? reason.trim() : undefined, approval_pin: reduce && needPin ? pin : undefined });
      }
      done('Item pesanan diperbarui.');
    } catch (err) {
      const next = fromApi(err);
      if (err instanceof ApiError && (err.code === 'PIN_REQUIRED' || next.approval_pin)) setNeedPin(true);
      setErrors(next);
      if (next.approval_pin) setPin('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={(e) => void submit(e)} noValidate data-items-form>
      <ul className="divide-y divide-gray-100 rounded-2xl border border-gray-200">
        {order.items.map((item) => (
          <li key={item.id} className="flex items-center gap-3 px-3 py-2.5" data-item-row={item.id}>
            <div className="min-w-0 flex-1">
              <p className={cn('truncate text-sm font-medium', qty[item.id] === 0 ? 'text-gray-400 line-through' : 'text-gray-900')}>{item.product_name}</p>
              <p className="text-xs text-gray-500">{rupiah(item.unit_price)}{qty[item.id] !== item.quantity && ` · semula ${item.quantity}`}</p>
            </div>
            <div className="flex items-center gap-1">
              <button type="button" aria-label={`Kurangi ${item.product_name}`} onClick={() => set(item.id, (qty[item.id] ?? 0) - 1)} className="flex h-10 w-10 items-center justify-center rounded-full border border-gray-300"><Minus className="h-4 w-4" /></button>
              <span className="w-8 text-center text-base font-semibold tabular-nums" data-qty>{qty[item.id]}</span>
              <button type="button" aria-label={`Tambah ${item.product_name}`} onClick={() => set(item.id, (qty[item.id] ?? 0) + 1)} className="flex h-10 w-10 items-center justify-center rounded-full border border-gray-300"><Plus className="h-4 w-4" /></button>
            </div>
          </li>
        ))}
      </ul>
      {changes.length > 0 && <p className="mt-2 text-right text-sm text-gray-600">Subtotal baru ± {rupiah(estimate)} <span className="text-xs text-gray-400">(pajak & diskon dihitung server)</span></p>}
      {remaining === 0 && <p className="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">Semua item dihapus. Kalau pesanan tidak jadi, pakai tombol Batalkan.</p>}
      {reducing && (
        <div className="mt-3 space-y-3">
          <ReasonField value={reason} onChange={setReason} error={errors.reason} placeholder="Mis. salah input, pelanggan tidak jadi" />
          {needPin && <PinField label="PIN pemilik toko" name="approval_pin" value={pin} onChange={setPin} error={errors.approval_pin} hint="Struk sudah dicetak, jadi pengurangan item perlu persetujuan pemilik." />}
        </div>
      )}
      <FormError text={errors.form ?? errors.qty} />
      <SubmitButton busy={busy} disabled={changes.length === 0 || remaining === 0}>Simpan perubahan item</SubmitButton>
    </form>
  );
}

function DiscountTab({ order, busy, setBusy, done }: TabProps) {
  const current = order.manual_discount_amount ?? 0;
  const [type, setType] = useState<'percent' | 'fixed'>(order.manual_discount?.type ?? 'percent');
  const [value, setValue] = useState('');
  const [reason, setReason] = useState('');
  const [pin, setPin] = useState('');
  const [errors, setErrors] = useState<Errors>({});
  const amount = Number(value.replace(/\D/g, '')) || 0;

  const submit = async (remove: boolean) => {
    const next: Errors = {};
    if (!reason.trim()) next.reason = remove ? 'Tulis alasan menghapus diskon.' : 'Tulis alasan memberi diskon.';
    if (!remove && amount < 1) next.value = 'Isi nilai diskonnya.';
    if (!remove && type === 'percent' && amount > 100) next.value = 'Maksimal 100%.';
    if (!remove && pin.length !== 6) next.approval_pin = 'Minta pemilik toko memasukkan PIN.';
    setErrors(next);
    if (Object.keys(next).length) return;
    setBusy(true);
    try {
      const res = await setPosOrderDiscount(order.id, remove ? { type: 'none', reason: reason.trim() } : { type, value: amount, reason: reason.trim(), approval_pin: pin });
      done(remove ? 'Diskon manual dihapus.' : `Diskon ${rupiah(res.order.manual_discount_amount ?? 0)} diterapkan. Total ${rupiah(res.order.final_amount)}.`);
    } catch (err) {
      const e2 = fromApi(err);
      setErrors(e2);
      if (e2.approval_pin) setPin('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={(e) => { e.preventDefault(); void submit(false); }} noValidate data-discount-form>
      {current > 0 && (
        <div className="mb-3 flex items-center justify-between gap-2 rounded-2xl bg-amber-50 px-3 py-2 text-sm text-amber-900">
          <span>Diskon sekarang: <strong>{rupiah(current)}</strong>{order.manual_discount?.type === 'percent' && ` (${order.manual_discount.value}%)`}</span>
          <button type="button" disabled={busy} onClick={() => void submit(true)} className="rounded-lg border border-amber-300 px-2 py-1 text-xs font-semibold">Hapus diskon</button>
        </div>
      )}
      <div className="grid grid-cols-2 gap-2 rounded-2xl bg-gray-100 p-1">
        {(['percent', 'fixed'] as const).map((t) => (
          <button key={t} type="button" onClick={() => setType(t)} aria-pressed={type === t}
            className={cn('min-h-10 rounded-xl text-sm font-semibold', type === t ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600')}>
            {t === 'percent' ? 'Persen (%)' : 'Nominal (Rp)'}
          </button>
        ))}
      </div>
      <label className="mt-3 block text-sm font-medium text-gray-700">
        Nilai diskon
        <div className={cn('mt-1 flex min-h-11 items-center rounded-2xl border px-4', errors.value ? 'border-red-400' : 'border-gray-300')}>
          {type === 'fixed' && <span className="text-gray-500">Rp</span>}
          <input name="value" inputMode="numeric" value={value === '' ? '' : type === 'fixed' ? amount.toLocaleString('id-ID') : String(amount)}
            onChange={(e) => setValue(e.target.value.replace(/\D/g, ''))} aria-invalid={errors.value ? true : undefined}
            className="min-w-0 flex-1 bg-transparent px-2 text-base outline-none" placeholder="0" />
          {type === 'percent' && <span className="text-gray-500">%</span>}
        </div>
        {errors.value && <span role="alert" className="mt-1 block text-xs font-normal text-red-600">{errors.value}</span>}
      </label>
      <div className="mt-3 space-y-3">
        <ReasonField value={reason} onChange={setReason} error={errors.reason} placeholder="Mis. kompensasi pesanan terlambat" />
        <PinField label="PIN pemilik toko" name="approval_pin" value={pin} onChange={setPin} error={errors.approval_pin} hint="Diskon manual selalu perlu persetujuan pemilik dan tercatat di log audit." />
      </div>
      <FormError text={errors.form} />
      <SubmitButton busy={busy}>Terapkan diskon</SubmitButton>
    </form>
  );
}

function SplitTab({ order, busy, setBusy, done }: TabProps) {
  const [move, setMove] = useState<Record<number, number>>({});
  const [errors, setErrors] = useState<Errors>({});
  const total = order.items.reduce((s, i) => s + i.quantity, 0);
  const moving = Object.values(move).reduce((s, v) => s + v, 0);
  const moveAmount = order.items.reduce((s, i) => s + i.unit_price * (move[i.id] ?? 0), 0);
  const set = (id: number, max: number, v: number) => setMove((m) => ({ ...m, [id]: Math.max(0, Math.min(max, v)) }));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (moving < 1) { setErrors({ form: 'Pilih item yang mau dipisah.' }); return; }
    if (moving >= total) { setErrors({ form: 'Sisakan minimal satu item di pesanan asal.' }); return; }
    setBusy(true);
    setErrors({});
    try {
      const res = await splitPosOrder(order.id, Object.entries(move).filter(([, q]) => q > 0).map(([id, q]) => ({ item_id: Number(id), qty: q })));
      done(`Bill dipisah: pesanan baru #${res.new_order.order_number} (${rupiah(res.new_order.final_amount)}).`);
    } catch (err) {
      setErrors(fromApi(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={(e) => void submit(e)} noValidate data-split-form>
      <p className="text-sm text-gray-600">Pilih item yang dibayar terpisah. Item itu pindah ke pesanan baru di meja yang sama.</p>
      <ul className="mt-3 divide-y divide-gray-100 rounded-2xl border border-gray-200">
        {order.items.map((item) => (
          <li key={item.id} className="flex items-center gap-3 px-3 py-2.5" data-split-row={item.id}>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-gray-900">{item.product_name}</p>
              <p className="text-xs text-gray-500">{item.quantity} × {rupiah(item.unit_price)}</p>
            </div>
            <div className="flex items-center gap-1">
              <button type="button" aria-label={`Kurangi pisahan ${item.product_name}`} onClick={() => set(item.id, item.quantity, (move[item.id] ?? 0) - 1)} className="flex h-10 w-10 items-center justify-center rounded-full border border-gray-300"><Minus className="h-4 w-4" /></button>
              <span className="w-8 text-center font-semibold tabular-nums">{move[item.id] ?? 0}</span>
              <button type="button" aria-label={`Pisahkan ${item.product_name}`} onClick={() => set(item.id, item.quantity, (move[item.id] ?? 0) + 1)} className="flex h-10 w-10 items-center justify-center rounded-full border border-gray-300"><Plus className="h-4 w-4" /></button>
            </div>
          </li>
        ))}
      </ul>
      {moving > 0 && <p className="mt-2 text-right text-sm text-gray-600">Dipisah: {moving} item ± {rupiah(moveAmount)} <span className="text-xs text-gray-400">(sebelum pajak)</span></p>}
      <FormError text={errors.form} />
      <SubmitButton busy={busy} disabled={moving < 1}>Pisahkan ke bill baru</SubmitButton>
    </form>
  );
}

function TableTab({ order, busy, setBusy, done }: TabProps) {
  const tables = useTables(order.table?.id ?? null);
  const [tableId, setTableId] = useState('');
  const [errors, setErrors] = useState<Errors>({});

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!tableId) { setErrors({ table_id: 'Pilih meja tujuan.' }); return; }
    setBusy(true);
    setErrors({});
    try {
      const res = await transferPosOrder(order.id, Number(tableId));
      done(`Pesanan #${order.order_number} dipindah ke ${res.order.table_label}.`);
    } catch (err) {
      setErrors(fromApi(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={(e) => void submit(e)} noValidate data-transfer-form>
      <p className="mb-3 text-sm text-gray-600">Hanya pesanan ini yang dipindah. Untuk memindah semua pesanan satu meja, pakai “Pindah / gabung” di Tagihan Meja.</p>
      <TableSelect tables={tables} value={tableId} onChange={setTableId} error={errors.table_id} />
      <FormError text={errors.form} />
      <SubmitButton busy={busy}>Pindahkan pesanan</SubmitButton>
    </form>
  );
}

/** Paid order: change cash ↔ non-cash with the owner PIN. */
export function PaymentMethodDialog({ order, onClose, onChanged }: { order: PosOrderListItem; onClose: () => void; onChanged: (message: string) => void }) {
  const titleId = useId();
  const [method, setMethod] = useState('');
  const [reason, setReason] = useState('');
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const options = useMemo(() => Object.keys(PAYMENT_METHOD_LABELS).filter((m) => m !== order.payment_method), [order.payment_method]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const next: Errors = {};
    if (!method) next.payment_method = 'Pilih metode bayar yang benar.';
    if (!reason.trim()) next.reason = 'Tulis alasannya.';
    if (pin.length !== 6) next.approval_pin = 'Minta pemilik toko memasukkan PIN.';
    setErrors(next);
    if (Object.keys(next).length) return;
    setBusy(true);
    try {
      await changePosOrderPaymentMethod(order.id, { payment_method: method, reason: reason.trim(), approval_pin: pin });
      onChanged(`Metode bayar #${order.order_number} diganti ke ${PAYMENT_METHOD_LABELS[method]}.`);
      onClose();
    } catch (err) {
      const e2 = fromApi(err);
      setErrors(e2);
      if (e2.approval_pin) setPin('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <PosDialogShell titleId={titleId} onClose={onClose} busy={busy}>
      <form onSubmit={(e) => void submit(e)} noValidate data-payment-method-form>
        <Header id={titleId} title="Ganti metode bayar" subtitle={`#${order.order_number} · ${rupiah(order.final_amount)} · sekarang ${PAYMENT_METHOD_LABELS[order.payment_method ?? ''] ?? order.payment_method}`} onClose={onClose} busy={busy} />
        <p className="mt-3 rounded-2xl bg-amber-50 px-3 py-2 text-sm text-amber-900">Mengubah tunai ↔ non-tunai memengaruhi hitungan kas. Perlu PIN pemilik dan tercatat di log audit.</p>
        <label className="mt-3 block text-sm font-medium text-gray-700">
          Metode yang benar
          <select name="payment_method" value={method} onChange={(e) => setMethod(e.target.value)} aria-invalid={errors.payment_method ? true : undefined}
            className={cn('mt-1 min-h-11 w-full rounded-2xl border bg-white px-3 text-base', errors.payment_method ? 'border-red-400' : 'border-gray-300')}>
            <option value="">Pilih metode</option>
            {options.map((m) => <option key={m} value={m}>{PAYMENT_METHOD_LABELS[m]}</option>)}
          </select>
          {errors.payment_method && <span role="alert" className="mt-1 block text-xs font-normal text-red-600">{errors.payment_method}</span>}
        </label>
        <div className="mt-3 space-y-3">
          <ReasonField value={reason} onChange={setReason} error={errors.reason} placeholder="Mis. pelanggan ternyata bayar QRIS" />
          <PinField label="PIN pemilik toko" name="approval_pin" value={pin} onChange={setPin} error={errors.approval_pin} />
        </div>
        <FormError text={errors.form} />
        <SubmitButton busy={busy}>Ganti metode bayar</SubmitButton>
      </form>
    </PosDialogShell>
  );
}

/** Table bill: move every unpaid order to another table (joins its open bill if it has one). */
export function MoveBillDialog({ bill, onClose, onChanged }: { bill: PosTableBill; onClose: () => void; onChanged: (message: string) => void }) {
  const titleId = useId();
  const tables = useTables(bill.dining_table_id);
  const [tableId, setTableId] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Errors>({});

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!tableId) { setErrors({ table_id: 'Pilih meja tujuan.' }); return; }
    setBusy(true);
    setErrors({});
    try {
      const res = await movePosTableBill(bill.id, Number(tableId));
      const target = res.bill?.table_label ?? 'meja tujuan';
      onChanged(res.mode === 'merged' ? `Tagihan digabung ke ${target}.` : `Tagihan dipindah ke ${target}.`);
      onClose();
    } catch (err) {
      setErrors(fromApi(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <PosDialogShell titleId={titleId} onClose={onClose} busy={busy}>
      <form onSubmit={(e) => void submit(e)} noValidate data-move-bill-form>
        <Header id={titleId} title={`Pindah / gabung ${bill.table_label || 'meja'}`} subtitle={`${bill.orders_count} pesanan · belum dibayar ${rupiah(bill.unpaid_amount)}`} onClose={onClose} busy={busy} />
        <p className="mt-3 text-sm text-gray-600">Semua pesanan yang belum dibayar pindah ke meja tujuan. Kalau meja itu sudah punya tagihan, keduanya digabung. Pesanan yang sudah lunas tetap di meja asal.</p>
        <div className="mt-3"><TableSelect tables={tables} value={tableId} onChange={setTableId} error={errors.table_id} /></div>
        <FormError text={errors.form} />
        <SubmitButton busy={busy}>Pindahkan tagihan</SubmitButton>
      </form>
    </PosDialogShell>
  );
}
