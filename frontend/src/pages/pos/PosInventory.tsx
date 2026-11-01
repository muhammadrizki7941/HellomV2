import { useCallback, useEffect, useMemo, useState } from 'react';
import { ArrowLeftRight, ClipboardList, History, PackagePlus, Plus, RefreshCw, Save, Search, Tags } from 'lucide-react';
import JsBarcode from 'jsbarcode';
import {
  createPosProductVariant,
  createPosPurchase,
  createPosStocktake,
  createPosStockTransfer,
  createPosSupplier,
  getPosOutlets,
  getPosProducts,
  getPosStockMovements,
  getPosSuppliers,
  getPosWarrantyLookup,
  updatePosProductPriceTiers,
  type PosOutlet,
  type PosProductRecord,
  type PosProductVariantRecord,
  type PosWarrantyRecord,
} from '@/lib/hellomApi';
import { useBusinessProfile } from '@/hooks/useBusinessProfile';

type Tab = 'variants' | 'purchase' | 'stocktake' | 'transfer' | 'history' | 'warranty';
type Supplier = { id: number; name: string; phone: string | null };
type Movement = {
  id: number;
  movement_type: string;
  qty: number;
  note: string | null;
  created_at: string;
  product?: { name: string; sku: string | null } | null;
  variant?: { name: string } | null;
};
type TierDraft = { label: string; min_quantity: string; customer_type: string; price: string };
type StockItem = {
  key: string;
  product: PosProductRecord;
  variantId: number | null;
  name: string;
  stock: number;
};

const formatRp = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const parseSerials = (value: string) => value.split(/[\n,;]+/).map((part) => part.trim()).filter(Boolean);
const inputClass = 'w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-lime-500 focus:outline-none focus:ring-2 focus:ring-lime-200';
const labelClass = 'block text-sm font-semibold text-zinc-800';
const buttonClass = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-lime-400 px-4 py-2 text-sm font-semibold text-zinc-950 transition hover:bg-lime-300 disabled:cursor-not-allowed disabled:opacity-50';

const TAB_ITEMS: Array<{ key: Tab; label: string; icon: typeof Tags }> = [
  { key: 'variants', label: 'Varian & harga jual', icon: Tags },
  { key: 'purchase', label: 'Barang masuk', icon: PackagePlus },
  { key: 'stocktake', label: 'Stok opname', icon: ClipboardList },
  { key: 'transfer', label: 'Transfer outlet', icon: ArrowLeftRight },
  { key: 'history', label: 'Riwayat stok', icon: History },
  { key: 'warranty', label: 'Pencarian garansi', icon: Search },
];

const TAB_GUIDANCE: Record<Tab, { title: string; description: string }> = {
  variants: {
    title: 'Atur pilihan dan harga jual',
    description: 'Pilih produk terlebih dahulu. Tambahkan varian bila barang punya pilihan seperti warna/ukuran, lalu atur harga eceran dan grosir bila fitur harga bertingkat aktif.',
  },
  purchase: {
    title: 'Catat barang yang baru dibeli',
    description: 'Pilih produk, isi jumlah dan harga beli per unit. Untuk produk ber-IMEI, jumlah nomor serial harus sama dengan jumlah barang.',
  },
  stocktake: {
    title: 'Cocokkan stok fisik dengan catatan',
    description: 'Isi jumlah yang benar-benar ada di outlet. Sistem hanya mencatat selisih sebagai penyesuaian; riwayat stok sebelumnya tetap tersimpan.',
  },
  transfer: {
    title: 'Pindahkan stok antar-outlet',
    description: 'Pilih outlet tujuan, produk, dan jumlah. Stok berkurang di outlet asal dan bertambah di outlet tujuan dalam satu transfer.',
  },
  history: {
    title: 'Lihat perubahan stok',
    description: 'Gunakan filter produk untuk mencari barang masuk, penjualan, retur, transfer, dan penyesuaian stok tertentu.',
  },
  warranty: {
    title: 'Cari informasi garansi',
    description: 'Cari berdasarkan IMEI/serial, nomor transaksi, atau nomor HP pelanggan untuk melihat detail penjualan dan masa garansi.',
  },
};

