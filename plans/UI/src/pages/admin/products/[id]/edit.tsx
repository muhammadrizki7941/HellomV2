import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Save, Upload, X } from 'lucide-react';
import {
  createProduct,
  deleteProductDoc,
  deleteProductFile,
  fetchAuthorizedBlobUrl,
  getAdminProductById,
  getImageUrl,
  updateProduct,
  uploadProductDoc,
  uploadProductFile,
  uploadProductThumbnail,
} from '@/lib/hellomApi';

type ProductFile = {
  id: number;
  label: string;
  file_type: string;
  version?: string | null;
  is_primary?: boolean;
};

type ProductDoc = {
  id: number;
  title: string;
  doc_type: string;
  content?: string | null;
  file_path?: string | null;
  video_url?: string | null;
  external_url?: string | null;
};

type Product = {
  id: number;
  name: string;
  slug: string;
  tagline?: string | null;
  description?: string | null;
  category: string;
  type: string;
  price: number;
  tech_stack?: string[] | null;
  tags?: string[] | null;
  is_featured: boolean;
  is_published: boolean;
  files: ProductFile[];
  docs: ProductDoc[];
};

const resolveYoutubeEmbedUrl = (value?: string | null): string | null => {
  const raw = String(value || '').trim();
  if (!raw) return null;

  try {
    const url = new URL(raw);
    const host = url.hostname.replace(/^www\./, '');
    let videoId = '';

    if (host === 'youtu.be') {
      videoId = url.pathname.split('/').filter(Boolean)[0] || '';
    } else if (host === 'youtube.com' || host === 'm.youtube.com' || host === 'music.youtube.com') {
      if (url.pathname === '/watch') {
        videoId = url.searchParams.get('v') || '';
      } else if (url.pathname.startsWith('/embed/')) {
        videoId = url.pathname.split('/')[2] || '';
      } else if (url.pathname.startsWith('/shorts/')) {
        videoId = url.pathname.split('/')[2] || '';
      } else if (url.pathname.startsWith('/live/')) {
        videoId = url.pathname.split('/')[2] || '';
      }
    }

    if (!videoId) {
      return null;
    }

    return `https://www.youtube.com/embed/${videoId}`;
  } catch {
    return null;
  }
};

