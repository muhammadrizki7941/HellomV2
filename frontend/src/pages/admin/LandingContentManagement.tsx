import { useCallback, useEffect, useState } from 'react';
import type React from 'react';
import { Loader2, Plus, Save, Sparkles, Trash2 } from 'lucide-react';
import {
  createAdminLandingArticle,
  createAdminLandingService,
  deleteAdminLandingArticle,
  deleteAdminLandingService,
  getAdminLandingContent,
  updateAdminLandingAbout,
  updateAdminLandingArticle,
  updateAdminLandingService,
  uploadShowcaseMedia,
} from '@/lib/hellomApi';
import ArticleManager from '@/components/admin/ArticleManager';

type Tab = 'about' | 'services' | 'articles';
type Item = Record<string, unknown> & { id?: number };

const emptyService = { title: '', slug: '', icon: 'Sparkles', short_description: '', long_description: '', featured_image: '', sort_order: 0, is_active: true };

export default function LandingContentManagement() {
  const [tab, setTab] = useState<Tab>('about');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [about, setAbout] = useState<Item>({
    title: 'Membangun dengan strategi, berkarya dengan estetika.',
    subtitle: 'About me',
    description: '',
    years_experience: 5,
    projects_completed: 100,
    happy_clients: 50,
    support_label: '24/7',
    products_label: 'Products',
    products_heading: 'Produk digital premium untuk hasil maksimal.',
    products_description: 'Pilih produk digital yang bisa langsung dipakai atau dibeli. POS, Landing Page Builder, template, ekstensi, dan produk lain akan tampil otomatis dari katalog backend.',
    products_cta_label: 'Lihat semua produk',
    is_active: true,
  });
  const [services, setServices] = useState<Item[]>([]);
  const [articles, setArticles] = useState<Item[]>([]);

  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await getAdminLandingContent();
      if (data.about) setAbout(data.about as Item);
      setServices((data.services as Item[]) || []);
      setArticles((data.articles as Item[]) || []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat konten situs.');
    } finally {
      setLoading(false);
    }
  }, []);

  // Every save/delete reports success or the API error instead of failing silently.
  const run = async (action: () => Promise<unknown>, success: string) => {
    setMessage(null);
    setError(null);
    try {
      await action();
      setMessage(success);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menyimpan. Coba lagi.');
    }
  };

  const confirmDelete = (label: string, remove: () => Promise<unknown>) => {
    if (!window.confirm(`Hapus ${label}? Tindakan ini tidak bisa dibatalkan.`)) return Promise.resolve();
    return run(remove, `${label[0].toUpperCase()}${label.slice(1)} dihapus.`);
  };

  useEffect(() => {
    void load();
  }, [load]);

  const saveAbout = () => run(() => updateAdminLandingAbout(about), 'Konten "Tentang" disimpan.');

  const saveService = (item: Item) => run(
    () => (item.id ? updateAdminLandingService(item.id, item) : createAdminLandingService(item)),
    'Layanan disimpan.',
  );

  const saveArticle = (item: Item) => run(
    () => (item.id ? updateAdminLandingArticle(item.id, item) : createAdminLandingArticle(item)),
    'Artikel disimpan.',
  );

  const setField = (setter: (items: Item[]) => void, items: Item[], index: number, key: string, value: unknown) => {
    setter(items.map((item, current) => current === index ? { ...item, [key]: value } : item));
  };

  const uploadImage = useCallback(async (file: File) => {
    const res = (await uploadShowcaseMedia(file)) as { url?: string; path?: string };
    return String(res.url || res.path || '');
  }, []);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Konten Situs</h1>
          <p className="text-zinc-500">Atur bagian Tentang, Layanan, dan artikel Wawasan di hellomspace.com.</p>
        </div>
      </div>

      {error && <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">{error}</div>}
      {message && !error && <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-3 text-sm text-zinc-800">{message}</div>}

      <div className="flex w-fit gap-1 rounded-lg bg-zinc-100 p-1">
        {(['about', 'services', 'articles'] as Tab[]).map((item) => (
          <button key={item} onClick={() => setTab(item)} className={`rounded-md px-4 py-2 text-sm font-semibold ${tab === item ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-500'}`}>
            {({ about: 'Tentang', services: 'Layanan', articles: 'Artikel' } as Record<Tab, string>)[item]}
          </button>
        ))}
      </div>

      {loading ? (
        <div className="flex h-40 items-center justify-center text-zinc-400"><Loader2 className="h-6 w-6 animate-spin" /></div>
      ) : null}

      {tab === 'about' && !loading && (
        <section className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
          <div className="grid gap-4 md:grid-cols-2">
            <Field label="Judul" value={String(about.title || '')} onChange={(value) => setAbout({ ...about, title: value })} />
            <Field label="Subjudul" value={String(about.subtitle || '')} onChange={(value) => setAbout({ ...about, subtitle: value })} />
            <Textarea label="Deskripsi" value={String(about.description || '')} onChange={(value) => setAbout({ ...about, description: value })} />
            <div className="grid grid-cols-2 gap-3">
              <Field label="Tahun pengalaman" type="number" value={String(about.years_experience || 0)} onChange={(value) => setAbout({ ...about, years_experience: Number(value) })} />
              <Field label="Proyek selesai" type="number" value={String(about.projects_completed || 0)} onChange={(value) => setAbout({ ...about, projects_completed: Number(value) })} />
              <Field label="Klien puas" type="number" value={String(about.happy_clients || 0)} onChange={(value) => setAbout({ ...about, happy_clients: Number(value) })} />
              <Field label="Label dukungan" value={String(about.support_label || '')} onChange={(value) => setAbout({ ...about, support_label: value })} />
            </div>
          </div>
          <button onClick={() => void saveAbout()} className="mt-5 inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-5 py-2 text-sm font-bold text-white"><Save className="h-4 w-4" /> Simpan</button>
        </section>
      )}

      {tab === 'about' && !loading && (
        <section className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-base font-bold text-zinc-900">Bagian Produk</h2>
            <p className="text-sm text-zinc-500">Copy ini tampil di section Products pada landing page utama.</p>
          </div>
          <div className="grid gap-4 md:grid-cols-2">
            <Field label="Label bagian" value={String(about.products_label || '')} onChange={(value) => setAbout({ ...about, products_label: value })} />
            <Field label="Teks tombol" value={String(about.products_cta_label || '')} onChange={(value) => setAbout({ ...about, products_cta_label: value })} />
            <Field label="Judul bagian" value={String(about.products_heading || '')} onChange={(value) => setAbout({ ...about, products_heading: value })} />
            <Textarea label="Deskripsi" value={String(about.products_description || '')} onChange={(value) => setAbout({ ...about, products_description: value })} />
          </div>
          <button onClick={() => void saveAbout()} className="mt-5 inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-5 py-2 text-sm font-bold text-white"><Save className="h-4 w-4" /> Simpan teks produk</button>
        </section>
      )}

      {tab === 'services' && !loading && (
        <CrudList
          icon={<Sparkles className="h-5 w-5" />}
          items={services}
          emptyItem={emptyService}
          setItems={setServices}
          onSave={saveService}
          onDelete={(id) => confirmDelete('layanan ini', () => deleteAdminLandingService(id))}
          render={(item, index) => (
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="Judul" value={String(item.title || '')} onChange={(value) => setField(setServices, services, index, 'title', value)} />
              <Field label="Slug" value={String(item.slug || '')} onChange={(value) => setField(setServices, services, index, 'slug', value)} />
              <Field label="Ikon" value={String(item.icon || '')} onChange={(value) => setField(setServices, services, index, 'icon', value)} />
              <Field label="Urutan" type="number" value={String(item.sort_order || 0)} onChange={(value) => setField(setServices, services, index, 'sort_order', Number(value))} />
              <Textarea label="Deskripsi singkat" value={String(item.short_description || '')} onChange={(value) => setField(setServices, services, index, 'short_description', value)} />
              <Textarea label="Deskripsi lengkap" value={String(item.long_description || '')} onChange={(value) => setField(setServices, services, index, 'long_description', value)} />
            </div>
          )}
        />
      )}

      {tab === 'articles' && !loading && (
        <ArticleManager
          articles={articles}
          setArticles={setArticles}
          onSave={saveArticle}
          onDelete={(id) => confirmDelete('artikel ini', () => deleteAdminLandingArticle(id))}
          uploadImage={uploadImage}
        />
      )}
    </div>
  );
}

