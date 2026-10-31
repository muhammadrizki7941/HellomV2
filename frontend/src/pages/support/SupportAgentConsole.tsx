import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, BookOpen, Check, Clock3, Headphones, Loader2, MessageSquare, Plus, Search, Send, Settings2, ShieldCheck, UserPlus } from 'lucide-react';
import { getSessionUser } from '@/lib/hellomApi';
import {
  activateSupportAgent,
  assignSupportTicket,
  deleteSupportArticle,
  deactivateSupportAgent,
  getAgentSupportTicket,
  getAgentSupportTickets,
  getSupportAdminOverview,
  getSupportArticles,
  getSupportCannedResponses,
  getSupportStats,
  getSupportAttachment,
  importSupportArticle,
  saveSupportArticle,
  saveSupportCategory,
  saveSupportCannedResponse,
  sendAgentSupportMessage,
  takeSupportTicket,
  updateAgentSupportTicket,
  type SupportAdminOverview,
  type SupportArticle,
  type SupportCannedResponse,
  type SupportCategory,
  type SupportMessage,
  type SupportPriority,
  type SupportStatus,
  type SupportTicket,
} from '@/services/api/support';

const STATUS: SupportStatus[] = ['new', 'ai_replied', 'waiting_agent', 'in_progress', 'waiting_user', 'resolved', 'closed'];
const PRIORITIES: SupportPriority[] = ['low', 'normal', 'high', 'urgent'];
const statusLabels: Record<SupportStatus, string> = { new: 'Baru', ai_replied: 'Dibalas AI', waiting_agent: 'Menunggu Agent', in_progress: 'Sedang Diproses', waiting_user: 'Menunggu Pengguna', resolved: 'Selesai', closed: 'Ditutup' };
const priorityLabels: Record<SupportPriority, string> = { low: 'Rendah', normal: 'Normal', high: 'Tinggi', urgent: 'Mendesak' };

