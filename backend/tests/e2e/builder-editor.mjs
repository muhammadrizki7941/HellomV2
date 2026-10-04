// Hellom Page link-in-bio editor (Fase 2): add from the illustrated gallery, edit with a live
// server-rendered phone preview, tap the preview to select, hide / duplicate / delete, keyboard
// reorder, undo / redo, autosave, publish — at 1366 px, then the phone layout at 360 px.
// Needs Laravel :8010 + Vite :3010 on hellom_pos_test (README). Reseeds tests/e2e/builder-seed.php.
// Expect "17/17 checks OK". Screenshots: storage/app/e2e_shots/editor-*.png
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const { check, finish } = checker();

function seed() {
  execFileSync('php', ['tests/e2e/builder-seed.php'], { env: { ...process.env, DB_DATABASE: 'hellom_pos_test' } });
  return JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
}
const api = (s, path, init = {}) => fetch(`${API}${path}`, { ...init, headers: { Authorization: `Bearer ${s.token}`, Accept: 'application/json', 'Content-Type': 'application/json', ...(init.headers || {}) } }).then((r) => r.json());

const browser = await openChrome(9354, 'cdp-builder-editor');
const { send, ev, waitFor, inPreview, tap, errors } = browser;
const shot = (name) => browser.shot(`editor-${name}`);
const clickText = (text, scope = 'document') => ev(`(() => { const b = [...${scope}.querySelectorAll('button')].find((x) => x.innerText.trim() === ${JSON.stringify(text)} || x.getAttribute('aria-label') === ${JSON.stringify(text)}); b?.click(); return !!b; })()`);
const waitPreview = (text, want = true) => browser.waitPreview(`document.body.textContent.includes(${JSON.stringify(text)}) === ${want}`);
let exitCode = 1;
try {

  // ───────────── Desktop ─────────────
  let s = seed();
  await api(s, '/apps/landing-builder/editor-preference', { method: 'PUT', body: JSON.stringify({ preference: 'lynk', tour_done: true }) });
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 860, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' }); await sleep(1200);
  await ev(`localStorage.clear(); localStorage.setItem('hellom_token', ${JSON.stringify(s.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(s.user))}); true`);
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  const ready = await waitFor(`document.body.innerText.includes('Halaman masih kosong') && !!document.querySelector('iframe[title="Pratinjau halaman"]')`, 20000);
  await sleep(1500);
  await shot('desktop-empty');
  check('desktop: editor opens with an empty state and the phone preview', ready);

  // Add a link button from the illustrated gallery.
  await clickText('Tambah blok');
  const gallery = await waitFor(`!!document.querySelector('[data-block-type="button"]') && document.body.textContent.includes('Disarankan untukmu')`);
  await shot('desktop-gallery');
  await ev(`document.querySelector('[data-block-type="button"]').click(); true`);
  const settings = await waitFor(`[...document.querySelectorAll('aside h2')].some((h) => h.textContent === 'Tombol link')`);
  check('desktop: "+ Tambah" gallery → block added → its settings open', gallery && settings);

  // Type the button text: the preview (real page) follows.
  const typed = await ev(`(() => {
    const input = [...document.querySelectorAll('aside input[type="text"]')][0];
    if (!input) return false;
    const set = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
    for (const v of ['Pesan', 'Pesan lewat', 'Pesan lewat WhatsApp']) { set.call(input, v); input.dispatchEvent(new Event('input', { bubbles: true })); }
    // The link (a button without one is hidden on the public page).
    const url = [...document.querySelectorAll('aside input[type="text"]')].find((i) => i.value === '#');
    if (url) { set.call(url, 'https://example.com/pesan'); url.dispatchEvent(new Event('input', { bubbles: true })); }
    return true;
  })()`);
  const live = typed && await waitPreview('Pesan lewat WhatsApp');
  await shot('desktop-editing');
  check('desktop: typing updates the server-rendered preview', live);

  const saved = await waitFor(`document.querySelector('header [aria-live]')?.innerText.includes('Tersimpan')`, 10000);
  const pageId = (await api(s, '/apps/landing-builder/site')).data.pages[0].id;
  let draft = (await api(s, `/apps/landing-builder/site/pages/${pageId}/document`)).data.document;
  check('desktop: autosave stores the draft', saved && draft.blocks[0]?.content?.text === 'Pesan lewat WhatsApp', JSON.stringify(draft.blocks[0]?.content));

  // Undo: one step for the whole typing, then redo.
  await clickText('Urungkan');
  const undone = await waitPreview('Pesan lewat WhatsApp', false);
  await clickText('Ulangi');
  const redone = await waitPreview('Pesan lewat WhatsApp', true);
  check('desktop: undo / redo', undone && redone);

  // Back to the list, add an image and a text block.
  await clickText('Kembali ke daftar');
  for (const type of ['text', 'image']) {
    await clickText('Tambah blok');
    await waitFor(`!!document.querySelector('[data-block-type="${type}"]')`);
    await ev(`document.querySelector('[data-block-type="${type}"]').click(); true`);
    await sleep(400);
    await clickText('Kembali ke daftar');
  }
  const rows = await waitFor(`document.querySelectorAll('[aria-label^="Urutan"] > li').length === 3`);
  check('desktop: list shows the three blocks', rows);

  // Tap the button in the preview → its settings open.
  await sleep(1500);
  const target = await inPreview(`(() => { const el = document.querySelector('[data-hl-block] a, [data-hl-block] .btn'); if (!el) return null; el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);
  if (target.value && target.box) await tap(target.box.x + target.value.x, target.box.y + target.value.y);
  const tapped = await waitFor(`[...document.querySelectorAll('aside h2')].some((h) => h.textContent === 'Tombol link')`);
  check('desktop: tapping a block in the preview opens its settings', !!target.value && tapped, JSON.stringify(target));

  // Hide it from the panel header → gone from the preview; show again.
  await clickText('Sembunyikan');
  const hidden = await waitPreview('Pesan lewat WhatsApp', false);
  await clickText('Tampilkan');
  const shownAgain = await waitPreview('Pesan lewat WhatsApp', true);
  check('desktop: hide / show', hidden && shownAgain);

  // Duplicate, then delete the copy (confirm dialog accepted).
  await clickText('Duplikat');
  const four = await waitFor(`(() => { document.querySelector('[aria-label="Kembali ke daftar"]')?.click(); return document.querySelectorAll('[aria-label^="Urutan"] > li').length === 4; })()`);
  await ev(`document.querySelectorAll('[aria-label^="Urutan"] > li')[1].querySelector('[aria-label^="Menu"]').click(); true`);
  await clickText('Hapus');
  const three = await waitFor(`document.querySelectorAll('[aria-label^="Urutan"] > li').length === 3`);
  check('desktop: duplicate and delete', four && three);

  // Keyboard reorder: move the first block (button) down one place.
  await ev(`document.querySelectorAll('[aria-label^="Urutan"] > li')[0].querySelector('[aria-label^="Geser"]').focus(); true`);
  for (const key of [' ', 'ArrowDown', ' ']) {
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key, code: key === ' ' ? 'Space' : key, windowsVirtualKeyCode: key === ' ' ? 32 : 40 });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code: key === ' ' ? 'Space' : key, windowsVirtualKeyCode: key === ' ' ? 32 : 40 });
    await sleep(350);
  }
  await waitFor(`document.querySelector('header [aria-live]')?.innerText.includes('Tersimpan')`, 10000);
  await sleep(2500);
  draft = (await api(s, `/apps/landing-builder/site/pages/${pageId}/document`)).data.document;
  check('desktop: keyboard reorder is saved', draft.blocks.map((b) => b.type).join(',') === 'text,button,image', draft.blocks.map((b) => b.type).join(','));

  // Publish.
  await clickText('Terbitkan');
  const published = await waitFor(`document.body.innerText.includes('Halaman terbit!')`, 15000);
  const publicHtml = await (await fetch(`http://127.0.0.1:8010/${s.username}`)).text();
  await shot('desktop-published');
  check('desktop: publish → live page shows the button', published && publicHtml.includes('Pesan lewat WhatsApp') && !publicHtml.includes('data-hl-block'));

  // ───────────── Phone 360 px ─────────────
  s = seed();
  await api(s, '/apps/landing-builder/editor-preference', { method: 'PUT', body: JSON.stringify({ preference: 'linktree', tour_done: true }) });
  await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 760, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: APP + '/' }); await sleep(1200);
  await ev(`localStorage.clear(); localStorage.setItem('hellom_token', ${JSON.stringify(s.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(s.user))}); true`);
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  const phone = await waitFor(`!!document.querySelector('nav[aria-label="Alat editor"]') && !!document.querySelector('iframe[title="Pratinjau halaman"]')`, 20000);
  await sleep(1500);
  const noScroll = await ev(`document.documentElement.scrollWidth <= innerWidth + 1`);
  await shot('phone-empty');
  check('phone: full preview + bottom bar, no sideways scroll', phone && noScroll);

  await ev(`document.querySelector('nav[aria-label="Alat editor"] [data-tour="add"]').click(); true`);
  const sheetGallery = await waitFor(`!!document.querySelector('[role="dialog"] [data-block-type="button"]')`);
  await shot('phone-gallery');
  await ev(`document.querySelector('[role="dialog"] [data-block-type="button"]').click(); true`);
  const sheetSettings = await waitFor(`[...document.querySelectorAll('[role="dialog"] h2')].some((h) => h.textContent === 'Tombol link')`);
  await shot('phone-settings');
  check('phone: gallery sheet → settings sheet', sheetGallery && sheetSettings);

  await ev(`[...document.querySelectorAll('[role="dialog"] button[aria-label="Tutup"]')].pop().click(); true`);
  await sleep(300);
  await ev(`document.querySelector('nav[aria-label="Alat editor"] [data-tour="list"]').click(); true`);
  const listSheet = await waitFor(`document.querySelectorAll('[role="dialog"] [aria-label^="Urutan"] > li').length === 1 && document.body.textContent.includes('Daftar link')`);
  await shot('phone-list');
  check('phone: list sheet with the Linktree words', listSheet);

  // Tap the block in the preview → settings sheet.
  await ev(`[...document.querySelectorAll('[role="dialog"] button[aria-label="Tutup"]')].pop().click(); true`);
  await sleep(1500);
  const btn = await inPreview(`(() => { const el = document.querySelector('[data-hl-block] a, [data-hl-block] .btn'); if (!el) return null; const r = el.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);
  if (btn.value && btn.box) await tap(btn.box.x + btn.value.x, btn.box.y + btn.value.y);
  const phoneTap = await waitFor(`[...document.querySelectorAll('[role="dialog"] h2')].some((h) => h.textContent === 'Tombol link')`);
  check('phone: tapping the preview opens the settings sheet', !!btn.value && phoneTap, JSON.stringify(btn));

  // Typing then the keyboard's delete key: focus stays in the field, text is deleted, sheet stays
  // open (regression: every edit re-ran the sheet's focus effect and pulled focus to the panel).
  await ev(`(() => { const i = document.querySelector('[role="dialog"] input[type="text"]'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); return true; })()`);
  for (const ch of ' baru') { await send('Input.insertText', { text: ch }); await sleep(120); }
  await sleep(600);
  for (let i = 0; i < 3; i++) {
    await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 });
    await sleep(250);
  }
  const typing = await ev(`(() => { const a = document.activeElement; const sheet = [...document.querySelectorAll('[role="dialog"] h2')].some((h) => h.textContent === 'Tombol link');
    return { focused: a?.tagName === 'INPUT' && !!a.closest('[role="dialog"]'), value: a?.value ?? null, sheet }; })()`);
  check('phone: typing + keyboard delete edits the text and keeps the sheet open', typing?.focused && typing.sheet && (typing.value ?? '').endsWith(' b'), JSON.stringify(typing));

  check('no script errors', errors.length === 0, JSON.stringify(errors));
} finally {
  browser.close();
  execFileSync('php', ['tests/e2e/builder-seed.php', 'cleanup'], { env: { ...process.env, DB_DATABASE: 'hellom_pos_test' } });
  exitCode = finish();
}
process.exit(exitCode);