function CrudList({
  icon,
  items,
  emptyItem,
  setItems,
  onSave,
  onDelete,
  render,
}: {
  icon: React.ReactNode;
  items: Item[];
  emptyItem: Item;
  setItems: (items: Item[]) => void;
  onSave: (item: Item) => Promise<void>;
  onDelete: (id: number) => Promise<void>;
  render: (item: Item, index: number) => React.ReactNode;
}) {
  return (
    <div className="space-y-4">
      <button onClick={() => setItems([{ ...emptyItem }, ...items])} className="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-bold text-white"><Plus className="h-4 w-4" /> Tambah</button>
      {items.map((item, index) => (
        <section key={item.id || `new-${index}`} className="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
          <div className="mb-4 flex items-center justify-between">
            <div className="flex items-center gap-2 font-bold text-zinc-900">{icon} {String(item.title || 'Item baru')}</div>
            <div className="flex gap-2">
              <button onClick={() => void onSave(item)} className="rounded-lg bg-zinc-900 px-3 py-2 text-xs font-bold text-white">Simpan</button>
              {item.id ? <button onClick={() => void onDelete(item.id!)} title="Hapus" className="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-600"><Trash2 className="h-4 w-4" /></button> : null}
            </div>
          </div>
          {render(item, index)}
        </section>
      ))}
    </div>
  );
}

function Field({ label, value, onChange, type = 'text' }: { label: string; value: string; onChange: (value: string) => void; type?: string }) {
  return (
    <label className="block text-sm font-medium text-zinc-700">
      {label}
      <input type={type} value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400" />
    </label>
  );
}

function Textarea({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return (
    <label className="block text-sm font-medium text-zinc-700">
      {label}
      <textarea rows={4} value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400" />
    </label>
  );
}

