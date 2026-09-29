import { useCallback, useEffect, useState } from 'react';
import { Loader2, Pencil, Plus, TicketPercent, Trash2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { createSellerCoupon, deleteSellerCoupon, getSellerCoupons, updateSellerCoupon } from '@/lib/hellomApi';
import type { CouponInput, SellerCoupon } from '@/lib/hellomApi';

// Kupon tab: discount codes (percent or rupiah, quota, validity) for the seller's products.
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const inputClass = 'mt-1 min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base outline-none focus:border-zinc-900';
const STATUS: Record<SellerCoupon['status'], [string, string]> = {
  active: ['Aktif', 'bg-emerald-100 text-emerald-800'],
  scheduled: ['Terjadwal', 'bg-sky-100 text-sky-800'],
  ended: ['Berakhir', 'bg-zinc-100 text-zinc-500'],
  used_up: ['Kuota habis', 'bg-amber-100 text-amber-800'],
  inactive: ['Nonaktif', 'bg-zinc-100 text-zinc-500'],
};
const toLocalInput = (iso: string | null) => (iso ? new Date(new Date(iso).getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '');

export default function CouponsPanel() {
  const [items, setItems] = useState<SellerCoupon[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<SellerCoupon | 'new' | null>(null);

  const load = useCallback(() => {
    getSellerCoupons().then((r) => setItems(r.items)).catch((err) => setError(err instanceof Error ? err.message : 'Kupon belum bisa dimuat'));
  }, []);
  useEffect(() => { load(); }, [load]);

  const remove = async (c: SellerCoupon) => {
    if (!window.confirm(`Hapus kupon ${c.code}?`)) return;
    try {
      await deleteSellerCoupon(c.id);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menghapus');
    }
  };

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Kupon</h1>
          <p className="text-sm text-zinc-600">Kode diskon untuk promo, reseller, atau followers.</p>
        </div>
        <button type="button" onClick={() => setEditing('new')} className="flex min-h-11 items-center gap-2 rounded-xl bg-zinc-900 px-4 text-sm font-bold text-white"><Plus className="h-4 w-4" /> Buat kupon</button>
      </div>
      {error && <p className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      {items === null && !error && <div className="h-32 animate-pulse rounded-2xl bg-zinc-100" />}
      {items?.length === 0 && (
        <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-500">
          <TicketPercent className="mx-auto h-10 w-10 text-zinc-300" />
          <p className="mt-2">Belum ada kupon. Contoh: HEMAT10 untuk diskon 10%.</p>
        </div>
      )}
      <ul className="space-y-2">
        {items?.map((c) => {
          const [label, tone] = STATUS[c.status];
          return (
            <li key={c.id} className="flex items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-4">
              <div className="min-w-0 flex-1">
                <p className="font-mono text-base font-bold">{c.code} <span className={cn('ml-1 rounded-full px-2 py-0.5 font-sans text-xs font-semibold', tone)}>{label}</span></p>
                <p className="text-sm text-zinc-600">
                  {c.type === 'percent' ? `Diskon ${c.value}%${c.max_discount ? ` (maks ${rupiah(c.max_discount)})` : ''}` : `Potongan ${rupiah(c.value)}`}
                  {c.min_purchase > 0 ? ` · min ${rupiah(c.min_purchase)}` : ''}
                </p>
                <p className="text-xs text-zinc-400">Dipakai {c.used_count}{c.max_uses ? `/${c.max_uses}` : ''}{c.ends_at ? ` · sampai ${new Date(c.ends_at).toLocaleDateString('id-ID')}` : ''}</p>
              </div>
              <button type="button" aria-label="Edit" onClick={() => setEditing(c)} className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100"><Pencil className="h-4 w-4" /></button>
              <button type="button" aria-label="Hapus" onClick={() => void remove(c)} className="flex h-11 w-11 items-center justify-center rounded-full text-rose-600 hover:bg-rose-50"><Trash2 className="h-4 w-4" /></button>
            </li>
          );
        })}
      </ul>
      {editing && <CouponForm coupon={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
    </div>
  );
}

function CouponForm({ coupon, onClose, onSaved }: { coupon: SellerCoupon | null; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState({
    code: coupon?.code ?? '',
    type: coupon?.type ?? 'percent',
    value: coupon ? String(coupon.value) : '',
    max_discount: coupon?.max_discount ? String(coupon.max_discount) : '',
    min_purchase: coupon?.min_purchase ? String(coupon.min_purchase) : '',
    max_uses: coupon?.max_uses ? String(coupon.max_uses) : '',
    starts_at: toLocalInput(coupon?.starts_at ?? null),
    ends_at: toLocalInput(coupon?.ends_at ?? null),
    is_active: coupon?.is_active ?? true,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = (key: keyof typeof form, value: string | boolean) => setForm((f) => ({ ...f, [key]: value }));
  const n = (v: string) => (v.trim() === '' ? null : Number(v.replace(/\D/g, '')));

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError(null);
    const body: CouponInput = {
      code: form.code,
      type: form.type as CouponInput['type'],
      value: n(form.value) ?? 0,
      max_discount: form.type === 'percent' ? n(form.max_discount) : null,
      min_purchase: n(form.min_purchase),
      max_uses: n(form.max_uses),
      starts_at: form.starts_at ? new Date(form.starts_at).toISOString() : null,
      ends_at: form.ends_at ? new Date(form.ends_at).toISOString() : null,
      is_active: form.is_active,
    };
    try {
      if (coupon) await updateSellerCoupon(coupon.id, body);
      else await createSellerCoupon(body);
      onSaved();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Kupon belum tersimpan');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" onClick={onClose}>
      <form onSubmit={save} onClick={(e) => e.stopPropagation()} className="max-h-[92svh] w-full max-w-md space-y-3 overflow-y-auto rounded-t-3xl bg-white p-5 sm:rounded-3xl" style={{ paddingBottom: 'calc(1.25rem + env(safe-area-inset-bottom))' }}>
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-bold">{coupon ? 'Edit kupon' : 'Buat kupon'}</h2>
          <button type="button" onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100"><X className="h-5 w-5" /></button>
        </div>
        <label className="block text-sm font-medium">Kode<input value={form.code} onChange={(e) => set('code', e.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, ''))} maxLength={40} className={cn(inputClass, 'font-mono uppercase')} placeholder="HEMAT10" /></label>
        <div className="grid grid-cols-2 gap-2">
          {(['percent', 'fixed'] as const).map((t) => (
            <button key={t} type="button" onClick={() => set('type', t)} className={cn('min-h-12 rounded-xl border text-sm font-semibold', form.type === t ? 'border-zinc-900' : 'border-zinc-200 text-zinc-500')}>{t === 'percent' ? 'Persen (%)' : 'Potongan (Rp)'}</button>
          ))}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-sm font-medium">{form.type === 'percent' ? 'Diskon (%)' : 'Potongan (Rp)'}<input inputMode="numeric" value={form.value} onChange={(e) => set('value', e.target.value.replace(/\D/g, ''))} className={inputClass} /></label>
          {form.type === 'percent' && <label className="block text-sm font-medium">Maks potongan <span className="font-normal text-zinc-500">(Rp)</span><input inputMode="numeric" value={form.max_discount} onChange={(e) => set('max_discount', e.target.value.replace(/\D/g, ''))} className={inputClass} placeholder="Opsional" /></label>}
          <label className="block text-sm font-medium">Min. belanja <span className="font-normal text-zinc-500">(Rp)</span><input inputMode="numeric" value={form.min_purchase} onChange={(e) => set('min_purchase', e.target.value.replace(/\D/g, ''))} className={inputClass} placeholder="Opsional" /></label>
          <label className="block text-sm font-medium">Kuota pemakaian<input inputMode="numeric" value={form.max_uses} onChange={(e) => set('max_uses', e.target.value.replace(/\D/g, ''))} className={inputClass} placeholder="Tak terbatas" /></label>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-sm font-medium">Mulai<input type="datetime-local" value={form.starts_at} onChange={(e) => set('starts_at', e.target.value)} className={inputClass} /></label>
          <label className="block text-sm font-medium">Berakhir<input type="datetime-local" value={form.ends_at} onChange={(e) => set('ends_at', e.target.value)} className={inputClass} /></label>
        </div>
        <label className="flex min-h-12 items-center gap-3 text-sm font-medium"><input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} className="h-5 w-5" /> Aktif</label>
        {error && <p role="alert" className="text-sm text-rose-600">{error}</p>}
        <button type="submit" disabled={saving} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 font-bold text-white disabled:opacity-50">
          {saving && <Loader2 className="h-5 w-5 animate-spin" />} Simpan kupon
        </button>
      </form>
    </div>
  );
}
