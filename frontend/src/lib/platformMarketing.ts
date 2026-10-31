const CONSENT = 'hellom_marketing_consent';
const ATTRIBUTION = 'hellom_marketing_attribution';
export function marketingConsent(): string {
  try { return localStorage.getItem(CONSENT) || ''; } catch { return 'denied'; }
}
export function setMarketingConsent(value: 'granted' | 'denied') {
  try {
    localStorage.setItem(CONSENT, value);
    if (value === 'denied') {
      localStorage.removeItem(ATTRIBUTION);
      localStorage.removeItem('hellom_marketing_visitor');
      sessionStorage.removeItem('hellom_marketing_session');
    }
  } catch { /* Storage may be blocked. */ }
  window.dispatchEvent(new Event('hellom-marketing-consent'));
}
export function platformPublicPath(path: string): boolean {
  return /^\/(?:aplikasi(?:\/[a-zA-Z0-9_-]+)?|produk(?:\/[a-zA-Z0-9_-]+)?|layanan|tentang|kontak|wawasan(?:\/[a-zA-Z0-9_-]+)?|register|login|checkout-pos|pembayaran\/[a-zA-Z0-9_-]+)?\/?$/.test(path);
}
export function marketingContext(): Record<string, string> | undefined {
  if (marketingConsent() !== 'granted') return undefined;
  try {
    const now = Date.now();
    const params = new URLSearchParams(location.search);
    let saved = JSON.parse(localStorage.getItem(ATTRIBUTION) || '{}');
    if (!saved.at || now - saved.at > 30 * 86400000 || params.has('utm_campaign') || params.has('fbclid')) {
      let ref = '';
      try { ref = new URL(document.referrer).hostname; } catch { /* Direct visitor. */ }
      const fbclid = params.get('fbclid') || '';
      saved = {
        at: now,
        source: (params.get('utm_source') || (fbclid ? 'facebook' : ref && ref !== location.hostname ? ref : 'direct')).slice(0, 120),
        medium: (params.get('utm_medium') || '').slice(0, 120),
        campaign: (params.get('utm_campaign') || '').slice(0, 120),
        content: (params.get('utm_content') || '').slice(0, 120),
        fbclid: fbclid.slice(0, 255),
        fbc: fbclid ? `fb.1.${now}.${fbclid.slice(0, 180)}` : '',
      };
    }
    if (platformPublicPath(location.pathname)) {
      saved.path = location.pathname.startsWith('/pembayaran/') ? '/pembayaran' : location.pathname;
    }
    const app = location.pathname.match(/^\/(?:aplikasi|produk)\/([^/]+)/)?.[1];
    if (app) saved.app_slug = app === 'landing-page-builder' ? 'landing_builder' : app;
    localStorage.setItem(ATTRIBUTION, JSON.stringify(saved));
    let visitor = localStorage.getItem('hellom_marketing_visitor');
    if (!visitor) { visitor = crypto.randomUUID(); localStorage.setItem('hellom_marketing_visitor', visitor); }
    let session = JSON.parse(sessionStorage.getItem('hellom_marketing_session') || '{}');
    if (!session.id || now - session.at > 1800000) session = { id: crypto.randomUUID() };
    session.at = now;
    sessionStorage.setItem('hellom_marketing_session', JSON.stringify(session));
    const cookie = (name: string) => document.cookie.split('; ').find((v) => v.startsWith(name + '='))?.slice(name.length + 1) || '';
    return {
      consent: 'granted',
      visitor_id: visitor,
      session_id: session.id,
      path: saved.path || '/',
      source: saved.source || 'direct',
      medium: saved.medium || '',
      campaign: saved.campaign || '',
      content: saved.content || '',
      fbclid: saved.fbclid || '',
      app_slug: saved.app_slug || '',
      fbc: saved.fbc || cookie('_fbc'),
      fbp: cookie('_fbp'),
    };
  } catch { return undefined; }
}

type QueuedMetaEvent = { name: string; eventId: string; value?: number; currency: string };
const queuedMetaEvents: QueuedMetaEvent[] = [];

export function trackPlatformMetaEvent(
  name: 'InitiateCheckout' | 'AddPaymentInfo' | 'Lead' | 'Purchase',
  eventId: string,
  value?: number,
  currency = 'IDR',
) {
  if (marketingConsent() !== 'granted') return;
  const pixel = (window as Window & {
    fbq?: (...args: unknown[]) => void;
  }).fbq;
  if (!pixel) {
    queuedMetaEvents.push({ name, eventId, value, currency });
    return;
  }
  pixel('track', name, value === undefined ? {} : { value, currency }, { eventID: eventId });
}

export function flushQueuedPlatformMetaEvents() {
  if (marketingConsent() !== 'granted') {
    queuedMetaEvents.length = 0;
    return;
  }
  const pixel = (window as Window & {
    fbq?: (...args: unknown[]) => void;
  }).fbq;
  if (!pixel) return;
  for (const event of queuedMetaEvents.splice(0)) {
    pixel('track', event.name, event.value === undefined ? {} : { value: event.value, currency: event.currency }, { eventID: event.eventId });
  }
}
