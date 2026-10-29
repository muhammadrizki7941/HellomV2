import { Component, useEffect, useState, type ReactNode } from 'react';
import { useLocation } from 'react-router-dom';
import HellomMascot from './HellomMascot';

/** Cosmetic feedback only: never intercepts clicks or delays routing. */
export function RouteJourney() {
  const { pathname } = useLocation();
  // Do not distract cashiers during transactions or customer self-ordering.
  if (/^\/(pos|order|customer)(\/|$)/.test(pathname)) return null;
  return <div key={pathname} className="hellom-journey" aria-hidden="true"><HellomMascot />Menuju halaman berikutnya…</div>;
}

export function ConnectionNotice() {
  const [offline, setOffline] = useState(() => !navigator.onLine);
  const [dismissed, setDismissed] = useState(false);
  useEffect(() => {
    const update = () => { setOffline(!navigator.onLine); setDismissed(false); };
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    return () => { window.removeEventListener('online', update); window.removeEventListener('offline', update); };
  }, []);
  if (!offline || dismissed) return null;
  return <aside className="hellom-connection" role="status"><HellomMascot mood="error" /><div><strong>Koneksi sedang terputus</strong><p>Periksa internet kamu. Jangan ulangi pembayaran sebelum memeriksa statusnya.</p></div><button type="button" aria-label="Tutup pemberitahuan koneksi" onClick={() => setDismissed(true)}>×</button></aside>;
}

/** A 503 means unavailable, not necessarily planned maintenance. Never invent an ETA. */
export function ServiceNotice() {
  const [visible, setVisible] = useState(false);
  useEffect(() => {
    let timeout: ReturnType<typeof setTimeout>;
    const show = () => {
      setVisible(true);
      clearTimeout(timeout);
      timeout = setTimeout(() => setVisible(false), 10000);
    };
    window.addEventListener('hellom-service-unavailable', show);
    return () => { clearTimeout(timeout); window.removeEventListener('hellom-service-unavailable', show); };
  }, []);
  if (!visible) return null;
  return <aside className="hellom-connection" role="status"><HellomMascot mood="maintenance" /><div><strong>Layanan sementara belum tersedia</strong><p>Coba lagi beberapa saat lagi. Jika sedang membayar, periksa status transaksi terlebih dahulu.</p></div><button type="button" aria-label="Tutup pemberitahuan layanan" onClick={() => setVisible(false)}>×</button></aside>;
}

export class PageErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  render() {
    if (!this.state.failed) return this.props.children;
    return <main className="flex min-h-[100svh] flex-col items-center justify-center gap-5 bg-[#faf9f6] px-6 text-center text-zinc-900">
      <HellomMascot mood="error" />
      <h1 className="text-2xl font-bold">Halaman belum bisa ditampilkan</h1>
      <p className="max-w-md text-sm text-zinc-600">Ada kendala saat membuka halaman. Coba muat ulang. Jika sedang membayar, cek status transaksi sebelum mengulang pembayaran.</p>
      <button className="min-h-12 rounded-xl bg-amber-400 px-6 font-semibold" onClick={() => window.location.reload()}>Muat ulang halaman</button>
      <a href="/" className="p-3 text-sm underline">Kembali ke beranda</a>
    </main>;
  }
}
