import { useCallback, useEffect, useState } from 'react';
import { Activity, AlertTriangle, CheckCircle, Clock, Database, HardDrive, Layers, RefreshCw, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getSystemHealth, type SystemHealth as Health, type SystemHealthCheck } from '@/lib/hellomApi';

const REFRESH_MS = 60_000;

const formatAge = (seconds?: number | null) => {
  if (seconds === null || seconds === undefined) return '—';
  if (seconds < 60) return `${seconds} detik lalu`;
  if (seconds < 3600) return `${Math.round(seconds / 60)} menit lalu`;
  return `${Math.round(seconds / 3600)} jam lalu`;
};

function CheckCard({ title, icon: Icon, check, details }: {
  title: string;
  icon: typeof Database;
  check: SystemHealthCheck;
  details: Array<[string, string]>;
}) {
  return (
    <div className={cn('rounded-xl border bg-white p-5', check.ok ? 'border-zinc-200' : 'border-red-200')}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2 font-semibold text-zinc-900"><Icon className="h-5 w-5 text-zinc-500" /> {title}</div>
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium', check.ok ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700')}>
          {check.ok ? <CheckCircle className="h-3.5 w-3.5" /> : <XCircle className="h-3.5 w-3.5" />}
          {check.ok ? 'Normal' : 'Bermasalah'}
        </span>
      </div>
      {check.error && <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{check.error}</p>}
      <dl className="mt-3 space-y-1 text-sm">
        {details.map(([label, value]) => (
          <div key={label} className="flex justify-between gap-4">
            <dt className="text-zinc-500">{label}</dt>
            <dd className="text-right font-medium text-zinc-800">{value}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

/** Super admin: live server checks from GET /api/health (database, cache, scheduler, queue). */
export default function SystemHealth() {
  const [health, setHealth] = useState<Health | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setHealth(await getSystemHealth());
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Server tidak bisa dihubungi.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
    const timer = window.setInterval(() => void load(), REFRESH_MS);
    return () => window.clearInterval(timer);
  }, [load]);

  const checks = health?.checks;
  const queue = checks?.queue;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-zinc-900"><Activity className="h-6 w-6" /> Kesehatan Sistem</h1>
          <p className="mt-1 text-zinc-600">Pemeriksaan langsung ke server, diperbarui tiap menit. Sama dengan yang dipantau monitor uptime di <code>/api/health</code>.</p>
        </div>
        <button onClick={() => void load()} className="inline-flex items-center gap-2 rounded-lg bg-zinc-100 px-4 py-2 text-sm font-medium hover:bg-zinc-200">
          <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} /> Periksa ulang
        </button>
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-700">
          <XCircle className="h-4 w-4" /> {error}
        </div>
      )}

      {health && (
        <div className={cn('flex items-center gap-3 rounded-xl border p-4', health.status === 'ok' ? 'border-green-200 bg-green-50 text-green-800' : 'border-amber-200 bg-amber-50 text-amber-900')}>
          {health.status === 'ok' ? <CheckCircle className="h-6 w-6" /> : <AlertTriangle className="h-6 w-6" />}
          <div>
            <p className="font-semibold">{health.status === 'ok' ? 'Semua layanan berjalan normal' : 'Ada layanan yang bermasalah'}</p>
            <p className="flex items-center gap-1 text-sm opacity-80"><Clock className="h-3.5 w-3.5" /> Diperiksa {new Date(health.time).toLocaleString('id-ID')}</p>
          </div>
        </div>
      )}

      {!health && loading && <p className="text-zinc-500">Memeriksa server…</p>}

      {checks && (
        <div className="grid gap-4 md:grid-cols-2">
          <CheckCard title="Database" icon={Database} check={checks.database} details={[['Waktu respons', checks.database.ms !== undefined ? `${checks.database.ms} ms` : '—']]} />
          <CheckCard title="Cache" icon={HardDrive} check={checks.cache} details={[['Tulis & baca', checks.cache.ok ? 'Berhasil' : 'Gagal']]} />
          <CheckCard
            title="Scheduler (cron)"
            icon={Clock}
            check={checks.scheduler}
            details={[
              ['Terakhir jalan', checks.scheduler.last_run_at ? new Date(checks.scheduler.last_run_at).toLocaleString('id-ID') : 'Belum pernah'],
              ['Usia', formatAge(checks.scheduler.age_seconds)],
            ]}
          />
          <CheckCard
            title="Antrean (queue)"
            icon={Layers}
            check={checks.queue}
            details={[
              ['Koneksi', queue?.connection ?? '—'],
              ['Menunggu', queue?.pending !== undefined ? String(queue.pending) : '—'],
              ['Tunggu terlama', formatAge(queue?.oldest_wait_seconds)],
              ['Job gagal', queue?.failed !== undefined && queue?.failed !== null ? String(queue.failed) : '—'],
            ]}
          />
        </div>
      )}
    </div>
  );
}
