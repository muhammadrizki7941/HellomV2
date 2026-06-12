import { useState, useEffect, useCallback } from 'react';
import {
  Plus, X, Save, Trash2, AlertCircle, Film, Image, ExternalLink,
  GripVertical, Eye, EyeOff, Upload, Loader2
} from 'lucide-react';
import {
  getAdminPortfolios, createAdminPortfolio, updateAdminPortfolio, deleteAdminPortfolio,
  getAdminClients, createAdminClient, updateAdminClient, deleteAdminClient,
  uploadShowcaseMedia,
  type ShowcasePortfolio, type ShowcaseClient,
} from '@/lib/hellomApi';

type Tab = 'portfolios' | 'clients';

const INITIAL_PORTFOLIO: Omit<ShowcasePortfolio, 'id' | 'created_at'> = {
  title: '', description: '', video_url: '', thumbnail_url: '', client_name: '', category: '', sort_order: 0, is_published: true,
};

const INITIAL_CLIENT: Omit<ShowcaseClient, 'id' | 'created_at'> = {
  name: '', logo_url: '', website_url: '', sort_order: 0, is_published: true,
};

export default function ShowcaseManagement() {
  const [tab, setTab] = useState<Tab>('portfolios');
  const [portfolios, setPortfolios] = useState<ShowcasePortfolio[]>([]);
  const [clients, setClients] = useState<ShowcaseClient[]>([]);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);

  // Portfolio modal
  const [portfolioModalOpen, setPortfolioModalOpen] = useState(false);
  const [editingPortfolioId, setEditingPortfolioId] = useState<number | null>(null);
  const [portfolioForm, setPortfolioForm] = useState(INITIAL_PORTFOLIO);
  const [uploadingVideo, setUploadingVideo] = useState(false);
  const [uploadingThumb, setUploadingThumb] = useState(false);

  // Client modal
  const [clientModalOpen, setClientModalOpen] = useState(false);
  const [editingClientId, setEditingClientId] = useState<number | null>(null);
  const [clientForm, setClientForm] = useState(INITIAL_CLIENT);
  const [uploadingLogo, setUploadingLogo] = useState(false);

  const loadPortfolios = useCallback(async () => {
    setLoading(true);
    setErrorMessage(null);
    try {
      const res = await getAdminPortfolios();
      setPortfolios(res.items);
    } catch (e) {
      setErrorMessage(e instanceof Error ? e.message : 'Failed to load portfolios');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadClients = useCallback(async () => {
    setLoading(true);
    setErrorMessage(null);
    try {
      const res = await getAdminClients();
      setClients(res.items);
    } catch (e) {
      setErrorMessage(e instanceof Error ? e.message : 'Failed to load clients');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadPortfolios();
    void loadClients();
  }, [loadPortfolios, loadClients]);

  /* ─── File Upload Helper ─── */
  const handleFileUpload = async (
    file: File,
    setUploading: (v: boolean) => void,
    onUrl: (url: string) => void,
  ) => {
    setUploading(true);
    setErrorMessage(null);
    try {
      const res = await uploadShowcaseMedia(file);
      onUrl(res.url);
      setInfoMessage(`File "${file.name}" uploaded.`);
    } catch (e) {
      setErrorMessage(e instanceof Error ? e.message : 'Upload failed');
    } finally {
      setUploading(false);
    }
  };

  /* ─── Portfolio CRUD ─── */
  const openPortfolioModal = (item?: ShowcasePortfolio) => {
    if (item) {
      setEditingPortfolioId(item.id);
      setPortfolioForm({
        title: item.title, description: item.description || '', video_url: item.video_url,
        thumbnail_url: item.thumbnail_url || '', client_name: item.client_name || '',
        category: item.category || '', sort_order: item.sort_order, is_published: item.is_published,
      });
    } else {
      setEditingPortfolioId(null);
      setPortfolioForm(INITIAL_PORTFOLIO);
    }
    setPortfolioModalOpen(true);
  };

  const savePortfolio = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    try {
      if (editingPortfolioId) {
        await updateAdminPortfolio(editingPortfolioId, portfolioForm);
        setInfoMessage('Portfolio updated.');
      } else {
        await createAdminPortfolio(portfolioForm);
        setInfoMessage('Portfolio created.');
      }
      setPortfolioModalOpen(false);
      setEditingPortfolioId(null);
      setPortfolioForm(INITIAL_PORTFOLIO);
      await loadPortfolios();
    } catch (e) {
      setErrorMessage(e instanceof Error ? e.message : 'Failed to save');
    }
  };

  const removePortfolio = async (id: number) => {
    if (!confirm('Delete this portfolio item?')) return;
    setErrorMessage(null);
    try {
      await deleteAdminPortfolio(id);
      setInfoMessage('Portfolio deleted.');
      await loadPortfolios();
    } catch (e) {
      setErrorMessage(e instanceof Error ? e.message : 'Failed to delete');
    }
  };

  /* ─── Client CRUD ─── */
  const openClientModal = (item?: ShowcaseClient) => {
    if (item) {
      setEditingClientId(item.id);
      setClientForm({
        name: item.name, logo_url: item.logo_url, website_url: item.website_url || '',
        sort_order: item.sort_order, is_published: item.is_published,
      });
    } else {
      setEditingClientId(null);
      setClientForm(INITIAL_CLIENT);
    }
    setClientModalOpen(true);
  };

  const saveClient = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    try {
      if (editingClientId) {
        await updateAdminClient(editingClientId, clientForm);
        setInfoMessage('Client updated.');
      } else {
        await createAdminClient(clientForm);
        setInfoMessage('Client created.');
      }
      setClientModalOpen(false);
      setEditingClientId(null);
      setClientForm(INITIAL_CLIENT);
      await loadClients();
    } catch (e) {
      setErrorMessage(e instanceof Error ? e.message : 'Failed to save');
    }
  };

  const removeClient = async (id: number) => {
    if (!confirm('Delete this client?')) return;
    setErrorMessage(null);
    try {
      await deleteAdminClient(id);
      setInfoMessage('Client deleted.');
      await loadClients();
    } catch (e) {
      setErrorMessage(e instanceof Error ? e.message : 'Failed to delete');
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Showcase Management</h1>
          <p className="text-zinc-500">Manage portfolio videos and trusted client logos displayed on the landing page.</p>
        </div>
      </div>

      {/* Banners */}
      {errorMessage && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600 flex items-center gap-2">
          <AlertCircle className="w-4 h-4 flex-shrink-0" /> {errorMessage}
        </div>
      )}
      {infoMessage && (
        <div className="p-3 rounded-lg bg-zinc-50 border border-zinc-200 text-sm text-zinc-700">{infoMessage}</div>
      )}

      {/* Tabs */}
      <div className="flex gap-1 bg-zinc-100 p-1 rounded-lg w-fit">
        <button onClick={() => setTab('portfolios')} className={`px-4 py-2 text-sm font-medium rounded-md transition-colors flex items-center gap-2 ${tab === 'portfolios' ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-500 hover:text-zinc-700'}`}>
          <Film className="w-4 h-4" /> Portfolio Videos ({portfolios.length})
        </button>
        <button onClick={() => setTab('clients')} className={`px-4 py-2 text-sm font-medium rounded-md transition-colors flex items-center gap-2 ${tab === 'clients' ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-500 hover:text-zinc-700'}`}>
          <Image className="w-4 h-4" /> Trusted Clients ({clients.length})
        </button>
      </div>

      {loading && (
        <div className="flex items-center justify-center py-12 text-zinc-400">
          <Loader2 className="w-6 h-6 animate-spin" />
        </div>
      )}

      {/* ─── Portfolios Tab ─── */}
      {tab === 'portfolios' && !loading && (
        <div className="space-y-4">
          <div className="flex justify-end">
            <button onClick={() => openPortfolioModal()} className="px-4 py-2 bg-zinc-900 text-white text-sm font-bold rounded-lg hover:bg-zinc-800 transition-colors flex items-center gap-2">
              <Plus className="w-4 h-4" /> Add Portfolio
            </button>
          </div>

          {portfolios.length === 0 ? (
            <div className="text-center py-16 text-zinc-400">
              <Film className="w-12 h-12 mx-auto mb-3 opacity-50" />
              <p className="font-medium">No portfolio items yet.</p>
              <p className="text-sm">Upload your first portfolio video to showcase on the landing page.</p>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
              {portfolios.map(p => (
                <div key={p.id} className="bg-white rounded-xl border border-zinc-200 shadow-sm overflow-hidden group">
                  {/* Video preview */}
                  <div className="relative aspect-video bg-zinc-100">
                    {p.video_url ? (
                      <video src={p.video_url} poster={p.thumbnail_url || undefined} className="w-full h-full object-cover" muted preload="metadata" />
                    ) : (
                      <div className="flex items-center justify-center h-full text-zinc-400"><Film className="w-8 h-8" /></div>
                    )}
                    <div className="absolute top-2 right-2 flex gap-1">
                      {p.is_published
                        ? <span className="px-2 py-0.5 bg-green-500 text-white text-[10px] font-bold rounded-full flex items-center gap-1"><Eye className="w-3 h-3" /> Live</span>
                        : <span className="px-2 py-0.5 bg-zinc-500 text-white text-[10px] font-bold rounded-full flex items-center gap-1"><EyeOff className="w-3 h-3" /> Draft</span>
                      }
                    </div>
                  </div>
                  <div className="p-4">
                    <h3 className="font-bold text-zinc-900 truncate">{p.title}</h3>
                    {p.client_name && <p className="text-xs text-zinc-500 mt-1">Klien: {p.client_name}</p>}
                    {p.category && <span className="inline-block mt-2 px-2 py-0.5 text-[10px] font-medium bg-yellow-100 text-yellow-700 rounded-full">{p.category}</span>}
                    <div className="flex items-center justify-between mt-3 pt-3 border-t border-zinc-100">
                      <span className="text-xs text-zinc-400 flex items-center gap-1"><GripVertical className="w-3 h-3" /> #{p.sort_order}</span>
                      <div className="flex gap-2">
                        <button onClick={() => openPortfolioModal(p)} className="text-xs text-zinc-500 hover:text-zinc-900 font-medium">Edit</button>
                        <button onClick={() => removePortfolio(p.id)} className="text-xs text-red-500 hover:text-red-700 font-medium">Delete</button>
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ─── Clients Tab ─── */}
      {tab === 'clients' && !loading && (
        <div className="space-y-4">
          <div className="flex justify-end">
            <button onClick={() => openClientModal()} className="px-4 py-2 bg-zinc-900 text-white text-sm font-bold rounded-lg hover:bg-zinc-800 transition-colors flex items-center gap-2">
              <Plus className="w-4 h-4" /> Add Client
            </button>
          </div>

          {clients.length === 0 ? (
            <div className="text-center py-16 text-zinc-400">
              <Image className="w-12 h-12 mx-auto mb-3 opacity-50" />
              <p className="font-medium">No trusted clients yet.</p>
              <p className="text-sm">Add client logos to display on the landing page.</p>
            </div>
          ) : (
            <div className="bg-white rounded-xl border border-zinc-200 shadow-sm overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="bg-zinc-50 border-b border-zinc-200">
                    <tr>
                      <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">Logo</th>
                      <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">Name</th>
                      <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">Website</th>
                      <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">Order</th>
                      <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">Status</th>
                      <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-100">
                    {clients.map(c => (
                      <tr key={c.id} className="hover:bg-zinc-50 transition-colors">
                        <td className="px-6 py-4">
                          {c.logo_url
                            ? <img src={c.logo_url} alt={c.name} className="h-10 w-auto object-contain rounded" />
                            : <div className="w-10 h-10 bg-zinc-200 rounded flex items-center justify-center text-zinc-500 font-bold">{c.name.charAt(0)}</div>
                          }
                        </td>
                        <td className="px-6 py-4 font-medium text-zinc-900">{c.name}</td>
                        <td className="px-6 py-4">
                          {c.website_url ? (
                            <a href={c.website_url} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline flex items-center gap-1 text-xs">
                              <ExternalLink className="w-3 h-3" /> {c.website_url.replace(/^https?:\/\//, '').slice(0, 30)}
                            </a>
                          ) : <span className="text-zinc-400">-</span>}
                        </td>
                        <td className="px-6 py-4 text-zinc-500">{c.sort_order}</td>
                        <td className="px-6 py-4">
                          {c.is_published
                            ? <span className="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-medium rounded-full">Published</span>
                            : <span className="px-2 py-0.5 bg-zinc-100 text-zinc-500 text-xs font-medium rounded-full">Draft</span>
                          }
                        </td>
                        <td className="px-6 py-4 text-right">
                          <div className="flex justify-end gap-2">
                            <button onClick={() => openClientModal(c)} className="text-xs text-zinc-500 hover:text-zinc-900 font-medium">Edit</button>
                            <button onClick={() => removeClient(c.id)} className="text-xs text-red-500 hover:text-red-700 font-medium">Delete</button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}

      {/* ─── Portfolio Modal ─── */}
      {portfolioModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="px-6 py-4 border-b border-zinc-200 flex items-center justify-between sticky top-0 bg-white z-10">
              <h2 className="text-xl font-bold text-zinc-900">{editingPortfolioId ? 'Edit Portfolio' : 'Add Portfolio'}</h2>
              <button onClick={() => { setPortfolioModalOpen(false); setEditingPortfolioId(null); setPortfolioForm(INITIAL_PORTFOLIO); }} className="p-2 hover:bg-zinc-100 rounded-lg text-zinc-500">
                <X className="w-5 h-5" />
              </button>
            </div>
            <form onSubmit={savePortfolio} className="p-6 space-y-5">
              {/* Title */}
              <div>
                <label className="block text-sm font-medium text-zinc-700 mb-1">Title *</label>
                <input type="text" required value={portfolioForm.title} onChange={e => setPortfolioForm(f => ({ ...f, title: e.target.value }))}
                  className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none" />
              </div>

              {/* Description */}
              <div>
                <label className="block text-sm font-medium text-zinc-700 mb-1">Description</label>
                <textarea rows={3} value={portfolioForm.description} onChange={e => setPortfolioForm(f => ({ ...f, description: e.target.value }))}
                  className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none" />
              </div>

              {/* Video Upload */}
              <div>
                <label className="block text-sm font-medium text-zinc-700 mb-1">Video *</label>
                {portfolioForm.video_url && (
                  <video src={portfolioForm.video_url} controls className="w-full rounded-lg mb-2 max-h-48 bg-black" />
                )}
                <label className="flex items-center gap-2 px-4 py-2 border border-dashed border-zinc-300 rounded-lg cursor-pointer hover:border-zinc-400 transition-colors text-sm text-zinc-600">
                  {uploadingVideo ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
                  {uploadingVideo ? 'Uploading...' : 'Upload Video (mp4, webm, mov — max 50MB)'}
                  <input type="file" accept="video/mp4,video/webm,video/quicktime" className="hidden" disabled={uploadingVideo}
                    onChange={e => { const f = e.target.files?.[0]; if (f) handleFileUpload(f, setUploadingVideo, url => setPortfolioForm(prev => ({ ...prev, video_url: url }))); }} />
                </label>
                {!portfolioForm.video_url && (
                  <input type="url" placeholder="Or paste video URL" value={portfolioForm.video_url} onChange={e => setPortfolioForm(f => ({ ...f, video_url: e.target.value }))}
                    className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm mt-2 focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none" />
                )}
              </div>

              {/* Thumbnail Upload */}
              <div>
                <label className="block text-sm font-medium text-zinc-700 mb-1">Thumbnail</label>
                {portfolioForm.thumbnail_url && (
                  <img src={portfolioForm.thumbnail_url} alt="Thumbnail" className="w-32 h-20 object-cover rounded-lg mb-2 border" />
                )}
                <label className="flex items-center gap-2 px-4 py-2 border border-dashed border-zinc-300 rounded-lg cursor-pointer hover:border-zinc-400 transition-colors text-sm text-zinc-600">
                  {uploadingThumb ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
                  {uploadingThumb ? 'Uploading...' : 'Upload Thumbnail'}
                  <input type="file" accept="image/*" className="hidden" disabled={uploadingThumb}
                    onChange={e => { const f = e.target.files?.[0]; if (f) handleFileUpload(f, setUploadingThumb, url => setPortfolioForm(prev => ({ ...prev, thumbnail_url: url }))); }} />
                </label>
              </div>

              {/* Client name + Category */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-zinc-700 mb-1">Client Name</label>
                  <input type="text" value={portfolioForm.client_name} onChange={e => setPortfolioForm(f => ({ ...f, client_name: e.target.value }))}
                    className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-zinc-700 mb-1">Category</label>
                  <input type="text" placeholder="e.g. Landing Page, POS, E-Commerce" value={portfolioForm.category} onChange={e => setPortfolioForm(f => ({ ...f, category: e.target.value }))}
                    className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none" />
                </div>
              </div>

              {/* Sort + Publish */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-zinc-700 mb-1">Sort Order</label>
                  <input type="number" value={portfolioForm.sort_order} onChange={e => setPortfolioForm(f => ({ ...f, sort_order: parseInt(e.target.value) || 0 }))}
                    className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none" />
                </div>
                <div className="flex items-end pb-1">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" checked={portfolioForm.is_published} onChange={e => setPortfolioForm(f => ({ ...f, is_published: e.target.checked }))}
                      className="w-4 h-4 rounded border-zinc-300 text-yellow-500 focus:ring-yellow-400" />
                    <span className="text-sm font-medium text-zinc-700">Published</span>
                  </label>
                </div>
              </div>

              {/* Actions */}
              <div className="pt-4 border-t border-zinc-100 flex justify-end gap-3">
                <button type="button" onClick={() => { setPortfolioModalOpen(false); setEditingPortfolioId(null); setPortfolioForm(INITIAL_PORTFOLIO); }}
                  className="px-4 py-2 text-sm font-medium text-zinc-500 hover:bg-zinc-100 rounded-lg transition-colors">Cancel</button>
                <button type="submit" className="px-6 py-2 bg-zinc-900 text-white text-sm font-bold rounded-lg hover:bg-zinc-800 transition-colors flex items-center gap-2">
                  <Save className="w-4 h-4" /> {editingPortfolioId ? 'Save Changes' : 'Create'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ─── Client Modal ─── */}
      {clientModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div className="px-6 py-4 border-b border-zinc-200 flex items-center justify-between sticky top-0 bg-white z-10">
              <h2 className="text-xl font-bold text-zinc-900">{editingClientId ? 'Edit Client' : 'Add Client'}</h2>
              <button onClick={() => { setClientModalOpen(false); setEditingClientId(null); setClientForm(INITIAL_CLIENT); }} className="p-2 hover:bg-zinc-100 rounded-lg text-zinc-500">
                <X className="w-5 h-5" />
              </button>
            </div>
            <form onSubmit={saveClient} className="p-6 space-y-5">
              {/* Name */}
              <div>
                <label className="block text-sm font-medium text-zinc-700 mb-1">Company Name *</label>
                <input type="text" required value={clientForm.name} onChange={e => setClientForm(f => ({ ...f, name: e.target.value }))}
                  className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none" />
              </div>

              {/* Logo Upload */}
              <div>
                <label className="block text-sm font-medium text-zinc-700 mb-1">Logo *</label>
                {clientForm.logo_url && (
                  <img src={clientForm.logo_url} alt="Logo" className="h-12 w-auto object-contain rounded border p-1 mb-2" />
                )}
                <label className="flex items-center gap-2 px-4 py-2 border border-dashed border-zinc-300 rounded-lg cursor-pointer hover:border-zinc-400 transition-colors text-sm text-zinc-600">
                  {uploadingLogo ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
                  {uploadingLogo ? 'Uploading...' : 'Upload Logo (jpg, png, svg, webp)'}
                  <input type="file" accept="image/*" className="hidden" disabled={uploadingLogo}
                    onChange={e => { const f = e.target.files?.[0]; if (f) handleFileUpload(f, setUploadingLogo, url => setClientForm(prev => ({ ...prev, logo_url: url }))); }} />
                </label>
              </div>

              {/* Website */}
              <div>
                <label className="block text-sm font-medium text-zinc-700 mb-1">Website URL</label>
                <input type="url" placeholder="https://example.com" value={clientForm.website_url} onChange={e => setClientForm(f => ({ ...f, website_url: e.target.value }))}
                  className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none" />
              </div>

              {/* Sort + Publish */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-zinc-700 mb-1">Sort Order</label>
                  <input type="number" value={clientForm.sort_order} onChange={e => setClientForm(f => ({ ...f, sort_order: parseInt(e.target.value) || 0 }))}
                    className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none" />
                </div>
                <div className="flex items-end pb-1">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" checked={clientForm.is_published} onChange={e => setClientForm(f => ({ ...f, is_published: e.target.checked }))}
                      className="w-4 h-4 rounded border-zinc-300 text-yellow-500 focus:ring-yellow-400" />
                    <span className="text-sm font-medium text-zinc-700">Published</span>
                  </label>
                </div>
              </div>

              {/* Actions */}
              <div className="pt-4 border-t border-zinc-100 flex justify-end gap-3">
                <button type="button" onClick={() => { setClientModalOpen(false); setEditingClientId(null); setClientForm(INITIAL_CLIENT); }}
                  className="px-4 py-2 text-sm font-medium text-zinc-500 hover:bg-zinc-100 rounded-lg transition-colors">Cancel</button>
                <button type="submit" className="px-6 py-2 bg-zinc-900 text-white text-sm font-bold rounded-lg hover:bg-zinc-800 transition-colors flex items-center gap-2">
                  <Save className="w-4 h-4" /> {editingClientId ? 'Save Changes' : 'Create'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
