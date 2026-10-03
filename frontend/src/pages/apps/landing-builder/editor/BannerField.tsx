import { useEffect, useRef, useState } from 'react';
import type React from 'react';
import { Trash2, Upload } from 'lucide-react';
import { getImageUrl, getVideoPreview } from '@/lib/hellomApi';
import type { Block } from '../types';

const RATIOS = [['wide', '16:9', '16 / 9'], ['banner', '3:1', '3 / 1'], ['square', '1:1', '1 / 1']] as const;

/**
 * Header banner of the profile block (Fase 7.1): picture/GIF or a YouTube link (plays muted on the
 * page), ratio, focus point (tap or drag on the preview), fade into the page, avatar position.
 */
export default function BannerField({ content, patch, onUpload }: {
  content: Block['content'];
  patch: (changes: Record<string, unknown>) => void;
  onUpload: (e: React.ChangeEvent<HTMLInputElement>) => void;
}) {
  const [source, setSource] = useState<'image' | 'video'>(content.coverVideo && !content.coverUrl ? 'video' : 'image');
  const [videoState, setVideoState] = useState<{ url: string; id?: string; error?: string } | null>(null);
  const frame = useRef<HTMLDivElement>(null);
  const dragging = useRef(false);
  const videoUrl = String(content.coverVideo ?? '').trim();

  useEffect(() => {
    if (!videoUrl) return;
    let alive = true;
    const timer = window.setTimeout(() => {
      getVideoPreview(videoUrl)
        .then((d) => alive && setVideoState(d.provider === 'youtube' ? { url: videoUrl, id: d.id } : { url: videoUrl, error: 'Banner video hanya bisa dari YouTube.' }))
        .catch((e) => alive && setVideoState({ url: videoUrl, error: e instanceof Error ? e.message : 'Link video belum dikenali.' }));
    }, 400);
    return () => { alive = false; window.clearTimeout(timer); };
  }, [videoUrl]);

  const video = videoState?.url === videoUrl ? videoState : null;
  const picture = content.coverUrl ? getImageUrl(content.coverUrl) : video?.id ? `https://i.ytimg.com/vi/${video.id}/hqdefault.jpg` : null;
  const ratio = RATIOS.find(([key]) => key === (content.coverRatio ?? 'banner'))?.[2] ?? '3 / 1';
  const fx = content.coverFocusX ?? 50;
  const fy = content.coverFocusY ?? 50;

  const setFocus = (clientX: number, clientY: number) => {
    const box = frame.current?.getBoundingClientRect();
    if (!box) return;
    const x = Math.round(Math.min(100, Math.max(0, ((clientX - box.left) / box.width) * 100)));
    const y = Math.round(Math.min(100, Math.max(0, ((clientY - box.top) / box.height) * 100)));
    patch({ coverFocusX: x === 50 ? undefined : x, coverFocusY: y === 50 ? undefined : y });
  };
  const seg = (active: boolean) => `min-h-11 flex-1 rounded-lg border px-2 text-sm font-semibold ${active ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 text-zinc-700'}`;

  return (
    <div className="space-y-3 rounded-xl border border-zinc-200 p-3" data-banner-field>
      <p className="text-sm font-bold text-zinc-900">Banner di atas profil <span className="font-normal text-zinc-500">(opsional)</span></p>
      <div className="flex gap-1.5" role="radiogroup" aria-label="Isi banner">
        <button type="button" role="radio" aria-checked={source === 'image'} onClick={() => setSource('image')} className={seg(source === 'image')}>Gambar / GIF</button>
        <button type="button" role="radio" aria-checked={source === 'video'} onClick={() => setSource('video')} className={seg(source === 'video')}>Video YouTube</button>
      </div>

      {source === 'image' ? (
        <div className="flex flex-wrap items-center gap-2">
          <label className="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-100 px-3 text-sm hover:bg-zinc-200">
            <Upload className="h-4 w-4 text-zinc-600" /> {content.coverUrl ? 'Ganti gambar' : 'Unggah gambar'}
            <input type="file" className="hidden" accept="image/jpeg,image/png,image/webp,image/gif" onChange={onUpload} />
          </label>
          {content.coverUrl && <button type="button" onClick={() => patch({ coverUrl: '' })} className="inline-flex min-h-11 items-center gap-1 rounded-lg px-3 text-sm text-red-600 hover:bg-red-50"><Trash2 className="h-4 w-4" /> Hapus</button>}
          <p className="w-full text-xs text-zinc-500">JPG, PNG, WebP, atau GIF sampai 8 MB. Foto otomatis dikompres.</p>
        </div>
      ) : (
        <div className="space-y-1.5">
          <input type="url" inputMode="url" value={content.coverVideo ?? ''} onChange={(e) => patch({ coverVideo: e.target.value || undefined })} aria-label="Link video YouTube untuk banner"
            className="min-h-11 w-full rounded-lg border border-zinc-300 px-3 text-base" placeholder="https://youtu.be/…" />
          {video?.error && <p className="text-xs text-rose-700">{video.error}</p>}
          {video?.id && <p className="text-xs font-medium text-green-700">✓ Diputar tanpa suara & berulang di halaman. Di editor tampil gambarnya saja.</p>}
          {!videoUrl && <p className="text-xs text-zinc-500">Tempel link YouTube. Gambar banner (kalau ada) dipakai sebelum video siap.</p>}
          {videoUrl && <button type="button" onClick={() => patch({ coverVideo: undefined })} className="text-xs font-semibold text-red-600">Hapus video</button>}
        </div>
      )}

      {picture && (
        <>
          <div ref={frame} className="relative touch-none cursor-crosshair select-none overflow-hidden rounded-xl bg-zinc-200" style={{ aspectRatio: ratio }}
            onPointerDown={(e) => { dragging.current = true; try { e.currentTarget.setPointerCapture(e.pointerId); } catch { /* pointer already gone */ } setFocus(e.clientX, e.clientY); }}
            onPointerMove={(e) => dragging.current && setFocus(e.clientX, e.clientY)}
            onPointerUp={() => { dragging.current = false; }}
            role="slider" aria-label="Titik fokus banner" aria-valuetext={`${fx}% dari kiri, ${fy}% dari atas`} aria-valuenow={fx} aria-valuemin={0} aria-valuemax={100} tabIndex={0}
            onKeyDown={(e) => {
              const step = { ArrowLeft: [-5, 0], ArrowRight: [5, 0], ArrowUp: [0, -5], ArrowDown: [0, 5] }[e.key];
              if (!step) return;
              e.preventDefault();
              patch({ coverFocusX: Math.min(100, Math.max(0, fx + step[0])), coverFocusY: Math.min(100, Math.max(0, fy + step[1])) });
            }}>
            <img src={picture} alt="" draggable={false} className="pointer-events-none h-full w-full object-cover" style={{ objectPosition: `${fx}% ${fy}%` }} />
            <span className="pointer-events-none absolute h-6 w-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-zinc-900/60 shadow" style={{ left: `${fx}%`, top: `${fy}%` }} />
          </div>
          <p className="text-xs text-zinc-500">Ketuk atau geser di gambar untuk memilih bagian yang paling penting.</p>
        </>
      )}

      <div className="space-y-1.5">
        <p className="text-xs font-bold text-zinc-700">Bentuk / tinggi</p>
        <div className="flex gap-1.5" role="radiogroup" aria-label="Rasio banner">
          {RATIOS.map(([key, label]) => (
            <button key={key} type="button" role="radio" aria-checked={(content.coverRatio ?? 'banner') === key} onClick={() => patch({ coverRatio: key === 'banner' ? undefined : key })}
              className={seg((content.coverRatio ?? 'banner') === key)}>{label}</button>
          ))}
        </div>
      </div>
      <label className="flex min-h-11 items-center gap-2 text-sm text-zinc-800">
        <input type="checkbox" className="h-5 w-5" checked={!!content.coverFade} onChange={(e) => patch({ coverFade: e.target.checked || undefined })} />
        Bagian bawah memudar ke warna background
      </label>
      <div className="space-y-1.5">
        <p className="text-xs font-bold text-zinc-700">Foto profil</p>
        <div className="flex gap-1.5" role="radiogroup" aria-label="Posisi foto profil">
          {([['overlap', 'Menumpuk di tepi banner'], ['below', 'Di bawah banner']] as const).map(([key, label]) => (
            <button key={key} type="button" role="radio" aria-checked={(content.avatarPosition ?? 'overlap') === key} onClick={() => patch({ avatarPosition: key === 'overlap' ? undefined : key })}
              className={seg((content.avatarPosition ?? 'overlap') === key)}>{label}</button>
          ))}
        </div>
      </div>
    </div>
  );
}
