// Shared headless-Chrome helpers for the Hellom Page builder e2e scripts (CDP over Node's WebSocket).
// The editor's phone preview is a sandboxed iframe with an opaque origin, so Chrome runs it in its
// own process: inPreview() reaches it through an auto-attached target session.
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function openChrome(port, profile) {
  const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
    '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${process.env.TEMP}\\${profile}`, '--no-first-run', 'about:blank',
  ], { stdio: 'ignore' });
  let info;
  for (let i = 0; i < 40 && !info; i++) { try { info = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json(); } catch { await sleep(250); } }
  const ws = new WebSocket(info.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener('open', r));

  let id = 0;
  const pending = new Map();
  const errors = [];
  const frames = new Map(); // frameId → sessionId of out-of-process iframes
  const send = (method, params = {}, sessionId) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params, ...(sessionId ? { sessionId } : {}) })); });
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    if (m.method === 'Runtime.exceptionThrown' && !m.sessionId) errors.push((m.params.exceptionDetails.exception?.description ?? m.params.exceptionDetails.text).slice(0, 200));
    if (m.method === 'Page.javascriptDialogOpening') send('Page.handleJavaScriptDialog', { accept: true }); // window.confirm → yes
    if (m.method === 'Target.attachedToTarget' && m.params.targetInfo.type === 'iframe') frames.set(m.params.targetInfo.targetId, m.params.sessionId);
    if (m.method === 'Target.detachedFromTarget') for (const [k, v] of frames) if (v === m.params.sessionId) frames.delete(k);
  });
  await send('Page.enable');
  await send('Runtime.enable');
  await send('DOM.enable');
  await send('Target.setAutoAttach', { autoAttach: true, waitForDebuggerOnStart: false, flatten: true });

  const ev = async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })).result?.result?.value;
  const waitFor = async (expr, ms = 15000) => { const t = Date.now(); while (Date.now() - t < ms) { if (await ev(expr)) return true; await sleep(200); } return false; };

  /** Evaluate inside the visible preview iframe (two take turns; the visible one has the title). */
  const inPreview = async (expr) => {
    const box = await ev(`document.querySelector('iframe[title="Pratinjau halaman"]')?.getBoundingClientRect().toJSON() ?? null`);
    for (const [frameId, sessionId] of frames) {
      const owner = await send('DOM.getFrameOwner', { frameId });
      const node = owner.result?.backendNodeId && await send('DOM.resolveNode', { backendNodeId: owner.result.backendNodeId });
      const title = node?.result && await send('Runtime.callFunctionOn', { objectId: node.result.object.objectId, functionDeclaration: 'function () { return this.title; }', returnByValue: true });
      if (title?.result?.result?.value !== 'Pratinjau halaman') continue;
      const res = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }, sessionId);
      return { value: res.result?.result?.value ?? null, box };
    }
    return { value: null, box };
  };
  const waitPreview = async (expr, ms = 12000) => { const t = Date.now(); while (Date.now() - t < ms) { if ((await inPreview(expr)).value) return true; await sleep(300); } return false; };

  const tap = async (x, y) => {
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
  };
  const shotsDir = new URL('../../storage/app/e2e_shots/', import.meta.url);
  mkdirSync(shotsDir, { recursive: true });
  const shot = async (name) => { const s = await send('Page.captureScreenshot', { format: 'png' }); writeFileSync(new URL(`${name}.png`, shotsDir), Buffer.from(s.result.data, 'base64')); };

  return { send, ev, waitFor, inPreview, waitPreview, tap, shot, errors, close: () => chrome.kill() };
}

export function checker() {
  const results = [];
  const check = (name, ok, detail = '') => { results.push({ name, ok }); console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${ok ? '' : ' ' + detail}`); };
  const finish = () => {
    const passed = results.filter((r) => r.ok).length;
    console.log(`\n${passed}/${results.length} checks OK`);
    return passed === results.length ? 0 : 1;
  };
  return { check, finish };
}
