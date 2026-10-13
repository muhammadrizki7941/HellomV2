import { useEffect, useState } from 'react';
import { BellRing, Smartphone } from 'lucide-react';
import { disablePush, enablePush, getPushState, type PushState } from '@/lib/webPush';

const HINTS: Partial<Record<PushState, string>> = {
  'ios-install': 'Di iPhone/iPad: Bagikan › Tambah ke Layar Utama, lalu buka dari ikon itu untuk mengaktifkan notifikasi.',
  denied: 'Notifikasi diblokir browser. Izinkan lewat ikon gembok di samping alamat situs.',
};

/** "Notifikasi di perangkat ini" row inside the bell dropdowns (web push). Hidden when the server has no keys. */
export default function PushToggle() {
  const [state, setState] = useState<PushState | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    getPushState().then((value) => alive && setState(value)).catch(() => alive && setState('unsupported'));
    return () => {
      alive = false;
    };
  }, []);

  if (state === null || state === 'unsupported' || state === 'server-off') return null;

  const toggle = async () => {
    setBusy(true);
    setError(null);
    try {
      setState(state === 'on' ? await disablePush() : await enablePush());
    } catch {
      setError('Gagal mengaktifkan. Coba lagi sebentar.');
    } finally {
      setBusy(false);
    }
  };

  const hint = error ?? HINTS[state];
  const canToggle = state === 'on' || state === 'off';

  return (
    <div className="border-b border-zinc-100 px-4 py-3">
      <div className="flex items-center gap-3">
        <div className="rounded-lg bg-zinc-100 p-2 text-zinc-700">
          {state === 'ios-install' ? <Smartphone className="h-4 w-4" /> : <BellRing className="h-4 w-4" />}
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-zinc-900">Notifikasi di perangkat ini</p>
          <p className="text-xs text-zinc-500">
            {state === 'on' ? 'Aktif — tetap muncul walau dashboard ditutup.' : 'Dapat kabar penjualan & penarikan walau dashboard ditutup.'}
          </p>
        </div>
        {canToggle && (
          <button
            type="button"
            disabled={busy}
            onClick={() => void toggle()}
            className={`shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-60 ${
              state === 'on' ? 'border border-zinc-200 text-zinc-700 hover:bg-zinc-50' : 'bg-zinc-900 text-white hover:bg-zinc-800'
            }`}
          >
            {busy ? 'Sebentar…' : state === 'on' ? 'Matikan' : 'Aktifkan'}
          </button>
        )}
      </div>
      {hint && <p className={`mt-2 text-xs ${error ? 'text-red-600' : 'text-zinc-500'}`}>{hint}</p>}
    </div>
  );
}
