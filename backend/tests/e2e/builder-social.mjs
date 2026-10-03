// Fase 4 Sosial media panel: add accounts (live check), an invalid one is explained and not shown,
// position/colour options, tap on the icon row in the preview opens the panel, import from the old
// "Ikon sosial media" block, published page, phone bottom sheet. Needs Laravel :8010 + Vite :3010
// on hellom_pos_test. Expect "7/7 checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const env = { ...process.env, DB_DATABASE: 'hellom_pos_test' };
const { check, finish } = checker();
execFileSync('php', ['tests/e2e/builder-seed.php'], { env });
const s = JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
const headers = { Authorization: `Bearer ${s.token}`, Accept: 'application/json', 'Content-Type': 'application/json' };
await fetch(`${API}/apps/landing-builder/editor-preference`, { method: 'PUT', headers, body: JSON.stringify({ preference: 'lynk', tour_done: true }) });
// A page with a profile and an OLD social block (before Fase 4).
const page = (await (await fetch(`${API}/apps/landing-builder/site/pages`, { method: 'POST', headers, body: JSON.stringify({ title: 'Beranda' }) })).json()).data;
const draft = (await (await fetch(`${API}/apps/landing-builder/site/pages/${page.id}/document`, { headers })).json()).data;
await fetch(`${API}/apps/landing-builder/site/pages/${page.id}/document`, { method: 'PUT', headers, body: JSON.stringify({ revision: draft.revision, document: { ...draft.document, blocks: [
  { id: 'p1', type: 'profile', hidden: false, content: { name: 'Toko Kue Bu Ani', bio: 'Kue rumahan' }, styles: {} },
  { id: 'old', type: 'social', hidden: false, content: { tiktok: 'https://www.tiktok.com/@kuebuani', youtube: '' }, styles: {} },
] } }) });

const browser = await openChrome(9358, 'cdp-builder-social');
const { send, ev, waitFor, inPreview, tap, errors } = browser;
const clickText = (text) => ev(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim() === ${JSON.stringify(text)} || x.getAttribute('aria-label') === ${JSON.stringify(text)}); b?.click(); return !!b; })()`);
const type = (selector, value) => ev(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, ${JSON.stringify(value)}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);

let exitCode = 1;
try {
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(s.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(s.user))}); true`);
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  await waitFor(`!!document.querySelector('[data-tour="social"]')`, 20000);
  await ev(`document.querySelector('[data-tour="social"]').click(); true`);

  // Import the old block's TikTok.
  const offered = await waitFor(`document.body.textContent.includes('Ambil ke panel ini')`);
  await clickText('Ambil ke panel ini');
  const imported = await waitFor(`document.querySelector('#social-tiktok')?.value === 'https://www.tiktok.com/@kuebuani'`);
  check('old social block is offered and imported', offered && imported);

  // Instagram + WhatsApp (valid), X (invalid → explained).
  for (const [platform, value] of [['instagram', '@toko.kue'], ['whatsapp', '0812 3456 7890'], ['x', 'bukan valid!']]) {
    await ev(`document.querySelector('[data-platform="${platform}"]').click(); true`);
    await waitFor(`!!document.querySelector('#social-${platform}')`);
    await type(`#social-${platform}`, value);
  }
  const hints = await waitFor(`document.querySelector('#social-instagram-hint')?.textContent.includes('https://www.instagram.com/toko.kue')
    && document.querySelector('#social-whatsapp-hint')?.textContent.includes('https://wa.me/6281234567890')
    && document.querySelector('#social-x-hint')?.textContent.includes('Belum dikenali')`);
  check('live check: ✓ for Instagram/WhatsApp, explanation for an invalid X', hints);

  // Above the profile, brand colours.
  await clickText('Di atas profil');
  await clickText('Warna asli');
  const row = await browser.waitPreview(`(() => {
    const nav = document.querySelector('nav.social-row.brand');
    if (!nav) return false;
    const hrefs = [...nav.querySelectorAll('a')].map((a) => a.getAttribute('href'));
    const h1 = document.querySelector('h1');
    return hrefs.join(',') === 'https://www.tiktok.com/@kuebuani,https://www.instagram.com/toko.kue,https://wa.me/6281234567890'
      && !!(nav.compareDocumentPosition(h1) & Node.DOCUMENT_POSITION_FOLLOWING);
  })()`);
  await browser.shot('social-desktop');
  check('preview: icon row above the profile, brand colours, invalid X not shown', row);

  // Tap the row in the preview → the panel opens (from the block list).
  await clickText('Kembali ke daftar');
  await waitFor(`!!document.querySelector('[data-tour="social"]')`);
  await sleep(1500);
  const icon = await inPreview(`(() => { const a = document.querySelector('nav.social-row a'); if (!a) return null; const r = a.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);
  if (icon.value && icon.box) await tap(icon.box.x + icon.value.x, icon.box.y + icon.value.y);
  const opened = await waitFor(`!!document.querySelector('#social-instagram')`);
  check('tapping the icon row in the preview opens the panel', !!icon.value && opened, JSON.stringify(icon));

  // Publish → the live page shows the same row.
  await waitFor(`document.querySelector('header [aria-live]')?.textContent.includes('Tersimpan')`, 10000);
  await clickText('Terbitkan');
  await waitFor(`document.body.textContent.includes('Halaman terbit!')`, 15000);
  const html = await (await fetch(`http://127.0.0.1:8010/${s.username}`)).text();
  check('published page: icons with links, X dropped, clicks tracked', html.includes('href="https://www.instagram.com/toko.kue"') && html.includes('data-label="WhatsApp"') && !html.includes('x.com/'));

  // Phone: bottom bar → Sosial sheet.
  await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 780, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  await waitFor(`!!document.querySelector('nav[aria-label="Alat editor"] [data-tour="social"]')`, 20000);
  await ev(`document.querySelector('nav[aria-label="Alat editor"] [data-tour="social"]').click(); true`);
  const sheet = await waitFor(`!!document.querySelector('[role="dialog"] #social-instagram') && document.documentElement.scrollWidth <= innerWidth + 1`);
  await browser.shot('social-phone');
  check('phone: Sosial in the bottom bar opens the panel sheet (no sideways scroll)', sheet);
  check('no script errors', errors.length === 0, JSON.stringify(errors));
} finally {
  browser.close();
  execFileSync('php', ['tests/e2e/builder-seed.php', 'cleanup'], { env });
  exitCode = finish();
}
process.exit(exitCode);
