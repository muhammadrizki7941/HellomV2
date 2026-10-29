import { useState } from 'react';
import { AlertTriangle, CheckCircle, ShieldCheck, XCircle } from 'lucide-react';
import { checkAdminMailDomain, type MailDomainCheckResult } from '@/lib/hellomApi';

const ICON = {
  ok: <CheckCircle className="h-4 w-4 text-emerald-600" />,
  warn: <AlertTriangle className="h-4 w-4 text-amber-600" />,
  fail: <XCircle className="h-4 w-4 text-red-600" />,
};

/** Pengaturan Email › Cek domain: SPF / DKIM / DMARC + whether the SMTP may send as the From domain. */
export default function MailDomainPanel() {
  const [result, setResult] = useState<MailDomainCheckResult | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async () => {
    setLoading(true);
    setError(null);
    try {
      setResult(await checkAdminMailDomain());
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memeriksa domain');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-600 text-white">
            <ShieldCheck className="h-5 w-5" />
          </div>
          <div>
            <h3 className="font-bold text-zinc-900">Cek domain pengirim (anti-spam)</h3>
            <p className="text-sm text-zinc-500">Periksa kecocokan pengirim dan autentikasi domain. Hasil ini bukan jaminan masuk inbox.</p>
          </div>
        </div>
        <button
          type="button"
          onClick={() => void run()}
          disabled={loading}
          className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-bold text-white hover:bg-zinc-800 disabled:opacity-60"
        >
          {loading ? 'Memeriksa…' : 'Cek sekarang'}
        </button>
      </div>

      {error && <p className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      {result && (
        <div className="space-y-3">
          <p className={`rounded-lg p-3 text-sm font-medium ${result.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'}`}>
            {result.ok
              ? `Domain ${result.domain} siap: email dari sini akan lolos pemeriksaan Gmail & Outlook.`
              : `Domain ${result.domain || '(belum diisi)'} belum siap — perbaiki yang bertanda merah atau kuning. Panduan lengkap: docs/EMAIL.md.`}
            {result.provider_label ? ` Penyedia SMTP: ${result.provider_label}.` : ''}
          </p>
          <ul className="divide-y divide-zinc-100 rounded-lg border border-zinc-200">
            {result.checks.map((item) => (
              <li key={item.key} className="flex gap-3 p-3">
                <span className="mt-0.5">{ICON[item.status]}</span>
                <div className="min-w-0 flex-1 text-sm">
                  <p className="font-semibold text-zinc-900">{item.label}</p>
                  <p className="break-words text-zinc-600">{item.detail}</p>
                  {item.fix && (
                    <p className="mt-1 break-words rounded bg-zinc-50 px-2 py-1 font-mono text-xs text-zinc-700">{item.fix}</p>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
