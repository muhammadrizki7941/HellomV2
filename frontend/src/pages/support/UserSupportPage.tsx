import { FormEvent, useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Bot, FileImage, Headphones, Loader2, MessageCircle, Plus, Send, ShieldAlert, Star, Ticket, Upload, X } from 'lucide-react';
import {
  closeSupportTicket,
  createSupportTicket,
  escalateSupportTicket,
  getSupportAttachment,
  getSupportCategories,
  getSupportTicket,
  getSupportTickets,
  reopenSupportTicket,
  sendSupportAssistantMessage,
  sendSupportFeedback,
  sendSupportMessage,
  type SupportCategory,
  type SupportMessage,
  type SupportStatus,
  type SupportTicket,
} from '@/services/api/support';

const statusNames: Record<SupportStatus, string> = {
  new: 'Baru',
  ai_replied: 'Dibalas AI',
  waiting_agent: 'Menunggu Agent',
  in_progress: 'Sedang Diproses',
  waiting_user: 'Menunggu Anda',
  resolved: 'Selesai',
  closed: 'Ditutup',
};

const statusTone: Record<SupportStatus, string> = {
  new: 'bg-blue-50 text-blue-700',
  ai_replied: 'bg-violet-50 text-violet-700',
  waiting_agent: 'bg-amber-50 text-amber-800',
  in_progress: 'bg-indigo-50 text-indigo-700',
  waiting_user: 'bg-orange-50 text-orange-700',
  resolved: 'bg-emerald-50 text-emerald-700',
  closed: 'bg-zinc-100 text-zinc-600',
};

function dateLabel(value: string | null | undefined) {
  if (!value) return '';
  return new Date(value).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
}

