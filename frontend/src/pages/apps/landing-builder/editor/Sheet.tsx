import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { X } from 'lucide-react';

/** Bottom sheet for the phone layout (list, gallery, block settings). The preview stays visible above it. */
export default function Sheet({ title, onClose, children, actions, tall = false }: {
  title: ReactNode;
  onClose: () => void;
  children: ReactNode;
  /** Extra buttons in the header (e.g. hide / duplicate / delete of a block). */
  actions?: ReactNode;
  /** Gallery and settings need more room than the list. */
  tall?: boolean;
}) {
  const panelRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  // Once per opening. Parents pass a new onClose on every render (every keystroke in a field), so
  // depending on it re-ran this effect and pulled focus from the field being edited — on phones
  // the keyboard's delete key then closed the sheet instead of deleting text.
  useEffect(() => {
    panelRef.current?.focus();
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') closeRef.current(); };
    document.addEventListener('keydown', onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = overflow; };
  }, []);

  return (
    <div className="fixed inset-0 z-50 flex flex-col justify-end" role="dialog" aria-modal="true" aria-label={typeof title === 'string' ? title : undefined}>
      <button type="button" aria-label="Tutup" className="absolute inset-0 cursor-default bg-black/30" onClick={onClose} />
      <div
        ref={panelRef}
        tabIndex={-1}
        className={`hl-light relative flex w-full flex-col rounded-t-3xl bg-white shadow-2xl outline-none ${tall ? 'max-h-[88svh]' : 'max-h-[70svh]'}`}
      >
        <div className="mx-auto mt-2 h-1.5 w-10 rounded-full bg-zinc-300" aria-hidden="true" />
        <div className="flex items-center gap-2 border-b border-zinc-100 px-4 py-2">
          <h2 className="min-w-0 flex-1 truncate text-base font-bold text-zinc-900">{title}</h2>
          {actions}
          <button type="button" onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-xl text-zinc-600 hover:bg-zinc-100"><X className="h-5 w-5" /></button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">{children}</div>
      </div>
    </div>
  );
}