export default function PosInventory() {
  const { hasModule, terminology } = useBusinessProfile();
  const [products, setProducts] = useState<PosProductRecord[]>([]);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [outlets, setOutlets] = useState<PosOutlet[]>([]);
  const [activeOutletId, setActiveOutletId] = useState<number | null>(null);
  const [movements, setMovements] = useState<Movement[]>([]);
  const [warranties, setWarranties] = useState<PosWarrantyRecord[]>([]);
  const [warrantySearchType, setWarrantySearchType] = useState<'serial_number' | 'order_number' | 'customer_phone'>('serial_number');
  const [warrantyQuery, setWarrantyQuery] = useState('');
  const [tab, setTab] = useState<Tab>('variants');
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [productId, setProductId] = useState('');
  const [movementProductId, setMovementProductId] = useState('');
  const [variantForm, setVariantForm] = useState({
    name: '', sku: '', barcode: '', price: '', track_stock: true, stock: '0',
    attributes: '',
  });
  const [tiers, setTiers] = useState<TierDraft[]>([
    { label: 'Eceran', min_quantity: '1', customer_type: '', price: '' },
    { label: 'Grosir', min_quantity: '6', customer_type: 'grosir', price: '' },
  ]);
  const [supplierForm, setSupplierForm] = useState({ name: '', phone: '' });
  const [purchaseForm, setPurchaseForm] = useState({
    supplier_id: '', product_id: '', product_variant_id: '', qty: '1', unit_cost: '', serials: '', reference_number: '',
  });
  const [stockCounts, setStockCounts] = useState<Record<string, string>>({});
  const [transferForm, setTransferForm] = useState({ destination_outlet_id: '', product_id: '', product_variant_id: '', qty: '1', serials: '' });

  const loadData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [productResponse, outletResponse] = await Promise.all([getPosProducts(), getPosOutlets()]);
      setProducts(productResponse.products);
      setOutlets(outletResponse.outlets);
      setActiveOutletId(Number(outletResponse.active_outlet_id) || null);
      setProductId((current) => current || String(productResponse.products[0]?.id ?? ''));
      if (hasModule('suppliers_purchasing')) {
        const supplierResponse = await getPosSuppliers();
        setSuppliers(supplierResponse.suppliers);
      }
      if (tab === 'history') {
        const movementResponse = await getPosStockMovements({
          product_id: movementProductId ? Number(movementProductId) : undefined,
          per_page: 100,
        });
        setMovements(movementResponse.movements.data as Movement[]);
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Data inventori gagal dimuat.');
    } finally {
      setLoading(false);
    }
  }, [hasModule, movementProductId, tab]);

  useEffect(() => { void loadData(); }, [loadData]);

  const stockItems = useMemo<StockItem[]>(() => products.flatMap<StockItem>((product): StockItem[] => {
    if (product.has_variants && product.variants?.length) {
      return product.variants.filter((variant) => variant.track_stock).map((variant) => ({
        key: `${product.id}:${variant.id}`,
        product,
        variantId: variant.id,
        name: `${product.name} · ${variant.name}`,
        stock: variant.stock ?? 0,
      }));
    }
    return product.track_stock ? [{
      key: `${product.id}:product`,
      product,
      variantId: null,
      name: product.name,
      stock: product.stock ?? 0,
    }] : [];
  }), [products]);
  const lowStockItems = stockItems.filter((item) => item.stock <= Number(item.product.min_stock ?? 0));
  const selectedProduct = products.find((product) => String(product.id) === productId) ?? null;
  const purchaseProduct = products.find((product) => String(product.id) === purchaseForm.product_id) ?? null;
  const transferProduct = products.find((product) => String(product.id) === transferForm.product_id) ?? null;
  const enabledTabs = TAB_ITEMS.filter(({ key }) =>
    (key !== 'purchase' || hasModule('suppliers_purchasing'))
    && (key !== 'stocktake' || hasModule('stock_opname'))
    && (key !== 'warranty' || hasModule('warranty'))
    && (key !== 'variants' || hasModule('variants') || hasModule('price_tiers'))
  );

  useEffect(() => {
    if (!enabledTabs.some(({ key }) => key === tab)) {
      setTab(enabledTabs[0]?.key ?? 'history');
    }
  }, [enabledTabs, tab]);

  useEffect(() => {
    setTiers(selectedProduct?.price_tiers?.length
      ? selectedProduct.price_tiers.map((tier) => ({
        label: tier.label,
        min_quantity: String(tier.min_quantity),
        customer_type: tier.customer_type ?? '',
        price: String(tier.price),
      }))
      : [
        { label: 'Eceran', min_quantity: '1', customer_type: '', price: '' },
        { label: 'Grosir', min_quantity: '6', customer_type: 'grosir', price: '' },
      ]);
  }, [selectedProduct]);

  const run = async (action: () => Promise<unknown>, success: string) => {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await action();
      setNotice(success);
      await loadData();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Aksi belum berhasil. Periksa data lalu coba lagi.');
    } finally {
      setBusy(false);
    }
  };

  const saveVariant = () => {
    if (!selectedProduct) return;
    let attributes: Record<string, string>;
    try {
      attributes = Object.fromEntries(
        variantForm.attributes
          .split(/[,\n;]+/)
          .map((entry) => entry.trim())
          .filter(Boolean)
          .map((entry) => {
            const separator = entry.indexOf('=');
            if (separator < 1 || separator === entry.length - 1) {
              throw new Error('Gunakan format nama=nilai.');
            }
            return [entry.slice(0, separator).trim(), entry.slice(separator + 1).trim()];
          })
      );
    } catch {
      setError('Detail varian harus memakai format nama=nilai, misalnya warna=Hitam, memori=128 GB.');
      return;
    }
    void run(() => createPosProductVariant(selectedProduct.id, {
      name: variantForm.name,
      sku: variantForm.sku || null,
      barcode: variantForm.barcode || null,
      price: variantForm.price ? Number(variantForm.price) : null,
      track_stock: variantForm.track_stock,
      stock: Number(variantForm.stock || 0),
      attributes,
    }), 'Varian berhasil disimpan.');
  };

  const saveTiers = () => {
    if (!selectedProduct) return;
    const data = tiers
      .filter((tier) => tier.label.trim() && tier.price !== '')
      .map((tier) => ({
        label: tier.label.trim(),
        min_quantity: Number(tier.min_quantity),
        customer_type: tier.customer_type.trim() || null,
        price: Number(tier.price),
      }));
    if (data.length < 2) {
      setError('Isi minimal dua tingkat harga, misalnya eceran dan grosir.');
      return;
    }
    void run(() => updatePosProductPriceTiers(selectedProduct.id, data), 'Harga bertingkat berhasil disimpan.');
  };

  const saveSupplier = () => {
    if (!supplierForm.name.trim()) {
      setError('Nama supplier wajib diisi.');
      return;
    }
    void run(async () => {
      await createPosSupplier(supplierForm);
      setSupplierForm({ name: '', phone: '' });
    }, 'Supplier berhasil ditambahkan.');
  };

  const savePurchase = () => {
    if (!purchaseForm.product_id) {
      setError(`Pilih ${terminology.product.toLowerCase()} yang dibeli.`);
      return;
    }
    const quantity = Number(purchaseForm.qty);
    const serials = parseSerials(purchaseForm.serials);
    if (purchaseProduct?.track_serial && serials.length !== quantity) {
      setError(`Masukkan tepat ${quantity} nomor serial/IMEI, satu nomor per baris.`);
      return;
    }
    void run(() => createPosPurchase({
      supplier_id: purchaseForm.supplier_id ? Number(purchaseForm.supplier_id) : null,
      reference_number: purchaseForm.reference_number || null,
      items: [{
        product_id: Number(purchaseForm.product_id),
        product_variant_id: purchaseForm.product_variant_id ? Number(purchaseForm.product_variant_id) : null,
        qty: quantity,
        unit_cost: Number(purchaseForm.unit_cost),
        serial_numbers: serials,
      }],
    }), 'Barang masuk dan pergerakan stok berhasil dicatat.');
  };

  const saveStocktake = () => {
    const items = stockItems.map((stockItem) => ({
      product_id: stockItem.product.id,
      ...(stockItem.variantId ? { product_variant_id: stockItem.variantId } : {}),
      counted_qty: Number(stockCounts[stockItem.key] ?? stockItem.stock),
      note: 'Stok opname dari POS',
    }));
    if (!items.length) {
      setError('Belum ada produk dengan pelacakan stok aktif.');
      return;
    }
    void run(() => createPosStocktake({ notes: 'Stok opname', items }), 'Stok opname disimpan sebagai pergerakan penyesuaian.');
  };

  const saveTransfer = () => {
    if (!transferForm.product_id || !transferForm.destination_outlet_id) {
      setError('Pilih outlet tujuan dan produk yang akan dipindahkan.');
      return;
    }
    const quantity = Number(transferForm.qty);
    const serials = parseSerials(transferForm.serials);
    if (transferProduct?.track_serial && serials.length !== quantity) {
      setError(`Masukkan tepat ${quantity} nomor serial/IMEI untuk transfer.`);
      return;
    }
    void run(() => createPosStockTransfer({
      destination_outlet_id: Number(transferForm.destination_outlet_id),
      client_uuid: crypto.randomUUID(),
      created_at_client: new Date().toISOString(),
      items: [{
        product_id: Number(transferForm.product_id),
        product_variant_id: transferForm.product_variant_id ? Number(transferForm.product_variant_id) : null,
        qty: quantity,
        serial_numbers: serials,
      }],
    }), 'Transfer stok berhasil dicatat.');
  };

  const searchWarranties = async () => {
    if (!warrantyQuery.trim()) {
      setError('Masukkan IMEI/serial, nomor transaksi, atau nomor HP pelanggan.');
      return;
    }
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const response = await getPosWarrantyLookup({ [warrantySearchType]: warrantyQuery.trim() });
      setWarranties(response.warranties);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Pencarian garansi gagal.');
    } finally {
      setBusy(false);
    }
  };

  const saveLabel = () => {
    const labelProducts = products.filter((product) => product.barcode || product.sku);
    if (!labelProducts.length) {
      setError('Isi barcode atau SKU pada produk sebelum mencetak label.');
      return;
    }
    const documentContent = labelProducts.map((product) => {
      const code = product.barcode || product.sku || '';
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      JsBarcode(svg, code, { format: 'CODE128', height: 36, displayValue: false, margin: 0 });
      return `<article><strong>${escapeHtml(product.name)}</strong>${svg.outerHTML}<small>${escapeHtml(code)}</small></article>`;
    }).join('');
    const printWindow = window.open('', '_blank');
    if (!printWindow) {
      setError('Jendela cetak diblokir browser. Izinkan pop-up lalu coba lagi.');
      return;
    }
    printWindow.document.write(`<!doctype html><html><head><title>Label Barcode</title><style>
      body{font-family:Arial,sans-serif;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:8px}
      article{height:90px;border:1px dashed #aaa;padding:8px;text-align:center;display:flex;flex-direction:column;justify-content:center;gap:4px}
      strong,small{font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}svg{height:40px;width:100%}
      @media print{body{padding:0;gap:4px}article{break-inside:avoid}}
      </style></head><body>${documentContent}<script>window.onload=()=>window.print()</script></body></html>`);
    printWindow.document.close();
  };

  if (loading && products.length === 0) {
    return <div className="p-6 text-sm text-zinc-600">Memuat inventori...</div>;
  }

  return (
    <div className="space-y-5 p-4 pb-24 md:p-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-semibold text-lime-700">Inventori POS</p>
          <h1 className="text-2xl font-bold text-zinc-950">Stok & harga {terminology.product.toLowerCase()}</h1>
          <p className="mt-1 max-w-2xl text-sm leading-6 text-zinc-700">Pilih fitur di bawah sesuai kebutuhan. Perubahan stok dicatat sebagai riwayat, sehingga catatan lama tidak tertimpa.</p>
        </div>
        <button type="button" className={buttonClass} onClick={() => void loadData()}>
          <RefreshCw className="h-4 w-4" /> Muat ulang
        </button>
      </header>

      {error && <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{error}</div>}
      {notice && <div role="status" className="rounded-xl border border-lime-200 bg-lime-50 p-3 text-sm text-lime-900">{notice}</div>}
      {lowStockItems.length > 0 && (
        <section className="rounded-2xl border border-amber-200 bg-amber-50 p-4" aria-label="Peringatan stok menipis">
          <h2 className="font-bold text-amber-950">Stok menipis · {lowStockItems.length} item</h2>
          <ul className="mt-2 grid gap-1 text-sm text-amber-900 sm:grid-cols-2 lg:grid-cols-3">
            {lowStockItems.slice(0, 9).map((item) => (
              <li key={item.key}>{item.name}: {item.stock} / minimum {item.product.min_stock ?? 0}</li>
            ))}
          </ul>
        </section>
      )}

      <nav className="flex gap-2 overflow-x-auto pb-1" aria-label="Fitur inventori">
        {enabledTabs.map(({ key, label, icon: Icon }) => (
          <button key={key} type="button" onClick={() => setTab(key)}
            aria-pressed={tab === key}
            className={`inline-flex shrink-0 items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-semibold transition-colors ${tab === key ? 'bg-zinc-950 text-white' : 'bg-white text-zinc-800 ring-1 ring-zinc-300 hover:bg-zinc-50'}`}>
            <Icon className="h-4 w-4" />{label}
          </button>
        ))}
        {hasModule('barcode') && <button className="inline-flex shrink-0 items-center gap-2 rounded-xl bg-white px-3 py-2.5 text-sm font-semibold text-zinc-800 ring-1 ring-zinc-300 hover:bg-zinc-50" onClick={saveLabel}>Cetak label barcode</button>}
      </nav>

      <aside className="rounded-xl border border-sky-200 bg-sky-50 p-4">
        <h2 className="text-sm font-bold text-sky-950">{TAB_GUIDANCE[tab].title}</h2>
        <p className="mt-1 text-sm leading-6 text-sky-900">{TAB_GUIDANCE[tab].description}</p>
      </aside>

      {tab === 'variants' && (
        <section className={`grid gap-5 ${hasModule('variants') && hasModule('price_tiers') ? 'xl:grid-cols-2' : ''}`}>
          {hasModule('variants') && (
            <div className="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 md:p-5">
            <h2 className="font-bold text-zinc-950">Varian produk</h2>
            <label className={labelClass}>Pilih produk
              <select className={`${inputClass} mt-1`} value={productId} onChange={(event) => setProductId(event.target.value)}>
                {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
              </select>
            </label>
            {selectedProduct?.variants?.length ? (
              <div className="overflow-x-auto rounded-xl border border-zinc-200">
                <table className="w-full min-w-[420px] text-left text-sm">
                  <thead className="bg-zinc-50 text-xs uppercase text-zinc-500"><tr><th className="p-3">Nama</th><th className="p-3">SKU</th><th className="p-3">Harga</th><th className="p-3">Stok</th></tr></thead>
                  <tbody>{selectedProduct.variants.map((variant: PosProductVariantRecord) => (
                    <tr key={variant.id} className="border-t border-zinc-100"><td className="p-3 font-medium">{variant.name}</td><td className="p-3">{variant.sku || '—'}</td><td className="p-3">{formatRp(variant.price ?? selectedProduct.price)}</td><td className="p-3">{variant.track_stock ? variant.stock ?? 0 : 'Tidak dibatasi'}</td></tr>
                  ))}</tbody>
                </table>
              </div>
            ) : <p className="text-sm text-zinc-500">Belum ada varian untuk produk ini.</p>}
            {hasModule('variants') && (
              <div className="grid gap-3 sm:grid-cols-2">
                <p className="text-sm leading-6 text-zinc-600 sm:col-span-2">Contoh varian: warna hitam, ukuran L, atau tipe HP tertentu. SKU dan barcode boleh dikosongkan jika belum ada.</p>
                <label className={labelClass}>Nama varian<input className={`${inputClass} mt-1`} value={variantForm.name} onChange={(event) => setVariantForm({ ...variantForm, name: event.target.value })} placeholder="Contoh: Hitam / 128 GB" /></label>
                <label className={labelClass}>SKU (opsional)<input className={`${inputClass} mt-1`} value={variantForm.sku} onChange={(event) => setVariantForm({ ...variantForm, sku: event.target.value })} placeholder="Kode internal barang" /></label>
                <label className={labelClass}>Barcode (opsional)<input className={`${inputClass} mt-1`} value={variantForm.barcode} onChange={(event) => setVariantForm({ ...variantForm, barcode: event.target.value })} placeholder="Scan atau ketik barcode" /></label>
                <label className={labelClass}>Harga jual (Rp)<input className={`${inputClass} mt-1`} type="number" min="0" inputMode="numeric" value={variantForm.price} onChange={(event) => setVariantForm({ ...variantForm, price: event.target.value })} placeholder="Kosongkan untuk ikut harga produk" /></label>
                <label className={labelClass}>Stok awal<input className={`${inputClass} mt-1`} type="number" min="0" inputMode="numeric" value={variantForm.stock} onChange={(event) => setVariantForm({ ...variantForm, stock: event.target.value })} /></label>
                <label className={`${labelClass} sm:col-span-2`}>Detail tambahan (opsional)
                  <textarea className={`${inputClass} mt-1`} rows={2} value={variantForm.attributes} onChange={(event) => setVariantForm({ ...variantForm, attributes: event.target.value })} placeholder="warna=Hitam, memori=128 GB" />
                  <span className="mt-1 block text-xs font-normal leading-5 text-zinc-600">Pisahkan beberapa detail dengan koma. Boleh dikosongkan jika nama varian sudah cukup.</span>
                </label>
                <button className={`${buttonClass} sm:col-span-2`} disabled={busy || !variantForm.name.trim()} onClick={saveVariant}><Plus className="h-4 w-4" /> Tambah varian</button>
              </div>
            )}
            </div>
          )}

          {hasModule('price_tiers') && (
            <div className="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 md:p-5">
              <h2 className="font-bold text-zinc-950">Harga eceran & grosir</h2>
              <label className={labelClass}>Produk yang sama
                <select className={`${inputClass} mt-1`} value={productId} onChange={(event) => setProductId(event.target.value)}>
                  {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                </select>
              </label>
              <p className="text-sm leading-6 text-zinc-600">Setiap tingkat berlaku mulai dari jumlah minimum. Isi nama, jumlah minimal, dan harga per unit. Contoh: grosir mulai 6 pcs.</p>
              <div className="space-y-2">
                {tiers.map((tier, index) => (
                  <div key={index} className="grid gap-2 rounded-xl bg-zinc-50 p-3 sm:grid-cols-4">
                    <label className={labelClass}>Nama tingkat<input className={`${inputClass} mt-1`} placeholder="Contoh: Eceran" value={tier.label} onChange={(event) => setTiers(tiers.map((row, i) => i === index ? { ...row, label: event.target.value } : row))} /></label>
                    <label className={labelClass}>Minimal jumlah<input className={`${inputClass} mt-1`} type="number" min="1" value={tier.min_quantity} onChange={(event) => setTiers(tiers.map((row, i) => i === index ? { ...row, min_quantity: event.target.value } : row))} /></label>
                    <label className={labelClass}>Tipe pelanggan (opsional)<input className={`${inputClass} mt-1`} placeholder="Contoh: grosir" value={tier.customer_type} onChange={(event) => setTiers(tiers.map((row, i) => i === index ? { ...row, customer_type: event.target.value } : row))} /></label>
                    <label className={labelClass}>Harga per unit (Rp)<input className={`${inputClass} mt-1`} type="number" min="0" inputMode="numeric" placeholder="Contoh: 15000" value={tier.price} onChange={(event) => setTiers(tiers.map((row, i) => i === index ? { ...row, price: event.target.value } : row))} /></label>
                  </div>
                ))}
              </div>
              <div className="flex flex-wrap gap-2">
                <button className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-zinc-300 px-4 text-sm font-semibold" onClick={() => setTiers([...tiers, { label: '', min_quantity: '1', customer_type: '', price: '' }])}><Plus className="h-4 w-4" /> Tambah tingkat</button>
                <button className={buttonClass} disabled={busy || !selectedProduct} onClick={saveTiers}><Save className="h-4 w-4" /> Simpan harga</button>
              </div>
              <p className="text-xs leading-5 text-zinc-600">Margin (harga jual dikurangi harga beli) hanya ditampilkan di laporan untuk owner/admin.</p>
            </div>
          )}
        </section>
      )}

      {tab === 'purchase' && hasModule('suppliers_purchasing') && (
        <section className="grid gap-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(260px,0.7fr)]">
          <div className="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 md:p-5">
            <h2 className="font-bold text-zinc-950">Catat barang masuk</h2>
            <div className="grid gap-3 sm:grid-cols-2">
              <label className={labelClass}>Supplier (opsional)<select className={`${inputClass} mt-1`} value={purchaseForm.supplier_id} onChange={(event) => setPurchaseForm({ ...purchaseForm, supplier_id: event.target.value })}><option value="">Tanpa supplier</option>{suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select></label>
              <label className={labelClass}>Nomor nota (opsional)<input className={`${inputClass} mt-1`} value={purchaseForm.reference_number} onChange={(event) => setPurchaseForm({ ...purchaseForm, reference_number: event.target.value })} placeholder="Nomor nota pembelian" /></label>
              <label className={labelClass}>{terminology.product}<select className={`${inputClass} mt-1`} value={purchaseForm.product_id} onChange={(event) => setPurchaseForm({ ...purchaseForm, product_id: event.target.value, product_variant_id: '' })}><option value="">Pilih {terminology.product.toLowerCase()}</option>{products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</select></label>
              {purchaseProduct?.variants?.length ? <label className={labelClass}>Varian<select className={`${inputClass} mt-1`} value={purchaseForm.product_variant_id} onChange={(event) => setPurchaseForm({ ...purchaseForm, product_variant_id: event.target.value })}><option value="">Pilih varian</option>{purchaseProduct.variants.map((variant) => <option key={variant.id} value={variant.id}>{variant.name}</option>)}</select></label> : null}
              <label className={labelClass}>Jumlah barang<input className={`${inputClass} mt-1`} type="number" min="1" inputMode="numeric" value={purchaseForm.qty} onChange={(event) => setPurchaseForm({ ...purchaseForm, qty: event.target.value })} /></label>
              <label className={labelClass}>Harga beli per barang (Rp)<input className={`${inputClass} mt-1`} type="number" min="0" inputMode="numeric" value={purchaseForm.unit_cost} onChange={(event) => setPurchaseForm({ ...purchaseForm, unit_cost: event.target.value })} placeholder="Contoh: 12000" /></label>
              {purchaseProduct?.track_serial && <label className={`${labelClass} sm:col-span-2`}>Nomor serial/IMEI (satu nomor per baris)<textarea className={`${inputClass} mt-1`} rows={4} value={purchaseForm.serials} onChange={(event) => setPurchaseForm({ ...purchaseForm, serials: event.target.value })} placeholder={'IMEI-001\nIMEI-002'} /><span className="mt-1 text-xs font-normal text-zinc-600">Jumlah nomor harus sama dengan jumlah barang yang dimasukkan.</span></label>}
            </div>
            <button className={buttonClass} disabled={busy || !purchaseForm.product_id || !purchaseForm.unit_cost} onClick={savePurchase}><PackagePlus className="h-4 w-4" /> Simpan barang masuk</button>
          </div>
          <div className="space-y-3 rounded-2xl border border-zinc-200 bg-white p-4 md:p-5">
            <h2 className="font-bold text-zinc-950">Tambah supplier</h2>
            <label className={labelClass}>Nama supplier<input className={`${inputClass} mt-1`} value={supplierForm.name} onChange={(event) => setSupplierForm({ ...supplierForm, name: event.target.value })} placeholder="Nama toko/distributor pemasok" /></label>
            <label className={labelClass}>Nomor telepon (opsional)<input className={`${inputClass} mt-1`} value={supplierForm.phone} onChange={(event) => setSupplierForm({ ...supplierForm, phone: event.target.value })} placeholder="08xxxxxxxxxx" /></label>
            <button className={buttonClass} disabled={busy || !supplierForm.name.trim()} onClick={saveSupplier}><Plus className="h-4 w-4" /> Tambah supplier</button>
            <ul className="divide-y divide-zinc-100 text-sm">{suppliers.map((supplier) => <li key={supplier.id} className="py-2">{supplier.name}{supplier.phone ? ` · ${supplier.phone}` : ''}</li>)}</ul>
          </div>
        </section>
      )}

      {tab === 'stocktake' && hasModule('stock_opname') && (
        <section className="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 md:p-5">
          <div><h2 className="font-bold text-zinc-950">Hitung stok fisik</h2><p className="mt-1 text-sm text-zinc-600">Selisih akan dicatat sebagai movement penyesuaian, tidak mengganti saldo secara langsung.</p></div>
          <div className="space-y-2">
            {stockItems.map((stockItem) => (
              <label key={stockItem.key} className="grid grid-cols-[minmax(0,1fr)_120px] items-center gap-3 rounded-xl border border-zinc-100 p-3 sm:grid-cols-[minmax(0,1fr)_150px_170px]">
                <span className="min-w-0"><span className="block truncate font-semibold text-zinc-900">{stockItem.name}</span><span className="text-xs text-zinc-500">Sistem: {stockItem.stock} {stockItem.product.unit || 'pcs'}</span></span>
                <span className="hidden text-right text-xs text-zinc-500 sm:block">Minimum: {stockItem.product.min_stock ?? 0}</span>
                <input className={inputClass} type="number" min="0" inputMode="numeric" aria-label={`Jumlah fisik ${stockItem.name}`} value={stockCounts[stockItem.key] ?? stockItem.stock} onChange={(event) => setStockCounts({ ...stockCounts, [stockItem.key]: event.target.value })} />
              </label>
            ))}
            {stockItems.length === 0 && <p className="rounded-xl bg-zinc-50 p-4 text-sm text-zinc-600">Tidak ada produk dengan stok yang dilacak.</p>}
          </div>
          <button className={buttonClass} disabled={busy || stockItems.length === 0} onClick={saveStocktake}><ClipboardList className="h-4 w-4" /> Simpan hasil opname</button>
        </section>
      )}

      {tab === 'transfer' && (
        <section className="max-w-3xl space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 md:p-5">
          <div><h2 className="font-bold text-zinc-950">Transfer antar-outlet</h2><p className="mt-1 text-sm text-zinc-600">Outlet tujuan harus memiliki SKU produk (dan SKU varian) yang sama.</p></div>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className={labelClass}>Outlet tujuan<select className={`${inputClass} mt-1`} value={transferForm.destination_outlet_id} onChange={(event) => setTransferForm({ ...transferForm, destination_outlet_id: event.target.value })}><option value="">Pilih outlet tujuan</option>{outlets.filter((outlet) => outlet.id !== activeOutletId).map((outlet) => <option key={outlet.id} value={outlet.id}>{outlet.name}</option>)}</select></label>
            <label className={labelClass}>{terminology.product}<select className={`${inputClass} mt-1`} value={transferForm.product_id} onChange={(event) => setTransferForm({ ...transferForm, product_id: event.target.value, product_variant_id: '' })}><option value="">Pilih {terminology.product.toLowerCase()}</option>{products.map((product) => <option key={product.id} value={product.id}>{product.name} {product.sku ? `(${product.sku})` : '(tanpa SKU)'}</option>)}</select></label>
            {transferProduct?.variants?.length ? <label className={labelClass}>Varian<select className={`${inputClass} mt-1`} value={transferForm.product_variant_id} onChange={(event) => setTransferForm({ ...transferForm, product_variant_id: event.target.value })}><option value="">Pilih varian</option>{transferProduct.variants.map((variant) => <option key={variant.id} value={variant.id}>{variant.name} {variant.sku ? `(${variant.sku})` : ''}</option>)}</select></label> : null}
            <label className={labelClass}>Jumlah barang<input className={`${inputClass} mt-1`} type="number" min="1" inputMode="numeric" value={transferForm.qty} onChange={(event) => setTransferForm({ ...transferForm, qty: event.target.value })} /></label>
            {transferProduct?.track_serial && <label className={`${labelClass} sm:col-span-2`}>IMEI/serial yang dipindahkan<textarea className={`${inputClass} mt-1`} rows={4} value={transferForm.serials} onChange={(event) => setTransferForm({ ...transferForm, serials: event.target.value })} placeholder="Satu nomor per baris" /><span className="mt-1 text-xs font-normal text-zinc-600">Jumlah nomor harus sama dengan jumlah barang.</span></label>}
          </div>
          <button className={buttonClass} disabled={busy || !transferForm.destination_outlet_id || !transferProduct?.sku} onClick={saveTransfer}><ArrowLeftRight className="h-4 w-4" /> Transfer stok</button>
        </section>
      )}

      {tab === 'history' && (
        <section className="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
          <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 className="font-bold text-zinc-950">Riwayat pergerakan stok</h2><p className="text-xs text-zinc-500">Maksimal 100 catatan terbaru di outlet aktif.</p></div>
            <div className="flex gap-2">
              <select aria-label="Filter riwayat berdasarkan produk" value={movementProductId} onChange={(event) => setMovementProductId(event.target.value)} className="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm sm:min-w-52">
                <option value="">Semua produk</option>
                {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
              </select>
              <button className="rounded-lg border border-zinc-300 p-2" onClick={() => void loadData()} aria-label="Muat ulang riwayat"><RefreshCw className="h-4 w-4" /></button>
            </div>
          </div>
          <div className="overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="bg-zinc-50 text-xs uppercase text-zinc-500"><tr><th className="p-3">Waktu</th><th className="p-3">Produk</th><th className="p-3">Jenis</th><th className="p-3">Jumlah</th><th className="p-3">Catatan</th></tr></thead><tbody>
            {movements.map((movement) => <tr key={movement.id} className="border-t border-zinc-100"><td className="p-3 whitespace-nowrap">{new Date(movement.created_at).toLocaleString('id-ID')}</td><td className="p-3">{movement.product?.name ?? 'Produk dihapus'}{movement.variant?.name ? ` · ${movement.variant.name}` : ''}</td><td className="p-3 capitalize">{movement.movement_type.replaceAll('_', ' ')}</td><td className={`p-3 font-semibold ${movement.qty < 0 ? 'text-rose-700' : 'text-emerald-700'}`}>{movement.qty > 0 ? '+' : ''}{movement.qty}</td><td className="p-3">{movement.note || '—'}</td></tr>)}
            {movements.length === 0 && <tr><td className="p-5 text-center text-zinc-500" colSpan={5}>Belum ada pergerakan stok.</td></tr>}
          </tbody></table></div>
        </section>
      )}
      {tab === 'warranty' && (
        <section className="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 md:p-5">
          <div>
            <h2 className="font-bold text-zinc-950">Cari garansi penjualan</h2>
            <p className="mt-1 text-sm text-zinc-600">Cari berdasarkan IMEI/serial, nomor transaksi, atau nomor HP pelanggan.</p>
          </div>
          <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto]">
            <select className={inputClass} value={warrantySearchType} onChange={(event) => {
              const value = event.target.value;
              if (value === 'serial_number' || value === 'order_number' || value === 'customer_phone') setWarrantySearchType(value);
            }}>
              <option value="serial_number">IMEI / serial</option>
              <option value="order_number">Nomor transaksi</option>
              <option value="customer_phone">Nomor HP pelanggan</option>
            </select>
            <input className={inputClass} value={warrantyQuery} onChange={(event) => setWarrantyQuery(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') void searchWarranties(); }} placeholder="Masukkan kata pencarian" />
            <button type="button" className={buttonClass} disabled={busy} onClick={() => void searchWarranties()}><Search className="h-4 w-4" />Cari</button>
          </div>
          {warranties.length > 0 ? (
            <ul className="divide-y divide-zinc-100 rounded-xl border border-zinc-200">
              {warranties.map((row, index) => {
                const isCovered = Boolean(row.warranty_expires_at && row.warranty_expires_at >= new Date().toISOString().slice(0, 10));
                return (
                  <li key={`${row.order_number}-${row.serial_number ?? index}`} className="grid gap-1 p-3 text-sm sm:grid-cols-2">
                    <span className="font-semibold text-zinc-950">{row.product_name}{row.serial_number ? ` · ${row.serial_number}` : ''}</span>
                    <span className={isCovered ? 'font-medium text-emerald-700' : 'font-medium text-zinc-500'}>
                      {row.warranty_expires_at ? `${isCovered ? 'Berlaku sampai' : 'Berakhir'} ${new Date(`${row.warranty_expires_at}T00:00:00`).toLocaleDateString('id-ID')}` : 'Tidak ada masa garansi'}
                    </span>
                    <span className="text-zinc-600">Transaksi {row.order_number} · {new Date(row.created_at).toLocaleDateString('id-ID')}</span>
                    <span className="text-zinc-600">{row.customer_name || 'Pelanggan'}{row.customer_phone ? ` · ${row.customer_phone}` : ''}</span>
                  </li>
                );
              })}
            </ul>
          ) : warrantyQuery && !busy ? (
            <p className="rounded-xl bg-zinc-50 p-4 text-sm text-zinc-600">Tidak ada data penjualan garansi yang cocok.</p>
          ) : null}
        </section>
      )}
    </div>
  );
}

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[character] ?? character);
}
