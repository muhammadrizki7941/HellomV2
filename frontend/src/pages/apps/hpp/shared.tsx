import { useEffect, useId, useState } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ApiError } from '@/lib/hellomApi';

// Shared bits of the Kalkulator HPP screens (dashboard app "hpp").

export const rupiah = (v: number | null | undefined) => (v === null || v === undefined ? '–' : `Rp${Math.round(v).toLocaleString('id-ID')}`);
/** Small unit costs keep decimals: Rp180/g, Rp0,5/ml. */
export const rupiahUnit = (v: number) => `Rp${v.toLocaleString('id-ID', { maximumFractionDigits: v < 10 ? 2 : v < 100 ? 1 : 0 })}`;
export const pct = (v: number | null | undefined) => (v === null || v === undefined ? '–' : `${v.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`);
export const toInt = (raw: string) => Number(raw.replace(/\D/g, '')) || 0;
export const toDecimal = (raw: string) => {
  const n = Number(raw.replace(/\./g, '').replace(',', '.'));
  return Number.isFinite(n) ? n : 0;
};

export type FieldErrors = Record<string, string | undefined>;
export const errorsFrom = (err: unknown): FieldErrors => {
  if (err instanceof ApiError) {
    const fields = Object.fromEntries(Object.entries(err.fieldErrors).map(([k, v]) => [k, v?.[0]]));
    return Object.keys(fields).length ? { ...fields, form: undefined } : { form: err.message };
  }
  return { form: err instanceof Error ? err.message : 'Belum berhasil. Coba lagi.' };
};

export function FoodCostBadge({ status, value }: { status: 'green' | 'yellow' | 'red' | null; value: number | null }) {
  if (!status) return <span className="text-xs text-zinc-400">belum ada harga</span>;
  const map = { green: 'bg-emerald-50 text-emerald-700 border-emerald-200', yellow: 'bg-amber-50 text-amber-800 border-amber-200', red: 'bg-red-50 text-red-700 border-red-200' };
  const label = { green: 'Sehat', yellow: 'Waspada', red: 'Terlalu mahal' };
  return (
    <span title="Food cost = bahan + kemasan ÷ harga jual. Hijau < 35%, kuning 35–45%, merah > 45%." className={cn('inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold', map[status])}>
      {pct(value)} · {label[status]}
    </span>
  );
}

export function MarginText({ value, target }: { value: number | null; target: number }) {
  if (value === null) return <span className="text-zinc-400">–</span>;
  return <span className={cn('font-semibold', value < 0 ? 'text-red-600' : value < target ? 'text-amber-700' : 'text-emerald-700')}>{pct(value)}</span>;
}

export function Dialog({ title, subtitle, onClose, busy, wide, children }: { title: string; subtitle?: string; onClose: () => void; busy?: boolean; wide?: boolean; children: React.ReactNode }) {
  const id = useId();
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && !busy) onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [busy, onClose]);
  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center bg-black/40 sm:items-center sm:p-4" onClick={() => !busy && onClose()}>
      <div role="dialog" aria-modal="true" aria-labelledby={id} onClick={(e) => e.stopPropagation()}
        className={cn('max-h-[94vh] w-full overflow-y-auto rounded-t-3xl bg-white p-5 shadow-xl sm:rounded-3xl', wide ? 'max-w-3xl' : 'max-w-lg')}
        style={{ paddingBottom: 'calc(1.25rem + env(safe-area-inset-bottom))' }}>
        <div className="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 id={id} className="text-lg font-bold text-zinc-900">{title}</h2>
            {subtitle && <p className="text-sm text-zinc-500">{subtitle}</p>}
          </div>
          <button type="button" onClick={onClose} disabled={busy} aria-label="Tutup" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100">
            <X className="h-5 w-5" />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}

export function Field({ label, error, hint, children }: { label: string; error?: string; hint?: React.ReactNode; children: React.ReactNode }) {
  return (
    <label className="block text-sm font-medium text-zinc-700">
      {label}
      <div className="mt-1">{children}</div>
      {error ? <span role="alert" className="mt-1 block text-xs font-normal text-red-600">{error}</span>
        : hint ? <span className="mt-1 block text-xs font-normal text-zinc-500">{hint}</span> : null}
    </label>
  );
}

export const inputClass = (error?: string) => cn('min-h-11 w-full rounded-xl border bg-white px-3 text-base text-zinc-900 outline-none', error ? 'border-red-400 bg-red-50/40' : 'border-zinc-300 focus:border-zinc-900');

/** Rupiah input that shows thousand separators. */
export function MoneyInput({ value, onChange, name, error, placeholder }: { value: number; onChange: (v: number) => void; name: string; error?: string; placeholder?: string }) {
  return (
    <div className={cn('flex items-center gap-1', inputClass(error))}>
      <span className="text-zinc-500">Rp</span>
      <input name={name} inputMode="numeric" value={value ? value.toLocaleString('id-ID') : ''} placeholder={placeholder ?? '0'}
        onChange={(e) => onChange(toInt(e.target.value))} aria-invalid={error ? true : undefined}
        className="min-w-0 flex-1 bg-transparent outline-none" />
    </div>
  );
}

export function useToast(): [string | null, (message: string) => void, React.ReactNode] {
  const [toast, setToast] = useState<string | null>(null);
  useEffect(() => {
    if (!toast) return undefined;
    const timer = window.setTimeout(() => setToast(null), 3500);
    return () => window.clearTimeout(timer);
  }, [toast]);
  const node = toast ? (
    <div role="status" data-toast className="fixed inset-x-0 bottom-24 z-[70] mx-auto w-fit max-w-[90vw] rounded-2xl bg-zinc-900 px-4 py-3 text-sm font-medium text-white shadow-lg lg:bottom-6">{toast}</div>
  ) : null;
  return [toast, setToast, node];
}

export function PrimaryButton({ busy, children, className, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & { busy?: boolean }) {
  return (
    <button {...props} disabled={busy || props.disabled}
      className={cn('flex min-h-11 items-center justify-center gap-2 rounded-xl bg-zinc-900 px-4 text-sm font-bold text-white hover:bg-black disabled:opacity-50', className)}>
      {busy && <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />} {children}
    </button>
  );
}
