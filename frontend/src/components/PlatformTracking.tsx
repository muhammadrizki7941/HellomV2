import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { apiRequest, getSessionUser } from '@/services/api/client';
import { flushQueuedPlatformMetaEvents, marketingConsent, marketingContext, platformPublicPath, setMarketingConsent } from '@/lib/platformMarketing';

type PixelFn = ((...args: unknown[]) => void) & { callMethod?: (...args: unknown[]) => void; queue: unknown[][]; push?: PixelFn; loaded: boolean; version: string };
const w = window as Window & { fbq?: PixelFn; _fbq?: PixelFn };
const initialized = new Set<string>();
function loadPixel(id: string) {
  if (!w.fbq) {
    const fn = function (...args: unknown[]) { if (fn.callMethod) fn.callMethod(...args); else fn.queue.push(args); } as PixelFn;
    fn.queue = []; fn.loaded = true; fn.version = '2.0'; fn.push = fn;
    w.fbq = fn; w._fbq = fn;
    const script = document.createElement('script'); script.async = true; script.src = 'https://connect.facebook.net/en_US/fbevents.js'; document.head.appendChild(script);
  }
  if (!initialized.has(id)) { w.fbq('set', 'autoConfig', false, id); w.fbq('init', id); initialized.add(id); }
  w.fbq('consent', 'grant');
}

export default function PlatformTracking() {
  const location = useLocation();
  const [consent, setConsent] = useState(marketingConsent);
  const [preferences, setPreferences] = useState(false);
  const [config, setConfig] = useState<{ enabled: boolean; pixel_id: string } | null>(null);
  const [checkoutActive, setCheckoutActive] = useState(false);
  const eligible = (platformPublicPath(location.pathname) || checkoutActive) && getSessionUser<{ role: string }>()?.role !== 'super_admin';
  useEffect(() => { apiRequest<{ enabled: boolean; pixel_id: string }>('/public/marketing/config', { token: null }).then(setConfig).catch(() => {}); }, []);
  useEffect(() => {
    const open = () => setCheckoutActive(true);
    const close = () => setCheckoutActive(false);
    window.addEventListener('hellom-checkout-open', open);
    window.addEventListener('hellom-checkout-close', close);
    return () => {
      window.removeEventListener('hellom-checkout-open', open);
      window.removeEventListener('hellom-checkout-close', close);
    };
  }, []);
  useEffect(() => {
    const update = () => {
      setConsent(marketingConsent());
      if (marketingConsent() === 'denied' && w.fbq) {
        w.fbq('consent', 'revoke');
        // Unload the platform pixel; a later shop visit uses its own consent.
        window.location.reload();
      }
    };
    window.addEventListener('hellom-marketing-consent', update);
    return () => window.removeEventListener('hellom-marketing-consent', update);
  }, []);
  useEffect(() => {
    if (!eligible || !config?.enabled || consent !== 'granted') return;
    // Delay also avoids duplicate effects during React StrictMode's development remount.
    const timer = setTimeout(() => {
      const context = marketingContext();
      if (!context) return;
      if (config.pixel_id) {
        loadPixel(config.pixel_id);
        flushQueuedPlatformMetaEvents();
      }
      const names = /^\/(aplikasi|produk)\/.+/.test(location.pathname) || location.pathname === '/' || location.pathname === '/checkout-pos'
        ? ['PageView', 'ViewContent']
        : ['PageView'];
      for (const event_name of names) {
        const event_id = crypto.randomUUID();
        void apiRequest('/public/marketing/events', { method: 'POST', token: null, body: { event_name, event_id, marketing: context } }).catch(() => {});
        if (config.pixel_id) w.fbq?.('trackSingle', config.pixel_id, event_name, { content_ids: context.app_slug ? [context.app_slug] : [], content_type: 'product' }, { eventID: 'web_' + event_id });
      }
    }, 150);
    return () => clearTimeout(timer);
  }, [eligible, location.pathname, location.search, consent, config]);
  if (!eligible || !config?.enabled) return null;
  if (consent && !preferences) return <button onClick={() => setPreferences(true)} className="fixed bottom-3 left-3 z-40 rounded-full border border-zinc-200 bg-white px-3 py-1 text-xs text-zinc-600 shadow">Preferensi privasi</button>;
  return <aside className="fixed bottom-4 left-4 right-4 z-[100] max-w-lg rounded-2xl border border-zinc-200 bg-white p-5 text-zinc-900 shadow-xl" aria-label="Preferensi analitik">
    <p className="font-semibold">Bantu kami meningkatkan Hellomspace</p><p className="mt-2 text-sm text-zinc-600">Dengan izin Anda, kami menggunakan analitik dan Meta Pixel untuk mengukur kunjungan serta hasil iklan. Anda tetap dapat menggunakan layanan tanpa mengizinkannya.</p>
    <div className="mt-4 flex gap-2"><button onClick={() => { setMarketingConsent('granted'); setPreferences(false); }} className="rounded-xl bg-zinc-900 px-4 py-2 text-sm text-white">Izinkan</button><button onClick={() => { setMarketingConsent('denied'); setPreferences(false); }} className="rounded-xl border px-4 py-2 text-sm">Tidak sekarang</button></div>
  </aside>;
}
