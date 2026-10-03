import { useEffect, useId, useRef, useState } from 'react';
import { Loader2, MapPin, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { searchShippingDestinations } from '@/lib/hellomApi';
import type { ShippingDestination } from '@/lib/hellomApi';

/**
 * Search a sub-district / city / postcode for courier rates (RajaOngkir). Used on the checkout
 * (buyer's place) and in the seller's settings (ship-from place). Results are cached server-side.
 */
export default function DestinationSearch({ value, onChange, label, placeholder = 'Ketik kecamatan, kota, atau kode pos', error, inputClassName }: {
  value: { id: string; label: string } | null;
  onChange: (destination: ShippingDestination | null) => void;
  label: string;
  placeholder?: string;
  error?: string | null;
  inputClassName?: string;
}) {
  const [query, setQuery] = useState('');
  const [items, setItems] = useState<ShippingDestination[]>([]);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [active, setActive] = useState(0);
  const listId = useId();
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const q = query.trim();
    if (q.length < 3) {
      setItems([]);
      setMessage(null);
      return undefined;
    }
    let alive = true;
    const timer = window.setTimeout(() => {
      setBusy(true);
      searchShippingDestinations(q)
        .then((res) => {
          if (!alive) return;
          setItems(res.items);
          setActive(0);
          setMessage(res.items.length === 0 ? 'Tidak ditemukan. Coba nama kecamatan atau kode pos.' : null);
        })
        .catch((err: unknown) => { if (alive) { setItems([]); setMessage(err instanceof Error ? err.message : 'Pencarian belum bisa dipakai.'); } })
        .finally(() => { if (alive) setBusy(false); });
    }, 400);
    return () => { alive = false; window.clearTimeout(timer); };
  }, [query]);

  const pick = (destination: ShippingDestination) => {
    onChange(destination);
    setQuery('');
    setItems([]);
    setOpen(false);
  };

  if (value) {
    return (
      <div>
        <p className="text-sm font-medium text-zinc-800">{label}</p>
        <div className="mt-1 flex min-h-12 items-center gap-2 rounded-xl border border-zinc-300 bg-white px-3">
          <MapPin className="h-4 w-4 shrink-0 text-zinc-500" />
          <span className="min-w-0 flex-1 text-sm text-zinc-900">{value.label}</span>
          <button type="button" onClick={() => { onChange(null); window.setTimeout(() => inputRef.current?.focus(), 0); }} className="flex h-10 shrink-0 items-center gap-1 rounded-lg px-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100" aria-label={`Ganti ${label.toLowerCase()}`}>
            <X className="h-4 w-4" /> Ganti
          </button>
        </div>
        {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
      </div>
    );
  }

  return (
    <div className="relative">
      <label className="block text-sm font-medium text-zinc-800">
        {label}
        <span className="relative mt-1 block">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
          <input
            ref={inputRef}
            role="combobox"
            aria-expanded={open && items.length > 0}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-invalid={!!error}
            value={query}
            onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
            onFocus={() => setOpen(true)}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => Math.min(i + 1, items.length - 1)); }
              if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
              if (e.key === 'Enter' && items[active]) { e.preventDefault(); pick(items[active]); }
              if (e.key === 'Escape') setOpen(false);
            }}
            placeholder={placeholder}
            autoComplete="off"
            className={cn('min-h-12 w-full rounded-xl border border-zinc-300 bg-white pl-9 pr-9 text-base text-zinc-900 outline-none focus:border-zinc-900', inputClassName)}
          />
          {busy && <Loader2 className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-zinc-400" />}
        </span>
      </label>
      {open && items.length > 0 && (
        <ul id={listId} role="listbox" className="absolute inset-x-0 top-full z-30 mt-1 max-h-72 overflow-y-auto rounded-xl border border-zinc-200 bg-white py-1 shadow-xl">
          {items.map((item, index) => (
            <li key={item.id} role="option" aria-selected={index === active}>
              <button
                type="button"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => pick(item)}
                className={cn('flex min-h-11 w-full items-start gap-2 px-3 py-2 text-left text-sm text-zinc-800', index === active ? 'bg-zinc-100' : 'hover:bg-zinc-50')}
              >
                <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-zinc-400" />{item.label}
              </button>
            </li>
          ))}
        </ul>
      )}
      {(error || message) && <p className={cn('mt-1 text-sm', error ? 'text-red-600' : 'text-zinc-500')}>{error || message}</p>}
      {!error && !message && query.trim().length > 0 && query.trim().length < 3 && <p className="mt-1 text-xs text-zinc-500">Ketik minimal 3 huruf.</p>}
    </div>
  );
}
