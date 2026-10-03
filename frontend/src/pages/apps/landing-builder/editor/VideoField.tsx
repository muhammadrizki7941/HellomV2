import { useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import { getVideoPreview } from '@/lib/hellomApi';
import type { Block } from '../types';
import type { VideoPreview } from '@/lib/hellomApi';

/**
 * Video block settings (Fase 7.3): paste a YouTube / Shorts / TikTok / Instagram Reels link and the
 * preview (thumbnail + title from YouTube) appears right away; options for autoplay, title, corners.
 */
export default function VideoField({ content, patch }: { content: Block['content']; patch: (changes: Record<string, unknown>) => void }) {
  const url = String(content.videoUrl ?? '').trim();
  const [state, setState] = useState<{ url: string; data?: VideoPreview; error?: string } | null>(null);

  useEffect(() => {
    if (!url) return;
    let alive = true;
    const timer = window.setTimeout(() => {
      getVideoPreview(url)
        .then((data) => alive && setState({ url, data }))
        .catch((e) => alive && setState({ url, error: e instanceof Error ? e.message : 'Link video belum dikenali.' }));
    }, 400);
    return () => { alive = false; window.clearTimeout(timer); };
  }, [url]);

  const current = state?.url === url ? state : null;
  const loading = url !== '' && !current;
  const choice = 'min-h-11 flex-1 rounded-lg border px-2 text-sm font-semibold';

  return (
    <div className="space-y-3">
      <div className="space-y-2">
        <label className="text-xs font-bold text-zinc-700" htmlFor="video-url">Link video</label>
        <input id="video-url" type="url" inputMode="url" value={content.videoUrl ?? ''} onChange={(e) => patch({ videoUrl: e.target.value })}
          className="w-full min-h-11 rounded-lg border border-zinc-300 px-3 text-base" placeholder="https://youtu.be/… · youtube.com/shorts/… · TikTok · Reels" />
        {!url && <p className="text-xs text-zinc-500">YouTube (termasuk Shorts dan link dengan waktu mulai), TikTok, atau Instagram Reels.</p>}
      </div>

      <div aria-live="polite">
        {loading && <p className="flex items-center gap-2 text-xs text-zinc-500"><Loader2 className="h-4 w-4 animate-spin" /> Mengecek link…</p>}
        {current?.error && <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700" data-video-error>{current.error}</p>}
        {current?.data && (
          <div className="flex gap-3 rounded-xl border border-zinc-200 p-2" data-video-preview={current.data.provider}>
            {current.data.thumbnail ? (
              <img src={current.data.thumbnail} alt="" className={current.data.vertical ? 'h-24 w-14 shrink-0 rounded-lg object-cover' : 'h-16 w-28 shrink-0 rounded-lg object-cover'} />
            ) : (
              <span className="flex h-16 w-14 shrink-0 items-center justify-center rounded-lg bg-zinc-900 text-xs font-bold text-white">{current.data.label}</span>
            )}
            <div className="min-w-0 text-sm">
              <p className="font-semibold text-green-700">✓ {current.data.label}{current.data.vertical ? ' · tampil vertikal 9:16' : ' · 16:9'}</p>
              {current.data.title && <p className="line-clamp-2 text-zinc-900">{current.data.title}</p>}
              {current.data.author && <p className="truncate text-xs text-zinc-500">{current.data.author}</p>}
              {current.data.start > 0 && <p className="text-xs text-zinc-500">Mulai di menit {Math.floor(current.data.start / 60)}:{String(current.data.start % 60).padStart(2, '0')}</p>}
              {current.data.title && !content.title && (
                <button type="button" onClick={() => patch({ title: current.data?.title ?? '' })} className="mt-1 text-xs font-semibold text-zinc-900 underline">Pakai sebagai judul</button>
              )}
            </div>
          </div>
        )}
      </div>

      <label className="flex min-h-11 items-center gap-2 text-sm text-zinc-800">
        <input type="checkbox" className="h-5 w-5" checked={!!content.autoplay} onChange={(e) => patch({ autoplay: e.target.checked || undefined })} />
        Putar otomatis tanpa suara saat terlihat (YouTube)
      </label>
      <label className="flex min-h-11 items-center gap-2 text-sm text-zinc-800">
        <input type="checkbox" className="h-5 w-5" checked={!content.hideTitle} onChange={(e) => patch({ hideTitle: e.target.checked ? undefined : true })} />
        Tampilkan judul di atas video
      </label>
      <div className="space-y-1.5">
        <p className="text-xs font-bold text-zinc-700">Sudut</p>
        <div className="flex gap-1.5" role="radiogroup" aria-label="Sudut video">
          {([['rounded', 'Membulat'], ['square', 'Siku']] as const).map(([value, label]) => {
            const active = (content.corners ?? 'rounded') === value;
            return (
              <button key={value} type="button" role="radio" aria-checked={active} onClick={() => patch({ corners: value === 'rounded' ? undefined : value })}
                className={`${choice} ${active ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 text-zinc-700'}`}>{label}</button>
            );
          })}
        </div>
      </div>
    </div>
  );
}
