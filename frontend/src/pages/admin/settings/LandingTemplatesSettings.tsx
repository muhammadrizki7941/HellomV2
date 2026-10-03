import { useEffect, useState } from 'react';
import { ArrowDown, ArrowUp, Eye, EyeOff, LayoutTemplate, Loader2, Trash2, Upload } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  ApiError,
  deleteAdminLandingTemplateImage,
  getAdminLandingTemplates,
  getImageUrl,
  updateAdminLandingTemplateLayout,
  uploadAdminLandingTemplateImage,
} from '@/lib/hellomApi';
import type { AdminLandingTemplate } from '@/lib/hellomApi';

/**
 * Super admin › Pengaturan › Template Halaman (Fase 7.4): pictures of each Hellom Page template
 * (banner, profile photo, product shots…), which templates sellers see, and their order.
 * Templates themselves are data files (backend/resources/landing/templates).
 */
export default function LandingTemplatesSettingsCard() {
  const [templates, setTemplates] = useState<AdminLandingTemplate[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    getAdminLandingTemplates().then((r) => setTemplates(r.templates))
      .catch((err: unknown) => setMessage({ ok: false, text: err instanceof Error ? err.message : 'Template belum bisa dimuat.' }));
  }, []);

  const errorText = (err: unknown) => (err instanceof ApiError ? Object.values(err.fieldErrors)[0]?.[0] ?? err.message : err instanceof Error ? err.message : 'Gagal.');
  const run = async (key: string, job: () => Promise<{ templates: AdminLandingTemplate[] }>, ok: string) => {
    setBusy(key);
    setMessage(null);
    try {
      setTemplates((await job()).templates);
      setMessage({ ok: true, text: ok });
    } catch (err) {
      setMessage({ ok: false, text: errorText(err) });
    } finally {
      setBusy(null);
    }
  };
  const saveLayout = (next: AdminLandingTemplate[], text: string) => {
    setTemplates(next);
    void run('layout', () => updateAdminLandingTemplateLayout({ order: next.map((t) => t.id), hidden: next.filter((t) => t.hidden).map((t) => t.id) }), text);
  };
  const move = (index: number, delta: number) => {
    if (!templates) return;
    const next = [...templates];
    const [item] = next.splice(index, 1);
    next.splice(index + delta, 0, item);
    saveLayout(next, 'Urutan template disimpan');
  };

  return (
    <section className="space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
      <div className="flex items-start gap-3">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100"><LayoutTemplate className="h-5 w-5 text-zinc-700" /></span>
        <div>
          <h2 className="text-lg font-bold text-zinc-900">Template Hellom Page</h2>
          <p className="text-sm text-zinc-600">Atur gambar contoh di tiap template, template mana yang tampil untuk penjual, dan urutannya. Gambar otomatis dikompres ke WebP. Slot yang kosong tampil tanpa gambar (foto profil jadi inisial).</p>
        </div>
      </div>
      {message && <p className={cn('rounded-xl p-3 text-sm', message.ok ? 'bg-green-50 text-green-700' : 'bg-rose-50 text-rose-700')} role="status">{message.text}</p>}
      {!templates && !message && <div className="h-40 animate-pulse rounded-xl bg-zinc-100" aria-busy="true" />}

      <ol className="space-y-3">
        {(templates ?? []).map((t, index) => (
          <li key={t.id} data-admin-template={t.id} className={cn('rounded-xl border p-4', t.hidden ? 'border-dashed border-zinc-300 bg-zinc-50' : 'border-zinc-200')}>
            <div className="flex flex-wrap items-start gap-3">
              <div className="min-w-0 flex-1">
                <p className="font-semibold text-zinc-900">
                  {index + 1}. {t.name}
                  {t.badge && <span className="ml-2 rounded-full bg-zinc-900 px-2 py-0.5 text-xs font-semibold text-white">{t.badge === 'populer' ? 'Populer' : 'Baru'}</span>}
                  {t.hidden && <span className="ml-2 rounded-full bg-zinc-200 px-2 py-0.5 text-xs font-semibold text-zinc-700">Disembunyikan</span>}
                </p>
                <p className="text-sm text-zinc-500">{t.category_label} · {t.description}</p>
              </div>
              <div className="flex gap-1">
                <button type="button" disabled={busy !== null || index === 0} onClick={() => move(index, -1)} aria-label={`Naikkan ${t.name}`} className="flex h-11 w-11 items-center justify-center rounded-lg border disabled:opacity-30"><ArrowUp className="h-4 w-4" /></button>
                <button type="button" disabled={busy !== null || index === (templates?.length ?? 0) - 1} onClick={() => move(index, 1)} aria-label={`Turunkan ${t.name}`} className="flex h-11 w-11 items-center justify-center rounded-lg border disabled:opacity-30"><ArrowDown className="h-4 w-4" /></button>
                <button type="button" disabled={busy !== null} onClick={() => templates && saveLayout(templates.map((x) => (x.id === t.id ? { ...x, hidden: !x.hidden } : x)), t.hidden ? `${t.name} ditampilkan lagi` : `${t.name} disembunyikan`)}
                  className="flex min-h-11 items-center gap-1.5 rounded-lg border px-3 text-sm font-semibold">
                  {t.hidden ? <><Eye className="h-4 w-4" /> Tampilkan</> : <><EyeOff className="h-4 w-4" /> Sembunyikan</>}
                </button>
              </div>
            </div>
            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {t.slots.map((slot) => {
                const key = `${t.id}:${slot.key}`;
                return (
                  <div key={slot.key} className="flex items-center gap-3 rounded-lg border border-zinc-200 p-2" data-slot={slot.key}>
                    {slot.url ? <img src={getImageUrl(slot.url)} alt="" className="h-14 w-14 shrink-0 rounded-md object-cover" /> : <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-[10px] text-zinc-500">Kosong</span>}
                    <div className="min-w-0 flex-1">
                      <p className="text-xs font-medium text-zinc-700">{slot.label}</p>
                      <div className="mt-1 flex gap-1">
                        <label className={cn('inline-flex min-h-9 cursor-pointer items-center gap-1 rounded-md border px-2 text-xs font-semibold', busy !== null && 'pointer-events-none opacity-50')}>
                          {busy === key ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Upload className="h-3.5 w-3.5" />} {slot.url ? 'Ganti' : 'Unggah'}
                          <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden"
                            onChange={(e) => { const file = e.target.files?.[0]; e.target.value = ''; if (file) void run(key, () => uploadAdminLandingTemplateImage(t.id, slot.key, file), `Gambar "${slot.label}" disimpan`); }} />
                        </label>
                        {slot.url && (
                          <button type="button" disabled={busy !== null} onClick={() => void run(key, () => deleteAdminLandingTemplateImage(t.id, slot.key), 'Gambar dihapus')}
                            aria-label={`Hapus gambar ${slot.label}`} className="inline-flex min-h-9 items-center rounded-md border px-2 text-rose-600"><Trash2 className="h-3.5 w-3.5" /></button>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </li>
        ))}
      </ol>
    </section>
  );
}