export default function AdminProductEdit() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState<'info' | 'files' | 'docs' | 'preview'>('info');
  const [product, setProduct] = useState<Product | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [form, setForm] = useState({
    name: '',
    tagline: '',
    category: 'application',
    type: 'paid',
    price: 0,
    description: '',
    tech_stack: '',
    tags: '',
    is_featured: false,
    is_published: false,
  });

  const [thumbnailFile, setThumbnailFile] = useState<File | null>(null);
  const [docPreviewUrls, setDocPreviewUrls] = useState<Record<number, string>>({});

  const [fileForm, setFileForm] = useState({
    label: '',
    version: '',
    file_type: 'zip',
    is_primary: false,
    sort_order: 0,
    file: null as File | null,
  });

  const [docForm, setDocForm] = useState({
    title: '',
    doc_type: 'text',
    content: '',
    video_url: '',
    external_url: '',
    file: null as File | null,
  });

  const isNew = !id || id === 'new';

  const loadProduct = async () => {
    if (!id || isNew) {
      setLoading(false);
      return;
    }

    setLoading(true);
    setError(null);
    try {
      const response = await getAdminProductById(id);
      const data = response as Product;
      setProduct(data);
      setForm({
        name: data.name || '',
        tagline: data.tagline || '',
        category: data.category || 'application',
        type: data.type || 'paid',
        price: Number(data.price || 0),
        description: data.description || '',
        tech_stack: (data.tech_stack || []).join(', '),
        tags: (data.tags || []).join(', '),
        is_featured: Boolean(data.is_featured),
        is_published: Boolean(data.is_published),
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat produk');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadProduct();
  }, [id]);

  useEffect(() => {
    let cancelled = false;
    const createdUrls: string[] = [];

    const loadDocPreviews = async () => {
      if (!product?.docs?.length) {
        setDocPreviewUrls({});
        return;
      }

      const entries = await Promise.all(
        product.docs
          .filter((doc) => doc.doc_type === 'pdf')
          .map(async (doc) => {
            try {
              const blobUrl = await fetchAuthorizedBlobUrl(`/admin/digital-products/docs/${doc.id}/preview`);
              createdUrls.push(blobUrl);
              return [doc.id, blobUrl] as const;
            } catch {
              return [doc.id, ''] as const;
            }
          })
      );

      if (!cancelled) {
        setDocPreviewUrls(
          entries.reduce<Record<number, string>>((acc, [docId, url]) => {
            if (url) acc[docId] = url;
            return acc;
          }, {})
        );
      }
    };

    void loadDocPreviews();

    return () => {
      cancelled = true;
      createdUrls.forEach((url) => URL.revokeObjectURL(url));
    };
  }, [product]);

  const techStackArray = useMemo(() => form.tech_stack.split(',').map((item) => item.trim()).filter(Boolean), [form.tech_stack]);
  const tagsArray = useMemo(() => form.tags.split(',').map((item) => item.trim()).filter(Boolean), [form.tags]);

  const handleSave = async () => {
    setError(null);
    try {
      const payload = {
        name: form.name,
        tagline: form.tagline || null,
        category: form.category,
        type: form.type,
        price: form.type === 'free' ? 0 : Number(form.price || 0),
        description: form.description || null,
        tech_stack: techStackArray,
        tags: tagsArray,
        is_featured: form.is_featured,
        is_published: form.is_published,
      };

      let targetId = product?.id;
      if (isNew) {
        const created = await createProduct(payload);
        targetId = (created as Product).id;
        navigate(`/admin/products/${targetId}/edit`, { replace: true });
      } else if (targetId) {
        await updateProduct(targetId, payload);
      }

      if (thumbnailFile && targetId) {
        const formData = new FormData();
        formData.append('thumbnail', thumbnailFile);
        await uploadProductThumbnail(targetId, formData);
        setThumbnailFile(null);
      }

      await loadProduct();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menyimpan produk');
    }
  };

  const handleUploadFile = async () => {
    if (!product || !fileForm.file) return;
    setError(null);
    try {
      const formData = new FormData();
      formData.append('label', fileForm.label);
      formData.append('file_type', fileForm.file_type);
      if (fileForm.version) formData.append('version', fileForm.version);
      formData.append('is_primary', fileForm.is_primary ? '1' : '0');
      formData.append('sort_order', String(fileForm.sort_order || 0));
      formData.append('product_file', fileForm.file);
      await uploadProductFile(product.id, formData);
      setFileForm({ label: '', version: '', file_type: 'zip', is_primary: false, sort_order: 0, file: null });
      await loadProduct();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal upload file');
    }
  };

  const handleUploadDoc = async () => {
    if (!product) return;
    setError(null);
    try {
      const payload: Record<string, unknown> = {
        title: docForm.title,
        doc_type: docForm.doc_type,
        content: docForm.doc_type === 'text' ? docForm.content : null,
        video_url: docForm.doc_type === 'video' ? docForm.video_url : null,
        external_url: docForm.doc_type === 'link' ? docForm.external_url : null,
      };

      if (docForm.doc_type === 'pdf' && docForm.file) {
        const formData = new FormData();
        formData.append('title', docForm.title);
        formData.append('doc_type', docForm.doc_type);
        formData.append('doc_pdf', docForm.file);
        await uploadProductDoc(product.id, formData);
      } else {
        await uploadProductDoc(product.id, payload);
      }

      setDocForm({ title: '', doc_type: 'text', content: '', video_url: '', external_url: '', file: null });
      await loadProduct();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal upload doc');
    }
  };

  const handleDeleteFile = async (fileId: number) => {
    if (!window.confirm('Hapus file ini?')) return;
    try {
      await deleteProductFile(fileId);
      await loadProduct();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menghapus file');
    }
  };

  const handleDeleteDoc = async (docId: number) => {
    if (!window.confirm('Hapus dokumentasi ini?')) return;
    try {
      await deleteProductDoc(docId);
      await loadProduct();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menghapus dokumentasi');
    }
  };

  if (loading) {
    return <div className="text-sm text-zinc-500">Memuat produk...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">{isNew ? 'Tambah Produk' : 'Edit Produk'}</h1>
          <p className="text-sm text-zinc-600">Kelola detail produk digital.</p>
        </div>
        <button
          onClick={() => void handleSave()}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-zinc-900 text-white text-sm font-semibold"
        >
          <Save className="w-4 h-4" /> Simpan
        </button>
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">
          {error}
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        {[
          { key: 'info', label: 'Info Produk' },
          { key: 'files', label: 'File & Download' },
          { key: 'docs', label: 'Dokumentasi' },
          { key: 'preview', label: 'Preview' },
        ].map((tab) => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key as typeof activeTab)}
            className={`px-4 py-2 text-xs font-semibold rounded-full border ${
              activeTab === tab.key
                ? 'bg-zinc-900 text-white border-zinc-900'
                : 'bg-white text-zinc-600 border-zinc-200'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {activeTab === 'info' && (
        <div className="bg-white border border-zinc-200 rounded-2xl p-6 space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label className="text-sm text-zinc-600">
              Nama Produk
              <input
                value={form.name}
                onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
                className="mt-2 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm"
              />
            </label>
            <label className="text-sm text-zinc-600">
              Tagline
              <input
                value={form.tagline}
                onChange={(event) => setForm((prev) => ({ ...prev, tagline: event.target.value }))}
                className="mt-2 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm"
              />
            </label>
            <label className="text-sm text-zinc-600">
              Category
              <select
                value={form.category}
                onChange={(event) => setForm((prev) => ({ ...prev, category: event.target.value }))}
                className="mt-2 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm"
              >
                <option value="source_code">Source Code</option>
                <option value="application">Aplikasi</option>
                <option value="extension">Extension</option>
                <option value="ebook">Ebook</option>
                <option value="template">Template</option>
                <option value="course">Course</option>
                <option value="other">Other</option>
              </select>
            </label>
            <label className="text-sm text-zinc-600">
              Type
              <select
                value={form.type}
                onChange={(event) => setForm((prev) => ({ ...prev, type: event.target.value }))}
                className="mt-2 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm"
              >
                <option value="free">Free</option>
                <option value="paid">Paid</option>
                <option value="subscription_locked">Subscription Locked</option>
              </select>
            </label>
            {form.type !== 'free' && (
              <label className="text-sm text-zinc-600">
                Harga (IDR)
                <input
                  type="number"
                  min={0}
                  value={form.price}
                  onChange={(event) => setForm((prev) => ({ ...prev, price: Number(event.target.value) }))}
                  className="mt-2 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm"
                />
              </label>
            )}
          </div>

          <label className="text-sm text-zinc-600">
            Deskripsi
            <textarea
              value={form.description}
              onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))}
              className="mt-2 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm min-h-[120px]"
            />
          </label>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label className="text-sm text-zinc-600">
              Tech Stack (pisahkan dengan koma)
              <input
                value={form.tech_stack}
                onChange={(event) => setForm((prev) => ({ ...prev, tech_stack: event.target.value }))}
                className="mt-2 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm"
              />
            </label>
            <label className="text-sm text-zinc-600">
              Tags (pisahkan dengan koma)
              <input
                value={form.tags}
                onChange={(event) => setForm((prev) => ({ ...prev, tags: event.target.value }))}
                className="mt-2 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm"
              />
            </label>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label className="text-sm text-zinc-600 flex items-center gap-2">
              <input
                type="checkbox"
                checked={form.is_featured}
                onChange={(event) => setForm((prev) => ({ ...prev, is_featured: event.target.checked }))}
              />
              Featured
            </label>
            <label className="text-sm text-zinc-600 flex items-center gap-2">
              <input
                type="checkbox"
                checked={form.is_published}
                onChange={(event) => setForm((prev) => ({ ...prev, is_published: event.target.checked }))}
              />
              Published
            </label>
          </div>

          <label className="text-sm text-zinc-600">
            Thumbnail
            <input
              type="file"
              accept="image/*"
              onChange={(event) => setThumbnailFile(event.target.files ? event.target.files[0] : null)}
              className="mt-2 block w-full text-sm text-zinc-600"
            />
          </label>
        </div>
      )}

      {activeTab === 'files' && (
        <div className="bg-white border border-zinc-200 rounded-2xl p-6 space-y-5">
          <div className="text-sm text-zinc-600 font-semibold">Upload File</div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input
              placeholder="Label"
              value={fileForm.label}
              onChange={(event) => setFileForm((prev) => ({ ...prev, label: event.target.value }))}
              className="rounded-lg border border-zinc-200 px-3 py-2 text-sm"
            />
            <input
              placeholder="Version"
              value={fileForm.version}
              onChange={(event) => setFileForm((prev) => ({ ...prev, version: event.target.value }))}
              className="rounded-lg border border-zinc-200 px-3 py-2 text-sm"
            />
            <select
              value={fileForm.file_type}
              onChange={(event) => setFileForm((prev) => ({ ...prev, file_type: event.target.value }))}
              className="rounded-lg border border-zinc-200 px-3 py-2 text-sm"
            >
              <option value="zip">ZIP</option>
              <option value="pdf">PDF</option>
              <option value="mp4">MP4</option>
              <option value="exe">EXE</option>
              <option value="apk">APK</option>
              <option value="other">Other</option>
            </select>
            <input
              type="file"
              onChange={(event) => setFileForm((prev) => ({ ...prev, file: event.target.files ? event.target.files[0] : null }))}
              className="text-sm text-zinc-600"
            />
            <label className="text-sm text-zinc-600 flex items-center gap-2">
              <input
                type="checkbox"
                checked={fileForm.is_primary}
                onChange={(event) => setFileForm((prev) => ({ ...prev, is_primary: event.target.checked }))}
              />
              File Utama
            </label>
          </div>
          <button
            onClick={() => void handleUploadFile()}
            disabled={!product || !fileForm.file || !fileForm.label}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-zinc-900 text-white text-sm font-semibold disabled:opacity-60"
          >
            <Upload className="w-4 h-4" /> Upload File
          </button>

          <div className="space-y-3">
            {(product?.files || []).map((file) => (
              <div key={file.id} className="flex items-center justify-between border border-zinc-200 rounded-lg px-4 py-2">
                <div>
                  <div className="text-sm font-semibold text-zinc-900">{file.label}</div>
                  <div className="text-xs text-zinc-500">{file.file_type.toUpperCase()} • {file.version || '-'}</div>
                </div>
                <button
                  onClick={() => void handleDeleteFile(file.id)}
                  className="text-xs font-semibold text-red-600 inline-flex items-center gap-1"
                >
                  <X className="w-3 h-3" /> Delete
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {activeTab === 'docs' && (
        <div className="bg-white border border-zinc-200 rounded-2xl p-6 space-y-5">
          <div className="text-sm text-zinc-600 font-semibold">Tambah Dokumentasi</div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input
              placeholder="Judul"
              value={docForm.title}
              onChange={(event) => setDocForm((prev) => ({ ...prev, title: event.target.value }))}
              className="rounded-lg border border-zinc-200 px-3 py-2 text-sm"
            />
            <select
              value={docForm.doc_type}
              onChange={(event) => setDocForm((prev) => ({ ...prev, doc_type: event.target.value }))}
              className="rounded-lg border border-zinc-200 px-3 py-2 text-sm"
            >
              <option value="text">Text</option>
              <option value="pdf">PDF</option>
              <option value="video">Video</option>
              <option value="link">Link</option>
            </select>
            {docForm.doc_type === 'text' && (
              <textarea
                placeholder="Konten"
                value={docForm.content}
                onChange={(event) => setDocForm((prev) => ({ ...prev, content: event.target.value }))}
                className="rounded-lg border border-zinc-200 px-3 py-2 text-sm min-h-[120px] md:col-span-2"
              />
            )}
            {docForm.doc_type === 'video' && (
              <input
                placeholder="URL Video"
                value={docForm.video_url}
                onChange={(event) => setDocForm((prev) => ({ ...prev, video_url: event.target.value }))}
                className="rounded-lg border border-zinc-200 px-3 py-2 text-sm"
              />
            )}
            {docForm.doc_type === 'link' && (
              <input
                placeholder="URL"
                value={docForm.external_url}
                onChange={(event) => setDocForm((prev) => ({ ...prev, external_url: event.target.value }))}
                className="rounded-lg border border-zinc-200 px-3 py-2 text-sm"
              />
            )}
            {docForm.doc_type === 'pdf' && (
              <input
                type="file"
                accept="application/pdf"
                onChange={(event) => setDocForm((prev) => ({ ...prev, file: event.target.files ? event.target.files[0] : null }))}
                className="text-sm text-zinc-600"
              />
            )}
          </div>
          <button
            onClick={() => void handleUploadDoc()}
            disabled={!product || !docForm.title}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-zinc-900 text-white text-sm font-semibold disabled:opacity-60"
          >
            <Upload className="w-4 h-4" /> Upload Doc
          </button>

          <div className="space-y-3">
            {(product?.docs || []).map((doc) => (
              <div key={doc.id} className="border border-zinc-200 rounded-lg px-4 py-3">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="text-sm font-semibold text-zinc-900">{doc.title}</div>
                    <div className="text-xs text-zinc-500">{doc.doc_type.toUpperCase()}</div>
                  </div>
                  <button
                    onClick={() => void handleDeleteDoc(doc.id)}
                    className="text-xs font-semibold text-red-600 inline-flex items-center gap-1"
                  >
                    <X className="w-3 h-3" /> Delete
                  </button>
                </div>
                {doc.doc_type === 'pdf' && docPreviewUrls[doc.id] ? (
                  <iframe
                    src={docPreviewUrls[doc.id]}
                    title={doc.title}
                    className="mt-3 h-72 w-full rounded-xl border border-zinc-200"
                  />
                ) : null}
                {doc.doc_type === 'video' && resolveYoutubeEmbedUrl(doc.video_url) ? (
                  <iframe
                    src={resolveYoutubeEmbedUrl(doc.video_url) || undefined}
                    title={doc.title}
                    className="mt-3 aspect-video w-full rounded-xl border border-zinc-200"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowFullScreen
                  />
                ) : null}
              </div>
            ))}
          </div>
        </div>
      )}

      {activeTab === 'preview' && (
        <div className="bg-white border border-zinc-200 rounded-2xl p-6 space-y-6 text-sm text-zinc-600">
          <div>
            <h2 className="text-xl font-bold text-zinc-900">{form.name || 'Nama produk'}</h2>
            <p className="mt-2 text-zinc-600">{form.tagline || 'Tagline produk akan tampil di sini.'}</p>
            <p className="mt-4 text-zinc-700">{form.description || 'Deskripsi produk akan tampil di area preview ini.'}</p>
          </div>

          <div className="grid gap-3 md:grid-cols-2">
            <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
              <div className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Harga</div>
              <div className="mt-2 text-lg font-bold text-zinc-900">
                {form.type === 'free' ? 'Gratis' : `Rp ${Number(form.price || 0).toLocaleString('id-ID')}`}
              </div>
            </div>
            <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
              <div className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Kategori</div>
              <div className="mt-2 text-lg font-bold text-zinc-900">{form.category}</div>
            </div>
          </div>

          {product?.thumbnail_url ? (
            <img
              src={getImageUrl(product.thumbnail_url)}
              alt={product.name}
              className="h-64 w-full rounded-2xl border border-zinc-200 object-cover"
            />
          ) : null}

          <div className="space-y-4">
            <h3 className="font-semibold text-zinc-900">Preview Dokumentasi</h3>
            {(product?.docs || []).length === 0 ? (
              <div className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-5">
                Belum ada dokumentasi untuk dipreview.
              </div>
            ) : (
              product?.docs.map((doc) => (
                <div key={doc.id} className="rounded-2xl border border-zinc-200 p-4">
                  <div className="font-semibold text-zinc-900">{doc.title}</div>
                  <div className="mt-1 text-xs text-zinc-500">{doc.doc_type.toUpperCase()}</div>
                  {doc.content ? <p className="mt-3 text-zinc-700">{doc.content}</p> : null}
                  {doc.doc_type === 'pdf' && docPreviewUrls[doc.id] ? (
                    <iframe
                      src={docPreviewUrls[doc.id]}
                      title={doc.title}
                      className="mt-4 h-96 w-full rounded-xl border border-zinc-200"
                    />
                  ) : null}
                  {doc.doc_type === 'video' && resolveYoutubeEmbedUrl(doc.video_url) ? (
                    <iframe
                      src={resolveYoutubeEmbedUrl(doc.video_url) || undefined}
                      title={doc.title}
                      className="mt-4 aspect-video w-full rounded-xl border border-zinc-200"
                      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                      allowFullScreen
                    />
                  ) : doc.video_url ? (
                    <a href={doc.video_url} target="_blank" rel="noreferrer" className="mt-3 inline-flex font-semibold text-zinc-900">
                      Buka video
                    </a>
                  ) : null}
                  {doc.external_url ? (
                    <a href={doc.external_url} target="_blank" rel="noreferrer" className="mt-3 inline-flex font-semibold text-zinc-900">
                      Buka link
                    </a>
                  ) : null}
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