function dateLabel(value?: string | null) {
  return value ? new Date(value).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

export default function SupportAgentConsole() {
  const isSuperAdmin = getSessionUser<{ role?: string }>()?.role === 'super_admin';
  const [tab, setTab] = useState<'queue' | 'knowledge' | 'settings' | 'stats'>('queue');
  const [tickets, setTickets] = useState<SupportTicket[]>([]);
  const [ticket, setTicket] = useState<SupportTicket | null>(null);
  const [overview, setOverview] = useState<SupportAdminOverview | null>(null);
  const [articles, setArticles] = useState<SupportArticle[]>([]);
  const [stats, setStats] = useState<Record<string, number | Record<string, number>>>({});
  const [canned, setCanned] = useState<SupportCannedResponse[]>([]);
  const [statusFilter, setStatusFilter] = useState('');
  const [priorityFilter, setPriorityFilter] = useState('');
  const [query, setQuery] = useState('');
  const [queuePage, setQueuePage] = useState(1);
  const [queuePageCount, setQueuePageCount] = useState(1);
  const [reply, setReply] = useState('');
  const [internal, setInternal] = useState(false);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [articleDraft, setArticleDraft] = useState<(Omit<Partial<SupportArticle>, 'tags'> & { tags?: string[] | string | null }) | null>(null);
  const [categoryDraft, setCategoryDraft] = useState<SupportCategory | null>(null);
  const [cannedDraft, setCannedDraft] = useState<SupportCannedResponse | null>(null);
  const [importText, setImportText] = useState('');
  const [importNotice, setImportNotice] = useState('');

  const loadQueue = useCallback(async () => {
    const [page, cannedResponses] = await Promise.all([
      getAgentSupportTickets({ page: queuePage, status: statusFilter, priority: priorityFilter, q: query }),
      getSupportCannedResponses(),
    ]);
    setTickets(page.data);
    setQueuePageCount(page.last_page);
    setCanned(cannedResponses);
  }, [priorityFilter, query, queuePage, statusFilter]);

  const loadSelected = useCallback(async (number: string) => {
    setTicket(await getAgentSupportTicket(number));
  }, []);

  const loadAdminData = useCallback(async () => {
    if (!isSuperAdmin) return;
    const [adminData, articlePage, dashboardStats] = await Promise.all([
      getSupportAdminOverview(),
      getSupportArticles(),
      getSupportStats(),
    ]);
    setOverview(adminData);
    setArticles(articlePage.data);
    setStats(dashboardStats);
  }, [isSuperAdmin]);

  const refresh = async (number?: string) => {
    await loadQueue();
    if (number) await loadSelected(number);
  };

  useEffect(() => {
    let active = true;
    setLoading(true);
    Promise.all([loadQueue(), loadAdminData()])
      .then(() => { if (active) setError(''); })
      .catch(e => { if (active) setError(e instanceof Error ? e.message : 'Gagal membuka antrean bantuan.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [loadAdminData, loadQueue]);

  const activeTicketNumber = ticket?.ticket_number;
  useEffect(() => {
    const timer = window.setInterval(() => {
      void loadQueue().catch(e => setError(e instanceof Error ? e.message : 'Antrean gagal diperbarui.'));
      if (activeTicketNumber) {
        void loadSelected(activeTicketNumber).catch(e => setError(e instanceof Error ? e.message : 'Percakapan gagal diperbarui.'));
      }
    }, 15000);
    return () => window.clearInterval(timer);
  }, [activeTicketNumber, loadQueue, loadSelected]);

  const withBusy = async (action: () => Promise<unknown>) => {
    setBusy(true);
    setError('');
    try { await action(); }
    catch (e) { setError(e instanceof Error ? e.message : 'Tindakan gagal diproses.'); }
    finally { setBusy(false); }
  };

  const chooseTicket = async (number: string) => {
    setError('');
    try { await loadSelected(number); }
    catch (e) { setError(e instanceof Error ? e.message : 'Tiket gagal dimuat.'); }
  };

  const submitReply = async (event: FormEvent) => {
    event.preventDefault();
    if (!ticket || !reply.trim()) return;
    await withBusy(async () => {
      await sendAgentSupportMessage(ticket.ticket_number, reply.trim(), internal);
      setReply('');
      setInternal(false);
      await refresh(ticket.ticket_number);
    });
  };

  const changeStatus = async (status: SupportStatus) => {
    if (!ticket) return;
    await withBusy(async () => {
      await updateAgentSupportTicket(ticket.ticket_number, { status });
      await refresh(ticket.ticket_number);
    });
  };

  const saveArticle = async (event: FormEvent) => {
    event.preventDefault();
    if (!articleDraft?.title || !articleDraft.category || !articleDraft.content_markdown) return;
    await withBusy(async () => {
      await saveSupportArticle({
        title: articleDraft.title!,
        category: articleDraft.category!,
        content_markdown: articleDraft.content_markdown!,
        status: articleDraft.status || 'draft',
        slug: articleDraft.slug,
        route_path: articleDraft.route_path,
        tags: typeof articleDraft.tags === 'string' ? articleDraft.tags.split(',').map(tag => tag.trim()).filter(Boolean) : articleDraft.tags,
      }, articleDraft.id);
      setArticleDraft(null);
      await loadAdminData();
    });
  };

  const importMarkdown = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const title = String(data.get('title') || '').trim();
    const category = String(data.get('category') || 'umum').trim();
    const routePath = String(data.get('route_path') || '').trim();
    let parsedArticles: ReturnType<typeof parseKnowledgeImport>;
    try {
      parsedArticles = parseKnowledgeImport(importText);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Format artikel tidak valid.');
      return;
    }
    const documents = parsedArticles.length > 0
      ? parsedArticles
      : title && importText.length >= 20
        ? [{ title, category, content: importText, ...(routePath ? { route_path: routePath } : {}) }]
        : [];
    if (documents.length === 0) {
      setError('Isi teks minimal 20 karakter. Jika dokumen berisi banyak artikel, gunakan format ## ARTIKEL 1 dengan metadata Judul, Kategori, Rute terkait, dan Isi.');
      return;
    }
    if (documents.length > 50 || documents.some(article => article.content.length < 20)) {
      setError('Dokumen harus berisi 1–50 artikel, masing-masing dengan isi minimal 20 karakter.');
      return;
    }
    const flaggedCount = documents.filter(article => /\[PERIKSA(?:\s*:|\])/i.test(article.content)).length;
    await withBusy(async () => {
      await importSupportArticle({ articles: documents });
      setImportText('');
      form.reset();
      await loadAdminData();
      setImportNotice(`${documents.length} artikel berhasil diimpor sebagai draft.${flaggedCount ? ` ${flaggedCount} artikel masih berisi tanda [PERIKSA] dan perlu ditinjau sebelum diterbitkan.` : ' Tinjau dahulu, lalu terbitkan agar bisa dipakai AI.'}`);
    });
  };

  const saveCategoryForm = async (event: FormEvent) => {
    event.preventDefault();
    if (!categoryDraft) return;
    await withBusy(async () => {
      await saveSupportCategory(categoryDraft, categoryDraft.id || undefined);
      setCategoryDraft(null);
      await loadAdminData();
    });
  };

  const saveCannedForm = async (event: FormEvent) => {
    event.preventDefault();
    if (!cannedDraft) return;
    await withBusy(async () => {
      await saveSupportCannedResponse(cannedDraft, cannedDraft.id || undefined);
      setCannedDraft(null);
      await Promise.all([loadAdminData(), loadQueue()]);
    });
  };

  const queueTitle = statusFilter ? statusLabels[statusFilter as SupportStatus] : 'Semua tiket';
  const statValue = (key: string): string | number => {
    const value = stats[key];
    return typeof value === 'number' || typeof value === 'string' ? value : '—';
  };
  const statCards: Array<{ label: string; value: string | number }> = [
    { label: 'Tiket aktif', value: statValue('open') },
    { label: 'Menunggu agent', value: statValue('waiting_agent') },
    { label: 'Melewati SLA', value: statValue('overdue') },
    { label: 'Selesai 30 hari', value: statValue('resolved_30d') },
    { label: 'Rating rata-rata', value: statValue('average_rating') },
  ];
  const downloadAttachment = async (id: number, name: string) => {
    try {
      const blob = await getSupportAttachment(id);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = name;
      anchor.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Lampiran tidak dapat diunduh.');
    }
  };

  return (
    <div className="min-h-screen bg-zinc-50 text-zinc-900">
      <header className="sticky top-0 z-20 border-b border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6">
        <div className="mx-auto flex max-w-[1500px] items-center justify-between gap-3">
          <div className="flex items-center gap-3"><span className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-400"><Headphones className="h-5 w-5" /></span><div><h1 className="font-bold">Pusat Bantuan · {isSuperAdmin ? 'Superadmin' : 'Agent'}</h1><p className="text-xs text-zinc-500">Antrean dukungan Hellom</p></div></div>
          <Link to={isSuperAdmin ? '/admin' : '/dashboard'} className="text-sm font-semibold text-zinc-600 hover:underline">{isSuperAdmin ? 'Admin' : 'Dashboard'}</Link>
        </div>
      </header>

      <main className="mx-auto max-w-[1500px] space-y-4 p-4 sm:p-6">
        {error && <div role="alert" className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700"><AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />{error}</div>}
        {!isSuperAdmin && error.includes('403') && <p className="rounded-xl bg-white p-5">Akun ini belum diberi akses agent. Hubungi superadmin.</p>}

        <div className="flex flex-wrap gap-2">
          <TabButton active={tab === 'queue'} onClick={() => setTab('queue')} icon={<MessageSquare className="h-4 w-4" />}>Antrean</TabButton>
          {isSuperAdmin && <>
            <TabButton active={tab === 'knowledge'} onClick={() => setTab('knowledge')} icon={<BookOpen className="h-4 w-4" />}>Knowledge base</TabButton>
            <TabButton active={tab === 'settings'} onClick={() => setTab('settings')} icon={<Settings2 className="h-4 w-4" />}>Agent & kategori</TabButton>
            <TabButton active={tab === 'stats'} onClick={() => setTab('stats')} icon={<Clock3 className="h-4 w-4" />}>Statistik</TabButton>
          </>}
        </div>

        {loading && <div className="flex items-center gap-2 rounded-xl bg-white p-6 text-sm text-zinc-500"><Loader2 className="h-4 w-4 animate-spin" />Memuat data...</div>}

        {tab === 'queue' && !loading && <div className="grid min-h-[70vh] gap-4 xl:grid-cols-[minmax(300px,0.75fr)_minmax(0,1.5fr)]">
          <section className="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
            <div className="space-y-3 border-b border-zinc-100 p-4">
              <div className="flex items-center justify-between"><h2 className="font-bold">{queueTitle}</h2><button onClick={() => void refresh(ticket?.ticket_number)} className="text-xs font-semibold text-amber-800">Muat ulang</button></div>
              <div className="flex gap-2"><label className="relative min-w-0 flex-1"><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" /><input value={query} onChange={e => { setQueuePage(1); setQuery(e.target.value); }} placeholder="Cari tiket..." className="h-10 w-full rounded-lg border border-zinc-200 pl-9 pr-3 text-sm" /></label><select value={statusFilter} onChange={e => { setQueuePage(1); setStatusFilter(e.target.value); }} className="h-10 max-w-36 rounded-lg border border-zinc-200 px-2 text-xs"><option value="">Semua status</option>{STATUS.map(s => <option key={s} value={s}>{statusLabels[s]}</option>)}</select></div>
              <select value={priorityFilter} onChange={e => { setQueuePage(1); setPriorityFilter(e.target.value); }} className="h-9 w-full rounded-lg border border-zinc-200 px-2 text-xs"><option value="">Semua prioritas</option>{PRIORITIES.map(p => <option key={p} value={p}>{priorityLabels[p]}</option>)}</select>
            </div>
            <div className="max-h-[65vh] divide-y divide-zinc-100 overflow-y-auto">
              {tickets.length === 0 && <p className="p-5 text-sm text-zinc-500">Tidak ada tiket sesuai filter.</p>}
              {tickets.map(item => <button key={item.ticket_number} onClick={() => void chooseTicket(item.ticket_number)} className={`w-full p-4 text-left hover:bg-amber-50/60 ${ticket?.ticket_number === item.ticket_number ? 'bg-amber-50' : ''}`}>
                <div className="flex items-start justify-between gap-2"><span className="truncate text-sm font-bold">{item.subject}</span><span className="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold">{priorityLabels[item.priority]}</span></div>
                <p className="mt-1 text-xs text-zinc-500">{item.ticket_number} · {item.requester?.name} · {dateLabel(item.last_activity_at)}</p>
                <p className="mt-2 text-xs font-medium text-amber-800">{statusLabels[item.status]} · {item.category}</p>
              </button>)}
            </div>
            <div className="flex items-center justify-between border-t border-zinc-100 px-4 py-3 text-xs text-zinc-500">
              <span>Halaman {queuePage} dari {queuePageCount}</span>
              <div className="flex gap-2"><button disabled={queuePage <= 1} onClick={() => setQueuePage(page => Math.max(1, page - 1))} className="rounded border px-2 py-1 disabled:opacity-40">Sebelumnya</button><button disabled={queuePage >= queuePageCount} onClick={() => setQueuePage(page => Math.min(queuePageCount, page + 1))} className="rounded border px-2 py-1 disabled:opacity-40">Berikutnya</button></div>
            </div>
          </section>

          <section className="flex min-h-[70vh] flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white">
            {!ticket ? <div className="m-auto max-w-sm p-8 text-center text-zinc-500"><Headphones className="mx-auto mb-3 h-8 w-8 text-zinc-300" /><p className="text-sm">Pilih tiket dari antrean untuk melihat percakapan dan menindaklanjuti.</p></div> : <>
              <div className="space-y-3 border-b border-zinc-100 p-4 sm:p-5">
                <div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs font-semibold text-amber-800">{ticket.ticket_number} · {ticket.category}</p><h2 className="mt-1 text-lg font-bold">{ticket.subject}</h2><p className="mt-1 text-xs text-zinc-500">{ticket.requester?.name} · {ticket.requester?.email}{ticket.organization?.name ? ` · ${ticket.organization.name}` : ' · Tanpa organisasi'}</p></div>
                  {ticket.status === 'new' || ticket.status === 'waiting_agent' ? <button disabled={busy} onClick={() => void withBusy(async () => { await takeSupportTicket(ticket.ticket_number); await refresh(ticket.ticket_number); })} className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-zinc-950 px-3 text-sm font-semibold text-white disabled:opacity-50"><Check className="h-4 w-4" />Ambil tiket</button> : null}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <select value={ticket.status} onChange={e => void changeStatus(e.target.value as SupportStatus)} className="h-9 rounded-lg border border-zinc-200 px-2 text-xs">{STATUS.map(s => <option key={s} value={s}>{statusLabels[s]}</option>)}</select>
                  <select value={ticket.priority} onChange={e => void withBusy(async () => { await updateAgentSupportTicket(ticket.ticket_number, { priority: e.target.value as SupportPriority }); await refresh(ticket.ticket_number); })} className="h-9 rounded-lg border border-zinc-200 px-2 text-xs">{PRIORITIES.map(p => <option key={p} value={p}>{priorityLabels[p]}</option>)}</select>
                  {isSuperAdmin && overview?.agents && <select value={String(ticket.assigned_agent_user_id || '')} onChange={e => { if (e.target.value) void withBusy(async () => { await assignSupportTicket(ticket.ticket_number, Number(e.target.value)); await refresh(ticket.ticket_number); }); }} className="h-9 max-w-52 rounded-lg border border-zinc-200 px-2 text-xs"><option value="">Belum ditugaskan</option>{overview.agents.filter(agent => agent.is_active).map(agent => <option key={agent.user_id} value={agent.user_id}>{agent.user.name}</option>)}</select>}
                  <span className="text-[11px] text-zinc-400">Update terakhir {dateLabel(ticket.last_activity_at)}</span>
                </div>
              </div>
              <div className="flex-1 space-y-3 overflow-y-auto p-4 sm:p-5">
                {(ticket.messages || []).map(message => <AgentMessage key={message.id} message={message} onDownload={downloadAttachment} />)}
              </div>
              <form onSubmit={submitReply} className="space-y-3 border-t border-zinc-100 p-4 sm:p-5">
                {!!canned.length && <select defaultValue="" onChange={e => { const selected = canned.find(item => String(item.id) === e.target.value); if (selected) setReply(selected.body); }} className="h-9 w-full rounded-lg border border-zinc-200 px-2 text-xs"><option value="">Gunakan balasan cepat...</option>{canned.map(item => <option key={item.id} value={item.id}>{item.title}</option>)}</select>}
                <textarea required maxLength={10000} rows={3} value={reply} onChange={e => setReply(e.target.value)} placeholder={internal ? 'Catatan ini hanya terlihat agent...' : 'Balas pengguna...'} className={`w-full rounded-xl border p-3 text-sm ${internal ? 'border-amber-300 bg-amber-50' : 'border-zinc-200'}`} />
                <div className="flex flex-wrap items-center justify-between gap-2"><label className="flex items-center gap-2 text-xs text-zinc-600"><input type="checkbox" checked={internal} onChange={e => setInternal(e.target.checked)} />Catatan internal (tidak terlihat pengguna)</label><button disabled={busy || !reply.trim()} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-zinc-950 px-4 text-sm font-semibold text-white disabled:opacity-50">{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}{internal ? 'Simpan catatan' : 'Kirim balasan'}</button></div>
              </form>
            </>}
          </section>
        </div>}

        {tab === 'knowledge' && isSuperAdmin && !loading && <section className="grid gap-4 xl:grid-cols-2">
          <div className="space-y-4">
            <div className="rounded-2xl border border-zinc-200 bg-white p-4 sm:p-5"><div className="mb-4 flex items-center justify-between"><h2 className="font-bold">Artikel knowledge base</h2><button onClick={() => setArticleDraft({ title: '', category: 'umum', content_markdown: '', status: 'draft' })} className="inline-flex min-h-9 items-center gap-1 rounded-lg bg-amber-400 px-3 text-xs font-bold"><Plus className="h-4 w-4" />Artikel</button></div>
              <div className="divide-y divide-zinc-100">{articles.map(article => <div key={article.id} className="flex items-center justify-between gap-2 py-3"><button onClick={() => setArticleDraft(article)} className="min-w-0 text-left"><span className="block truncate text-sm font-semibold">{article.title}</span><span className="text-xs text-zinc-500">{article.category} · v{article.version} · {article.status === 'published' ? 'Terbit' : 'Draft'}</span></button><button onClick={() => void withBusy(async () => { await deleteSupportArticle(article.id); await loadAdminData(); })} className="text-xs text-red-600 hover:underline">Hapus</button></div>)}</div>
            </div>
            <form onSubmit={importMarkdown} className="space-y-3 rounded-2xl border border-zinc-200 bg-white p-4 sm:p-5">
              <h3 className="font-bold">Impor teks / Markdown</h3><p className="text-xs leading-5 text-zinc-500">Pilih file .md/.txt untuk membacanya sebagai teks di browser. Artikel akan masuk sebagai draft dan perlu ditinjau sebelum dipublikasikan.</p>
              <input name="file" type="file" accept=".md,.markdown,.txt,text/markdown,text/plain" onChange={async e => { const file = e.target.files?.[0]; if (file) setImportText(await file.text()); }} className="block w-full text-xs" />
              <input name="title" required placeholder="Judul artikel" className="h-10 w-full rounded-lg border border-zinc-200 px-3 text-sm" />
              <div className="grid gap-2 sm:grid-cols-2"><input name="category" defaultValue="umum" placeholder="Kategori" className="h-10 rounded-lg border border-zinc-200 px-3 text-sm" /><input name="route_path" placeholder="Rute terkait, mis. /dashboard/profile" className="h-10 rounded-lg border border-zinc-200 px-3 text-sm" /></div>
              <textarea required minLength={20} maxLength={100000} rows={7} value={importText} onChange={e => setImportText(e.target.value)} placeholder="Isi .md atau .txt..." className="w-full rounded-lg border border-zinc-200 p-3 font-mono text-xs" />
              <button disabled={busy} className="min-h-10 rounded-lg bg-zinc-950 px-4 text-sm font-semibold text-white disabled:opacity-50">Impor sebagai draft</button>
            </form>
          </div>
          <div className="rounded-2xl border border-zinc-200 bg-white p-4 sm:p-5">
            {articleDraft ? <form onSubmit={saveArticle} className="space-y-3">
              <div className="flex items-center justify-between"><h2 className="font-bold">{articleDraft.id ? 'Edit artikel' : 'Artikel baru'}</h2><button type="button" onClick={() => setArticleDraft(null)} className="text-sm text-zinc-500">Batal</button></div>
              <input required maxLength={180} value={articleDraft.title || ''} onChange={e => setArticleDraft({ ...articleDraft, title: e.target.value })} placeholder="Judul" className="h-10 w-full rounded-lg border border-zinc-200 px-3 text-sm" />
              <div className="grid gap-2 sm:grid-cols-2"><input value={articleDraft.category || ''} onChange={e => setArticleDraft({ ...articleDraft, category: e.target.value })} placeholder="Kategori" className="h-10 rounded-lg border border-zinc-200 px-3 text-sm" /><input value={articleDraft.route_path || ''} onChange={e => setArticleDraft({ ...articleDraft, route_path: e.target.value })} placeholder="Rute terkait (opsional)" className="h-10 rounded-lg border border-zinc-200 px-3 text-sm" /></div>
              <input value={Array.isArray(articleDraft.tags) ? articleDraft.tags.join(', ') : String(articleDraft.tags || '')} onChange={e => setArticleDraft({ ...articleDraft, tags: e.target.value })} placeholder="Tag, pisahkan dengan koma" className="h-10 w-full rounded-lg border border-zinc-200 px-3 text-sm" />
              <textarea required minLength={20} maxLength={100000} rows={16} value={articleDraft.content_markdown || ''} onChange={e => setArticleDraft({ ...articleDraft, content_markdown: e.target.value })} className="w-full rounded-lg border border-zinc-200 p-3 font-mono text-xs" />
              <div className="flex flex-wrap items-center justify-between gap-3"><select value={articleDraft.status || 'draft'} onChange={e => setArticleDraft({ ...articleDraft, status: e.target.value as 'draft' | 'published' })} className="h-10 rounded-lg border border-zinc-200 px-3 text-sm"><option value="draft">Draft</option><option value="published">Terbitkan</option></select><button disabled={busy} className="min-h-10 rounded-lg bg-amber-400 px-4 text-sm font-bold disabled:opacity-50">Simpan artikel</button></div>
            </form> : <div className="flex h-full min-h-64 flex-col items-center justify-center text-center text-zinc-500"><BookOpen className="mb-3 h-8 w-8 text-zinc-300" /><p className="text-sm">Pilih artikel untuk mengedit, atau buat artikel panduan baru.</p></div>}
          </div>
        </section>}

        {tab === 'settings' && isSuperAdmin && !loading && <div className="grid gap-4 xl:grid-cols-2">
          <section className="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 sm:p-5">
            <div className="flex items-center justify-between"><h2 className="font-bold">Agent aktif</h2><UserPlus className="h-5 w-5 text-amber-700" /></div>
            <div className="flex gap-2"><select id="support-agent-user" className="h-10 min-w-0 flex-1 rounded-lg border border-zinc-200 px-2 text-sm"><option value="">Pilih akun Hellom...</option>{overview?.users.map(user => <option key={user.id} value={user.id}>{user.name} · {user.email} ({user.role})</option>)}</select><button onClick={() => { const input = document.getElementById('support-agent-user') as HTMLSelectElement | null; if (input?.value) void withBusy(async () => { await activateSupportAgent(Number(input.value)); await loadAdminData(); }); }} className="min-h-10 rounded-lg bg-zinc-950 px-3 text-xs font-semibold text-white">Aktifkan</button></div>
            <div className="divide-y divide-zinc-100">{overview?.agents.map(agent => <div key={agent.user_id} className="flex items-center justify-between gap-2 py-3"><div><p className="text-sm font-semibold">{agent.user.name}</p><p className="text-xs text-zinc-500">{agent.user.email} · {agent.is_active ? 'Aktif' : 'Nonaktif'}</p></div>{agent.is_active && <button onClick={() => void withBusy(async () => { await deactivateSupportAgent(agent.user_id); await loadAdminData(); })} className="text-xs font-semibold text-red-600 hover:underline">Nonaktifkan</button>}</div>)}</div>
          </section>
          <div className="space-y-4">
            <section className="rounded-2xl border border-zinc-200 bg-white p-4 sm:p-5">
              <div className="mb-3 flex items-center justify-between"><h2 className="font-bold">Kategori & prioritas</h2><button onClick={() => setCategoryDraft({ id: 0, slug: '', name: '', default_priority: 'normal', is_active: true, sort_order: (overview?.categories.length || 0) + 1 })} className="text-xs font-bold text-amber-800">+ Kategori</button></div>
              {categoryDraft && <form onSubmit={saveCategoryForm} className="mb-4 grid gap-2 rounded-xl bg-zinc-50 p-3 sm:grid-cols-2"><input required value={categoryDraft.name} onChange={e => setCategoryDraft({ ...categoryDraft, name: e.target.value })} placeholder="Nama" className="h-9 rounded-lg border px-2 text-sm" /><input required value={categoryDraft.slug} onChange={e => setCategoryDraft({ ...categoryDraft, slug: e.target.value })} placeholder="slug" className="h-9 rounded-lg border px-2 text-sm" /><select value={categoryDraft.default_priority} onChange={e => setCategoryDraft({ ...categoryDraft, default_priority: e.target.value as SupportPriority })} className="h-9 rounded-lg border px-2 text-sm">{PRIORITIES.map(p => <option key={p} value={p}>{priorityLabels[p]}</option>)}</select><div className="flex gap-2"><input type="number" value={categoryDraft.sort_order} onChange={e => setCategoryDraft({ ...categoryDraft, sort_order: Number(e.target.value) })} className="h-9 w-20 rounded-lg border px-2 text-sm" /><label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={categoryDraft.is_active} onChange={e => setCategoryDraft({ ...categoryDraft, is_active: e.target.checked })} />Aktif</label><button className="ml-auto rounded-lg bg-zinc-950 px-3 text-xs text-white">Simpan</button></div></form>}
              <div className="divide-y divide-zinc-100">{overview?.categories.map(item => <button key={item.id} onClick={() => setCategoryDraft(item)} className="flex w-full items-center justify-between py-2 text-left text-sm"><span>{item.name} <span className="text-xs text-zinc-400">({item.slug})</span></span><span className="text-xs text-zinc-500">{priorityLabels[item.default_priority]} · {item.is_active ? 'Aktif' : 'Nonaktif'}</span></button>)}</div>
            </section>
            <section className="rounded-2xl border border-zinc-200 bg-white p-4 sm:p-5">
              <div className="mb-3 flex items-center justify-between"><h2 className="font-bold">Balasan cepat</h2><button onClick={() => setCannedDraft({ id: 0, title: '', category: null, body: '', is_active: true })} className="text-xs font-bold text-amber-800">+ Balasan</button></div>
              {cannedDraft && <form onSubmit={saveCannedForm} className="mb-4 space-y-2 rounded-xl bg-zinc-50 p-3"><input required value={cannedDraft.title} onChange={e => setCannedDraft({ ...cannedDraft, title: e.target.value })} placeholder="Judul balasan" className="h-9 w-full rounded-lg border px-2 text-sm" /><textarea required value={cannedDraft.body} onChange={e => setCannedDraft({ ...cannedDraft, body: e.target.value })} placeholder="Isi balasan" className="w-full rounded-lg border p-2 text-sm" /><label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={cannedDraft.is_active} onChange={e => setCannedDraft({ ...cannedDraft, is_active: e.target.checked })} />Aktif</label><button className="rounded-lg bg-zinc-950 px-3 py-2 text-xs text-white">Simpan balasan</button></form>}
              <div className="divide-y divide-zinc-100">{overview?.canned_responses.map(item => <button key={item.id} onClick={() => setCannedDraft(item)} className="block w-full py-2 text-left"><span className="block text-sm font-semibold">{item.title}</span><span className="block truncate text-xs text-zinc-500">{item.body}</span></button>)}</div>
            </section>
          </div>
        </div>}

        {tab === 'stats' && isSuperAdmin && !loading && <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">{statCards.map(card => <div key={card.label} className="rounded-2xl border border-zinc-200 bg-white p-5"><p className="text-sm text-zinc-500">{card.label}</p><p className="mt-2 text-3xl font-bold">{card.value}</p></div>)}</div>}

        {isSuperAdmin && tab === 'knowledge' && overview?.knowledge.published === 0 && <div role="status" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
          <strong>AI belum memiliki artikel yang dapat dijadikan sumber jawaban.</strong>
          <p className="mt-1">Saat ini {overview.knowledge.drafts} artikel masih berstatus draft. AI hanya menggunakan artikel berstatus Terbit. Buka tiap artikel, periksa isinya, lalu pilih “Terbitkan”.</p>
        </div>}
        {isSuperAdmin && tab === 'knowledge' && !!overview?.knowledge.review_required && <div role="status" className="rounded-xl border border-orange-300 bg-orange-50 p-4 text-sm text-orange-950">
          <strong>{overview.knowledge.review_required} artikel terbit masih memiliki tanda [PERIKSA].</strong>
          <p className="mt-1">Bagian bertanda tersebut dikecualikan dari konteks AI. Lengkapi dan hapus semua tanda sebelum menyimpan artikel sebagai Terbit.</p>
        </div>}
        {isSuperAdmin && importNotice && tab === 'knowledge' && <p role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{importNotice}</p>}
        {isSuperAdmin && !overview?.groq_ready && <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">Groq belum dikonfigurasi. Atur GROQ_API_KEY di backend/.env agar AI dapat menjawab.</p>}
      </main>
    </div>
  );
}

function TabButton({ active, onClick, icon, children }: { active: boolean; onClick: () => void; icon: ReactNode; children: ReactNode }) {
  return <button onClick={onClick} className={`inline-flex min-h-10 items-center gap-2 rounded-xl border px-3 text-sm font-semibold ${active ? 'border-zinc-950 bg-zinc-950 text-white' : 'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-100'}`}>{icon}{children}</button>;
}

function AgentMessage({ message, onDownload }: { message: SupportMessage; onDownload: (id: number, name: string) => void }) {
  const internal = message.is_internal;
  const label = internal ? 'Catatan internal' : message.actor_type === 'ai' ? 'Asisten AI' : message.actor_type === 'agent' ? (message.sender?.name || 'Agent') : message.actor_type === 'system' ? 'Sistem' : message.sender?.name || 'Pengguna';
  return <div className={`rounded-xl p-3 ${internal ? 'border border-amber-200 bg-amber-50' : message.actor_type === 'agent' ? 'bg-indigo-50' : 'bg-zinc-50'}`}>
    <div className="mb-1 flex items-center gap-2 text-xs font-semibold text-zinc-600">{internal ? <ShieldCheck className="h-3.5 w-3.5" /> : null}{label}<span className="font-normal text-zinc-400">· {dateLabel(message.created_at)}</span></div>
    <MessageBody body={message.body} />
    {!!message.attachments?.length && <div className="mt-2 flex flex-wrap gap-2">{message.attachments.map(file => <button key={file.id} onClick={() => onDownload(file.id, file.name)} className="text-xs underline">{file.name}</button>)}</div>}
  </div>;
}

function MessageBody({ body }: { body: string }) {
  const parts = body.split(/(\/(?:dashboard|pos)(?:\/[A-Za-z0-9._?=&-]*)?)/g);
  return <p className="whitespace-pre-wrap break-words text-sm leading-6">{parts.map((part, index) =>
    part.startsWith('/dashboard') || part.startsWith('/pos')
      ? <Link key={index} to={part} className="text-amber-800 underline underline-offset-2">{part}</Link>
      : part
  )}</p>;
}

function parseKnowledgeImport(markdown: string): Array<{ title: string; category: string; content: string; route_path?: string }> {
  const sections = [...markdown.matchAll(/(?:^|\r?\n)##\s+ARTIKEL\s+\d+\s*(?:\r?\n|$)/gi)];
  if (sections.length === 0) return [];
  const parsed = sections.map((section, index) => {
    const start = (section.index || 0) + section[0].length;
    const nextStart = sections[index + 1]?.index ?? markdown.length;
    const body = markdown.slice(start, nextStart).replace(/\r\n/g, '\n');
    const title = body.match(/^\*\*Judul:\*\*\s*(.+)$/mi)?.[1]?.trim();
    const category = body.match(/^\*\*Kategori:\*\*\s*(.+)$/mi)?.[1]?.trim();
    const routePath = body.match(/^\*\*Rute terkait:\*\*\s*(.+)$/mi)?.[1]?.trim();
    const contentMarker = body.match(/^\*\*Isi:\*\*\s*\n/im);
    const content = contentMarker
      ? body.slice((contentMarker.index || 0) + contentMarker[0].length).replace(/\n---\s*$/m, '').trim()
      : null;
    if (!title || !category || !content) {
      throw new Error(`Metadata Judul, Kategori, atau Isi tidak lengkap pada artikel ke-${index + 1}.`);
    }
    return {
      title,
      category,
      content,
      ...(routePath && /^\/(dashboard|pos)(\/[A-Za-z0-9._?=&-]*)?$/.test(routePath) ? { route_path: routePath } : {}),
    };
  });
  if (parsed.some(article => article.content.length < 20)) {
    throw new Error('Setiap artikel harus memiliki isi minimal 20 karakter.');
  }
  return parsed;
}
