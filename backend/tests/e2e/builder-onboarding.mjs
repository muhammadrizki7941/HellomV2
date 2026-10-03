// Hellom Page builder onboarding (link-in-bio, Fase 1): "Sebelumnya pakai apa?" on the first
// visit, the shop wizard only after it, the editor speaks the chosen preset (Linktree → "link"),
// the block gallery starts with links, and the tour can be finished and is remembered.
// Needs Laravel :8010 + Vite :3010 on hellom_pos_test and a fresh tests/e2e/builder-seed.php.
// Expect "8/8 checks OK" (4 per viewport). Screenshots: storage/app/e2e_shots/onboarding-*.png
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const SHOTS = new URL('../../storage/app/e2e_shots/', import.meta.url);
mkdirSync(SHOTS, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok }); console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${ok ? '' : ' ' + detail}`); };

const PORT = 9353;
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${process.env.TEMP}\\cdp-builder-onboarding`, '--no-first-run', 'about:blank'], { stdio: 'ignore' });
try {
  let info; for (let i = 0; i < 40 && !info; i++) { try { info = await (await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' })).json(); } catch { await sleep(250); } }
  const ws = new WebSocket(info.webSocketDebuggerUrl); await new Promise((r) => ws.addEventListener('open', r));
  let id = 0; const pend = new Map(); const errors = [];
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id); }
    if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description ?? m.params.exceptionDetails.text);
  });
  const send = (method, params = {}) => new Promise((r) => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
  const ev = async (x) => (await send('Runtime.evaluate', { expression: x, returnByValue: true, awaitPromise: true })).result?.result?.value;
  const waitFor = async (x, ms = 20000) => { const t = Date.now(); while (Date.now() - t < ms) { if (await ev(x)) return true; await sleep(200); } return false; };
  const click = (text, scope = 'document') => ev(`(() => { const b = [...${scope}.querySelectorAll('button')].find((x) => x.innerText.trim().startsWith(${JSON.stringify(text)})); b?.click(); return !!b; })()`);
  const shot = async (name) => { const s = await send('Page.captureScreenshot', { format: 'png' }); writeFileSync(new URL(`onboarding-${name}.png`, SHOTS), Buffer.from(s.result.data, 'base64')); };
  await send('Page.enable'); await send('Runtime.enable');

  for (const [width, mobile] of [[1366, false], [360, true]]) {
    // Fresh seller for each viewport: no answer yet, page not published.
    execFileSync('php', ['tests/e2e/builder-seed.php'], { env: { ...process.env, DB_DATABASE: 'hellom_pos_test' } });
    const seed = JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
    await send('Emulation.setDeviceMetricsOverride', { width, height: 800, deviceScaleFactor: 1, mobile });
    await send('Page.navigate', { url: APP + '/' }); await sleep(1200);
    await ev(`localStorage.clear(); localStorage.setItem('hellom_token', ${JSON.stringify(seed.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(seed.user))}); true`);
    await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder' });

    const asked = await waitFor(`document.body.innerText.includes('Sebelumnya kamu terbiasa pakai apa?')`);
    const wizardHidden = await ev(`!document.getElementById('onboarding-title')`);
    await shot(`question-${width}`);
    await ev(`[...document.querySelectorAll('[role="radio"]')].find((b) => b.innerText.includes('Linktree'))?.click(); true`);
    await click('Lanjut');
    // The shop wizard (username/template/product) comes after the answer, with the matching template marked.
    const wizard = await waitFor(`!!document.getElementById('onboarding-title')`);
    check(`${width}px: question first, then the shop wizard`, asked && wizardHidden && wizard, JSON.stringify({ asked, wizardHidden, wizard }));
    await ev(`document.querySelector('[role="dialog"] button[aria-label="Tutup"]')?.click(); true`);

    // Editor in "Linktree" words, with the tour.
    await waitFor(`[...document.querySelectorAll('button')].some((b) => /^\\s*Editor\\s*$/.test(b.innerText))`);
    await ev(`[...document.querySelectorAll('button')].find((b) => /^\\s*Editor\\s*$/.test(b.innerText))?.click(); true`);
    const tour = await waitFor(`document.body.innerText.includes('Langkah 1 dari')`);
    const words = await ev(`document.body.innerText.includes('Tambah link')`);
    await shot(`tour-${width}`);
    check(`${width}px: editor uses the preset words and starts the tour`, tour && words, JSON.stringify({ tour, words }));

    let steps = 0;
    while (steps < 8 && await ev(`document.body.innerText.includes('Langkah ')`)) {
      steps++;
      if (!(await click('Lanjut')) && !(await click('Mulai'))) break;
      await sleep(250);
    }
    const saved = await (await fetch(`${API}/apps/landing-builder/editor-preference`, { headers: { Authorization: `Bearer ${seed.token}`, Accept: 'application/json' } })).json();
    check(`${width}px: tour finished (${steps} steps) and remembered`, saved.data?.preference === 'linktree' && saved.data?.tour_done === true && !(await ev(`document.body.innerText.includes('Langkah ')`)), JSON.stringify(saved.data));

    // "+ Tambah" gallery: links first for a Linktree user.
    await ev(`[...document.querySelectorAll('[data-tour="add"]')].find((b) => b.getBoundingClientRect().width > 0)?.click(); true`);
    await waitFor(`!!document.querySelector('[data-block-type]')`);
    const first = await ev(`document.querySelector('[data-block-type]')?.getAttribute('data-block-type') ?? null`);
    await shot(`gallery-${width}`);
    check(`${width}px: block gallery starts with links ("${first}")`, first === 'button' && errors.length === 0, JSON.stringify({ first, errors }));
  }
} finally {
  chrome.kill();
  const passed = results.filter((r) => r.ok).length;
  console.log(`\n${passed}/${results.length} checks OK`);
  process.exit(passed === results.length ? 0 : 1);
}
