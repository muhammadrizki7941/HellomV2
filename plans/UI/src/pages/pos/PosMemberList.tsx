import { useState, useEffect } from 'react';
import { Search, Plus, Star, ShoppingBag, TrendingUp, MessageCircle, CheckCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { createPosMember, getPosMembers } from '@/lib/hellomApi';

type Member = {
  id: number;
  name: string;
  phone: string;
  email: string;
  total_points: number;
  total_orders: number;
  total_spent: number;
  last_order_at: string;
  created_at: string;
};

type MemberForm = {
  name: string;
  phone: string;
  email: string;
};

type AddResult = {
  member: Member;
  waLink: string | null;
};

function normalizePhone(value: string): string {
  const digits = value.replace(/\D+/g, '');
  if (!digits) return '';
  if (digits.startsWith('62')) return digits;
  if (digits.startsWith('0')) return `62${digits.slice(1)}`;
  return digits;
}

function buildWaLink(phone: string, message: string): string | null {
  const normalized = normalizePhone(phone);
  if (!normalized) return null;
  return `https://wa.me/${normalized}?text=${encodeURIComponent(message)}`;
}

export default function PosMemberList() {
  const [members, setMembers] = useState<Member[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [showAddModal, setShowAddModal] = useState(false);
  const [addLoading, setAddLoading] = useState(false);
  const [addError, setAddError] = useState<string | null>(null);
  const [addResult, setAddResult] = useState<AddResult | null>(null);
  const [memberForm, setMemberForm] = useState<MemberForm>({ name: '', phone: '', email: '' });

  useEffect(() => {
    void loadMembers();
  }, []);

  const loadMembers = async () => {
    try {
      setLoading(true);
      const response = await getPosMembers() as any;
      setMembers((response as any).data || []);
    } catch {
      setMembers([]);
    } finally {
      setLoading(false);
    }
  };

  const filteredMembers = members.filter(
    (m) =>
      m.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      (m.phone || '').includes(searchTerm) ||
      (m.email || '').toLowerCase().includes(searchTerm.toLowerCase())
  );

  const getTierInfo = (totalOrders: number) => {
    if (totalOrders >= 20) return { tier: 'VIP', color: 'text-purple-600 bg-purple-50 border-purple-200' };
    if (totalOrders >= 5) return { tier: 'Reguler', color: 'text-blue-600 bg-blue-50 border-blue-200' };
    return { tier: 'Baru', color: 'text-green-600 bg-green-50 border-green-200' };
  };

  const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount);

  const formatDate = (dateString: string) => {
    if (!dateString) return '-';
    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(dateString));
  };

  const buildMemberWaLink = (member: Member, customMsg?: string) => {
    const msg = customMsg || `Halo ${member.name}! 👋\n\nKamu sudah terdaftar sebagai member kami.\nLihat poin & riwayat pesananmu di sini.\n\nTerima kasih sudah menjadi member! 🌟`;
    return buildWaLink(member.phone, msg);
  };

  const handleSubmitMember = async (e: React.FormEvent) => {
    e.preventDefault();
    setAddLoading(true);
    setAddError(null);

    try {
      const posRes = (await createPosMember({
        name: memberForm.name.trim(),
        phone: memberForm.phone.trim(),
        email: memberForm.email.trim() || undefined,
      })) as any;

      const newMember: Member = posRes?.member || posRes?.data?.member || {
        id: Date.now(),
        name: memberForm.name.trim(),
        phone: memberForm.phone.trim(),
        email: memberForm.email.trim(),
        total_points: 0,
        total_orders: 0,
        total_spent: 0,
        last_order_at: '',
        created_at: new Date().toISOString(),
      };

      const waMsg =
        `Halo ${memberForm.name.trim()}! 👋\n\n` +
        `Selamat, kamu sudah terdaftar sebagai member kami! 🎉\n\n` +
        `Tunjukkan nomor HP ini kepada kasir untuk mendapatkan poin di setiap transaksi.\n\n` +
        `Terima kasih sudah menjadi member! 🌟`;

      const waLink = buildWaLink(memberForm.phone.trim(), waMsg);

      setAddResult({ member: newMember, waLink });
      await loadMembers();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Gagal membuat member. Coba lagi.';
      setAddError(msg);
    } finally {
      setAddLoading(false);
    }
  };

  const closeModal = () => {
    setShowAddModal(false);
    setAddResult(null);
    setAddError(null);
    setMemberForm({ name: '', phone: '', email: '' });
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Manajemen Member</h1>
            <p className="text-gray-600 mt-1">Kelola member dan program loyalitas</p>
          </div>
          <button
            onClick={() => setShowAddModal(true)}
            className="px-4 py-2 bg-amber-400 text-[#111111] rounded-lg hover:bg-amber-500 transition-colors flex items-center gap-2"
          >
            <Plus className="w-4 h-4" />
            Tambah Member
          </button>
        </div>

        {/* Stats Cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {[
            { label: 'Total Member', value: members.length, icon: <Star className="w-5 h-5 text-blue-600" />, bg: 'bg-blue-100' },
            { label: 'Total Poin', value: members.reduce((s, m) => s + m.total_points, 0), icon: <TrendingUp className="w-5 h-5 text-green-600" />, bg: 'bg-green-100' },
            { label: 'Total Orders', value: members.reduce((s, m) => s + m.total_orders, 0), icon: <ShoppingBag className="w-5 h-5 text-purple-600" />, bg: 'bg-purple-100' },
            { label: 'Total Pendapatan', value: formatCurrency(members.reduce((s, m) => s + m.total_spent, 0)), icon: <TrendingUp className="w-5 h-5 text-amber-600" />, bg: 'bg-amber-100' },
          ].map((stat) => (
            <div key={stat.label} className="bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
              <div className="flex items-center gap-3">
                <div className={cn('w-10 h-10 rounded-lg flex items-center justify-center', stat.bg)}>
                  {stat.icon}
                </div>
                <div>
                  <p className="text-xs text-gray-500">{stat.label}</p>
                  <p className="text-lg font-bold text-gray-900">{stat.value}</p>
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Search */}
        <div className="relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
          <input
            type="text"
            placeholder="Cari member berdasarkan nama, nomor HP, atau email..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full pl-10 pr-4 py-2 bg-white border border-gray-200 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
          />
        </div>

        {/* Members Table */}
        <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
          {loading ? (
            <div className="p-8 text-center">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-amber-400 mx-auto mb-4" />
              <p className="text-gray-600 text-sm">Loading members...</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Member</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Kontak</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tier</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Poin</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Orders</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Total Belanja</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Bergabung</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {filteredMembers.map((member) => {
                    const tierInfo = getTierInfo(member.total_orders);
                    const waLink = buildMemberWaLink(member);
                    return (
                      <tr key={member.id} className="hover:bg-gray-50 transition-colors">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-3">
                            <div className="w-9 h-9 bg-amber-100 rounded-full flex items-center justify-center text-amber-800 font-bold text-sm flex-shrink-0">
                              {member.name.charAt(0).toUpperCase()}
                            </div>
                            <div>
                              <div className="text-sm font-semibold text-gray-900">{member.name}</div>
                              <div className="text-xs text-gray-400">ID #{member.id}</div>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="text-sm text-gray-900">{member.phone || '—'}</div>
                          <div className="text-xs text-gray-400">{member.email || '—'}</div>
                        </td>
                        <td className="px-4 py-3">
                          <span className={cn('inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border', tierInfo.color)}>
                            {tierInfo.tier}
                          </span>
                        </td>
                        <td className="px-4 py-3">
                          <div className="text-sm font-bold text-gray-900">{member.total_points}</div>
                          <div className="text-xs text-gray-400">poin</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="text-sm font-bold text-gray-900">{member.total_orders}</div>
                          <div className="text-xs text-gray-400">pesanan</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="text-sm font-semibold text-gray-900">{formatCurrency(member.total_spent)}</div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="text-xs text-gray-500">{formatDate(member.created_at)}</div>
                        </td>
                        <td className="px-4 py-3">
                          {waLink ? (
                            <a
                              href={waLink}
                              target="_blank"
                              rel="noopener noreferrer"
                              title={`Kirim WhatsApp ke ${member.name}`}
                              className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-500 hover:bg-green-600 text-white text-xs font-semibold rounded-lg transition-colors"
                            >
                              <MessageCircle className="w-3.5 h-3.5" />
                              WhatsApp
                            </a>
                          ) : (
                            <span className="text-xs text-gray-400">No HP kosong</span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {filteredMembers.length === 0 && !loading && (
            <div className="p-12 text-center">
              <div className="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                <Star className="w-6 h-6 text-gray-400" />
              </div>
              <p className="text-sm text-gray-500">Belum ada member yang ditemukan.</p>
            </div>
          )}
        </div>

        {/* ─── Add Member Modal ─── */}
        {showAddModal && (
          <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden">

              {/* Success state */}
              {addResult ? (
                <div className="p-6">
                  <div className="flex items-center gap-3 mb-4">
                    <div className="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                      <CheckCircle className="w-5 h-5 text-green-600" />
                    </div>
                    <div>
                      <h3 className="text-base font-bold text-gray-900">Member berhasil ditambahkan!</h3>
                      <p className="text-xs text-gray-500">{addResult.member.name} · {addResult.member.phone}</p>
                    </div>
                  </div>

                  <div className="mb-4 rounded-xl bg-amber-50 border border-amber-200 p-3 text-xs text-amber-700">
                    💡 Kirim pesan WhatsApp agar member tahu cara akses portal poin & riwayat pesanan mereka.
                  </div>

                  <div className="flex flex-col gap-2">
                    {addResult.waLink && (
                      <a
                        href={addResult.waLink}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex items-center justify-center gap-2 w-full py-3 bg-green-500 hover:bg-green-600 text-white font-semibold rounded-xl transition-colors text-sm"
                      >
                        <MessageCircle className="w-4 h-4" />
                        Kirim Selamat Datang via WhatsApp
                      </a>
                    )}
                    <button
                      type="button"
                      onClick={closeModal}
                      className="w-full py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl transition-colors text-sm"
                    >
                      Selesai
                    </button>
                  </div>
                </div>
              ) : (
                /* Form state */
                <>
                  <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h3 className="text-base font-bold text-gray-900">Tambah Member Baru</h3>
                    <button type="button" onClick={closeModal} className="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
                  </div>

                  <form onSubmit={handleSubmitMember} className="p-6 space-y-4">
                    {addError && (
                      <div className="rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                        {addError}
                      </div>
                    )}

                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        Nama Lengkap <span className="text-red-500">*</span>
                      </label>
                      <input
                        type="text"
                        value={memberForm.name}
                        onChange={(e) => setMemberForm({ ...memberForm, name: e.target.value })}
                        placeholder="contoh: Budi Santoso"
                        className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300 text-sm"
                        required
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        Nomor HP <span className="text-red-500">*</span>
                      </label>
                      <input
                        type="tel"
                        value={memberForm.phone}
                        onChange={(e) => setMemberForm({ ...memberForm, phone: e.target.value })}
                        placeholder="contoh: 08123456789"
                        className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300 text-sm"
                        required
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        Email
                        <span className="ml-1 text-xs text-gray-400 font-normal">(opsional)</span>
                      </label>
                      <input
                        type="email"
                        value={memberForm.email}
                        onChange={(e) => setMemberForm({ ...memberForm, email: e.target.value })}
                        placeholder="contoh: budi@email.com"
                        className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300 text-sm"
                      />
                    </div>

                    <div className="flex gap-3 pt-2">
                      <button
                        type="button"
                        onClick={closeModal}
                        className="flex-1 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors text-sm font-medium"
                      >
                        Batal
                      </button>
                      <button
                        type="submit"
                        disabled={addLoading || !memberForm.name.trim() || !memberForm.phone.trim()}
                        className="flex-1 py-2 bg-amber-400 text-[#111111] hover:bg-amber-500 rounded-lg transition-colors disabled:opacity-50 text-sm font-semibold"
                      >
                        {addLoading ? 'Menyimpan...' : 'Tambah Member'}
                      </button>
                    </div>
                  </form>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
