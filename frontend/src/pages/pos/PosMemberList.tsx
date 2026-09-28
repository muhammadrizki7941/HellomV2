import { useCallback, useEffect, useState } from 'react';
import { Search, Plus, Star, ShoppingBag, TrendingUp, MessageCircle, CheckCircle, Download, X, Users, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  adjustPosMemberPoints,
  createPosMember,
  exportPosMembers,
  getPosFraudFlags,
  getPosMemberDetail,
  getPosMemberDuplicates,
  getPosMemberLedger,
  getPosMemberOrders,
  getPosMemberPage,
  getPosOutlets,
  mergePosMembers,
  resolvePosFraudFlag,
} from '@/lib/hellomApi';
import type {
  PosFraudFlag,
  PosLedgerRow,
  PosMemberDetail,
  PosMemberDuplicateGroup,
  PosMemberListParams,
  PosMemberRecord,
  PosOutlet,
} from '@/lib/hellomApi';

type MemberForm = { name: string; phone: string; email: string };
type AddResult = { member: { name: string; phone: string | null }; waLink: string | null };
type Tab = 'members' | 'duplicates' | 'fraud';

function normalizePhone(value: string): string {
  const digits = value.replace(/\D+/g, '');
  if (!digits) return '';
  if (digits.startsWith('62')) return digits;
  if (digits.startsWith('0')) return `62${digits.slice(1)}`;
  return digits;
}

function buildWaLink(phone: string | null, message: string): string | null {
  const normalized = normalizePhone(phone || '');
  if (!normalized) return null;
  return `https://wa.me/${normalized}?text=${encodeURIComponent(message)}`;
}

const formatCurrency = (amount: number) =>
  new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount || 0);

const formatDate = (dateString: string | null) => {
  if (!dateString) return '-';
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(dateString));
};

const tierColor = (tier: string) =>
  tier === 'VIP' ? 'text-purple-600 bg-purple-50 border-purple-200'
    : tier === 'Reguler' ? 'text-blue-600 bg-blue-50 border-blue-200'
      : 'text-green-600 bg-green-50 border-green-200';