export default function UserSupportPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const selectedNumber = searchParams.get('ticket');
  const [tickets, setTickets] = useState<SupportTicket[]>([]);
  const [ticketPage, setTicketPage] = useState(1);
  const [ticketPageCount, setTicketPageCount] = useState(1);
  const [categories, setCategories] = useState<SupportCategory[]>([]);
  const [ticket, setTicket] = useState<SupportTicket | null>(null);
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [view, setView] = useState<'list' | 'ai' | 'new'>('list');
  const [message, setMessage] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState('');
  const [rating, setRating] = useState(0);
  const [feedback, setFeedback] = useState('');

  const loadTickets = useCallback(async (pageNumber = ticketPage) => {
    const [page, categoryList] = await Promise.all([getSupportTickets(pageNumber), getSupportCategories()]);
    setTickets(page.data);
    setTicketPageCount(page.last_page);
    setCategories(categoryList);
    setCategory(current => current || categoryList[0]?.slug || 'lainnya');
  }, [ticketPage]);

  const loadTicket = useCallback(async (number: string) => {
    const result = await getSupportTicket(number);
    setTicket(result);
    setRating(result.rating || 0);
  }, []);

  useEffect(() => {
    let active = true;
    setLoading(true);
    Promise.all([loadTickets(), selectedNumber ? loadTicket(selectedNumber) : Promise.resolve(null)])
      .then(() => { if (active) setError(''); })
      .catch(e => { if (active) setError(e instanceof Error ? e.message : 'Gagal memuat Pusat Bantuan.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [loadTicket, loadTickets, selectedNumber]);

  useEffect(() => {
    if (!selectedNumber) return;
    const timer = window.setInterval(() => {
      void Promise.all([loadTickets(), loadTicket(selectedNumber)]).catch(e => {
        setError(e instanceof Error ? e.message : 'Percakapan gagal diperbarui.');
      });
    }, 15000);
    return () => window.clearInterval(timer);
  }, [loadTicket, loadTickets, selectedNumber]);

  const openTicket = (number: string) => {
    setView('list');
    setSearchParams({ ticket: number });
  };
  const backToList = () => {
    setTicket(null);
    setView('list');
    setSearchParams({});
  };
  const refresh = async (number?: string, pageNumber?: number) => {
    await loadTickets(pageNumber);
    if (number) await loadTicket(number);
  };

  const sendAi = async (event: FormEvent) => {
    event.preventDefault();
    if (message.trim().length < 2 || sending) return;
    setError('');
    setSending(true);
    try {
      const result = await sendSupportAssistantMessage(message.trim(), ticket?.ticket_number);
      setMessage('');
      setView('list');
      setSearchParams({ ticket: result.ticket.ticket_number });
      setTicket(await getSupportTicket(result.ticket.ticket_number));
      await loadTickets(1);
      setTicketPage(1);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Pesan AI gagal dikirim.');
    } finally {
      setSending(false);
    }
  };

  const sendManualTicket = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (sending) return;
    setError('');
    setSending(true);
    try {
      const formData = new FormData();
      formData.set('subject', subject);
      formData.set('category', category);
      formData.set('message', message);
      files.forEach(file => formData.append('files[]', file));
      const result = await createSupportTicket(formData);
      setMessage('');
      setFiles([]);
      setSubject('');
      setView('list');
      await refresh(result.ticket_number, 1);
      setTicketPage(1);
      setSearchParams({ ticket: result.ticket_number });
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Tiket gagal dibuat.');
    } finally {
      setSending(false);
    }
  };

  const sendReply = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!ticket || !message.trim() || sending) return;
    setSending(true);
    setError('');
    try {
      if (ticket.source === 'ai' && ['new', 'ai_replied'].includes(ticket.status)) {
        await sendSupportAssistantMessage(message.trim(), ticket.ticket_number);
      } else {
        const formData = new FormData();
        formData.set('body', message.trim());
        files.forEach(file => formData.append('files[]', file));
        await sendSupportMessage(ticket.ticket_number, formData);
      }
      setMessage('');
      setFiles([]);
      await refresh(ticket.ticket_number);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Pesan gagal dikirim.');
    } finally {
      setSending(false);
    }
  };

  const chooseFiles = (selected: FileList | null) => {
    if (!selected) return;
    setFiles(Array.from(selected).slice(0, 3));
  };

  const downloadAttachment = async (id: number, name: string) => {
    try {
      const blob = await getSupportAttachment(id);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = name;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Lampiran tidak dapat diunduh.');
    }
  };

  const showList = view === 'list' && !ticket;
  const aiActive = ticket?.source === 'ai' && ['new', 'ai_replied'].includes(ticket.status);

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Pusat Bantuan</h1>
          <p className="mt-1 text-sm text-zinc-500">Tanya AI, atau hubungi tim Hellom melalui tiket yang bisa Anda lacak.</p>
        </div>
        <Link to="/dashboard" className="text-sm font-semibold text-amber-800 hover:underline">Kembali ke dashboard</Link>
      </header>

      <div className="flex flex-wrap gap-2">
        <button onClick={() => { setTicket(null); setView('ai'); setSearchParams({}); setMessage(''); }} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-zinc-950 px-4 text-sm font-semibold text-white hover:bg-zinc-800">
          <Bot className="h-4 w-4" />Tanya AI
        </button>
        <button onClick={() => { setTicket(null); setView('new'); setSearchParams({}); setMessage(''); }} className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">
          <Plus className="h-4 w-4" />Buat tiket
        </button>
      </div>

      {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {loading && <div role="status" className="flex items-center gap-2 rounded-xl bg-white p-5 text-sm text-zinc-500"><Loader2 className="h-4 w-4 animate-spin" />Memuat Pusat Bantuan...</div>}

      {showList && !loading && (
        <section className="grid gap-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(260px,0.7fr)]">
          <div className="rounded-2xl border border-zinc-200 bg-white p-4 sm:p-6">
            <div className="mb-4 flex items-center gap-2"><Ticket className="h-5 w-5 text-amber-700" /><h2 className="font-bold text-zinc-900">Riwayat tiket</h2></div>
            {tickets.length === 0 ? <p className="rounded-xl bg-zinc-50 p-5 text-sm text-zinc-500">Belum ada tiket. Mulai dari Tanya AI atau buat tiket baru.</p> : <>
              <div className="divide-y divide-zinc-100">
                {tickets.map(item => <button key={item.ticket_number} onClick={() => openTicket(item.ticket_number)} className="flex w-full items-start justify-between gap-3 py-4 text-left hover:bg-zinc-50">
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold text-zinc-900">{item.subject}</span>
                    <span className="mt-1 block text-xs text-zinc-500">{item.ticket_number} · {dateLabel(item.last_activity_at)}</span>
                    {item.last_message && <span className="mt-2 block truncate text-xs text-zinc-500">{item.last_message.body}</span>}
                  </span>
                  <span className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold ${statusTone[item.status]}`}>{statusNames[item.status]}</span>
                </button>)}
              </div>
              <div className="flex items-center justify-between pt-3 text-xs text-zinc-500">
                <span>Halaman {ticketPage} dari {ticketPageCount}</span>
                <div className="flex gap-2"><button disabled={ticketPage <= 1} onClick={() => setTicketPage(page => Math.max(1, page - 1))} className="rounded border px-2 py-1 disabled:opacity-40">Sebelumnya</button><button disabled={ticketPage >= ticketPageCount} onClick={() => setTicketPage(page => Math.min(ticketPageCount, page + 1))} className="rounded border px-2 py-1 disabled:opacity-40">Berikutnya</button></div>
              </div>
            </>}
          </div>
          <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <div className="flex items-start gap-3"><Bot className="mt-0.5 h-5 w-5 shrink-0 text-amber-800" /><div>
              <h2 className="font-bold text-zinc-900">Asisten AI Hellom</h2>
              <p className="mt-2 text-sm leading-6 text-zinc-700">Tanyakan cara memakai fitur Hellom. Jika pertanyaan perlu ditangani tim, percakapan akan diteruskan menjadi tiket.</p>
              <p className="mt-3 text-xs text-amber-900">Jangan masukkan password, OTP, atau data pribadi pelanggan.</p>
              <button onClick={() => { setTicket(null); setView('ai'); setSearchParams({}); }} className="mt-4 inline-flex min-h-10 items-center gap-2 rounded-lg bg-amber-400 px-3 text-sm font-bold text-zinc-950 hover:bg-amber-500"><MessageCircle className="h-4 w-4" />Mulai percakapan</button>
            </div></div>
          </div>
        </section>
      )}

      {view === 'new' && !ticket && (
        <form onSubmit={sendManualTicket} className="max-w-3xl space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 sm:p-6">
          <div className="flex items-center justify-between"><h2 className="text-lg font-bold">Buat tiket bantuan</h2><button type="button" onClick={backToList} aria-label="Tutup form" className="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100"><X className="h-4 w-4" /></button></div>
          <label className="block text-sm font-medium text-zinc-700">Subjek<input required minLength={3} maxLength={180} value={subject} onChange={e => setSubject(e.target.value)} className="mt-2 min-h-11 w-full rounded-xl border border-zinc-200 px-3" /></label>
          <label className="block text-sm font-medium text-zinc-700">Kategori<select value={category} onChange={e => setCategory(e.target.value)} className="mt-2 min-h-11 w-full rounded-xl border border-zinc-200 bg-white px-3">{categories.map(item => <option key={item.slug} value={item.slug}>{item.name}</option>)}</select></label>
          <label className="block text-sm font-medium text-zinc-700">Jelaskan masalah<textarea required minLength={2} maxLength={10000} rows={5} value={message} onChange={e => setMessage(e.target.value)} className="mt-2 w-full rounded-xl border border-zinc-200 p-3" /></label>
          <FilePicker files={files} onChange={chooseFiles} onRemove={index => setFiles(items => items.filter((_, i) => i !== index))} />
          <button disabled={sending} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-zinc-950 px-4 text-sm font-semibold text-white disabled:opacity-60">{sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}Kirim tiket</button>
        </form>
      )}

      {view === 'ai' && !ticket && (
        <section className="max-w-3xl rounded-2xl border border-zinc-200 bg-white p-4 sm:p-6">
          <div className="mb-4 flex items-center justify-between"><div className="flex items-center gap-2"><Bot className="h-5 w-5 text-amber-700" /><h2 className="font-bold">Asisten AI Hellom</h2></div><button onClick={backToList} className="text-sm text-zinc-500 hover:underline">Tutup</button></div>
          <p className="mb-4 rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-900">AI hanya membantu panduan Hellom. Pertanyaan akun, bug, data hilang, pembayaran, dan langganan langsung diteruskan ke agent. Hindari mengirim data pribadi pelanggan.</p>
          <form onSubmit={sendAi} className="flex flex-col gap-2 sm:flex-row"><textarea required minLength={2} maxLength={3000} rows={3} value={message} onChange={e => setMessage(e.target.value)} placeholder="Contoh: Bagaimana cara mengatur menu di POS?" className="min-h-24 flex-1 rounded-xl border border-zinc-200 p-3 text-sm" /><button disabled={sending} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-amber-400 px-4 text-sm font-bold text-zinc-950 disabled:opacity-60">{sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}Tanya</button></form>
        </section>
      )}

      {ticket && (
        <section className="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 p-4 sm:p-5">
            <div className="flex min-w-0 items-center gap-3"><button onClick={backToList} className="rounded-lg p-2 hover:bg-zinc-100" aria-label="Kembali"><ArrowLeft className="h-4 w-4" /></button><div className="min-w-0"><h2 className="truncate font-bold text-zinc-900">{ticket.subject}</h2><p className="text-xs text-zinc-500">{ticket.ticket_number} · {ticket.category}</p></div></div>
            <span className={`rounded-full px-3 py-1 text-xs font-semibold ${statusTone[ticket.status]}`}>{statusNames[ticket.status]}</span>
          </div>
          <div className="max-h-[55vh] space-y-4 overflow-y-auto p-4 sm:p-6">
            {(ticket.messages || []).map(item => <MessageBubble key={item.id} message={item} isMine={item.actor_type === 'user'} onDownload={downloadAttachment} />)}
            {ticket.messages?.length === 0 && <p className="text-sm text-zinc-500">Belum ada pesan di tiket ini.</p>}
          </div>
          {ticket.source === 'ai' && ['new', 'ai_replied'].includes(ticket.status) && <div className="border-t border-amber-100 bg-amber-50 px-4 py-3 sm:px-6"><button onClick={async () => { try { await escalateSupportTicket(ticket.ticket_number); await refresh(ticket.ticket_number); } catch (e) { setError(e instanceof Error ? e.message : 'Tidak bisa menghubungi CS.'); } }} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-amber-300 bg-white px-3 text-sm font-semibold text-amber-900 hover:bg-amber-100"><Headphones className="h-4 w-4" />Hubungi CS</button><span className="ml-3 text-xs text-zinc-600">Percakapan AI ikut diteruskan sebagai konteks.</span></div>}
          {!['resolved', 'closed'].includes(ticket.status) && <form onSubmit={sendReply} className="space-y-3 border-t border-zinc-100 p-4 sm:p-5">
            <textarea required maxLength={10000} rows={3} value={message} onChange={e => setMessage(e.target.value)} placeholder={aiActive ? 'Lanjutkan bertanya...' : 'Tulis balasan untuk tim bantuan...'} className="w-full rounded-xl border border-zinc-200 p-3 text-sm" />
            {!aiActive && <FilePicker files={files} onChange={chooseFiles} onRemove={index => setFiles(items => items.filter((_, i) => i !== index))} />}
            <button disabled={sending} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-zinc-950 px-4 text-sm font-semibold text-white disabled:opacity-60">{sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}Kirim balasan</button>
            <button type="button" onClick={async () => { try { await closeSupportTicket(ticket.ticket_number); await refresh(ticket.ticket_number); } catch (e) { setError(e instanceof Error ? e.message : 'Tiket gagal ditutup.'); } }} className="ml-3 min-h-10 px-3 text-sm text-zinc-500 hover:underline">Tutup tiket</button>
          </form>}
          {ticket.status === 'resolved' && <div className="space-y-3 border-t border-zinc-100 p-4 sm:p-5">
            <div className="flex flex-wrap items-center gap-2"><span className="text-sm font-semibold text-zinc-800">Apakah bantuan ini berguna?</span>{[1, 2, 3, 4, 5].map(value => <button key={value} onClick={() => setRating(value)} aria-label={`${value} bintang`} className={value <= rating ? 'text-amber-500' : 'text-zinc-300'}><Star className="h-5 w-5 fill-current" /></button>)}</div>
            <textarea maxLength={1000} value={feedback} onChange={e => setFeedback(e.target.value)} placeholder="Masukan (opsional)" className="w-full rounded-xl border border-zinc-200 p-3 text-sm" />
            <button disabled={!rating} onClick={async () => { try { await sendSupportFeedback(ticket.ticket_number, rating, feedback); await loadTicket(ticket.ticket_number); } catch (e) { setError(e instanceof Error ? e.message : 'Masukan gagal disimpan.'); } }} className="min-h-10 rounded-xl bg-amber-400 px-4 text-sm font-bold disabled:opacity-50">Kirim penilaian</button>
            {ticket.reopen_until && new Date(ticket.reopen_until) > new Date() && <button onClick={async () => { try { await reopenSupportTicket(ticket.ticket_number); await refresh(ticket.ticket_number); } catch (e) { setError(e instanceof Error ? e.message : 'Tiket gagal dibuka kembali.'); } }} className="ml-2 min-h-10 rounded-xl border border-zinc-200 px-4 text-sm font-semibold">Buka kembali</button>}
          </div>}
          {ticket.status === 'closed' && <p className="border-t border-zinc-100 p-5 text-sm text-zinc-500">Tiket ditutup. Jika masih memerlukan bantuan, buat tiket baru.</p>}
        </section>
      )}
    </div>
  );
}

function FilePicker({ files, onChange, onRemove }: { files: File[]; onChange: (files: FileList | null) => void; onRemove: (index: number) => void }) {
  return <div className="flex flex-wrap items-center gap-2">
    <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 px-3 text-xs font-semibold text-zinc-700 hover:bg-zinc-50"><Upload className="h-4 w-4" />Lampirkan screenshot<input type="file" accept="image/png,image/jpeg,image/webp" multiple className="sr-only" onChange={e => onChange(e.target.files)} /></label>
    <span className="text-[11px] text-zinc-400">Maks. 3 gambar, 5 MB per file</span>
    {files.map((file, index) => <span key={`${file.name}-${index}`} className="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-2 py-1 text-xs"><FileImage className="h-3 w-3" />{file.name}<button type="button" onClick={() => onRemove(index)} aria-label="Hapus lampiran"><X className="h-3 w-3" /></button></span>)}
  </div>;
}

function MessageBubble({ message, isMine, onDownload }: { message: SupportMessage; isMine: boolean; onDownload: (id: number, name: string) => void }) {
  if (message.is_internal) return null;
  const label = message.actor_type === 'ai' ? 'Asisten AI' : message.actor_type === 'agent' ? (message.sender?.name || 'Tim Hellom') : message.actor_type === 'system' ? 'Pusat Bantuan' : 'Anda';
  return <div className={`flex ${isMine ? 'justify-end' : 'justify-start'}`}><div className={`max-w-[90%] rounded-2xl p-3 sm:max-w-[80%] ${isMine ? 'bg-zinc-950 text-white' : message.actor_type === 'ai' ? 'border border-amber-200 bg-amber-50 text-zinc-900' : 'bg-zinc-100 text-zinc-900'}`}>
    <div className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold opacity-70">{message.actor_type === 'ai' ? <Bot className="h-3 w-3" /> : message.actor_type === 'system' ? <ShieldAlert className="h-3 w-3" /> : null}{label}<span>· {dateLabel(message.created_at)}</span></div>
    <MessageBody body={message.body} isMine={isMine} />
    {!!message.attachments?.length && <div className="mt-2 space-y-1">{message.attachments.map(file => <button key={file.id} onClick={() => onDownload(file.id, file.name)} className="block text-xs underline">{file.name}</button>)}</div>}
  </div></div>;
}

function MessageBody({ body, isMine }: { body: string; isMine: boolean }) {
  const parts = body.split(/(\/(?:dashboard|pos)(?:\/[A-Za-z0-9._?=&-]*)?)/g);
  return <p className="whitespace-pre-wrap break-words text-sm leading-6">{parts.map((part, index) =>
    part.startsWith('/dashboard') || part.startsWith('/pos')
      ? <Link key={index} to={part} className={`underline underline-offset-2 ${isMine ? 'text-amber-200' : 'text-amber-800'}`}>{part}</Link>
      : part
  )}</p>;
}
