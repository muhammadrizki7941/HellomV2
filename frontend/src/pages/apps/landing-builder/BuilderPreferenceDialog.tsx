import { useEffect, useRef, useState } from 'react';
import { Check, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { BuilderPreference } from '@/lib/hellomApi';
import { EDITOR_PRESETS, PRESET_ORDER } from './presets';

/**
 * "Sebelumnya kamu terbiasa pakai apa?" — first visit to the builder (and from Pengaturan).
 * Other products appear as plain text only.
 */
export default function BuilderPreferenceDialog({ current, onChoose, onClose }: {
  current: BuilderPreference | null;
  onChoose: (preference: BuilderPreference) => void;
  /** Missing on the first visit: an answer is needed (a default is still used if it fails). */
  onClose?: () => void;
}) {
  const [selected, setSelected] = useState<BuilderPreference | null>(current);
  const firstRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    firstRef.current?.focus();
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && onClose) onClose(); };
    document.addEventListener('keydown', onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = overflow; };
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-[80] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="builder-pref-title">
      <div className="hl-light w-full max-w-lg rounded-t-3xl bg-white p-5 shadow-2xl sm:rounded-3xl sm:p-6">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="builder-pref-title" className="text-lg font-bold text-zinc-900">Sebelumnya kamu terbiasa pakai apa?</h2>
            <p className="mt-1 text-sm text-zinc-600">Editor akan menyesuaikan istilah, urutan menu, dan template awal supaya langsung terasa familiar. Bisa diubah kapan saja di Pengaturan.</p>
          </div>
          {onClose && (
            <button type="button" onClick={onClose} className="rounded-lg p-1 text-zinc-500 hover:bg-zinc-100" aria-label="Tutup"><X className="h-5 w-5" /></button>
          )}
        </div>

        <div className="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Platform sebelumnya">
          {PRESET_ORDER.map((id, index) => {
            const preset = EDITOR_PRESETS[id];
            const active = selected === id;
            return (
              <button
                key={id}
                ref={index === 0 ? firstRef : undefined}
                type="button"
                role="radio"
                aria-checked={active}
                onClick={() => setSelected(id)}
                className={cn(
                  'flex min-h-[76px] items-start gap-3 rounded-2xl border-2 p-4 text-left transition-colors',
                  active ? 'border-zinc-900 bg-zinc-50' : 'border-zinc-200 hover:border-zinc-400',
                )}
              >
                <span className={cn('mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2', active ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-300')}>
                  {active && <Check className="h-3 w-3" />}
                </span>
                <span>
                  <span className="block font-bold text-zinc-900">{preset.label}</span>
                  <span className="mt-0.5 block text-sm text-zinc-600">{preset.description}</span>
                </span>
              </button>
            );
          })}
        </div>

        <button
          type="button"
          onClick={() => selected && onChoose(selected)}
          disabled={!selected}
          className="mt-5 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-zinc-900 px-4 text-base font-semibold text-white disabled:opacity-40"
        >
          Lanjut
        </button>
      </div>
    </div>
  );
}
