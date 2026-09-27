import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Search, Filter, Trash2, Eye, CheckCircle, ChevronLeft, ChevronRight, RefreshCw, Mail, Send, Ban,
  Shield, ShieldOff, Settings, CalendarClock, Loader2
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  approveProductPurchase,
  createOrganizationInvitation,
  deleteAdminUser,
  getAdminPlans,
  getAdminUserDetail,
  getAdminUsers,
  getOrganizationInvitations,
  getOrganizationTeam,
  getSessionUser,
  reactivateUser,
  removeOrganizationMember,
  resendOrganizationInvitation,
  revokeOrganizationInvitation,
  suspendUser,
  updateAdminUserAppAccess,
} from '@/lib/hellomApi';

type UserItem = {
  id: number;
  name: string;
  email: string;
  plan: string;
  status: 'Active' | 'Suspended';
  joinedDate: string;
  avatar: string;
  role: string;
  organization?: { id: number; name: string; slug: string; status?: string };
};

type InvitationItem = {
  id: number;
  email: string;
  role: string;
  status: string;
  expiresAt: string;
  createdAt: string;
};

type PlanItem = {
  id: number;
  slug: string;
  name: string;
  type: string;
  price: number;
};

type UserDetail = Awaited<ReturnType<typeof getAdminUserDetail>>['user'];

type AccessDraft = {
  organizationId: number;
  appSlug: string;
  planId: string;
  status: 'active' | 'locked' | 'expired' | 'cancelled' | 'suspended';
  startsAt: string;
  endsAt: string;
  amount: string;
};

function toDateInput(value: string | null | undefined): string {
  return value ? value.slice(0, 10) : '';
}

function currency(amount: number) {
  return `Rp ${amount.toLocaleString('id-ID')}`;
}

function paymentGatewayLabel(value?: string | null) {
  if (value === 'ipaymu') return 'iPaymu';
  if (value === 'doku') return 'DOKU';
  if (value === 'xendit') return 'Xendit';
  if (value === 'manual') return 'Manual';
  if (value === 'free') return 'Gratis';
  return value || '-';
}

