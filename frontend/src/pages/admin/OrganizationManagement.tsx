import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Building2, Search, Eye, RefreshCw, Ban, CheckCircle, X, KeyRound } from 'lucide-react';
import { cn } from '@/lib/utils';
import AdminPager from '@/components/admin/AdminPager';
import {
  getAdminOrganizations,
  getOrganizationDetail,
  overrideEntitlement,
  reactivateAdminOrganization,
  suspendAdminOrganization,
  updateOrganizationOutletLimit,
  type AdminOrganizationListItem,
  type AdminPagination,
} from '@/lib/hellomApi';

type Entitlement = {
  id: number;
  app: { id: number; name: string; slug: string } | null;
  plan: { id: number; name: string; slug: string } | null;
  status: string;
  starts_at: string | null;
  ends_at: string | null;
};

type AccessDraft = { appSlug: string; status: 'active' | 'locked'; endsAt: string; lifetime: boolean };

const PER_PAGE = 20;

const STATUS_LABEL: Record<string, string> = { active: 'Aktif', suspended: 'Disuspend' };
const ENTITLEMENT_LABEL: Record<string, string> = { active: 'Aktif', locked: 'Dikunci', expired: 'Kedaluwarsa', cancelled: 'Dibatalkan', suspended: 'Disuspend', trialing: 'Uji coba' };

const formatDate = (value: string | null) => (value ? new Date(value).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const inDays = (days: number) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);
const errorText = (error: unknown, fallback: string) => (error instanceof Error && error.message ? error.message : fallback);

