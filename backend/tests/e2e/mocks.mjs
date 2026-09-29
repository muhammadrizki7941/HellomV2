// Local stand-ins for the outside world during the Fase 5 journey (never real services):
//  - iPaymu sandbox API on :8020 (/api/v2/payment, /api/v2/payment/direct, /api/v2/transaction)
//    plus a "sandbox payment page" /pay/:sid whose button sends the notify webhook like iPaymu;
//  - SMTP sink on :1025 capturing every email; GET :8020/mails lists them (JSON).
import http from 'node:http';
import net from 'node:net';
import { randomBytes } from 'node:crypto';

const sessions = new Map(); // sid → { trxId, referenceId, amount, notifyUrl, returnUrl, status }
const byTrx = new Map();
const mails = [];

const readBody = (req) => new Promise((resolve) => { let b = ''; req.on('data', (c) => { b += c; }); req.on('end', () => resolve(b)); });
const send = (res, status, body, type = 'application/json') => { res.writeHead(status, { 'Content-Type': type }); res.end(typeof body === 'string' ? body : JSON.stringify(body)); };

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1:8020');
  const raw = req.method === 'POST' ? await readBody(req) : '';

  if (url.pathname === '/api/v2/payment' || url.pathname === '/api/v2/payment/direct') {
    const p = JSON.parse(raw || '{}');
    const sid = 'sid-' + randomBytes(6).toString('hex');
    const trxId = String(100000 + sessions.size + Math.floor(Math.random() * 1000));
    const amount = Array.isArray(p.price) ? p.price.reduce((s, v, i) => s + Number(v) * Number(p.qty?.[i] ?? 1), 0) : Number(p.amount);
    const s = { sid, trxId, referenceId: p.referenceId, amount, notifyUrl: p.notifyUrl, returnUrl: p.returnUrl, status: 0 };
    sessions.set(sid, s); byTrx.set(trxId, s);
    console.log(`[ipaymu] ${url.pathname} ref=${p.referenceId} amount=${amount}`);
    return send(res, 200, { Status: 200, Success: true, Message: 'success', Data: { SessionID: sid, TransactionId: trxId, Url: `http://127.0.0.1:8020/pay/${sid}`, QrString: 'MOCKQR' + trxId } });
  }
  if (url.pathname === '/api/v2/transaction') {
    const { transactionId } = JSON.parse(raw || '{}');
    const s = byTrx.get(String(transactionId));
    if (!s) return send(res, 404, { Status: 404, Success: false, Message: 'not found' });
    console.log(`[ipaymu] check trx=${transactionId} status=${s.status}`);
    return send(res, 200, { Status: 200, Success: true, Data: { TransactionId: s.trxId, SessionId: s.sid, ReferenceId: s.referenceId, Amount: s.amount, Fee: 4500,
      Status: s.status, StatusDesc: s.status === 1 ? 'Berhasil' : 'Pending', PaymentMethod: 'va', PaymentChannel: 'bca' } });
  }
  const pay = url.pathname.match(/^\/pay\/([\w-]+)$/);
  if (pay) {
    const s = sessions.get(pay[1]);
    if (!s) return send(res, 404, 'unknown session', 'text/plain');
    if (req.method === 'GET') {
      return send(res, 200, `<!doctype html><meta name="viewport" content="width=device-width"><title>iPaymu Sandbox (mock)</title>
<body style="font-family:sans-serif;padding:24px"><h1>iPaymu Sandbox</h1><p>Virtual Account BCA · Rp ${s.amount.toLocaleString('id-ID')}</p>
<form method="post"><button id="pay" style="min-height:48px;padding:0 24px">Bayar sekarang</button></form></body>`, 'text/html');
    }
    s.status = 1;
    const form = new URLSearchParams({ trx_id: s.trxId, sid: s.sid, reference_id: s.referenceId, status: 'berhasil', status_code: '1', via: 'va', channel: 'bca', amount: String(s.amount) });
    const hook = await fetch(s.notifyUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, body: form });
    console.log(`[ipaymu] webhook → ${hook.status} ${(await hook.text()).slice(0, 160)}`);
    res.writeHead(302, { Location: s.returnUrl }); return res.end();
  }
  if (url.pathname === '/mails') return send(res, 200, mails);
  send(res, 404, { message: 'mock: not found' });
}).listen(8020, '127.0.0.1', () => console.log('[mock] iPaymu on :8020'));

// Minimal SMTP sink (no STARTTLS/AUTH advertised).
net.createServer((sock) => {
  let data = false; let buf = ''; let mail = { from: '', to: [], raw: '' };
  sock.write('220 e2e-smtp ready\r\n');
  sock.on('data', (chunk) => {
    buf += chunk.toString('utf8');
    while (true) {
      if (data) {
        const end = buf.indexOf('\r\n.\r\n');
        if (end < 0) return;
        mail.raw = buf.slice(0, end); buf = buf.slice(end + 5); data = false;
        const subject = (mail.raw.match(/^Subject: (.*)$/mi) || [])[1] || '';
        mails.push({ ...mail, subject, at: new Date().toISOString() });
        console.log(`[smtp] to=${mail.to.join(',')} subject=${subject}`);
        mail = { from: '', to: [], raw: '' };
        sock.write('250 OK queued\r\n');
        continue;
      }
      const nl = buf.indexOf('\r\n');
      if (nl < 0) return;
      const line = buf.slice(0, nl); buf = buf.slice(nl + 2);
      const cmd = line.slice(0, 4).toUpperCase();
      if (cmd === 'EHLO' || cmd === 'HELO') sock.write('250-e2e-smtp\r\n250 8BITMIME\r\n');
      else if (cmd === 'MAIL') { mail.from = line; sock.write('250 OK\r\n'); }
      else if (cmd === 'RCPT') { mail.to.push((line.match(/<(.*)>/) || [])[1] || line); sock.write('250 OK\r\n'); }
      else if (cmd === 'DATA') { data = true; sock.write('354 End with <CRLF>.<CRLF>\r\n'); }
      else if (cmd === 'QUIT') { sock.end('221 Bye\r\n'); return; }
      else sock.write('250 OK\r\n');
    }
  });
}).listen(1025, '127.0.0.1', () => console.log('[mock] SMTP on :1025'));
