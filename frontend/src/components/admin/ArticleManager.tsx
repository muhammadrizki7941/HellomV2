import { useState } from 'react';
import {
  ChevronDown,
  Eye,
  Plus,
  Sparkles,
  Trash2,
  Upload,
  Wand2,
} from 'lucide-react';
import RichTextEditor from '@/components/admin/RichTextEditor';
import SeoAssistant from '@/components/admin/SeoAssistant';
import { aiAssistArticle, getImageUrl } from '@/lib/hellomApi';

type Item = Record<string, unknown> & { id?: number };

interface ArticleManagerProps {
  articles: Item[];
  setArticles: (items: Item[]) => void;
  onSave: (item: Item) => Promise<void>;
  onDelete: (id: number) => Promise<void>;
  uploadImage: (file: File) => Promise<string>;
}

const emptyArticle: Item = {
  title: '', slug: '', meta_title: '', meta_description: '', meta_keywords: '', og_image: '',
  author: '', thumbnail: '', excerpt: '', content: '', category: '', published_at: '',
  read_time: 5, is_featured: false, is_active: true,
};

const slugify = (value: string) =>
  value.toLowerCase().trim().replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-').slice(0, 80);

const str = (value: unknown) => (value === null || value === undefined ? '' : String(value));

export default function ArticleManager({ articles, setArticles, onSave, onDelete, uploadImage }: ArticleManagerProps) {
  const [openIndex, setOpenIndex] = useState<number | null>(0);
  const [savingIndex, setSavingIndex] = useState<number | null>(null);
  const [aiBusy, setAiBusy] = useState<string>('');
  const [aiError, setAiError] = useState<string>('');

  const update = (index: number, patch: Partial<Item>) => {
    setArticles(articles.map((item, current) => (current === index ? { ...item, ...patch } : item)));
  };

  const addArticle = () => {
    setArticles([{ ...emptyArticle }, ...articles]);
    setOpenIndex(0);
  };

  const handleTitle = (index: number, value: string) => {
    const item = articles[index];
    const patch: Partial<Item> = { title: value };
    // Auto-fill slug only when it has not been customised yet.
    if (!str(item.slug) || str(item.slug) === slugify(str(item.title))) {
      patch.slug = slugify(value);
    }
    update(index, patch);
  };

  const handleThumbnail = async (index: number, file: File) => {
    setAiError('');
    try {
      const url = await uploadImage(file);
      update(index, { thumbnail: url });
    } catch {
      setAiError('Gagal mengunggah thumbnail.');
    }
  };

  const estimateReadTime = (index: number) => {
    const plain = str(articles[index].content).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    const words = plain ? plain.split(/\s+/).filter(Boolean).length : 0;
    update(index, { read_time: Math.max(1, Math.round(words / 200)) });
  };

  const runAi = async (index: number, mode: 'draft' | 'improve' | 'seo' | 'excerpt' | 'ideas') => {
    const item = articles[index];
    setAiBusy(`${index}:${mode}`);
    setAiError('');
    try {
      const res = await aiAssistArticle({
        mode,
        title: str(item.title),
        content: str(item.content),
        keywords: str(item.meta_keywords),
        category: str(item.category),
      });

      if (mode === 'seo' && res.fields) {
        const f = res.fields;
        const patch: Partial<Item> = {};
        if (f.meta_title) patch.meta_title = f.meta_title;
        if (f.meta_description) patch.meta_description = f.meta_description;
        if (f.meta_keywords) patch.meta_keywords = f.meta_keywords;
        if (f.slug && !str(item.slug)) patch.slug = slugify(f.slug);
        if (f.excerpt && !str(item.excerpt)) patch.excerpt = f.excerpt;
        update(index, patch);
      } else if (mode === 'excerpt' && res.result) {
        update(index, { excerpt: res.result });
      } else if ((mode === 'draft' || mode === 'improve') && res.result) {
        update(index, { content: res.result });
      } else if (mode === 'ideas' && res.result) {
        window.alert(`Ide judul:\n\n${res.result}`);
      }
    } catch (err) {
      setAiError(err instanceof Error ? err.message : 'AI gagal merespons.');
    } finally {
      setAiBusy('');
    }
  };

  const save = async (index: number) => {
    setSavingIndex(index);
    try {
      await onSave(articles[index]);
    } finally {
      setSavingIndex(null);
    }
  };

  const aiBtn = (index: number, mode: 'draft' | 'improve' | 'seo' | 'excerpt' | 'ideas', label: string) => (
    <button
      type="button"
      onClick={() => void runAi(index, mode)}
      disabled={aiBusy !== ''}
      className="inline-flex items-center gap-1.5 rounded-lg border border-yellow-300 bg-yellow-50 px-3 py-1.5 text-xs font-bold text-yellow-700 transition hover:bg-yellow-100 disabled:opacity-50"
    >
      <Wand2 className="h-3.5 w-3.5" />
      {aiBusy === `${index}:${mode}` ? 'Memproses...' : label}
    </button>
  );

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <button onClick={addArticle} className="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-bold text-white">
          <Plus className="h-4 w-4" /> Tambah Artikel
        </button>
        <p className="text-xs text-zinc-500">{articles.length} artikel</p>
      </div>

      {aiError ? <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-700">{aiError}</div> : null}

      {articles.map((item, index) => {
        const isOpen = openIndex === index;
        const active = item.is_active !== false;
        return (
          <section key={item.id || `new-${index}`} className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
            <button
              type="button"
              onClick={() => setOpenIndex(isOpen ? null : index)}
              className="flex w-full items-center justify-between gap-3 px-5 py-4 text-left"
            >
              <div className="flex items-center gap-3">
                <div className="h-10 w-14 shrink-0 overflow-hidden rounded-md bg-zinc-100">
                  {str(item.thumbnail) ? <img src={getImageUrl(str(item.thumbnail))} alt="" className="h-full w-full object-cover" /> : null}
                </div>
                <div>
                  <p className="font-semibold text-zinc-900">{str(item.title) || 'Artikel baru'}</p>
                  <div className="mt-0.5 flex items-center gap-2 text-xs">
                    <span className={`rounded-full px-2 py-0.5 font-semibold ${active ? 'bg-emerald-100 text-emerald-700' : 'bg-zinc-100 text-zinc-500'}`}>
                      {active ? 'Tayang' : 'Draft'}
                    </span>
                    {item.is_featured ? <span className="rounded-full bg-yellow-100 px-2 py-0.5 font-semibold text-yellow-700">Featured</span> : null}
                    {str(item.category) ? <span className="text-zinc-400">{str(item.category)}</span> : null}
                  </div>
                </div>
              </div>
              <ChevronDown className={`h-5 w-5 text-zinc-400 transition ${isOpen ? 'rotate-180' : ''}`} />
            </button>

            {isOpen ? (
              <div className="grid gap-6 border-t border-zinc-100 p-5 lg:grid-cols-[1fr_320px]">
                <div className="space-y-5">
                  {/* Thumbnail */}
                  <div>
                    <label className="text-sm font-semibold text-zinc-700">Thumbnail</label>
                    <div className="mt-2 flex items-center gap-4">
                      <div className="h-24 w-40 shrink-0 overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50">
                        {str(item.thumbnail) ? <img src={getImageUrl(str(item.thumbnail))} alt="" className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center text-xs text-zinc-400">Belum ada</div>}
                      </div>
                      <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">
                        <Upload className="h-4 w-4" /> Unggah gambar
                        <input type="file" accept="image/*" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) void handleThumbnail(index, f); e.target.value = ''; }} />
                      </label>
                    </div>
                  </div>

                  <div className="grid gap-3 sm:grid-cols-2">
                    <TextField label="Judul" value={str(item.title)} onChange={(v) => handleTitle(index, v)} />
                    <TextField label="Slug (URL)" value={str(item.slug)} onChange={(v) => update(index, { slug: v })} />
                    <TextField label="Kategori" value={str(item.category)} onChange={(v) => update(index, { category: v })} />
                    <TextField label="Penulis" value={str(item.author)} onChange={(v) => update(index, { author: v })} />
                    <TextField label="Tanggal Tayang" type="datetime-local" value={str(item.published_at).slice(0, 16)} onChange={(v) => update(index, { published_at: v })} />
                    <div className="flex items-end gap-2">
                      <TextField label="Waktu Baca (menit)" type="number" value={str(item.read_time || 5)} onChange={(v) => update(index, { read_time: Number(v) })} />
                      <button type="button" onClick={() => estimateReadTime(index)} className="mb-1 rounded-lg border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-600 hover:bg-zinc-50">Auto</button>
                    </div>
                  </div>

                  {/* Excerpt */}
                  <div>
                    <div className="mb-1 flex items-center justify-between">
                      <label className="text-sm font-semibold text-zinc-700">Ringkasan (Excerpt)</label>
                      {aiBtn(index, 'excerpt', 'AI buatkan')}
                    </div>
                    <textarea rows={2} value={str(item.excerpt)} onChange={(e) => update(index, { excerpt: e.target.value })} className="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400" />
                  </div>

                  {/* Content */}
                  <div>
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                      <label className="text-sm font-semibold text-zinc-700">Isi Artikel</label>
                      <div className="flex flex-wrap gap-2">
                        {aiBtn(index, 'ideas', 'Ide judul')}
                        {aiBtn(index, 'draft', 'AI tulis draft')}
                        {aiBtn(index, 'improve', 'AI rapikan')}
                      </div>
                    </div>
                    <RichTextEditor
                      value={str(item.content)}
                      onChange={(html) => update(index, { content: html })}
                      onUploadImage={uploadImage}
                    />
                  </div>

                  {/* SEO */}
                  <div className="rounded-xl border border-yellow-200 bg-yellow-50/60 p-4">
                    <div className="mb-3 flex items-center justify-between">
                      <p className="flex items-center gap-2 text-sm font-bold text-zinc-800"><Sparkles className="h-4 w-4 text-yellow-500" /> SEO</p>
                      {aiBtn(index, 'seo', 'AI isi otomatis')}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                      <TextField label="Meta Title (kosong = pakai Judul)" value={str(item.meta_title)} onChange={(v) => update(index, { meta_title: v })} />
                      <TextField label="Meta Keywords (pisah koma)" value={str(item.meta_keywords)} onChange={(v) => update(index, { meta_keywords: v })} />
                    </div>
                    <div className="mt-3">
                      <label className="text-sm font-semibold text-zinc-700">Meta Description</label>
                      <textarea rows={2} value={str(item.meta_description)} onChange={(e) => update(index, { meta_description: e.target.value })} className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400" />
                      <p className="mt-1 text-xs text-zinc-400">{str(item.meta_description).length} / 160 karakter</p>
                    </div>
                    <div className="mt-3">
                      <TextField label="OG Image URL (kosong = pakai Thumbnail)" value={str(item.og_image)} onChange={(v) => update(index, { og_image: v })} />
                    </div>
                  </div>

                  <div className="flex flex-wrap items-center gap-6">
                    <Toggle label="Featured (tampil utama)" checked={Boolean(item.is_featured)} onChange={(v) => update(index, { is_featured: v })} />
                    <Toggle label="Tayang / Publikasikan" checked={active} onChange={(v) => update(index, { is_active: v })} />
                  </div>

                  <div className="flex items-center gap-3 border-t border-zinc-100 pt-4">
                    <button type="button" onClick={() => void save(index)} disabled={savingIndex === index} className="rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-60">
                      {savingIndex === index ? 'Menyimpan...' : 'Simpan Artikel'}
                    </button>
                    {item.slug ? (
                      <a href={`/insights/${str(item.slug)}`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-4 py-2.5 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">
                        <Eye className="h-4 w-4" /> Pratinjau
                      </a>
                    ) : null}
                    {item.id ? (
                      <button type="button" onClick={() => void onDelete(item.id!)} className="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-4 py-2.5 text-sm font-semibold text-rose-600 hover:bg-rose-50">
                        <Trash2 className="h-4 w-4" /> Hapus
                      </button>
                    ) : null}
                  </div>
                </div>

                <div className="lg:sticky lg:top-4 lg:self-start">
                  <SeoAssistant
                    title={str(item.title)}
                    slug={str(item.slug)}
                    metaTitle={str(item.meta_title)}
                    metaDescription={str(item.meta_description)}
                    metaKeywords={str(item.meta_keywords)}
                    excerpt={str(item.excerpt)}
                    content={str(item.content)}
                    thumbnail={str(item.thumbnail)}
                  />
                </div>
              </div>
            ) : null}
          </section>
        );
      })}
    </div>
  );
}

function TextField({ label, value, onChange, type = 'text' }: { label: string; value: string; onChange: (value: string) => void; type?: string }) {
  return (
    <label className="block text-sm font-medium text-zinc-700">
      {label}
      <input type={type} value={value} onChange={(e) => onChange(e.target.value)} className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400" />
    </label>
  );
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (value: boolean) => void }) {
  return (
    <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-zinc-700">
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="h-4 w-4 rounded border-zinc-300 text-yellow-500 focus:ring-yellow-400" />
      {label}
    </label>
  );
}
