// Super admin › Pengaturan › Tim admin: invite (password confirm) → pending list; the invitee opens
// /undangan-admin on a 360 px phone, creates a password and lands in the admin dashboard as super
// admin. Needs Laravel :8010 + Vite :3010 on hellom_pos_test (README). Expect "4/4 checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const env = { ...process.env, DB_DATABASE: 'hellom_pos_test' };
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env }).toString().trim().split('\n').pop();
const { check, finish } = checker();
execFileSync('php', ['tests/e2e/builder-seed.php'], { env });
const s = JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
// Known password for the seeded super admin (the form asks for it).
tinker(`App\\Models\\User::query()->where('email', '${s.admin_user.email}')->update(['password' => bcrypt('rahasia-admin-123')]); echo 'ok';`);
const INVITEE = 'e2e-calon-admin@example.test';
const cleanupInvitee = () => tinker(`$ids = App\\Models\\User::query()->where('email', '${INVITEE}')->pluck('id'); DB::table('api_tokens')->whereIn('user_id', $ids)->delete(); DB::table('audit_logs')->whereIn('user_id', $ids)->delete(); App\\Models\\PlatformAdminInvitation::query()->where('email', '${INVITEE}')->delete(); App\\Models\\User::query()->whereIn('id', $ids)->delete(); echo 'ok';`);
cleanupInvitee();

const browser = await openChrome(9364, 'cdp-admin-team');
const { send, ev, waitFor, errors } = browser;
const setVal = (sel, v) => ev(`(() => { const el = document.querySelector(${JSON.stringify(sel)}); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, ${JSON.stringify(v)}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);
const clickText = (text) => ev(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim().includes(${JSON.stringify(text)})); b?.click(); return !!b; })()`);

let exitCode = 1;
try {
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(s.admin_token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(s.admin_user))}); true`);
  await send('Page.navigate', { url: APP + '/admin/settings' });
  await waitFor(`[...document.querySelectorAll('button')].some((b) => b.textContent.includes('Tim admin'))`, 20000);
  await clickText('Tim admin');
  const listed = await waitFor(`!!document.querySelector('[data-admin="${s.admin_user.email}"]') && document.querySelector('[data-admin="${s.admin_user.email}"]').textContent.includes('Kamu')`, 15000);
  check('Tim admin tab loads (no 403) and lists you as admin', listed);

  await clickText('Undang admin');
  await waitFor(`!!document.querySelector('[data-invite-form]')`);
  await setVal('[data-invite-form] input[type="email"]', INVITEE);
  await setVal('[data-invite-form] input[type="password"]', 'salah-password');
  await clickText('Kirim undangan');
  const wrong = await waitFor(`document.body.textContent.includes('Password kamu salah')`, 10000);
  await setVal('[data-invite-form] input[type="password"]', 'rahasia-admin-123');
  await clickText('Kirim undangan');
  const sent = await waitFor(`!!document.querySelector('[data-invitation="${INVITEE}"]') && document.body.textContent.includes('Undangan dikirim ke ${INVITEE}')`, 10000);
  await browser.shot('admin-team');
  check('invite: wrong password refused, then sent and listed as pending', wrong && sent, JSON.stringify({ wrong, sent }));

  // The emailed link carries a token we cannot read here: give the pending invitation a known one.
  const token = 'e2e' + 'x'.repeat(45);
  tinker(`App\\Models\\PlatformAdminInvitation::query()->where('email', '${INVITEE}')->whereNull('accepted_at')->update(['token_hash' => hash('sha256', '${token}')]); echo 'ok';`);

  await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 780, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.clear(); true`);
  await send('Page.navigate', { url: `${APP}/undangan-admin?token=${token}` });
  const page = await waitFor(`document.body.textContent.includes('Jadi admin Hellom') && document.body.textContent.includes('${INVITEE}')`, 15000);
  await setVal('#adm-name', 'Calon Admin');
  await setVal('#adm-password', 'admin-baru-123');
  await setVal('#adm-confirm', 'admin-baru-123');
  const noScroll = await ev(`document.documentElement.scrollWidth <= innerWidth + 1`);
  await browser.shot('admin-invite-phone');
  await clickText('Buat akun & terima');
  const landed = await waitFor(`location.pathname.startsWith('/admin') && JSON.parse(localStorage.getItem('hellom_user') || '{}').role === 'super_admin'`, 15000);
  check('invite page on 360 px: create password → signed in as super admin in /admin', page && noScroll && landed, JSON.stringify({ page, noScroll, landed }));
  const role = tinker(`echo App\\Models\\User::query()->where('email', '${INVITEE}')->value('role');`);
  check('database: invitee is super_admin; no script errors', role === 'super_admin' && errors.length === 0, JSON.stringify({ role, errors }));
} finally {
  browser.close();
  cleanupInvitee();
  execFileSync('php', ['tests/e2e/builder-seed.php', 'cleanup'], { env });
  exitCode = finish();
}
process.exit(exitCode);
