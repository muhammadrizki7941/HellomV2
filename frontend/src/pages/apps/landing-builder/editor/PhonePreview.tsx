import { useEffect, useRef, useState } from 'react';
import { Loader2, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { renderLandingPreview } from '@/lib/hellomApi';
import type { LandingDocument } from '@/lib/hellomApi';

/**
 * Live phone preview. The server renders the unsaved document with the same views as the
 * public page (what you see is what gets published). The HTML runs in a sandboxed iframe
 * without same-origin access (seller content can never reach the dashboard session) and
 * talks to the editor only by postMessage: a tap selects a block, the editor highlights it.
 * Two iframes take turns so a re-render never flashes and keeps the scroll position.
 */
const RENDER_DEBOUNCE_MS = 350;

type Message = { hl?: string; id?: string | null; y?: number };

export default function PhonePreview({ pageId, document, selectedId, onSelect, framed = true, className }: {
  pageId: number;
  document: LandingDocument;
  selectedId: string | null;
  onSelect: (id: string | null) => void;
  /** Phone frame (desktop) or full-bleed (phone screens). */
  framed?: boolean;
  className?: string;
}) {
  const frames = [useRef<HTMLIFrameElement>(null), useRef<HTMLIFrameElement>(null)];
  const [front, setFront] = useState(0);
  const [html, setHtml] = useState<[string, string]>(['', '']);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [retry, setRetry] = useState(0);
  const frontRef = useRef(0);
  const pendingRef = useRef<number | null>(null); // iframe index waiting for its first message
  const scrollRef = useRef(0);
  const selectedRef = useRef(selectedId);
  const fromPreviewRef = useRef(false);
  const onSelectRef = useRef(onSelect);
  const seqRef = useRef(0);
  const serialized = JSON.stringify(document);

  useEffect(() => { onSelectRef.current = onSelect; }, [onSelect]);
  useEffect(() => { selectedRef.current = selectedId; }, [selectedId]);

  // Render (debounced) into the hidden iframe.
  useEffect(() => {
    const seq = ++seqRef.current;
    setLoading(true);
    const timer = window.setTimeout(() => {
      renderLandingPreview(pageId, JSON.parse(serialized) as LandingDocument)
        .then(({ html: next }) => {
          if (seq !== seqRef.current) return; // a newer edit is on its way
          const back = 1 - frontRef.current;
          pendingRef.current = back;
          setHtml((current) => {
            const copy: [string, string] = [current[0], current[1]];
            // Unique per render: identical HTML would not reload the iframe (no "ready").
            copy[back] = `${next}<!-- r${seq} -->`;
            return copy;
          });
          setFailed(false);
        })
        .catch(() => { if (seq === seqRef.current) { setFailed(true); setLoading(false); } });
    }, seqRef.current === 1 ? 0 : RENDER_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [pageId, serialized, retry]);

  // Messages from the preview (only from our two iframes).
  useEffect(() => {
    const post = (index: number, message: Record<string, unknown>) => frames[index].current?.contentWindow?.postMessage(message, '*');
    const onMessage = (event: MessageEvent<Message>) => {
      const index = frames.findIndex((f) => f.current?.contentWindow === event.source);
      if (index < 0 || !event.data || typeof event.data !== 'object') return;
      const data = event.data;
      if (data.hl === 'ready' && index === pendingRef.current) {
        // New render loaded: same scroll + highlight, then show it.
        post(index, { hl: 'restore', y: scrollRef.current });
        post(index, { hl: 'highlight', id: selectedRef.current, scroll: false });
        pendingRef.current = null;
        frontRef.current = index;
        setFront(index);
        setLoading(false);
      }
      if (index !== frontRef.current) return;
      if (data.hl === 'scroll' && typeof data.y === 'number') scrollRef.current = data.y;
      if (data.hl === 'select') {
        fromPreviewRef.current = true;
        onSelectRef.current(data.id ?? null);
      }
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Selected in the list/settings → highlight and scroll there (a tap in the preview stays put).
  useEffect(() => {
    const scroll = !fromPreviewRef.current;
    fromPreviewRef.current = false;
    frames[frontRef.current].current?.contentWindow?.postMessage({ hl: 'highlight', id: selectedId, scroll }, '*');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedId]);

  const screen = (
    <div className="relative h-full w-full overflow-hidden bg-white">
      {[0, 1].map((index) => (
        <iframe
          key={index}
          ref={frames[index]}
          title={index === front ? 'Pratinjau halaman' : 'Pratinjau (memuat)'}
          aria-hidden={index !== front}
          tabIndex={index === front ? 0 : -1}
          sandbox="allow-scripts"
          srcDoc={html[index]}
          className={cn('absolute inset-0 h-full w-full border-0', index === front ? 'visible' : 'invisible')}
        />
      ))}
      {loading && !failed && (
        <div className="pointer-events-none absolute right-2 top-2 rounded-full bg-white/90 p-1.5 shadow" aria-label="Memperbarui pratinjau">
          <Loader2 className="h-4 w-4 animate-spin text-zinc-500" />
        </div>
      )}
      {failed && (
        <div className="absolute inset-x-3 top-3 flex items-center justify-between gap-2 rounded-xl bg-red-50 p-3 text-sm text-red-700 shadow">
          Pratinjau belum bisa dimuat.
          <button type="button" onClick={() => setRetry((n) => n + 1)} className="inline-flex min-h-9 items-center gap-1 font-semibold underline"><RefreshCw className="h-4 w-4" /> Coba lagi</button>
        </div>
      )}
    </div>
  );

  if (!framed) return <div className={cn('h-full w-full', className)} data-tour="preview">{screen}</div>;

  return (
    <div className={cn('flex h-full items-center justify-center', className)}>
      <div data-tour="preview" className="relative aspect-[9/19] h-full max-h-[760px] min-h-[480px] rounded-[2.75rem] bg-zinc-900 p-3 shadow-2xl ring-1 ring-black/10">
        <div className="absolute left-1/2 top-3 z-10 h-5 w-24 -translate-x-1/2 rounded-b-2xl bg-zinc-900" aria-hidden="true" />
        <div className="h-full overflow-hidden rounded-[2.1rem]">{screen}</div>
      </div>
    </div>
  );
}
