import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import ts from 'typescript';

// Exercise the component handlers with mocked network and browser boundaries.
function harness(context = 'guest', registerError = false) {
  const state = [], effects = [], navigation = [], sessions = [], registrations = [];
  let cursor = 0, initial = true, closed = 0;
  const params = new URLSearchParams('paket=pos_pro&cycle=yearly');
  const plan = { id: 1, slug: 'pos_pro', name: 'Pro', price: 52000, features: [], billing_cycles: ['monthly', 'yearly'] };
  const jsx = (type, props) => ({ type, props });
  const modules = {
    react: {
      useState(value) { const i = cursor++; if (!(i in state)) state[i] = value; return [state[i], v => { state[i] = typeof v === 'function' ? v(state[i]) : v; }]; },
      useEffect(fn) { if (initial) effects.push(fn); }, useMemo: fn => fn(),
    },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-router-dom': { useNavigate: () => path => navigation.push(path), useSearchParams: () => [params] },
    '@/lib/utils': { cn: () => '' },
    '@/services/api/client': { getToken: () => context === 'member' ? 'existing' : null, setSession: (...args) => sessions.push(args) },
    '@/services/api/auth': { register: async payload => { registrations.push(payload); if (registerError) throw new Error('Email sudah digunakan'); return { token: 'new-session', user: { id: 5 } }; } },
    '@/services/api/posCheckout': { getPosCheckoutOptions: async () => ({ plans: [plan], payment: { available: true, channels: [{ key: 'qris', label: 'QRIS', type: 'qris' }] } }) },
    '@/services/api/billing': {
      getPricingMatrix: async () => ({ items: [] }), getPaymentGatewayStatus: async () => ({}), getWalletOverview: async () => ({ wallet: {} }),
      checkoutStart: async () => ({ status_url: '/pembayaran/test-token', payment: {}, checkout_intent: {} }),
    },
    '@/lib/platformMarketing': { trackPlatformMetaEvent() {} },
  };
  const code = ts.transpileModule(readFileSync(new URL('../src/components/CheckoutModal.tsx', import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
  }).outputText;
  const sandbox = { exports: {}, require: name => modules[name] || {}, Error, URL, URLSearchParams, crypto: { randomUUID: () => 'event-id' },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    document: { body: { style: {} } }, Event: class {},
    window: { location: { origin: 'https://example.test' }, dispatchEvent() {}, setTimeout() {}, clearTimeout() {}, addEventListener() {}, removeEventListener() {} },
  };
  vm.runInNewContext(code, sandbox);
  const render = () => { cursor = 0; const tree = sandbox.exports.default({ isOpen: true, appName: 'POS', context, onClose: () => { closed++; } }); initial = false; return tree; };
  const nodes = tree => !tree || typeof tree !== 'object' ? [] : Array.isArray(tree) ? tree.flatMap(nodes) : [tree, ...nodes(tree.props?.children)];
  const text = tree => typeof tree === 'string' ? tree : Array.isArray(tree) ? tree.map(text).join('') : tree?.props ? text(tree.props.children) : '';
  const button = label => nodes(render()).find(n => n.type === 'button' && text(n).includes(label));
  const input = (autoComplete, value) => nodes(render()).find(n => n.type === 'input' && n.props.autoComplete === autoComplete).props.onChange({ target: { value } });
  const accept = () => nodes(render()).find(n => n.type === 'input' && n.props.type === 'checkbox').props.onChange({ target: { checked: true } });
  const flush = () => new Promise(resolve => setImmediate(resolve));
  return { render, nodes, text, button, input, accept, navigation, sessions, registrations, closed: () => closed,
    async init() { render(); effects.forEach(fn => fn()); await flush(); }, flush };
}

test('guest creates a session then opens payment methods in dashboard without closing to home', async () => {
  const h = harness(); await h.init();
  assert.equal(h.nodes(h.render()).some(n => n.props?.autoComplete === 'email'), false);
  h.button('Lanjut Isi Data Diri').props.onClick();
  for (const [field, value] of Object.entries({ name: 'Budi', email: 'budi@example.test', tel: '08123456789', organization: 'Kopi', 'new-password': 'secret123' })) h.input(field, value);
  h.accept(); h.button('Buat Akun & Lanjut Pembayaran').props.onClick();
  assert.ok(h.nodes(h.render()).some(n => n.props?.label?.includes('pembuatan akun')));
  await h.flush();
  assert.equal(h.sessions[0][0], 'new-session');
  assert.equal(h.registrations[0].phone, '08123456789');
  assert.deepEqual(h.navigation, ['/dashboard?checkout=pos&paket=pos_pro&cycle=yearly']);
  assert.equal(h.closed(), 0);
});

test('registration failure stays in checkout and shows the error', async () => {
  const h = harness('guest', true); await h.init(); h.button('Lanjut Isi Data Diri').props.onClick();
  for (const [field, value] of Object.entries({ name: 'Budi', email: 'budi@example.test', tel: '08123456789', organization: 'Kopi', 'new-password': 'secret123' })) h.input(field, value);
  h.accept(); h.button('Buat Akun & Lanjut Pembayaran').props.onClick(); await h.flush();
  assert.match(h.text(h.render()), /Email sudah digunakan/);
  assert.equal(h.navigation.length, 0); assert.equal(h.sessions.length, 0);
});

test('member payment navigation is not overwritten by the close callback', async () => {
  const h = harness('member'); await h.init();
  assert.match(h.text(h.render()), /Metode Pembayaran/);
  h.button('Lanjut ke Ringkasan').props.onClick(); h.accept();
  h.button('Bayar Sekarang').props.onClick(); await h.flush();
  assert.deepEqual(h.navigation, ['/pembayaran/test-token']); assert.equal(h.closed(), 0);
});
