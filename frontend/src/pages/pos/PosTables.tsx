import { useEffect, useState } from 'react';
import { Search, Plus, Edit2, Trash2, QrCode, Eye, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getPosTables, createPosTable, updatePosTable, deletePosTable, getCurrentOrganization } from '@/lib/hellomApi';

type Table = {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
  public_id: string;
};

export default function PosTables() {
  const [tables, setTables] = useState<Table[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingTable, setEditingTable] = useState<Table | null>(null);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [organizationSlug, setOrganizationSlug] = useState<string | null>(null);
  const [formData, setFormData] = useState({
    code: '',
    name: '',
    is_active: true,
  });

  useEffect(() => {
    loadTables();
    void loadCurrentOrganization();
  }, []);

  const loadTables = async () => {
    try {
      setLoading(true);
      const result = await getPosTables();
      setTables(result.tables || []);
    } catch (err) {
      setError('Gagal memuat daftar meja');
    } finally {
      setLoading(false);
    }
  };

  const loadCurrentOrganization = async () => {
    try {
      const organization = await getCurrentOrganization();
      setOrganizationSlug(organization?.slug || null);
    } catch {
      setOrganizationSlug(null);
    }
  };

  const filteredTables = tables.filter(table =>
    table.code.toLowerCase().includes(searchTerm.toLowerCase()) ||
    (table.name || '').toLowerCase().includes(searchTerm.toLowerCase())
  );

  const handleCreateTable = () => {
    setEditingTable(null);
    setFormData({ code: '', name: '', is_active: true });
    setSubmitError(null);
    setShowCreateModal(true);
  };

  const handleEditTable = (table: Table) => {
    setEditingTable(table);
    setFormData({
      code: table.code,
      name: table.name || '',
      is_active: table.is_active,
    });
    setSubmitError(null);
    setShowCreateModal(true);
  };

  const handleDeleteTable = async (tableId: number) => {
    if (confirm('Are you sure you want to delete this table?')) {
      try {
        await deletePosTable(tableId);
        await loadTables();
      } catch (err) {
        alert('Failed to delete table');
      }
    }
  };

  const handleSubmitTable = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitError(null);

    const payload = {
      code: formData.code.trim(),
      name: formData.name.trim() || undefined,
      is_active: formData.is_active,
    };

    try {
      if (editingTable) {
        await updatePosTable(editingTable.id, payload);
      } else {
        await createPosTable(payload);
      }

      await loadTables();
      setShowCreateModal(false);
      setEditingTable(null);
      setFormData({ code: '', name: '', is_active: true });
    } catch (err) {
      setSubmitError(err instanceof Error ? err.message : 'Gagal menyimpan meja.');
    }
  };

  const generateQRUrl = (table: Table) => {
    if (organizationSlug) {
      return `${window.location.origin}/customer/${organizationSlug}/order/${table.public_id}`;
    }

    return `${window.location.origin}/customer/order/${table.public_id}`;
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Daftar Meja</h1>
          <p className="text-gray-600 mt-1">Manage restaurant tables and QR codes</p>
        </div>
        <button
          onClick={handleCreateTable}
          className="px-4 py-2 bg-amber-400 text-[#111111] rounded-lg hover:bg-amber-500 transition-colors flex items-center gap-2"
        >
          <Plus className="w-4 h-4" />
          Tambah Meja
        </button>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{error}</p>
        </div>
      )}

      {/* Search */}
      <div className="flex gap-4">
        <div className="flex-1 relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
          <input
            type="text"
            placeholder="Cari meja..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full pl-10 pr-4 py-2 bg-white border border-gray-200 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
          />
        </div>
      </div>

      {/* Tables Table */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kode</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kode QR</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {loading ? (
                <tr>
                  <td colSpan={5} className="px-6 py-12 text-center text-gray-500">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-amber-400 mx-auto mb-4"></div>
                    Memuat daftar meja...
                  </td>
                </tr>
              ) : filteredTables.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-6 py-12 text-center text-gray-500">
                    Tidak ada meja yang sesuai pencarian.
                  </td>
                </tr>
              ) : (
                filteredTables.map(table => (
                  <tr key={table.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                      {table.code}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {table.name || '-'}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={cn(
                        'inline-flex px-2 py-1 text-xs font-medium rounded-full',
                        table.is_active
                          ? 'bg-amber-100 text-amber-900'
                          : 'bg-red-100 text-red-800'
                      )}>
                        {table.is_active ? 'Aktif' : 'Tidak Aktif'}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <button
                        onClick={() => window.open(generateQRUrl(table), '_blank')}
                        className="text-amber-700 hover:text-[#111111] flex items-center gap-1"
                      >
                        <QrCode className="w-4 h-4" />
                        Lihat Kode QR
                      </button>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <div className="flex items-center gap-2">
                        <button
                          onClick={() => handleEditTable(table)}
                          className="text-amber-700 hover:text-[#111111]"
                        >
                          <Edit2 className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => handleDeleteTable(table.id)}
                          className="text-red-600 hover:text-red-900"
                        >
                          <Trash2 className="w-4 h-4" />
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

      {/* Create/Edit Modal */}
      {showCreateModal && (
        <div className="fixed inset-0 bg-white/20 backdrop-blur-md flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl max-w-md w-full p-6">
            <div className="flex items-center justify-between mb-6">
              <h3 className="text-lg font-semibold text-gray-900">
                {editingTable ? 'Edit Meja' : 'Tambah Meja Baru'}
              </h3>
              <button
                onClick={() => {
                  setShowCreateModal(false);
                  setSubmitError(null);
                }}
                className="text-gray-400 hover:text-gray-600"
              >
                <X className="w-6 h-6" />
              </button>
            </div>

            <form onSubmit={handleSubmitTable} className="space-y-4">
              {submitError && (
                <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                  {submitError}
                </div>
              )}

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Kode Meja *
                </label>
                <input
                  type="text"
                  value={formData.code}
                  onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                  className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                  placeholder="cth: T01, T02, VIP1"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Nama Meja (opsional)
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                  placeholder="cth: Meja Sudut, Meja VIP"
                />
              </div>

              <div className="flex items-center">
                <input
                  type="checkbox"
                  id="table_active"
                  checked={formData.is_active}
                  onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                  className="h-4 w-4 text-amber-500 focus:ring-amber-300 border-gray-300 rounded"
                />
                <label htmlFor="table_active" className="ml-2 text-sm text-gray-700">
                  Meja Aktif
                </label>
              </div>

              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => {
                    setShowCreateModal(false);
                    setSubmitError(null);
                  }}
                  className="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-amber-400 text-[#111111] hover:bg-amber-500 rounded-lg transition-colors"
                >
                  {editingTable ? 'Perbarui Meja' : 'Simpan Meja'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      </div>
    </div>
  );
}