export default function PosMemberList() {
  const [tab, setTab] = useState<Tab>('members');
  const [members, setMembers] = useState<PosMemberRecord[]>([]);
  const [meta, setMeta] = useState({ page: 1, lastPage: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [outletId, setOutletId] = useState('');
  const [sort, setSort] = useState<NonNullable<PosMemberListParams['sort']>>('recent');
  const [outlets, setOutlets] = useState<PosOutlet[]>([]);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [showAddModal, setShowAddModal] = useState(false);
  const [addLoading, setAddLoading] = useState(false);
  const [addError, setAddError] = useState<string | null>(null);
  const [addResult, setAddResult] = useState<AddResult | null>(null);
  const [memberForm, setMemberForm] = useState<MemberForm>({ name: '', phone: '', email: '' });

  const params = useCallback((page = 1): PosMemberListParams => ({
    q: searchTerm.trim() || undefined,
    outlet_id: outletId || undefined,
    sort,
    page,
  }), [searchTerm, outletId, sort]);

  const loadMembers = useCallback(async (page = 1) => {
    try {
      setLoading(true);
      const res = await getPosMemberPage(params(page));
      setMembers(res.data || []);
      setMeta({ page: res.current_page, lastPage: res.last_page, total: res.total });
    } catch {
      setMembers([]);
    } finally {
      setLoading(false);
    }
  }, [params]);

  useEffect(() => {
    const timer = window.setTimeout(() => void loadMembers(1), 300);
    return () => window.clearTimeout(timer);
  }, [loadMembers]);

  useEffect(() => {
    getPosOutlets().then((res) => setOutlets(res.outlets || [])).catch(() => setOutlets([]));
  }, []);

  const handleExport = async () => {
    try {
      const blob = await exportPosMembers({ ...params(1), page: undefined });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `Member-${new Date().toISOString().slice(0, 10)}.xlsx`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Gagal mengekspor');
    }
  };

  const handleSubmitMember = async (e: React.FormEvent) => {
    e.preventDefault();
    setAddLoading(true);
    setAddError(null);
    try {
      const res = await createPosMember({
        name: memberForm.name.trim(),
        phone: memberForm.phone.trim(),
        email: memberForm.email.trim() || undefined,
      });
      const waMsg =
        `Halo ${memberForm.name.trim()}! 👋\n\n` +
        `Kamu sudah terdaftar sebagai member kami. 🎉\n\n` +
        `📱 Lihat poin & riwayat pesananmu:\n` +
        `Masukkan nomor HP kamu di portal member kami.\n\n` +
        `Terima kasih sudah menjadi member! 🌟`;
      setAddResult({ member: { name: res.member.name, phone: res.member.phone }, waLink: buildWaLink(res.member.phone, waMsg) });
      await loadMembers(1);
    } catch (err: unknown) {
      setAddError(err instanceof Error ? err.message : 'Gagal membuat member. Coba lagi.');
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

  const pagePoints = members.reduce((s, m) => s + m.redeemable_points, 0);
  const pageOrders = members.reduce((s, m) => s + m.total_orders, 0);
  const pageSpent = members.reduce((s, m) => s + m.total_spent, 0);

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="mx-auto max-w-7xl space-y-6">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Manajemen Member</h1>
            <p className="mt-1 text-gray-600">Member berlaku di semua outlet. Nomor HP = identitas member.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <button onClick={() => void handleExport()} className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-gray-800 hover:bg-gray-50">
              <Download className="h-4 w-4" />
              Export Excel
            </button>
            <button onClick={() => setShowAddModal(true)} className="flex items-center gap-2 rounded-lg bg-amber-400 px-4 py-2 text-[#111111] hover:bg-amber-500">
              <Plus className="h-4 w-4" />
              Tambah Member
            </button>
          </div>
        </div>

        <div className="flex gap-1 border-b border-gray-200">
          {([['members', 'Member', Users], ['duplicates', 'Nomor ganda', Users], ['fraud', 'Sinyal kecurangan', ShieldAlert]] as const).map(([key, label, Icon]) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={cn('flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium', tab === key ? 'border-amber-400 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700')}
            >
              <Icon className="h-4 w-4" />
              {label}
            </button>
          ))}
        </div>

        {tab === 'duplicates' && <DuplicatesPanel onMerged={() => void loadMembers(1)} />}
        {tab === 'fraud' && <FraudPanel />}

        {tab === 'members' && (
          <>
            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
              {[
                { label: 'Total Member', value: meta.total, icon: <Star className="h-5 w-5 text-blue-600" />, bg: 'bg-blue-100' },
                { label: 'Saldo poin (halaman ini)', value: pagePoints, icon: <TrendingUp className="h-5 w-5 text-green-600" />, bg: 'bg-green-100' },
                { label: 'Pesanan (halaman ini)', value: pageOrders, icon: <ShoppingBag className="h-5 w-5 text-purple-600" />, bg: 'bg-purple-100' },
                { label: 'Belanja (halaman ini)', value: formatCurrency(pageSpent), icon: <TrendingUp className="h-5 w-5 text-amber-600" />, bg: 'bg-amber-100' },
              ].map((stat) => (
                <div key={stat.label} className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                  <div className="flex items-center gap-3">
                    <div className={cn('flex h-10 w-10 items-center justify-center rounded-lg', stat.bg)}>{stat.icon}</div>
                    <div>
                      <p className="text-xs text-gray-500">{stat.label}</p>
                      <p className="text-lg font-bold text-gray-900">{stat.value}</p>
                    </div>
                  </div>
                </div>
              ))}
            </div>

            <div className="flex flex-col gap-3 md:flex-row">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                <input
                  type="text"
                  placeholder="Cari nama, nomor HP (08… / 62…), atau email..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full rounded-lg border border-gray-200 bg-white py-2 pl-10 pr-4 text-gray-900 placeholder-gray-400 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                />
              </div>
              <select value={outletId} onChange={(e) => setOutletId(e.target.value)} className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <option value="">Semua outlet</option>
                {outlets.map((o) => <option key={o.id} value={o.id}>Pernah pesan di {o.name}</option>)}
              </select>
              <select value={sort} onChange={(e) => setSort(e.target.value as typeof sort)} className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <option value="recent">Terakhir pesan</option>
                <option value="most_active">Paling aktif</option>
                <option value="top_spend">Belanja terbesar</option>
                <option value="points">Poin terbanyak</option>
                <option value="name">Nama A–Z</option>
              </select>
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
              {loading ? (
                <div className="p-8 text-center">
                  <div className="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-b-2 border-amber-400" />
                  <p className="text-sm text-gray-600">Memuat member...</p>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead className="border-b border-gray-200 bg-gray-50">
                      <tr>
                        {['Member', 'Kontak', 'Tier', 'Saldo Poin', 'Pesanan', 'Total Belanja', 'Terakhir Pesan', 'Aksi'].map((h) => (
                          <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {members.map((member) => {
                        const waLink = buildWaLink(member.phone, `Halo ${member.name}! 👋\n\nSaldo poin kamu: ${member.redeemable_points} poin. Terima kasih sudah menjadi member! 🌟`);
                        return (
                          <tr key={member.id} className="cursor-pointer transition-colors hover:bg-gray-50" onClick={() => setDetailId(member.id)}>
                            <td className="px-4 py-3">
                              <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-amber-100 text-sm font-bold text-amber-800">
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
                              <span className={cn('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold', tierColor(member.tier))}>{member.tier}</span>
                            </td>
                            <td className="px-4 py-3 text-sm font-bold text-gray-900">{member.redeemable_points}</td>
                            <td className="px-4 py-3 text-sm font-bold text-gray-900">{member.total_orders}</td>
                            <td className="px-4 py-3 text-sm font-semibold text-gray-900">{formatCurrency(member.total_spent)}</td>
                            <td className="px-4 py-3 text-xs text-gray-500">{formatDate(member.last_order_at)}</td>
                            <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                              {waLink ? (
                                <a href={waLink} target="_blank" rel="noopener noreferrer"
                                  className="inline-flex items-center gap-1.5 rounded-lg bg-green-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-600">
                                  <MessageCircle className="h-3.5 w-3.5" />
                                  WhatsApp
                                </a>
                              ) : <span className="text-xs text-gray-400">No HP kosong</span>}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}

              {members.length === 0 && !loading && (
                <div className="p-12 text-center text-sm text-gray-500">Belum ada member yang ditemukan.</div>
              )}
              {meta.lastPage > 1 && (
                <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 text-sm">
                  <button disabled={meta.page <= 1} onClick={() => void loadMembers(meta.page - 1)} className="rounded px-3 py-1 hover:bg-gray-100 disabled:opacity-40">← Sebelumnya</button>
                  <span className="text-gray-500">Halaman {meta.page} dari {meta.lastPage}</span>
                  <button disabled={meta.page >= meta.lastPage} onClick={() => void loadMembers(meta.page + 1)} className="rounded px-3 py-1 hover:bg-gray-100 disabled:opacity-40">Berikutnya →</button>
                </div>
              )}
            </div>
          </>
        )}

        {detailId !== null && (
          <MemberDrawer memberId={detailId} onClose={() => setDetailId(null)} onChanged={() => void loadMembers(meta.page)} />
        )}

        {showAddModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm">
            <div className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
              {addResult ? (
                <div className="p-6">
                  <div className="mb-4 flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-green-100">
                      <CheckCircle className="h-5 w-5 text-green-600" />
                    </div>
                    <div>
                      <h3 className="text-base font-bold text-gray-900">Member berhasil ditambahkan!</h3>
                      <p className="text-xs text-gray-500">{addResult.member.name} · {addResult.member.phone}</p>
                    </div>
                  </div>
                  <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
                    💡 Kirim pesan WhatsApp agar member tahu cara akses portal poin & riwayat pesanan mereka.
                  </div>
                  <div className="flex flex-col gap-2">
                    {addResult.waLink && (
                      <a href={addResult.waLink} target="_blank" rel="noopener noreferrer"
                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-green-500 py-3 text-sm font-semibold text-white hover:bg-green-600">
                        <MessageCircle className="h-4 w-4" />
                        Kirim Selamat Datang via WhatsApp
                      </a>
                    )}
                    <button type="button" onClick={closeModal} className="w-full rounded-xl bg-gray-100 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-200">
                      Selesai
                    </button>
                  </div>
                </div>
              ) : (
                <>
                  <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h3 className="text-base font-bold text-gray-900">Tambah Member Baru</h3>
                    <button type="button" onClick={closeModal} className="text-xl leading-none text-gray-400 hover:text-gray-600">✕</button>
                  </div>
                  <form onSubmit={handleSubmitMember} className="space-y-4 p-6">
                    {addError && <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{addError}</div>}
                    {([
                      ['name', 'Nama Lengkap', 'text', 'contoh: Budi Santoso', true],
                      ['phone', 'Nomor HP', 'tel', 'contoh: 08123456789', true],
                      ['email', 'Email (opsional)', 'email', 'contoh: budi@email.com', false],
                    ] as const).map(([field, label, type, placeholder, required]) => (
                      <label key={field} className="block text-sm font-medium text-gray-700">
                        {label} {required && <span className="text-red-500">*</span>}
                        <input
                          type={type}
                          value={memberForm[field]}
                          onChange={(e) => setMemberForm({ ...memberForm, [field]: e.target.value })}
                          placeholder={placeholder}
                          required={required}
                          className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                        />
                      </label>
                    ))}
                    <div className="flex gap-3 pt-2">
                      <button type="button" onClick={closeModal} className="flex-1 rounded-lg bg-gray-100 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Batal</button>
                      <button type="submit" disabled={addLoading || !memberForm.name.trim() || !memberForm.phone.trim()}
                        className="flex-1 rounded-lg bg-amber-400 py-2 text-sm font-semibold text-[#111111] hover:bg-amber-500 disabled:opacity-50">
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

function MemberDrawer({ memberId, onClose, onChanged }: { memberId: number; onClose: () => void; onChanged: () => void }) {
  const [detail, setDetail] = useState<PosMemberDetail | null>(null);
  const [ledger, setLedger] = useState<PosLedgerRow[]>([]);
  const [orders, setOrders] = useState<Awaited<ReturnType<typeof getPosMemberOrders>>['data']>([]);
  const [view, setView] = useState<'points' | 'orders'>('points');
  const [adjustPoints, setAdjustPoints] = useState('');
  const [adjustReason, setAdjustReason] = useState('');
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const load = useCallback(async () => {
    const [d, l, o] = await Promise.all([getPosMemberDetail(memberId), getPosMemberLedger(memberId), getPosMemberOrders(memberId)]);
    setDetail(d);
    setLedger(l.data || []);
    setOrders(o.data || []);
  }, [memberId]);

  useEffect(() => {
    load().catch((err) => setMessage({ ok: false, text: err instanceof Error ? err.message : 'Gagal memuat member' }));
  }, [load]);

  const submitAdjust = async () => {
    const points = parseInt(adjustPoints, 10);
    if (!points || !adjustReason.trim()) return;
    try {
      await adjustPosMemberPoints(memberId, points, adjustReason.trim());
      setAdjustPoints('');
      setAdjustReason('');
      setMessage({ ok: true, text: 'Poin diperbarui dan tercatat di audit log.' });
      await load();
      onChanged();
    } catch (err) {
      setMessage({ ok: false, text: err instanceof Error ? err.message : 'Gagal mengubah poin' });
    }
  };

  const m = detail?.member;

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/30" onClick={onClose}>
      <div className="h-full w-full max-w-lg overflow-y-auto bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between">
          <div>
            <h3 className="text-lg font-bold text-gray-900">{m?.name ?? 'Memuat…'}</h3>
            {m && <p className="text-sm text-gray-500">{m.phone} · {m.email || 'tanpa email'}</p>}
          </div>
          <button onClick={onClose} className="rounded-full p-1 text-gray-400 hover:bg-gray-100"><X className="h-5 w-5" /></button>
        </div>

        {m && (
          <div className="mt-4 grid grid-cols-3 gap-3 text-center">
            <div className="rounded-xl bg-amber-50 p-3"><p className="text-xs text-gray-500">Saldo poin</p><p className="text-lg font-bold">{m.redeemable_points}</p></div>
            <div className="rounded-xl bg-gray-50 p-3"><p className="text-xs text-gray-500">Pesanan</p><p className="text-lg font-bold">{m.total_orders}</p></div>
            <div className="rounded-xl bg-gray-50 p-3"><p className="text-xs text-gray-500">Belanja</p><p className="text-sm font-bold">{formatCurrency(m.total_spent)}</p></div>
          </div>
        )}

        <div className="mt-5 rounded-xl border border-gray-200 p-4">
          <p className="text-sm font-semibold text-gray-900">Ubah poin manual</p>
          <p className="text-xs text-gray-500">Hanya owner/supervisor. Pakai angka minus untuk mengurangi. Alasan wajib.</p>
          <div className="mt-2 flex gap-2">
            <input value={adjustPoints} onChange={(e) => setAdjustPoints(e.target.value.replace(/[^\d-]/g, ''))} placeholder="+50 / -20"
              className="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-sm" />
            <input value={adjustReason} onChange={(e) => setAdjustReason(e.target.value)} placeholder="Alasan"
              className="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-sm" />
            <button onClick={() => void submitAdjust()} disabled={!parseInt(adjustPoints, 10) || !adjustReason.trim()}
              className="rounded-lg bg-amber-400 px-3 text-sm font-semibold disabled:opacity-50">Simpan</button>
          </div>
          {message && <p className={cn('mt-2 text-xs', message.ok ? 'text-green-700' : 'text-red-600')}>{message.text}</p>}
        </div>

        <div className="mt-5 flex gap-2 text-sm">
          <button onClick={() => setView('points')} className={cn('rounded-lg px-3 py-1.5', view === 'points' ? 'bg-gray-900 text-white' : 'bg-gray-100')}>Riwayat poin</button>
          <button onClick={() => setView('orders')} className={cn('rounded-lg px-3 py-1.5', view === 'orders' ? 'bg-gray-900 text-white' : 'bg-gray-100')}>Riwayat pesanan</button>
        </div>

        <div className="mt-3 divide-y divide-gray-100">
          {view === 'points' && ledger.map((row) => (
            <div key={row.id} className="flex items-start justify-between gap-3 py-2 text-sm">
              <div className="min-w-0">
                <p className="font-medium text-gray-900">{row.type_label}{row.order_number ? ` · #${row.order_number}` : ''}</p>
                <p className="truncate text-xs text-gray-500">{row.reason || '-'}{row.outlet ? ` · ${row.outlet}` : ''}{row.user ? ` · oleh ${row.user}` : ''}</p>
                <p className="text-xs text-gray-400">{formatDate(row.created_at)}</p>
              </div>
              <div className="shrink-0 text-right">
                <p className={cn('font-bold', row.points >= 0 ? 'text-green-700' : 'text-red-600')}>{row.points >= 0 ? `+${row.points}` : row.points}</p>
                <p className="text-xs text-gray-400">saldo {row.balance_after}</p>
              </div>
            </div>
          ))}
          {view === 'orders' && orders.map((o) => (
            <div key={o.id} className="flex items-center justify-between py-2 text-sm">
              <div>
                <p className="font-medium text-gray-900">#{o.order_number}</p>
                <p className="text-xs text-gray-500">{o.outlet || '-'} · {formatDate(o.created_at)} · {o.payment_status}</p>
              </div>
              <div className="text-right">
                <p className="font-semibold">{formatCurrency(o.final_amount)}</p>
                <p className="text-xs text-gray-400">+{o.points_earned} / −{o.redeemed_points} poin</p>
              </div>
            </div>
          ))}
          {((view === 'points' && ledger.length === 0) || (view === 'orders' && orders.length === 0)) && (
            <p className="py-6 text-center text-sm text-gray-500">Belum ada riwayat.</p>
          )}
        </div>
      </div>
    </div>
  );
}

function DuplicatesPanel({ onMerged }: { onMerged: () => void }) {
  const [groups, setGroups] = useState<PosMemberDuplicateGroup[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    getPosMemberDuplicates().then((res) => setGroups(res.duplicates)).catch((err) => setError(err instanceof Error ? err.message : 'Gagal memuat'));
  }, []);
  useEffect(load, [load]);

  const merge = async (targetId: number, sourceId: number, sourceName: string) => {
    const reason = window.prompt(`Gabungkan "${sourceName}" (#${sourceId}) ke member #${targetId}? Poin & riwayat pindah, tidak bisa dibatalkan.\n\nAlasan:`);
    if (!reason || !reason.trim()) return;
    try {
      await mergePosMembers(targetId, sourceId, reason.trim());
      load();
      onMerged();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Gagal menggabungkan');
    }
  };

  if (error) return <p className="rounded-lg bg-red-50 p-4 text-sm text-red-700">{error}</p>;
  if (!groups) return <p className="text-sm text-gray-500">Memuat…</p>;
  if (groups.length === 0) return <p className="rounded-xl bg-white p-6 text-sm text-gray-500 shadow-sm">Tidak ada nomor HP ganda. 👍</p>;

  return (
    <div className="space-y-4">
      <p className="text-sm text-gray-600">Nomor HP yang sama dipakai beberapa member (data lama). Pilih member utama, lalu gabungkan yang lain ke sana. Hanya owner.</p>
      {groups.map((group) => {
        const target = group.members[0];
        return (
          <div key={group.phone} className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p className="text-sm font-semibold text-gray-900">+{group.phone}</p>
            <div className="mt-2 divide-y divide-gray-100">
              {group.members.map((member, index) => (
                <div key={member.id} className="flex items-center justify-between py-2 text-sm">
                  <div>
                    <p className="font-medium">{member.name} <span className="text-xs text-gray-400">#{member.id}</span>{index === 0 && <span className="ml-2 rounded bg-green-100 px-1.5 text-xs text-green-700">utama</span>}</p>
                    <p className="text-xs text-gray-500">{member.redeemable_points} poin · {member.total_orders} pesanan · daftar {formatDate(member.created_at)}</p>
                  </div>
                  {index > 0 && (
                    <button onClick={() => void merge(target.id, member.id, member.name)} className="rounded-lg border border-gray-200 px-3 py-1 text-xs hover:bg-gray-50">
                      Gabungkan ke #{target.id}
                    </button>
                  )}
                </div>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}

function FraudPanel() {
  const [flags, setFlags] = useState<PosFraudFlag[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    getPosFraudFlags('open').then((res) => setFlags(res.data)).catch((err) => setError(err instanceof Error ? err.message : 'Gagal memuat'));
  }, []);
  useEffect(load, [load]);

  const resolve = async (flag: PosFraudFlag, status: 'dismissed' | 'confirmed') => {
    try {
      await resolvePosFraudFlag(flag.id, status);
      load();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Gagal');
    }
  };

  if (error) return <p className="rounded-lg bg-red-50 p-4 text-sm text-red-700">{error}</p>;
  if (!flags) return <p className="text-sm text-gray-500">Memuat…</p>;
  if (flags.length === 0) return <p className="rounded-xl bg-white p-6 text-sm text-gray-500 shadow-sm">Tidak ada sinyal kecurangan terbuka.</p>;

  return (
    <div className="space-y-3">
      {flags.map((flag) => (
        <div key={flag.id} className="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-amber-200 bg-white p-4 shadow-sm">
          <div className="text-sm">
            <p className="font-semibold text-gray-900">{flag.rule_label}</p>
            <p className="text-gray-600">Member: {flag.member_name ?? `#${flag.member_id}`}{flag.user_name ? ` · Kasir: ${flag.user_name}` : ''}</p>
            <p className="text-xs text-gray-400">{formatDate(flag.created_at)} · {JSON.stringify(flag.details)}</p>
          </div>
          <div className="flex gap-2">
            <button onClick={() => void resolve(flag, 'dismissed')} className="rounded-lg border border-gray-200 px-3 py-1 text-xs hover:bg-gray-50">Abaikan</button>
            <button onClick={() => void resolve(flag, 'confirmed')} className="rounded-lg bg-red-600 px-3 py-1 text-xs text-white hover:bg-red-700">Tandai benar</button>
          </div>
        </div>
      ))}
    </div>
  );
}
