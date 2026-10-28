import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import ts from 'typescript';

function storage() {
  const values = new Map();
  return { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key) };
}
function load(path, extras = {}) {
  const source = readFileSync(new URL(path, import.meta.url), 'utf8');
  const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  const context = { exports: {}, require: () => ({}), console, URL, URLSearchParams, Date, ...extras };
  vm.runInNewContext(code, context);
  return context;
}

test('consent, campaign continuity, canonical app key, and withdrawal', () => {
  const localStorage = storage(); const sessionStorage = storage();
  const location = { pathname: '/aplikasi/landing-page-builder', hostname: 'hellom.test', search: '?utm_source=instagram&utm_campaign=launch&fbclid=click-123' };
  let sequence = 0;
  const c = load('../src/lib/platformMarketing.ts', { localStorage, sessionStorage, location, document: { cookie: '', referrer: '' }, crypto: { randomUUID: () => `generated-${++sequence}` }, window: { dispatchEvent() {} }, Event: class {} });
  assert.equal(c.exports.marketingContext(), undefined);
  c.exports.setMarketingConsent('granted');
  const first = c.exports.marketingContext();
  assert.equal(first.source, 'instagram'); assert.equal(first.campaign, 'launch'); assert.equal(first.app_slug, 'landing_builder');
  assert.match(first.fbc, /^fb\.1\.\d+\.click-123$/);
  location.pathname = '/dashboard/payments'; location.search = '';
  const checkout = c.exports.marketingContext();
  assert.equal(checkout.campaign, 'launch'); assert.equal(checkout.visitor_id, first.visitor_id); assert.equal(checkout.session_id, first.session_id);
  assert.equal(checkout.path, '/aplikasi/landing-page-builder');
  c.exports.setMarketingConsent('denied');
  assert.equal(c.exports.marketingContext(), undefined); assert.equal(localStorage.getItem('hellom_marketing_attribution'), null);
  assert.equal(c.exports.platformPublicPath('/admin/marketing'), false);
  assert.equal(c.exports.platformPublicPath('/beli/seller/product'), false);
});

test('seller pixel initializes and targets its own ID when another pixel already exists', () => {
  const calls = []; const window = { fbq: (...args) => calls.push(args) };
  const localStorage = storage(); localStorage.setItem('hl_consent:shop', 'granted');
  const c = load('../src/lib/sellerPixels.ts', { window, localStorage, document: {} });
  c.exports.loadSellerPixels({ meta_pixel_id: '123456789012' }, 'shop');
  c.exports.trackSellerEvent('Purchase', { value: 48000, event_id: 'purchase_123' });
  assert.ok(calls.some((c) => c[0] === 'init' && c[1] === '123456789012'));
  assert.ok(calls.some((c) => c[0] === 'trackSingle' && c[1] === '123456789012' && c[2] === 'Purchase'));
  assert.equal(calls.some((c) => c[0] === 'track'), false);
});
