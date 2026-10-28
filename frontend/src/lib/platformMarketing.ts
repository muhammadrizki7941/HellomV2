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
  return /^\/(?:aplikasi(?:\/[a-zA-Z0-9_-]+)?|produk(?:\/[a-zA-Z0-9_-]+)?|layanan|tentang|kontak|wawasan(?:\/[a-zA-Z0-9_-]+)?|register|login)?\/?$/.test(path);
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
      saved = {
        at: now, source: (params.get('utm_source') || (params.has('fbclid') ? 'facebook' : ref && ref !== location.hostname ? ref : 'direct')).slice(0, 120),
        campaign: (params.get('utm_campaign') || '').slice(0, 120),
        fbc: params.get('fbclid') ? `fb.1.${now}.${params.get('fbclid')!.slice(0, 180)}` : '',
      };
    }
    if (platformPublicPath(location.pathname)) saved.path = location.pathname;
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
    return { consent: 'granted', visitor_id: visitor, session_id: session.id, path: saved.path || '/', source: saved.source || 'direct', campaign: saved.campaign || '', app_slug: saved.app_slug || '', fbc: saved.fbc || cookie('_fbc'), fbp: cookie('_fbp') };
  } catch { return undefined; }
}