export default function OrganizationManagement() {
  const [searchParams] = useSearchParams();
  const [organizations, setOrganizations] = useState<AdminOrganizationListItem[]>([]);
  const [pagination, setPagination] = useState<AdminPagination | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [searchInput, setSearchInput] = useState(searchParams.get('search') || '');
  const [search, setSearch] = useState(searchInput);
  const [statusFilter, setStatusFilter] = useState('all');
  const [busyOrgId, setBusyOrgId] = useState<number | null>(null);

  const [selectedOrg, setSelectedOrg] = useState<AdminOrganizationListItem | null>(null);
  const [entitlements, setEntitlements] = useState<Entitlement[]>([]);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [outletOverride, setOutletOverride] = useState('');
  const [outletSaving, setOutletSaving] = useState(false);
  const [outletMessage, setOutletMessage] = useState<string | null>(null);
  const [accessDraft, setAccessDraft] = useState<AccessDraft | null>(null);
  const [accessSaving, setAccessSaving] = useState(false);

  // Search waits for the admin to stop typing instead of firing a request per key.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const loadOrganizations = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await getAdminOrganizations({
        limit: PER_PAGE,
        page,
        status: statusFilter === 'all' ? undefined : statusFilter,
        search: search || undefined,
      });
      setOrganizations(result.items || []);
      setPagination(result.pagination ?? null);
    } catch (err) {
      setError(errorText(err, 'Gagal memuat daftar organisasi.'));
    } finally {
      setLoading(false);
    }
  }, [page, search, statusFilter]);

  useEffect(() => {
    void loadOrganizations();
  }, [loadOrganizations]);

  const loadOrgDetail = async (org: AdminOrganizationListItem) => {
    setSelectedOrg(org);
    setDetailLoading(true);
    setDetailError(null);
    setAccessDraft(null);
    setOutletMessage(null);
    try {
      const result = await getOrganizationDetail(org.id) as { organization: AdminOrganizationListItem; entitlements: Entitlement[] };
      setSelectedOrg(result.organization);
      setEntitlements(result.entitlements || []);
      setOutletOverride(result.organization.max_outlets_override != null ? String(result.organization.max_outlets_override) : '');
    } catch (err) {
      setDetailError(errorText(err, 'Gagal memuat detail organisasi.'));
    } finally {
      setDetailLoading(false);
    }
  };

  const toggleSuspend = async (org: AdminOrganizationListItem) => {
    const suspending = org.status === 'active';
    const question = suspending
      ? `Suspend "${org.name}"? Semua anggotanya tidak bisa memakai POS dan aplikasi berbayar sampai diaktifkan lagi.`
      : `Aktifkan lagi "${org.name}"?`;
    if (!window.confirm(question)) return;

    setBusyOrgId(org.id);
    setError(null);
    try {
      if (suspending) await suspendAdminOrganization(org.id);
      else await reactivateAdminOrganization(org.id);
      setNotice(suspending ? `"${org.name}" disuspend.` : `"${org.name}" aktif lagi.`);
      await loadOrganizations();
      if (selectedOrg?.id === org.id) setSelectedOrg({ ...org, status: suspending ? 'suspended' : 'active' });
    } catch (err) {
      setError(errorText(err, 'Gagal mengubah status organisasi.'));
    } finally {
      setBusyOrgId(null);
    }
  };

  const saveOutletOverride = async () => {
    if (!selectedOrg) return;
    setOutletSaving(true);
    setOutletMessage(null);
    try {
      const value = outletOverride.trim() === '' ? null : Math.max(1, Number(outletOverride));
      await updateOrganizationOutletLimit(selectedOrg.id, value);
      setOutletMessage(value === null ? 'Override dihapus — memakai batas paket.' : `Batas outlet diatur ke ${value}.`);
    } catch (err) {
      setOutletMessage(errorText(err, 'Gagal menyimpan batas outlet.'));
    } finally {
      setOutletSaving(false);
    }
  };

  const saveAccess = async () => {
    if (!selectedOrg || !accessDraft) return;
    if (accessDraft.status === 'active' && !accessDraft.lifetime && !accessDraft.endsAt) {
      setDetailError('Isi tanggal berakhir akses, atau centang "Seumur hidup".');
      return;
    }
    setAccessSaving(true);
    setDetailError(null);
    try {
      await overrideEntitlement({
        organization_id: selectedOrg.id,
        app_slug: accessDraft.appSlug,
        status: accessDraft.status,
        ...(accessDraft.status === 'active' ? (accessDraft.lifetime ? { lifetime: true } : { ends_at: accessDraft.endsAt }) : {}),
      });
      setAccessDraft(null);
      await loadOrgDetail(selectedOrg);
    } catch (err) {
      setDetailError(errorText(err, 'Gagal mengubah akses aplikasi.'));
    } finally {
      setAccessSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Organisasi</h1>
        <p className="mt-1 text-zinc-600">Semua toko/bisnis di Hellom: status, akses aplikasi, dan batas outlet.</p>
      </div>

      <div className="flex flex-col gap-3 sm:flex-row">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
          <input
            type="search"
            placeholder="Cari nama atau slug organisasi…"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            className="w-full rounded-lg border border-zinc-200 py-2 pl-10 pr-4 outline-none focus:border-transparent focus:ring-2 focus:ring-yellow-400"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
          className="rounded-lg border border-zinc-200 px-4 py-2 outline-none focus:ring-2 focus:ring-yellow-400"
        >
          <option value="all">Semua status</option>
          <option value="active">Aktif</option>
          <option value="suspended">Disuspend</option>
        </select>
        <button
          onClick={() => void loadOrganizations()}
          className="inline-flex items-center justify-center gap-2 rounded-lg bg-zinc-100 px-4 py-2 text-sm font-medium transition-colors hover:bg-zinc-200"
          title="Muat ulang"
        >
          <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} /> <span className="sm:hidden">Muat ulang</span>
        </button>
      </div>

      {error && <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">{error}</div>}
      {notice && !error && <div className="rounded-lg border border-emerald-100 bg-emerald-50 p-3 text-sm text-emerald-700">{notice}</div>}

      <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-zinc-50">
              <tr>
                {['Organisasi', 'Status', 'Anggota', 'Dibuat', 'Aksi'].map((label) => (
                  <th key={label} className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{label}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-200">
              {loading && organizations.length === 0 ? (
                <tr><td colSpan={5} className="px-6 py-10 text-center text-zinc-500">Memuat organisasi…</td></tr>
              ) : organizations.length === 0 ? (
                <tr><td colSpan={5} className="px-6 py-12 text-center text-zinc-500">{search || statusFilter !== 'all' ? 'Tidak ada organisasi yang cocok dengan filter.' : 'Belum ada organisasi.'}</td></tr>
              ) : (
                organizations.map((org) => (
                  <tr key={org.id} className="hover:bg-zinc-50">
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <div className="rounded-lg bg-zinc-100 p-2"><Building2 className="h-4 w-4 text-zinc-600" /></div>
                        <div className="min-w-0">
                          <p className="truncate font-medium text-zinc-900">{org.name}</p>
                          <p className="truncate text-sm text-zinc-500">{org.slug}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className={cn('inline-flex rounded-full px-2 py-1 text-xs font-medium', org.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800')}>
                        {STATUS_LABEL[org.status] ?? org.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-sm text-zinc-900">{org.users_count}</td>
                    <td className="px-6 py-4 text-sm text-zinc-500">{formatDate(org.created_at)}</td>
                    <td className="px-6 py-4">
                      <div className="flex flex-wrap gap-2">
                        <button onClick={() => void loadOrgDetail(org)} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-1 text-sm text-blue-700 transition-colors hover:bg-blue-100">
                          <Eye className="h-4 w-4" /> Detail
                        </button>
                        <button
                          onClick={() => void toggleSuspend(org)}
                          disabled={busyOrgId === org.id}
                          className={cn(
                            'inline-flex items-center gap-1.5 rounded-lg px-3 py-1 text-sm transition-colors disabled:opacity-60',
                            org.status === 'active' ? 'bg-red-50 text-red-700 hover:bg-red-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                          )}
                        >
                          {org.status === 'active' ? <><Ban className="h-4 w-4" /> Suspend</> : <><CheckCircle className="h-4 w-4" /> Aktifkan</>}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        <AdminPager pagination={pagination} loading={loading} unit="organisasi" onPage={setPage} />
      </div>

      {selectedOrg && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setSelectedOrg(null)}>
          <div className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-start justify-between border-b border-zinc-200 p-6">
              <div>
                <h3 className="text-lg font-semibold text-zinc-900">{selectedOrg.name}</h3>
                <p className="mt-1 text-sm text-zinc-600">{selectedOrg.slug} · {STATUS_LABEL[selectedOrg.status] ?? selectedOrg.status}</p>
              </div>
              <button onClick={() => setSelectedOrg(null)} className="rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600" aria-label="Tutup">
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-6 p-6">
              {detailError && <p className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">{detailError}</p>}

              {detailLoading ? (
                <p className="text-center text-zinc-500">Memuat detail…</p>
              ) : (
                <>
                  <section className="space-y-3">
                    <h4 className="font-medium text-zinc-900">Akses aplikasi</h4>
                    {entitlements.length === 0 ? (
                      <p className="text-sm text-zinc-500">Belum ada akses aplikasi.</p>
                    ) : entitlements.map((ent) => (
                      <div key={ent.id} className="rounded-lg border border-zinc-200 p-3">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                          <div>
                            <p className="font-medium text-zinc-900">{ent.app?.name ?? 'Aplikasi'}</p>
                            <p className="text-sm text-zinc-500">Paket: {ent.plan?.name ?? '—'}</p>
                            <p className="text-xs text-zinc-400">
                              {ENTITLEMENT_LABEL[ent.status] ?? ent.status} · mulai {formatDate(ent.starts_at)} · berakhir {ent.ends_at ? formatDate(ent.ends_at) : ent.status === 'active' ? 'seumur hidup' : '—'}
                            </p>
                          </div>
                          {ent.app && (
                            <button
                              onClick={() => setAccessDraft({ appSlug: ent.app!.slug, status: 'active', endsAt: inDays(30), lifetime: false })}
                              className="inline-flex items-center gap-1.5 rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 transition-colors hover:bg-zinc-200"
                            >
                              <KeyRound className="h-4 w-4" /> Atur akses
                            </button>
                          )}
                        </div>

                        {accessDraft && accessDraft.appSlug === ent.app?.slug && (
                          <div className="mt-3 space-y-3 rounded-lg bg-zinc-50 p-3">
                            <div className="flex flex-wrap items-center gap-3 text-sm">
                              <select
                                value={accessDraft.status}
                                onChange={(e) => setAccessDraft({ ...accessDraft, status: e.target.value as AccessDraft['status'] })}
                                className="rounded-lg border border-zinc-300 px-3 py-2"
                              >
                                <option value="active">Buka akses</option>
                                <option value="locked">Kunci akses</option>
                              </select>
                              {accessDraft.status === 'active' && (
                                <>
                                  <label className="flex items-center gap-2">
                                    Sampai
                                    <input
                                      type="date"
                                      value={accessDraft.endsAt}
                                      min={inDays(1)}
                                      disabled={accessDraft.lifetime}
                                      onChange={(e) => setAccessDraft({ ...accessDraft, endsAt: e.target.value })}
                                      className="rounded-lg border border-zinc-300 px-3 py-2 disabled:opacity-50"
                                    />
                                  </label>
                                  <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={accessDraft.lifetime} onChange={(e) => setAccessDraft({ ...accessDraft, lifetime: e.target.checked })} />
                                    Seumur hidup
                                  </label>
                                </>
                              )}
                            </div>
                            <div className="flex gap-2">
                              <button onClick={() => void saveAccess()} disabled={accessSaving} className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 disabled:opacity-60">
                                {accessSaving ? 'Menyimpan…' : 'Simpan akses'}
                              </button>
                              <button onClick={() => setAccessDraft(null)} className="rounded-lg border border-zinc-200 px-4 py-2 text-sm">Batal</button>
                            </div>
                          </div>
                        )}
                      </div>
                    ))}
                  </section>

                  <section className="border-t border-zinc-200 pt-4">
                    <h4 className="font-medium text-zinc-900">Batas outlet (POS)</h4>
                    <p className="mt-1 text-sm text-zinc-500">Override batas jumlah outlet untuk organisasi ini. Kosongkan untuk memakai batas dari paket POS-nya.</p>
                    <div className="mt-3 flex items-center gap-2">
                      <input
                        type="number"
                        min={1}
                        value={outletOverride}
                        onChange={(e) => setOutletOverride(e.target.value)}
                        placeholder="Pakai batas paket"
                        className="w-44 rounded-lg border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-amber-400"
                      />
                      <button onClick={() => void saveOutletOverride()} disabled={outletSaving} className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 disabled:opacity-60">
                        {outletSaving ? 'Menyimpan…' : 'Simpan'}
                      </button>
                    </div>
                    {outletMessage && <p className="mt-2 text-xs text-zinc-600">{outletMessage}</p>}
                  </section>

                  <section className="flex items-center justify-between border-t border-zinc-200 pt-4">
                    <div>
                      <h4 className="font-medium text-zinc-900">Status organisasi</h4>
                      <p className="mt-1 text-sm text-zinc-500">Organisasi yang disuspend tidak bisa memakai POS dan aplikasi berbayar.</p>
                    </div>
                    <button
                      onClick={() => void toggleSuspend(selectedOrg)}
                      disabled={busyOrgId === selectedOrg.id}
                      className={cn('rounded-lg px-4 py-2 text-sm font-semibold disabled:opacity-60', selectedOrg.status === 'active' ? 'bg-red-50 text-red-700 hover:bg-red-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100')}
                    >
                      {selectedOrg.status === 'active' ? 'Suspend' : 'Aktifkan'}
                    </button>
                  </section>
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
