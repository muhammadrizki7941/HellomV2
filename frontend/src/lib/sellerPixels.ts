// Seller ad pixels on the React buyer pages (/beli checkout, /pesanan thank-you).
// Ids come from the server (validated there) and are checked again here before any script
// is added. Events: InitiateCheckout, AddPaymentInfo, Purchase (event_id shared with the
// server-side Conversions API so Meta counts a purchase once).
import { claimPurchaseEvent } from '@/lib/hellomApi';

type Ids = Partial<Record<'meta_pixel_id' | 'ga4_id' | 'google_ads_id' | 'google_ads_label' | 'tiktok_pixel_id', string>>;

const PATTERNS: Record<keyof Ids, RegExp> = {
  meta_pixel_id: /^\d{10,20}$/,
  ga4_id: /^G-[A-Z0-9]{6,12}$/,
  google_ads_id: /^AW-\d{6,12}$/,
  google_ads_label: /^[A-Za-z0-9_-]{4,40}$/,
  tiktok_pixel_id: /^[A-Z0-9]{15,25}$/,
};

type AnyFn = (...args: unknown[]) => void;
type W = Window & { fbq?: AnyFn & { queue?: unknown[]; callMethod?: AnyFn; loaded?: boolean; version?: string; push?: AnyFn }; _fbq?: unknown; dataLayer?: unknown[]; gtag?: AnyFn; ttq?: { track: AnyFn; page: AnyFn; load: (id: string) => void } & unknown[] };

let loaded: Ids | null = null;

function script(src: string) {
  const s = document.createElement('script');
  s.async = true;
  s.src = src;
  document.head.appendChild(s);
}

/** The visitor's pixel consent for a shop (same key as the server-rendered page). */
export function getPixelConsent(username: string | null | undefined): 'granted' | 'denied' | null {
  try {
    const v = username ? localStorage.getItem(`hl_consent:${username}`) : null;
    return v === 'granted' || v === 'denied' ? v : null;
  } catch {
    return null;
  }
}

export function setPixelConsent(username: string, value: 'granted' | 'denied') {
  try { localStorage.setItem(`hl_consent:${username}`, value); } catch { /* ignore */ }
}

