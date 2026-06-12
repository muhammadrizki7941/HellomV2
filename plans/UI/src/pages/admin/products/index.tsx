import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle, EyeOff, Plus, Trash2 } from 'lucide-react';
import { deleteProduct, getAdminProducts, publishProduct, unpublishProduct } from '@/lib/hellomApi';

type Product = {
  id: number;
  name: string;
  category: string;
  type: string;
  price: number;
  is_published: boolean;
  total_downloads: number;
};

export default function AdminProducts() {
  const [items, setItems] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadProducts = async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await getAdminProducts();
      const payload = response as { data?: Product[] };
      setItems(payload.data || []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat produk');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadProducts();
  }, []);

  const handlePublish = async (product: Product) => {
    try {
      if (product.is_published) {
        await unpublishProduct(product.id);
      } else {
        await publishProduct(product.id);
      }
      await loadProducts();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal mengubah status');
    }
  };

  const handleDelete = async (product: Product) => {
    if (!window.confirm('Hapus produk ini?')) return;
    try {
      await deleteProduct(product.id);
      await loadProducts();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menghapus produk');
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Kelola Produk</h1>
          <p className="text-sm text-zinc-600">Kelola katalog produk digital.</p>
        </div>
        <Link
          to="/admin/products/new"
          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-zinc-900 text-white text-sm font-semibold"
        >
          <Plus className="w-4 h-4" /> Tambah Produk
        </Link>
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">
          {error}
        </div>
      )}

      <div className="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-zinc-50 text-zinc-600">
            <tr>
              <th className="text-left font-semibold px-4 py-3">Name</th>
              <th className="text-left font-semibold px-4 py-3">Category</th>
              <th className="text-left font-semibold px-4 py-3">Type</th>
              <th className="text-left font-semibold px-4 py-3">Price</th>
              <th className="text-left font-semibold px-4 py-3">Status</th>
              <th className="text-left font-semibold px-4 py-3">Downloads</th>
              <th className="text-left font-semibold px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100">
            {loading ? (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-zinc-500">Memuat produk...</td>
              </tr>
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-zinc-500">Belum ada produk.</td>
              </tr>
            ) : (
              items.map((item) => (
                <tr key={item.id}>
                  <td className="px-4 py-3 font-semibold text-zinc-900">{item.name}</td>
                  <td className="px-4 py-3 text-zinc-600">{item.category}</td>
                  <td className="px-4 py-3 text-zinc-600">{item.type}</td>
                  <td className="px-4 py-3 text-zinc-600">Rp {Number(item.price || 0).toLocaleString('id-ID')}</td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-1 rounded-full text-xs font-semibold ${item.is_published ? 'bg-emerald-100 text-emerald-700' : 'bg-zinc-100 text-zinc-600'}`}>
                      {item.is_published ? 'Published' : 'Draft'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-zinc-600">{item.total_downloads || 0}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-2">
                      <Link
                        to={`/admin/products/${item.id}/edit`}
                        className="text-xs font-semibold text-zinc-900 hover:text-zinc-700"
                      >
                        Edit
                      </Link>
                      <button
                        onClick={() => void handlePublish(item)}
                        className="text-xs font-semibold text-emerald-700"
                      >
                        {item.is_published ? (
                          <span className="inline-flex items-center gap-1"><EyeOff className="w-3 h-3" /> Unpublish</span>
                        ) : (
                          <span className="inline-flex items-center gap-1"><CheckCircle className="w-3 h-3" /> Publish</span>
                        )}
                      </button>
                      <button
                        onClick={() => void handleDelete(item)}
                        className="text-xs font-semibold text-red-600 inline-flex items-center gap-1"
                      >
                        <Trash2 className="w-3 h-3" /> Delete
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
