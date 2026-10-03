import { Link2, Package } from 'lucide-react';
import { useSellerProducts } from '../sellerProducts';

// Links a product block to a Hellom Page product (content.productId = product public id).
// The public page then shows the product's current price/stock/image from the database
// and "Beli" goes to its checkout page. The copied name/price/image only feed the editor preview.
const plainText = (html: string | null) => {
  if (!html) return '';
  const div = document.createElement('div');
  div.innerHTML = html;
  return (div.textContent ?? '').trim();
};

export default function LinkedProductPicker({ content, onPatch }: { content: Record<string, any>; onPatch: (changes: Record<string, unknown>) => void }) {
  const { products: all, error } = useSellerProducts();
  // "Produk fisik" / "Produk digital" cards offer only that kind (old blocks: everything).
  const kind = content.kind as 'digital' | 'physical' | undefined;
  const products = all?.filter((p) => !kind || (kind === 'physical' ? p.type === 'physical' : p.type !== 'physical'));
  const linked = all?.find((p) => p.id === content.productId);

  const choose = (publicId: string) => {
    if (!publicId) {
      onPatch({ productId: null });
      return;
    }
    const p = products?.find((x) => x.id === publicId);
    if (!p) return;
    onPatch({
      productId: p.id,
      name: p.name,
      price: `Rp ${p.price.toLocaleString('id-ID')}`,
      description: plainText(p.description).slice(0, 300),
      imageUrl: p.image_url ?? content.imageUrl ?? '',
      // Old inline delivery link is not used once linked; drop it from the block.
      fileUrl: '',
    });
  };

  return (
    <div className="space-y-2 rounded-xl border border-yellow-200 bg-yellow-50 p-3">
      <label className="flex items-center gap-2 text-xs font-bold text-zinc-800"><Link2 className="h-4 w-4" /> Produk yang dijual</label>
      {error && <p className="text-xs text-rose-600">{error}</p>}
      <select value={content.productId || ''} onChange={(e) => choose(e.target.value)} className="min-h-11 w-full rounded-lg border border-zinc-300 bg-white px-2 text-base">
        <option value="">— Pilih produk —</option>
        {products?.map((p) => (
          <option key={p.id} value={p.id}>{p.name} · Rp {p.price.toLocaleString('id-ID')}{!p.is_active ? ' (disembunyikan)' : ''}</option>
        ))}
      </select>
      {products && products.length === 0 && <p className="text-xs text-zinc-600">Belum ada {kind === 'physical' ? 'produk fisik' : kind === 'digital' ? 'produk digital' : 'produk'}. Tambahkan dulu di tab <span className="font-semibold">Produk</span>.</p>}
      {content.productId && !linked && products && <p className="text-xs text-amber-700">Produk ini sudah dihapus. Pilih produk lain.</p>}
      {linked ? (
        <p className="text-xs text-zinc-600">Harga, gambar, dan stok di halaman publik selalu mengikuti produk ini. Ubah produknya di tab <span className="font-semibold">Produk</span>.</p>
      ) : (
        <p className="flex items-start gap-1 text-xs text-zinc-600"><Package className="mt-0.5 h-3.5 w-3.5 shrink-0" /> Hubungkan ke produk agar pembeli dapat halaman checkout lengkap, kupon, dan link akses otomatis.</p>
      )}
    </div>
  );
}
