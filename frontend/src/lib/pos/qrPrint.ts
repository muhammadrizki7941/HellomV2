// Printable sheet of table QR codes (one card per table) in a new window.
import QRCode from 'qrcode';

type PrintableTable = { code: string; name: string | null; url: string };

const escapeHtml = (value: string) =>
  value.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] as string));

export async function printTableQrSheet(options: { title: string; subtitle?: string | null; tables: PrintableTable[] }): Promise<boolean> {
  // Open the window first (inside the click) so pop-up blockers allow it.
  const win = window.open('', '_blank');
  if (!win) return false;
  win.document.write('<p style="font-family:sans-serif;padding:24px">Menyiapkan QR…</p>');

  const cards = await Promise.all(
    options.tables.map(async (table) => {
      const dataUrl = await QRCode.toDataURL(table.url, { width: 360, margin: 1 });
      const label = table.name ? `${table.code} · ${table.name}` : table.code;
      return `<div class="card"><img src="${dataUrl}" alt="QR ${escapeHtml(label)}"/><div class="label">${escapeHtml(label)}</div><div class="hint">Scan untuk pesan</div></div>`;
    })
  );

  win.document.open();
  win.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(options.title)}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: system-ui, sans-serif; margin: 16px; color: #111; }
  h1 { font-size: 18px; margin: 0; } p.sub { margin: 4px 0 16px; color: #555; font-size: 12px; }
  .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
  .card { border: 1px dashed #999; border-radius: 12px; padding: 12px; text-align: center; page-break-inside: avoid; }
  .card img { width: 100%; max-width: 200px; }
  .label { font-weight: 700; font-size: 16px; margin-top: 6px; }
  .hint { font-size: 11px; color: #666; }
  @media print { .noprint { display: none; } body { margin: 0; } }
</style></head><body>
<div class="noprint" style="margin-bottom:12px"><button onclick="window.print()">Cetak</button></div>
<h1>${escapeHtml(options.title)}</h1>${options.subtitle ? `<p class="sub">${escapeHtml(options.subtitle)}</p>` : ''}
<div class="grid">${cards.join('')}</div>
</body></html>`);
  win.document.close();
  win.focus();
  window.setTimeout(() => win.print(), 400);

  return true;
}
