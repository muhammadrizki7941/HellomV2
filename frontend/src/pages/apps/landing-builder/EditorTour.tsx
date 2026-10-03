import { useCallback, useEffect, useLayoutEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import type { EditorPreset } from './presets';

/**
 * Short editor tour after the onboarding question (skippable). Steps point at elements marked
 * data-tour="…"; steps whose element is not on screen (desktop vs phone layout) are skipped.
 */
interface TourStep {
  target: string;
  title: string;
  body: string;
  /** Only in guided mode ("Belum pernah"). */
  guidedOnly?: boolean;
}

function tourSteps(preset: EditorPreset): TourStep[] {
  const { item, items, add, list } = preset.terms;
  return [
    { target: 'add', title: `${add} di sini`, body: `Dari sini kamu ${add.toLowerCase()} — tombol link, produk, gambar, video, dan lainnya.` },
    { target: 'templates', title: 'Mulai dari template', body: 'Belum tahu mau isi apa? Pilih template siap pakai, lalu ganti teks & gambarnya.', guidedOnly: true },
    { target: 'list', title: list, body: `Ketuk untuk mengubah isi ${item}. Tahan & geser untuk mengatur urutan ${items}.` },
    { target: 'preview', title: 'Pratinjau langsung', body: 'Beginilah halaman kamu terlihat di HP pembeli. Ketuk bagian mana pun untuk mengeditnya.' },
    { target: 'design', title: 'Tampilan', body: 'Atur tema warna, huruf, dan bentuk tombol supaya sesuai brand kamu.' },
    { target: 'publish', title: 'Terbitkan', body: 'Perubahan tersimpan otomatis sebagai draf. Tekan Terbitkan agar halaman bisa dilihat & dibagikan.' },
  ].filter((step) => preset.guided || !step.guidedOnly);
}

function visibleTarget(key: string): HTMLElement | null {
  const nodes = Array.from(document.querySelectorAll<HTMLElement>(`[data-tour="${key}"]`));
  return nodes.find((node) => {
    const rect = node.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0 && getComputedStyle(node).visibility !== 'hidden';
  }) ?? null;
}

export default function EditorTour({ preset, onFinish }: { preset: EditorPreset; onFinish: () => void }) {
  const steps = useMemo(() => tourSteps(preset), [preset]);
  const [index, setIndex] = useState(0);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const [available, setAvailable] = useState<TourStep[]>([]);

  // Which steps have an element right now (computed once the editor has rendered).
  useEffect(() => {
    const timer = window.setTimeout(() => setAvailable(steps.filter((s) => visibleTarget(s.target))), 300);
    return () => window.clearTimeout(timer);
  }, [steps]);

  const step = available[index];

  const measure = useCallback(() => {
    if (!step) return;
    const node = visibleTarget(step.target);
    setRect(node ? node.getBoundingClientRect() : null);
  }, [step]);

  useLayoutEffect(() => {
    if (!step) return undefined;
    visibleTarget(step.target)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    measure();
    window.addEventListener('resize', measure);
    window.addEventListener('scroll', measure, true);
    return () => { window.removeEventListener('resize', measure); window.removeEventListener('scroll', measure, true); };
  }, [step, measure]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onFinish(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onFinish]);

  if (!step || !rect) return null;

  const last = index === available.length - 1;
  const pad = 6;
  const below = rect.bottom + 180 < window.innerHeight;
  const cardWidth = Math.min(320, window.innerWidth - 24);
  const left = Math.min(Math.max(12, rect.left + rect.width / 2 - cardWidth / 2), window.innerWidth - cardWidth - 12);

  return createPortal(
    <div className="fixed inset-0 z-[90]" role="dialog" aria-modal="true" aria-labelledby="hl-tour-title">
      {/* Spotlight: the dimmed page with a hole around the target. */}
      <div
        className="pointer-events-none fixed rounded-xl transition-all duration-200"
        style={{
          top: rect.top - pad, left: rect.left - pad, width: rect.width + pad * 2, height: rect.height + pad * 2,
          boxShadow: '0 0 0 9999px rgba(0,0,0,.55)', outline: '2px solid #facc15',
        }}
      />
      <div
        className="hl-light fixed rounded-2xl bg-white p-4 shadow-2xl"
        style={{ width: cardWidth, left, ...(below ? { top: rect.bottom + pad + 10 } : { bottom: window.innerHeight - rect.top + pad + 10 }) }}
      >
        <p className="text-xs font-medium text-zinc-500">Langkah {index + 1} dari {available.length}</p>
        <h3 id="hl-tour-title" className="mt-1 font-bold text-zinc-900">{step.title}</h3>
        <p className="mt-1 text-sm text-zinc-700" aria-live="polite">{step.body}</p>
        <div className="mt-4 flex items-center justify-between gap-2">
          <button type="button" onClick={onFinish} className="min-h-11 rounded-lg px-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100">Lewati</button>
          <div className="flex gap-2">
            {index > 0 && (
              <button type="button" onClick={() => setIndex((i) => i - 1)} className="min-h-11 rounded-lg border border-zinc-200 px-3 text-sm font-semibold text-zinc-800">Kembali</button>
            )}
            <button
              type="button"
              autoFocus
              onClick={() => (last ? onFinish() : setIndex((i) => i + 1))}
              className="min-h-11 rounded-lg bg-zinc-900 px-4 text-sm font-semibold text-white"
            >
              {last ? 'Mulai' : 'Lanjut'}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
