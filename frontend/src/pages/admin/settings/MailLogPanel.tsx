import { useCallback, useEffect, useState } from 'react';
import { History, RefreshCw, Search } from 'lucide-react';
import { getAdminEmailLogs, resendAdminEmailLog, type AdminEmailLog } from '@/lib/hellomApi';

const STATUS: Record<AdminEmailLog['status'], { label: string; tone: string }> = {
  sent: { label: 'Terkirim', tone: 'bg-emerald-100 text-emerald-700' },
  failed: { label: 'Gagal', tone: 'bg-red-100 text-red-700' },
  skipped: { label: 'Berhenti langganan', tone: 'bg-zinc-100 text-zinc-600' },
};

const formatTime = (value: string) =>
  new Date(value).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

/** Pengaturan Email › Riwayat: every email attempt (90 days), failed ones can be resent. */
export default function MailLogPanel() {
  const [logs, setLogs] = useState<AdminEmailLog[]>([]);
  const [summary, setSummary] = useState<{ sent_24h: number; failed_24h: number } | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [status, setStatus] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
  const [resending, setResending] = useState<number | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const result = await getAdminEmailLogs({ page, status: status || undefined, q: search || undefined });
      setLogs(result.data);
      setSummary(result.summary);
      setLastPage(result.meta.last_page);
    } catch (err) {
      setMessage({ ok: false, text: err instanceof Error ? err.message : 'Gagal memuat riwayat email' });
    } finally {
      setLoading(false);
    }
  }, [page, search, status]);

  useEffect(() => {
    void load();
  }, [load]);

  const resend = async (log: AdminEmailLog) => {
    setResending(log.id);
    setMessage(null);
    try {
      await resendAdminEmailLog(log.id);
      setMessage({ ok: true, text: `Email ke ${log.to_email} terkirim ulang.` });
      await load();
    } catch (err) {
      setMessage({ ok: false, text: err instanceof Error ? err.message : 'Masih gagal terkirim' });
    } finally {
      setResending(null);
    }
  };

  return (
    <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-zinc-900 text-white">
            <History className="h-5 w-5" />
          </div>
          <div>
            <h3 className="font-bold text-zinc-900">Riwayat email</h3>
            <p className="text-sm text-zinc-500">
              {summary ? `24 jam terakhir: ${summary.sent_24h} terkirim, ${summary.failed_24h} gagal.` : 'Semua email yang dikirim platform (90 hari).'}
            </p>
          </div>
        </div>
        <button type="button" onClick={() => void load()} className="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100" aria-label="Muat ulang riwayat">
          <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
        </button>
      </div>

      <div className="mb-3 flex flex-col gap-2 sm:flex-row">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-2.5 h-4 w-4 text-zinc-400" />
          <input
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Cari penerima atau subjek…"
            className="w-full rounded-lg border border-zinc-300 py-2 pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
          />
        </div>
        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          aria-label="Filter status email"
          className="rounded-lg border border-zinc-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
        >
          <option value="">Semua status</option>
          <option value="sent">Terkirim</option>
          <option value="failed">Gagal</option>
          <option value="skipped">Berhenti langganan</option>
        </select>
      </div>

      {message && (
        <p className={`mb-3 rounded-lg p-3 text-sm ${message.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'}`}>{message.text}</p>
      )}

      {logs.length === 0 ? (
        <p className="rounded-lg border border-dashed border-zinc-200 p-6 text-center text-sm text-zinc-500">
          {loading ? 'Memuat…' : 'Belum ada email yang cocok.'}
        </p>
      ) : (
        <ul className="divide-y divide-zinc-100 rounded-lg border border-zinc-200">
          {logs.map((log) => (
            <li key={log.id} className="flex flex-wrap items-start gap-3 p-3 text-sm">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${STATUS[log.status].tone}`}>{STATUS[log.status].label}</span>
                  {log.category === 'marketing' && <span className="text-[11px] text-zinc-500">Info & tips</span>}
                  <span className="text-xs text-zinc-500">{formatTime(log.created_at)}</span>
                </div>
                <p className="mt-1 truncate font-medium text-zinc-900">{log.subject || '(tanpa subjek)'}</p>
                <p className="truncate text-zinc-600">{log.to_email}</p>
                {log.error && <p className="mt-1 break-words text-xs text-red-600">{log.error}</p>}
              </div>
              {log.can_resend && (
                <button
                  type="button"
                  onClick={() => void resend(log)}
                  disabled={resending === log.id}
                  className="shrink-0 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 disabled:opacity-60"
                >
                  {resending === log.id ? 'Mengirim…' : 'Kirim ulang'}
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {lastPage > 1 && (
        <div className="mt-3 flex items-center justify-between text-sm text-zinc-600">
          <span>Halaman {page} dari {lastPage}</span>
          <div className="flex gap-2">
            <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded border border-zinc-300 px-3 py-1 disabled:opacity-50">
              Sebelumnya
            </button>
            <button type="button" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)} className="rounded border border-zinc-300 px-3 py-1 disabled:opacity-50">
              Berikutnya
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