export default function UserManagement() {
  const sessionUser = getSessionUser<{ role: string }>();
  const isSuperAdmin = sessionUser?.role === 'super_admin';
  const itemsPerPage = 10;
  const [activeTab, setActiveTab] = useState<'team' | 'global'>(isSuperAdmin ? 'global' : 'team');
  const [searchTerm, setSearchTerm] = useState('');
  const [filterStatus, setFilterStatus] = useState('All');
  const [users, setUsers] = useState<UserItem[]>([]);
  const [currentPage, setCurrentPage] = useState(1);
  const [globalPagination, setGlobalPagination] = useState({ total: 0, perPage: itemsPerPage, currentPage: 1, lastPage: 1 });
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);
  const [invitationLoading, setInvitationLoading] = useState(false);
  const [invitations, setInvitations] = useState<InvitationItem[]>([]);
  const [newInvitation, setNewInvitation] = useState({ email: '', role: 'member' as 'admin' | 'member', expiresInDays: 7 });
  const [invitationFilter, setInvitationFilter] = useState<'pending' | 'accepted' | 'revoked' | 'expired'>('pending');
  const [selectedUserDetail, setSelectedUserDetail] = useState<UserDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [plans, setPlans] = useState<PlanItem[]>([]);
  const [savingAccessKey, setSavingAccessKey] = useState<string | null>(null);
  const [accessDrafts, setAccessDrafts] = useState<Record<string, AccessDraft>>({});
  const navigate = useNavigate();

  const loadPlans = async () => {
    try {
      const result = await getAdminPlans();
      setPlans((result.items || []).map((plan) => ({
        id: plan.id,
        slug: plan.slug,
        name: plan.name,
        type: plan.type,
        price: plan.price,
      })));
    } catch {
      // no-op
    }
  };

  const loadUsers = async () => {
    setLoading(true);
    setErrorMessage(null);
    try {
      if (activeTab === 'global' && isSuperAdmin) {
        const result = await getAdminUsers({ search: searchTerm || undefined, page: currentPage, limit: itemsPerPage });
        const mapped = (result.items || []).map((u: { id: number; name: string; email: string; role: string; created_at: string; current_organization?: { id: number; name: string; slug: string; status: string } | null }) => ({
          id: u.id,
          name: u.name,
          email: u.email,
          plan: u.role === 'admin' || u.role === 'super_admin' ? 'Admin' : 'Member',
          status: (u.role === 'suspended' ? 'Suspended' : 'Active') as 'Active' | 'Suspended',
          joinedDate: new Date(u.created_at).toLocaleDateString('id-ID'),
          avatar: `https://api.dicebear.com/7.x/initials/svg?seed=${encodeURIComponent(u.name)}`,
          role: u.role,
          organization: u.current_organization || undefined,
        }));
        setUsers(mapped);
        setGlobalPagination({
          total: result.pagination?.total || mapped.length,
          perPage: result.pagination?.per_page || itemsPerPage,
          currentPage: result.pagination?.current_page || currentPage,
          lastPage: result.pagination?.last_page || 1,
        });
        return;
      }

      const team = await getOrganizationTeam();
      const mapped = (team.items || []).map((member) => ({
        id: member.id,
        name: member.name,
        email: member.email,
        plan: member.role === 'owner' || member.role === 'admin' || member.role === 'super_admin' ? 'Admin' : 'Member',
        status: 'Active' as const,
        joinedDate: member.joined_at ? new Date(member.joined_at).toLocaleDateString('id-ID') : '-',
        avatar: `https://api.dicebear.com/7.x/avataaars/svg?seed=${member.id}`,
        role: member.role,
      }));
      setUsers(mapped);
      setGlobalPagination({ total: mapped.length, perPage: itemsPerPage, currentPage: 1, lastPage: Math.max(1, Math.ceil(mapped.length / itemsPerPage)) });
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat user management';
      setErrorMessage(message);
    } finally {
      setLoading(false);
    }
  };

  const loadInvitations = async () => {
    setInvitationLoading(true);
    try {
      const result = await getOrganizationInvitations({ status: invitationFilter, limit: 50 });
      setInvitations((result.items || []).map((item) => ({
        id: item.id,
        email: item.email,
        role: item.role,
        status: item.status,
        expiresAt: item.expires_at ? new Date(item.expires_at).toLocaleString('id-ID') : '-',
        createdAt: item.created_at ? new Date(item.created_at).toLocaleString('id-ID') : '-',
      })));
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat invitations';
      setErrorMessage(message);
    } finally {
      setInvitationLoading(false);
    }
  };

  const openUserDetail = async (user: UserItem) => {
    if (!isSuperAdmin || activeTab !== 'global') {
      setInfoMessage('Detail pembelian dan masa aktif aplikasi tersedia di tab Global Users untuk Super Admin.');
      return;
    }

    setDetailLoading(true);
    setSelectedUserDetail(null);
    setErrorMessage(null);

    try {
      const detail = await getAdminUserDetail(user.id);
      setSelectedUserDetail(detail.user);

      const nextDrafts: Record<string, AccessDraft> = {};
      detail.user.organizations.forEach((organization) => {
        organization.entitlements.forEach((entitlement) => {
          const subscription = organization.subscriptions.find((item) => item.app?.slug === entitlement.app?.slug);
          const key = `${organization.id}:${entitlement.app?.slug || 'unknown'}`;
          nextDrafts[key] = {
            organizationId: organization.id,
            appSlug: entitlement.app?.slug || '',
            planId: String(entitlement.plan?.id || subscription?.plan?.id || ''),
            status: (entitlement.status as AccessDraft['status']) || 'locked',
            startsAt: toDateInput(subscription?.starts_at || entitlement.starts_at),
            endsAt: toDateInput(subscription?.ends_at || entitlement.ends_at),
            amount: String(subscription?.amount || entitlement.plan?.price || 0),
          };
        });
      });
      setAccessDrafts(nextDrafts);
      await loadPlans();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal memuat detail user');
    } finally {
      setDetailLoading(false);
    }
  };

  useEffect(() => {
    void loadUsers();
  }, [activeTab, currentPage, searchTerm]);

  useEffect(() => {
    void loadInvitations();
  }, [invitationFilter]);

  const filteredUsers = users.filter((user) => {
    const matchesSearch = user.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      user.email.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'All' || user.status === filterStatus;
    return matchesSearch && matchesStatus;
  });

  const totalPages = activeTab === 'global'
    ? Math.max(1, globalPagination.lastPage)
    : Math.max(1, Math.ceil(filteredUsers.length / itemsPerPage));
  const paginatedUsers = activeTab === 'global'
    ? filteredUsers
    : filteredUsers.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);
  const totalResults = activeTab === 'global' ? globalPagination.total : filteredUsers.length;
  const showingFrom = totalResults === 0 ? 0 : activeTab === 'global'
    ? ((globalPagination.currentPage - 1) * globalPagination.perPage) + 1
    : ((currentPage - 1) * itemsPerPage) + 1;
  const showingTo = activeTab === 'global'
    ? Math.min(globalPagination.currentPage * globalPagination.perPage, totalResults)
    : Math.min(currentPage * itemsPerPage, totalResults);
  const activeCount = filteredUsers.filter((user) => user.status === 'Active').length;
  const suspendedCount = filteredUsers.filter((user) => user.status === 'Suspended').length;
  const organizationCount = new Set(filteredUsers.map((user) => user.organization?.id).filter(Boolean)).size;

  const planOptionsByApp = useMemo(() => {
    const map = new Map<string, PlanItem[]>();
    plans.forEach((plan) => {
      const key = plan.slug.startsWith('pos') ? 'pos' : plan.slug.startsWith('free') ? 'landing_builder' : 'all';
      if (!map.has(key)) map.set(key, []);
      map.get(key)?.push(plan);
    });
    return map;
  }, [plans]);

  const handleDelete = async (user: UserItem) => {
    if (!window.confirm(`Hapus ${user.name}?`)) return;
    setErrorMessage(null);

    try {
      if (activeTab === 'global' && isSuperAdmin) {
        await deleteAdminUser(user.id);
        setInfoMessage(`User ${user.name} berhasil dihapus dari platform.`);
      } else {
        await removeOrganizationMember(user.id);
        setInfoMessage('User berhasil dihapus dari organisasi.');
      }
      await loadUsers();
    } catch (deleteError) {
      const message = deleteError instanceof Error ? deleteError.message : 'Gagal menghapus user';
      setErrorMessage(message);
    }
  };

  const handleStatusToggle = async (user: UserItem) => {
    if (!isSuperAdmin || activeTab !== 'global') {
      setInfoMessage('Suspend/activate hanya tersedia di tab Global Users untuk Super Admin.');
      return;
    }
    setErrorMessage(null);
    try {
      if (user.status === 'Active') {
        await suspendUser(user.id);
        setInfoMessage(`User ${user.name} berhasil di-suspend.`);
      } else {
        await reactivateUser(user.id);
        setInfoMessage(`User ${user.name} berhasil di-reactivate.`);
      }
      await loadUsers();
      if (selectedUserDetail?.id === user.id) {
        await openUserDetail(user);
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Gagal mengubah status user';
      setErrorMessage(message);
    }
  };

  const handleCreateInvitation = async (event: React.FormEvent) => {
    event.preventDefault();
    setErrorMessage(null);
    try {
      const result = await createOrganizationInvitation({
        email: newInvitation.email,
        role: newInvitation.role,
        expires_in_days: newInvitation.expiresInDays,
      });

      const token = result.invitation.token;
      const appBase = window.location.pathname.startsWith('/hellom') ? '/hellom' : '';
      const registerUrl = `${window.location.origin}${appBase}/register?inviteToken=${encodeURIComponent(token || '')}`;
      if (token) {
        await navigator.clipboard.writeText(registerUrl);
      }
      const deliveryNote = result.email_delivery?.sent === false ? ` Email gagal terkirim: ${result.email_delivery.error}` : ' Email undangan siap dipakai.';
      setInfoMessage(token ? `Link invitation berhasil dibuat dan dicopy ke clipboard.${deliveryNote}` : `Invitation berhasil dibuat.${deliveryNote}`);
      setNewInvitation({ email: '', role: 'member', expiresInDays: 7 });
      await loadInvitations();
    } catch (inviteError) {
      const message = inviteError instanceof Error ? inviteError.message : 'Gagal membuat invitation';
      setErrorMessage(message);
    }
  };

  const handleResendInvitation = async (invitationId: number) => {
    setErrorMessage(null);
    try {
      const result = await resendOrganizationInvitation(invitationId);
      setInfoMessage(result.email_delivery?.sent ? 'Invitation berhasil di-resend.' : `Invitation di-resend tetapi email gagal: ${result.email_delivery?.error || 'unknown error'}`);
      await loadInvitations();
    } catch (resendError) {
      const message = resendError instanceof Error ? resendError.message : 'Gagal resend invitation';
      setErrorMessage(message);
    }
  };

  const handleRevokeInvitation = async (invitationId: number) => {
    setErrorMessage(null);
    try {
      await revokeOrganizationInvitation(invitationId);
      setInfoMessage('Invitation berhasil di-revoke.');
      await loadInvitations();
    } catch (revokeError) {
      const message = revokeError instanceof Error ? revokeError.message : 'Gagal revoke invitation';
      setErrorMessage(message);
    }
  };

  const handleAccessDraftChange = (key: string, patch: Partial<AccessDraft>) => {
    setAccessDrafts((current) => ({
      ...current,
      [key]: {
        ...current[key],
        ...patch,
      },
    }));
  };

  const handleSaveAccess = async (key: string) => {
    if (!selectedUserDetail) return;
    const draft = accessDrafts[key];
    if (!draft?.appSlug) return;

    setSavingAccessKey(key);
    setErrorMessage(null);
    try {
      await updateAdminUserAppAccess(selectedUserDetail.id, {
        organization_id: draft.organizationId,
        app_slug: draft.appSlug,
        status: draft.status,
        plan_id: draft.planId ? Number(draft.planId) : undefined,
        subscription_status: draft.status === 'locked' ? 'suspended' : draft.status,
        amount: Number(draft.amount || 0),
        billing_cycle: 'monthly',
        starts_at: draft.startsAt || undefined,
        ends_at: draft.endsAt || undefined,
      });

      setInfoMessage(`Akses aplikasi ${draft.appSlug} berhasil diperbarui.`);
      await openUserDetail({
        id: selectedUserDetail.id,
        name: selectedUserDetail.name,
        email: selectedUserDetail.email,
        plan: selectedUserDetail.role,
        status: selectedUserDetail.role === 'suspended' ? 'Suspended' : 'Active',
        joinedDate: new Date(selectedUserDetail.created_at).toLocaleDateString('id-ID'),
        avatar: `https://api.dicebear.com/7.x/initials/svg?seed=${encodeURIComponent(selectedUserDetail.name)}`,
        role: selectedUserDetail.role,
        organization: selectedUserDetail.current_organization || undefined,
      });
      await loadUsers();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal memperbarui akses aplikasi');
    } finally {
      setSavingAccessKey(null);
    }
  };

  const handleApproveManualPurchase = async (purchaseId: number) => {
    if (!selectedUserDetail) return;

    setErrorMessage(null);
    try {
      await approveProductPurchase(purchaseId);
      setInfoMessage('Pembayaran manual berhasil dikonfirmasi dan akses produk sudah dibuka.');
      await openUserDetail({
        id: selectedUserDetail.id,
        name: selectedUserDetail.name,
        email: selectedUserDetail.email,
        plan: selectedUserDetail.role,
        status: selectedUserDetail.role === 'suspended' ? 'Suspended' : 'Active',
        joinedDate: new Date(selectedUserDetail.created_at).toLocaleDateString('id-ID'),
        avatar: `https://api.dicebear.com/7.x/initials/svg?seed=${encodeURIComponent(selectedUserDetail.name)}`,
        role: selectedUserDetail.role,
        organization: selectedUserDetail.current_organization || undefined,
      });
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal mengonfirmasi pembelian manual');
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="inline-flex items-center gap-2 rounded-full border border-yellow-200 bg-yellow-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-yellow-700">
            Admin Control Center
          </div>
          <h1 className="mt-3 text-3xl font-bold text-zinc-950">User Management</h1>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">
            {activeTab === 'global'
              ? 'Semua user platform beserta pembelian app, masa aktif, dan kontrol suspend.'
              : 'Kelola anggota organisasi dan invitation email yang benar-benar bisa dipakai user baru.'}
          </p>
        </div>
        <button onClick={() => void loadUsers()} className="inline-flex items-center gap-2 rounded-2xl border border-zinc-200 bg-white px-4 py-2.5 font-medium text-zinc-700 shadow-sm transition hover:border-yellow-300 hover:bg-yellow-50 hover:text-zinc-950">
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} /> Refresh
        </button>
      </div>

      {isSuperAdmin && (
        <div className="inline-flex w-full flex-wrap gap-2 rounded-2xl border border-zinc-200 bg-white p-2 shadow-sm">
          <button
            onClick={() => { setActiveTab('global'); setCurrentPage(1); }}
            className={cn(
              'inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold transition',
              activeTab === 'global'
                ? 'bg-zinc-950 text-white shadow-lg shadow-zinc-950/10'
                : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-950'
            )}
          >
            <Shield className="w-4 h-4 inline mr-1.5" />Global Users
          </button>
          <button
            onClick={() => { setActiveTab('team'); setCurrentPage(1); }}
            className={cn(
              'rounded-xl px-4 py-2.5 text-sm font-semibold transition',
              activeTab === 'team'
                ? 'bg-zinc-950 text-white shadow-lg shadow-zinc-950/10'
                : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-950'
            )}
          >
            Org Team
          </button>
        </div>
      )}

      {errorMessage && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">{errorMessage}</div>
      )}
      {infoMessage && (
        <div className="p-3 rounded-lg bg-zinc-50 border border-zinc-200 text-sm text-zinc-700">{infoMessage}</div>
      )}

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)]">
        <div className="rounded-[28px] border border-zinc-200 bg-[linear-gradient(135deg,#fffdf3,white_45%,#f8fafc)] p-5 shadow-sm">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
              <p className="text-sm font-semibold text-zinc-900">Cari, filter, lalu tindak user lebih cepat</p>
              <p className="mt-1 text-sm text-zinc-600">Kontras diperjelas supaya nama, status, role, dan aksi selalu mudah dibaca di layar terang.</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-2xl border border-white/80 bg-white/90 px-4 py-3 shadow-sm">
                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Visible Users</p>
                <p className="mt-2 text-2xl font-bold text-zinc-950">{totalResults}</p>
              </div>
              <div className="rounded-2xl border border-white/80 bg-white/90 px-4 py-3 shadow-sm">
                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Organizations</p>
                <p className="mt-2 text-2xl font-bold text-zinc-950">{organizationCount}</p>
              </div>
            </div>
          </div>
        </div>

        <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
          <div className="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">Active</p>
            <p className="mt-2 text-2xl font-bold text-emerald-900">{activeCount}</p>
          </div>
          <div className="rounded-2xl border border-rose-100 bg-rose-50 px-4 py-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-rose-700">Suspended</p>
            <p className="mt-2 text-2xl font-bold text-rose-900">{suspendedCount}</p>
          </div>
          <div className="rounded-2xl border border-zinc-200 bg-white px-4 py-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Current Tab</p>
            <p className="mt-2 text-sm font-semibold text-zinc-900">{activeTab === 'global' ? 'Global user scope' : 'Organization team scope'}</p>
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-[28px] border border-zinc-200 bg-white shadow-sm">
        <div className="border-b border-zinc-200 bg-zinc-50/80 px-5 py-4">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="relative w-full lg:max-w-md">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400" />
              <input
                type="text"
                placeholder="Cari nama atau email user..."
                value={searchTerm}
                onChange={(e) => { setCurrentPage(1); setSearchTerm(e.target.value); }}
                className="w-full rounded-2xl border border-zinc-200 bg-white py-3 pl-10 pr-4 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/40"
              />
            </div>

            <div className="inline-flex items-center gap-2 rounded-2xl border border-zinc-200 bg-white px-3 py-3 text-sm text-zinc-600 shadow-sm">
              <Filter className="w-4 h-4 text-zinc-500" />
              <select
                value={filterStatus}
                onChange={(e) => setFilterStatus(e.target.value)}
                className="bg-transparent text-sm font-medium text-zinc-700 outline-none"
              >
                <option value="All">All Status</option>
                <option value="Active">Active</option>
                <option value="Suspended">Suspended</option>
              </select>
            </div>
          </div>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-zinc-50/80 border-b border-zinc-200">
              <tr>
                <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">User</th>
                <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">Role</th>
                <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">Organization</th>
                <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">Status</th>
                <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs">Joined Date</th>
                <th className="px-6 py-4 font-medium text-zinc-500 uppercase tracking-wider text-xs text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100">
              {paginatedUsers.length > 0 ? (
                paginatedUsers.map((user) => (
                  <tr key={user.id} className="transition-colors hover:bg-yellow-50/40">
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <img src={user.avatar} alt={user.name} className="w-11 h-11 rounded-full border border-zinc-200 bg-zinc-100 shadow-sm" />
                        <div>
                          <p className="font-semibold text-zinc-950">{user.name}</p>
                          <p className="text-xs text-zinc-500">{user.email}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className="inline-flex rounded-full border border-zinc-200 bg-zinc-100 px-2.5 py-1 text-xs font-semibold capitalize text-zinc-700">
                        {user.role}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-zinc-600">
                      {user.organization ? (
                        <div>
                          <p className="font-medium text-zinc-800">{user.organization.name}</p>
                          <p className="text-xs text-zinc-500">{user.organization.slug}</p>
                        </div>
                      ) : '-'}
                    </td>
                    <td className="px-6 py-4">
                      <div className={cn(
                        'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold',
                        user.status === 'Active'
                          ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                          : 'border-rose-200 bg-rose-50 text-rose-700'
                      )}>
                        {user.status === 'Active' ? (
                          <CheckCircle className="w-4 h-4 text-green-500" />
                        ) : (
                          <ShieldOff className="w-4 h-4 text-red-500" />
                        )}
                        <span>{user.status}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4 text-sm font-medium text-zinc-600">{user.joinedDate}</td>
                    <td className="px-6 py-4 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          onClick={() => void handleStatusToggle(user)}
                          className={cn('rounded-xl border p-2.5 transition-colors', user.status === 'Active' ? 'border-zinc-200 text-zinc-500 hover:border-yellow-200 hover:bg-yellow-50 hover:text-yellow-700' : 'border-rose-200 text-rose-500 hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700')}
                          title={user.status === 'Active' ? 'Suspend user' : 'Reactivate user'}
                        >
                          {user.status === 'Active' ? <ShieldOff className="w-4 h-4" /> : <Shield className="w-4 h-4" />}
                        </button>
                        {user.organization && (
                          <button
                            onClick={() => navigate(`/admin/organizations?search=${encodeURIComponent(user.organization?.slug || '')}`)}
                            className="rounded-xl border border-zinc-200 p-2.5 text-zinc-500 transition-colors hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700"
                            title="Manage organization apps"
                          >
                            <Settings className="w-4 h-4" />
                          </button>
                        )}
                        <button
                          onClick={() => void openUserDetail(user)}
                          className="rounded-xl border border-zinc-200 p-2.5 text-zinc-500 transition-colors hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700"
                          title="Lihat pembelian dan masa aktif app"
                        >
                          <Eye className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => void handleDelete(user)}
                          className="rounded-xl border border-zinc-200 p-2.5 text-zinc-500 transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-700"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={6} className="px-6 py-12 text-center text-zinc-500">
                    Tidak ada user yang cocok dengan pencarian atau filter saat ini.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        <div className="flex flex-col gap-3 border-t border-zinc-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-zinc-500">
            Showing <span className="font-medium">{showingFrom}</span> to <span className="font-medium">{showingTo}</span> of <span className="font-medium">{totalResults}</span> results
          </p>
          <div className="flex gap-2">
            <button
              onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
              disabled={currentPage === 1}
              className="p-2 border border-zinc-200 rounded-lg hover:bg-zinc-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <button
              onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
              disabled={currentPage === totalPages || totalPages === 0}
              className="p-2 border border-zinc-200 rounded-lg hover:bg-zinc-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-[28px] border border-zinc-200 bg-white shadow-sm">
        <div className="px-6 py-4 border-b border-zinc-200 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
          <div>
            <h2 className="font-bold text-zinc-900">Team Invitations</h2>
            <p className="text-sm leading-6 text-zinc-500">Invitation ini bisa dipakai user lama maupun user baru. Jika belum punya akun, user cukup daftar dari link undangan.</p>
          </div>
          <div className="flex items-center gap-2">
            <select
              value={invitationFilter}
              onChange={(event) => setInvitationFilter(event.target.value as 'pending' | 'accepted' | 'revoked' | 'expired')}
              className="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 outline-none focus:border-yellow-400 focus:ring-yellow-400"
            >
              <option value="pending">Pending</option>
              <option value="accepted">Accepted</option>
              <option value="revoked">Revoked</option>
              <option value="expired">Expired</option>
            </select>
            <button onClick={() => void loadInvitations()} className="flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100">
              <RefreshCw className={`w-4 h-4 ${invitationLoading ? 'animate-spin' : ''}`} /> Refresh
            </button>
          </div>
        </div>

        <form onSubmit={handleCreateInvitation} className="grid grid-cols-1 gap-4 border-b border-zinc-100 bg-zinc-50/50 px-6 py-5 md:grid-cols-4">
          <label className="space-y-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Email Member</span>
            <input
              type="email"
              required
              value={newInvitation.email}
              onChange={(event) => setNewInvitation((prev) => ({ ...prev, email: event.target.value }))}
              placeholder="nama@email.com"
              className="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/40"
            />
          </label>
          <label className="space-y-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Role</span>
            <select
              value={newInvitation.role}
              onChange={(event) => setNewInvitation((prev) => ({ ...prev, role: event.target.value as 'admin' | 'member' }))}
              className="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/40"
            >
              <option value="member">member</option>
              <option value="admin">admin</option>
            </select>
          </label>
          <label className="space-y-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Expired (Hari)</span>
            <input
              type="number"
              min={1}
              max={30}
              value={newInvitation.expiresInDays}
              onChange={(event) => setNewInvitation((prev) => ({ ...prev, expiresInDays: Number(event.target.value || 7) }))}
              className="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/40"
            />
          </label>
          <div className="flex items-end">
            <button type="submit" className="flex w-full items-center justify-center gap-2 rounded-xl bg-zinc-950 px-3 py-2.5 font-semibold text-white transition-colors hover:bg-zinc-800">
              <Mail className="w-4 h-4" /> Create Invite
            </button>
          </div>
        </form>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-zinc-50 border-b border-zinc-200">
              <tr>
                <th className="px-6 py-3 font-medium text-zinc-500">Email</th>
                <th className="px-6 py-3 font-medium text-zinc-500">Role</th>
                <th className="px-6 py-3 font-medium text-zinc-500">Status</th>
                <th className="px-6 py-3 font-medium text-zinc-500">Created</th>
                <th className="px-6 py-3 font-medium text-zinc-500">Expires</th>
                <th className="px-6 py-3 font-medium text-zinc-500 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100">
              {invitations.length > 0 ? (
                invitations.map((invitation) => (
                  <tr key={invitation.id} className="transition-colors hover:bg-yellow-50/30">
                    <td className="px-6 py-4 font-medium text-zinc-900">{invitation.email}</td>
                    <td className="px-6 py-4 text-zinc-600">{invitation.role}</td>
                    <td className="px-6 py-4">
                      <span className="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700">{invitation.status}</span>
                    </td>
                    <td className="px-6 py-4 text-zinc-500">{invitation.createdAt}</td>
                    <td className="px-6 py-4 text-zinc-500">{invitation.expiresAt}</td>
                    <td className="px-6 py-4 text-right">
                      <div className="flex justify-end gap-2">
                        <button
                          onClick={() => void handleResendInvitation(invitation.id)}
                          disabled={invitation.status !== 'pending'}
                          className="rounded-xl border border-zinc-200 p-2.5 text-zinc-500 hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700 disabled:opacity-30 disabled:cursor-not-allowed"
                          title="Resend invitation"
                        >
                          <Send className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => void handleRevokeInvitation(invitation.id)}
                          disabled={invitation.status !== 'pending'}
                          className="rounded-xl border border-zinc-200 p-2.5 text-zinc-500 hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:opacity-30 disabled:cursor-not-allowed"
                          title="Revoke invitation"
                        >
                          <Ban className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={6} className="px-6 py-10 text-center text-zinc-500">Tidak ada invitation pada filter ini.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {(selectedUserDetail || detailLoading) && (
        <div className="fixed inset-0 z-50 bg-black/50 p-4 backdrop-blur-sm">
          <div className="mx-auto max-h-[92vh] w-full max-w-6xl overflow-y-auto rounded-3xl bg-white text-zinc-900 shadow-2xl">
            <div className="sticky top-0 z-10 flex items-center justify-between border-b border-zinc-200 bg-white px-6 py-4">
              <div>
                <h3 className="text-xl font-bold text-zinc-950">User Purchase & App Access</h3>
                <p className="text-sm text-zinc-500">Pantau kapan user mendaftar, membeli app apa, masa aktif, dan suspend app per organisasi.</p>
              </div>
              <button onClick={() => setSelectedUserDetail(null)} className="rounded-2xl border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700">
                Tutup
              </button>
            </div>

            {detailLoading && (
              <div className="flex min-h-[260px] items-center justify-center">
                <Loader2 className="h-8 w-8 animate-spin text-zinc-400" />
              </div>
            )}

            {selectedUserDetail && !detailLoading && (
              <div className="space-y-6 p-6">
                <div className="grid gap-4 md:grid-cols-4">
                  <div className="rounded-3xl border border-zinc-200 bg-zinc-50 p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">User</p>
                    <p className="mt-2 text-lg font-bold text-zinc-950">{selectedUserDetail.name}</p>
                    <p className="text-sm text-zinc-500">{selectedUserDetail.email}</p>
                  </div>
                  <div className="rounded-3xl border border-zinc-200 bg-zinc-50 p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Daftar</p>
                    <p className="mt-2 text-lg font-bold text-zinc-950">{new Date(selectedUserDetail.created_at).toLocaleDateString('id-ID')}</p>
                  </div>
                  <div className="rounded-3xl border border-zinc-200 bg-zinc-50 p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Current Organization</p>
                    <p className="mt-2 text-lg font-bold text-zinc-950">{selectedUserDetail.current_organization?.name || '-'}</p>
                  </div>
                  <div className="rounded-3xl border border-zinc-200 bg-zinc-50 p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Role Global</p>
                    <p className="mt-2 text-lg font-bold text-zinc-950">{selectedUserDetail.role}</p>
                  </div>
                </div>

                <section className="rounded-3xl border border-zinc-200 bg-white shadow-sm">
                  <div className="border-b border-zinc-200 px-6 py-5">
                    <h4 className="text-lg font-bold text-zinc-950">Pembelian Produk Digital</h4>
                    <p className="text-sm text-zinc-500">Riwayat produk yang dibeli user ini, termasuk status pembayaran dan approval manual oleh super admin.</p>
                  </div>
                  <div className="p-6">
                    {selectedUserDetail.product_purchases?.length ? (
                      <div className="space-y-4">
                        {selectedUserDetail.product_purchases.map((purchase) => (
                          <div key={purchase.id} className="rounded-3xl border border-zinc-200 bg-zinc-50/60 p-5">
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                              <div>
                                <h5 className="text-lg font-bold text-zinc-950">{purchase.product?.name || 'Produk digital'}</h5>
                                <p className="mt-1 text-sm text-zinc-500">
                                  Invoice: <strong>{purchase.transaction_code || '-'}</strong> | Dibuat: <strong>{purchase.created_at ? new Date(purchase.created_at).toLocaleString('id-ID') : '-'}</strong>
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2 text-xs text-zinc-600">
                                  <span className="rounded-full bg-white px-3 py-1">Metode: {paymentGatewayLabel(purchase.payment_gateway)} / {purchase.payment_method || '-'}</span>
                                  <span className="rounded-full bg-white px-3 py-1">Status: {purchase.payment_status}</span>
                                  <span className="rounded-full bg-white px-3 py-1">Nominal: {currency(Number(purchase.amount_paid || 0))}</span>
                                  <span className="rounded-full bg-white px-3 py-1">Paid at: {purchase.paid_at ? new Date(purchase.paid_at).toLocaleString('id-ID') : '-'}</span>
                                </div>
                              </div>
                              <div className="flex flex-wrap gap-3">
                                {purchase.payment_status === 'pending' && purchase.payment_gateway === 'manual' ? (
                                  <button
                                    type="button"
                                    onClick={() => void handleApproveManualPurchase(purchase.id)}
                                    className="rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white"
                                  >
                                    Konfirmasi manual & buka akses
                                  </button>
                                ) : purchase.payment_status === 'pending' ? (
                                  <span className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
                                    Menunggu webhook gateway
                                  </span>
                                ) : (
                                  <span className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                                    {purchase.payment_status === 'paid' ? 'Akses produk aktif' : purchase.payment_status}
                                  </span>
                                )}
                              </div>
                            </div>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <div className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-500">
                        User ini belum memiliki pembelian produk digital.
                      </div>
                    )}
                  </div>
                </section>

                {selectedUserDetail.organizations.map((organization) => (
                  <section key={organization.id} className="rounded-3xl border border-zinc-200 bg-white shadow-sm">
                    <div className="flex flex-col gap-2 border-b border-zinc-200 px-6 py-5 lg:flex-row lg:items-center lg:justify-between">
                      <div>
                        <h4 className="text-lg font-bold text-zinc-950">{organization.name}</h4>
                        <p className="text-sm text-zinc-500">
                          Role di org: <strong>{organization.role}</strong> | Status org: <strong>{organization.status}</strong> | {organization.subscriptions.length} pembelian tercatat
                        </p>
                      </div>
                    </div>

                    <div className="space-y-4 p-6">
                      {organization.entitlements.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-500">
                          Belum ada entitlement aplikasi pada organisasi ini.
                        </div>
                      ) : (
                        organization.entitlements.map((entitlement) => {
                          const appSlug = entitlement.app?.slug || '';
                          const key = `${organization.id}:${appSlug}`;
                          const draft = accessDrafts[key];
                          const subscription = organization.subscriptions.find((item) => item.app?.slug === appSlug);
                          const eligiblePlans = [
                            ...(planOptionsByApp.get(appSlug) || []),
                            ...(planOptionsByApp.get('all') || []),
                          ].filter((plan, index, list) => list.findIndex((item) => item.id === plan.id) === index);

                          return (
                            <div key={key} className="rounded-3xl border border-zinc-200 bg-zinc-50/60 p-5">
                              <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                  <h5 className="text-lg font-bold text-zinc-950">{entitlement.app?.name || 'Unknown App'}</h5>
                                  <p className="mt-1 text-sm text-zinc-500">
                                    Plan aktif: <strong>{entitlement.plan?.name || subscription?.plan?.name || '-'}</strong> | Status akses: <strong>{entitlement.status}</strong>
                                  </p>
                                  <div className="mt-3 flex flex-wrap gap-2 text-xs text-zinc-600">
                                    <span className="rounded-full bg-white px-3 py-1">Mulai akses: {entitlement.starts_at ? new Date(entitlement.starts_at).toLocaleDateString('id-ID') : '-'}</span>
                                    <span className="rounded-full bg-white px-3 py-1">Berakhir: {subscription?.ends_at ? new Date(subscription.ends_at).toLocaleDateString('id-ID') : entitlement.ends_at ? new Date(entitlement.ends_at).toLocaleDateString('id-ID') : '-'}</span>
                                    <span className="rounded-full bg-white px-3 py-1">Pembelian: {subscription ? currency(subscription.amount) : '-'}</span>
                                  </div>
                                </div>
                                {subscription && (
                                  <div className="rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-600">
                                    <div className="font-semibold text-zinc-900">Subscription terakhir</div>
                                    <div className="mt-1">Status: {subscription.status}</div>
                                    <div>Siklus: {subscription.billing_cycle}</div>
                                    <div>Mulai: {subscription.starts_at ? new Date(subscription.starts_at).toLocaleDateString('id-ID') : '-'}</div>
                                  </div>
                                )}
                              </div>

                              {draft && (
                                <div className="mt-5 grid gap-3 md:grid-cols-5">
                                  <select
                                    value={draft.planId}
                                    onChange={(event) => handleAccessDraftChange(key, { planId: event.target.value, amount: String(eligiblePlans.find((plan) => String(plan.id) === event.target.value)?.price || draft.amount) })}
                                    className="rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/30"
                                  >
                                    <option value="">Pilih plan</option>
                                    {eligiblePlans.map((plan) => (
                                      <option key={plan.id} value={plan.id}>{plan.name}</option>
                                    ))}
                                  </select>
                                  <select
                                    value={draft.status}
                                    onChange={(event) => handleAccessDraftChange(key, { status: event.target.value as AccessDraft['status'] })}
                                    className="rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/30"
                                  >
                                    <option value="active">active</option>
                                    <option value="locked">locked</option>
                                    <option value="expired">expired</option>
                                    <option value="cancelled">cancelled</option>
                                    <option value="suspended">suspended</option>
                                  </select>
                                  <input type="date" value={draft.startsAt} onChange={(event) => handleAccessDraftChange(key, { startsAt: event.target.value })} className="rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/30" />
                                  <input type="date" value={draft.endsAt} onChange={(event) => handleAccessDraftChange(key, { endsAt: event.target.value })} className="rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/30" />
                                  <input type="number" value={draft.amount} onChange={(event) => handleAccessDraftChange(key, { amount: event.target.value })} className="rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400/30" placeholder="Nominal" />
                                </div>
                              )}

                              {draft && (
                                <div className="mt-4 flex flex-wrap gap-3">
                                  <button
                                    type="button"
                                    onClick={() => void handleSaveAccess(key)}
                                    disabled={savingAccessKey === key}
                                    className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
                                  >
                                    {savingAccessKey === key ? <Loader2 className="h-4 w-4 animate-spin" /> : <CalendarClock className="h-4 w-4" />}
                                    Simpan akses app
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => handleAccessDraftChange(key, { status: 'locked' })}
                                    className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700"
                                  >
                                    Suspend app saja
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => {
                                      const now = new Date();
                                      const next = new Date();
                                      next.setMonth(next.getMonth() + 1);
                                      handleAccessDraftChange(key, {
                                        status: 'active',
                                        startsAt: now.toISOString().slice(0, 10),
                                        endsAt: next.toISOString().slice(0, 10),
                                      });
                                    }}
                                    className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700"
                                  >
                                    Aktifkan 30 hari
                                  </button>
                                </div>
                              )}
                            </div>
                          );
                        })
                      )}
                    </div>
                  </section>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