/** Load the seller's pixels once (no-op when there are none or without consent). */
export function loadSellerPixels(raw: Record<string, string> | null | undefined, username?: string | null): Ids {
  if (username !== undefined && getPixelConsent(username) !== 'granted') return {};
  if (loaded) return loaded;
  const ids: Ids = {};
  (Object.keys(PATTERNS) as Array<keyof Ids>).forEach((k) => {
    const v = raw?.[k];
    if (v && PATTERNS[k].test(v)) ids[k] = v;
  });
  loaded = ids;
  const w = window as W;
  if (ids.meta_pixel_id && !w.fbq) {
    const fbq: any = function (...args: unknown[]) { fbq.callMethod ? fbq.callMethod(...args) : fbq.queue.push(args); };
    fbq.push = fbq; fbq.loaded = true; fbq.version = '2.0'; fbq.queue = [];
    w.fbq = fbq; w._fbq = fbq;
    script('https://connect.facebook.net/en_US/fbevents.js');
    w.fbq!('init', ids.meta_pixel_id);
    w.fbq!('track', 'PageView');
  }
  const gid = ids.ga4_id || ids.google_ads_id;
  if (gid && !w.gtag) {
    script(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(gid)}`);
    w.dataLayer = w.dataLayer || [];
    w.gtag = function () { w.dataLayer!.push(arguments); } as AnyFn;
    w.gtag('js', new Date());
    if (ids.ga4_id) w.gtag('config', ids.ga4_id);
    if (ids.google_ads_id) w.gtag('config', ids.google_ads_id);
  }
  if (ids.tiktok_pixel_id && !w.ttq) {
    const ttq: any = [];
    ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie'];
    ttq.setAndDefer = (t: any, e: string) => { t[e] = (...args: unknown[]) => { t.push([e, ...args]); }; };
    ttq.methods.forEach((m: string) => ttq.setAndDefer(ttq, m));
    ttq.load = (id: string) => script(`https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=${encodeURIComponent(id)}&lib=ttq`);
    w.ttq = ttq;
    ttq.load(ids.tiktok_pixel_id);
    ttq.page();
  }
  return ids;
}

type EventData = { value?: number; currency?: string; content_ids?: string[]; content_name?: string; event_id?: string };

export function trackSellerEvent(name: 'InitiateCheckout' | 'AddPaymentInfo' | 'Purchase', data: EventData = {}) {
  const w = window as W;
  const ids = loaded ?? {};
  const payload = { value: data.value, currency: data.currency ?? 'IDR', content_ids: data.content_ids, content_name: data.content_name, content_type: 'product' };
  if (ids.meta_pixel_id && w.fbq) w.fbq('track', name, payload, data.event_id ? { eventID: data.event_id } : undefined);
  if (w.gtag) {
    const ga = { InitiateCheckout: 'begin_checkout', AddPaymentInfo: 'add_payment_info', Purchase: 'purchase' }[name];
    w.gtag('event', ga, { value: data.value, currency: 'IDR', transaction_id: data.event_id, items: (data.content_ids ?? []).map((id) => ({ item_id: id, item_name: data.content_name })) });
    if (name === 'Purchase' && ids.google_ads_id && ids.google_ads_label) {
      w.gtag('event', 'conversion', { send_to: `${ids.google_ads_id}/${ids.google_ads_label}`, value: data.value, currency: 'IDR', transaction_id: data.event_id });
    }
  }
  if (ids.tiktok_pixel_id && w.ttq) {
    const tt = { InitiateCheckout: 'InitiateCheckout', AddPaymentInfo: 'AddPaymentInfo', Purchase: 'CompletePayment' }[name];
    w.ttq.track(tt, { value: data.value, currency: 'IDR', content_id: data.content_ids?.[0], content_type: 'product' }, data.event_id ? { event_id: data.event_id } : undefined);
  }
}

/** UTM / click ids saved by the public page (localStorage "hl_attr"). */
export function readAttribution(): Record<string, string> | undefined {
  try {
    const raw = JSON.parse(localStorage.getItem('hl_attr') || 'null') as Record<string, unknown> | null;
    if (!raw) return undefined;
    // Older than 30 days: ignore.
    if (typeof raw.ts === 'number' && Date.now() - raw.ts > 30 * 86400000) return undefined;
    const out: Record<string, string> = {};
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ttclid', 'referrer', 'landing'].forEach((k) => {
      if (typeof raw[k] === 'string' && raw[k]) out[k] = String(raw[k]).slice(0, 150);
    });
    return Object.keys(out).length ? out : undefined;
  } catch {
    return undefined;
  }
}

/** Also capture UTM params when the buyer lands straight on /beli from an ad. */
export function captureAttributionFromUrl() {
  try {
    const p = new URLSearchParams(window.location.search);
    const found: Record<string, unknown> = {};
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ttclid'].forEach((k) => { const v = p.get(k); if (v) found[k] = v.slice(0, 150); });
    if (Object.keys(found).length) {
      found.referrer = document.referrer && !document.referrer.includes(window.location.host) ? new URL(document.referrer).host : '';
      found.landing = window.location.pathname;
      found.ts = Date.now();
      localStorage.setItem('hl_attr', JSON.stringify(found));
    }
  } catch {
    /* ignore */
  }
}

/** Purchase pixel event, once per order (the server hands it out a single time). */
export async function firePurchase(reference: string) {
  try {
    const ev = await claimPurchaseEvent(reference);
    if (!ev.fire) return;
    if (getPixelConsent(ev.username) !== 'granted') return;
    loadSellerPixels(ev.tracking, ev.username);
    trackSellerEvent('Purchase', { value: ev.value, content_ids: ev.content_ids, content_name: ev.content_name, event_id: ev.event_id });
    await new Promise((r) => setTimeout(r, 800)); // give the pixels a moment before navigating
  } catch {
    /* tracking must never block the buyer */
  }
}
