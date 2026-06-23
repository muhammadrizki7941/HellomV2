import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Store, ChevronDown, Check, Settings2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  getPosOutlets,
  getActiveOutletId,
  setActiveOutletId,
  getActiveOutletEventName,
  type PosOutlet,
} from '@/lib/hellomApi';

export default function OutletSwitcher({ compact = false }: { compact?: boolean }) {
  const navigate = useNavigate();
  const [outlets, setOutlets] = useState<PosOutlet[]>([]);
  const [activeId, setActiveId] = useState<string | null>(getActiveOutletId());
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let mounted = true;
    const load = async () => {
      try {
        const res = await getPosOutlets();
        if (!mounted) return;
        setOutlets(res.outlets || []);

        // Ensure an active outlet is selected so X-Outlet-Id is always sent.
        const stored = getActiveOutletId();
        const validStored = stored && res.outlets.some((o) => String(o.id) === stored);
        if (!validStored) {
          const fallback = res.outlets.find((o) => o.is_primary) || res.outlets[0];
          if (fallback) {
            setActiveOutletId(fallback.id); // updates storage (no reload on initial set)
            setActiveId(String(fallback.id));
          }
        } else {
          setActiveId(stored);
        }
      } catch {
        // POS context may not be ready yet; ignore.
      } finally {
        if (mounted) setLoading(false);
      }
    };
    void load();

    const onChange = () => setActiveId(getActiveOutletId());
    window.addEventListener(getActiveOutletEventName(), onChange);
    return () => {
      mounted = false;
      window.removeEventListener(getActiveOutletEventName(), onChange);
    };
  }, []);

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const active = outlets.find((o) => String(o.id) === activeId) || outlets[0];

  const handleSelect = (outlet: PosOutlet) => {
    setOpen(false);
    if (String(outlet.id) === activeId) return;
    setActiveOutletId(outlet.id);
    // Reload so every page refetches scoped to the newly selected outlet.
    window.location.reload();
  };

  if (loading && outlets.length === 0) {
    return (
      <div className={cn('flex items-center gap-2 rounded-lg border border-[#eadfbe] bg-white px-3 py-2 text-sm text-[#8a7d63]', compact && 'py-1.5')}>
        <Store className="h-4 w-4" /> Memuat outlet…
      </div>
    );
  }

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={cn(
          'flex w-full items-center gap-2 rounded-lg border border-[#eadfbe] bg-white px-3 text-left transition hover:border-amber-300',
          compact ? 'py-1.5' : 'py-2'
        )}
      >
        <Store className="h-4 w-4 shrink-0 text-amber-500" />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-semibold text-[#111111]">{active?.name || 'Outlet'}</span>
          {!compact && <span className="block text-[11px] text-[#8a7d63]">Outlet aktif</span>}
        </span>
        <ChevronDown className={cn('h-4 w-4 shrink-0 text-[#8a7d63] transition', open && 'rotate-180')} />
      </button>

      {open && (
        <div className="absolute left-0 right-0 z-50 mt-1 overflow-hidden rounded-xl border border-[#eadfbe] bg-white shadow-xl">
          <div className="max-h-64 overflow-y-auto py-1">
            {outlets.map((outlet) => (
              <button
                key={outlet.id}
                type="button"
                onClick={() => handleSelect(outlet)}
                className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-[#fff7db]"
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-[#111111]">
                    {outlet.name}
                    {outlet.is_primary && <span className="ml-1.5 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">Utama</span>}
                  </span>
                  {outlet.address && <span className="block truncate text-[11px] text-[#8a7d63]">{outlet.address}</span>}
                </span>
                {String(outlet.id) === activeId && <Check className="h-4 w-4 shrink-0 text-amber-500" />}
              </button>
            ))}
          </div>
          <button
            type="button"
            onClick={() => { setOpen(false); navigate('/pos/outlets'); }}
            className="flex w-full items-center gap-2 border-t border-[#f1e7c9] px-3 py-2.5 text-sm font-medium text-[#4b5563] hover:bg-[#fff7db]"
          >
            <Settings2 className="h-4 w-4" /> Kelola Outlet
          </button>
        </div>
      )}
    </div>
  );
}
