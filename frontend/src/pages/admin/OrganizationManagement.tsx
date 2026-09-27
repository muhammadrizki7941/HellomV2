import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Building2, Search, Eye, RefreshCw, CheckCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getAdminOrganizations, getOrganizationDetail, overrideEntitlement, updateOrganizationOutletLimit } from '@/lib/hellomApi';

type Organization = {
  id: number;
  name: string;
  slug: string;
  status: string;
  users_count: number;
  created_at: string;
  max_outlets_override?: number | null;
};

type Entitlement = {
  id: number;
  app: { id: number; name: string; slug: string };
  plan: { id: number; name: string; slug: string };
  status: string;
  starts_at: string | null;
  ends_at: string | null;
};

// Implemented in hellomApi.ts





export default function OrganizationManagement() {
  const [searchParams] = useSearchParams();
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState(searchParams.get('search') || '');
  const [statusFilter, setStatusFilter] = useState('all');
  const [selectedOrg, setSelectedOrg] = useState<Organization | null>(null);
  const [orgDetail, setOrgDetail] = useState<{ organization: Organization; entitlements: Entitlement[] } | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [outletOverride, setOutletOverride] = useState('');
  const [outletSaving, setOutletSaving] = useState(false);
  const [outletMessage, setOutletMessage] = useState<string | null>(null);

  const saveOutletOverride = async () => {
    if (!orgDetail) return;
    setOutletSaving(true);
    setOutletMessage(null);
    try {
      const value = outletOverride.trim() === '' ? null : Math.max(1, Number(outletOverride));
      await updateOrganizationOutletLimit(orgDetail.organization.id, value);
      setOutletMessage(value === null ? 'Override dihapus — pakai batas paket.' : `Batas outlet di-set ke ${value}.`);
    } catch (err) {
      setOutletMessage(err instanceof Error ? err.message : 'Gagal menyimpan batas outlet');
    } finally {
      setOutletSaving(false);
    }
  };

  const loadOrganizations = async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await getAdminOrganizations({
        limit: 50,
        status: statusFilter === 'all' ? undefined : statusFilter,
        search: searchTerm || undefined,
      });
      setOrganizations(result.items || []);
    } catch (err) {
      setError('Failed to load organizations');
    } finally {
      setLoading(false);
    }
  };

  const loadOrgDetail = async (org: Organization) => {
    setSelectedOrg(org);
    setDetailLoading(true);
    try {
      const result = await getOrganizationDetail(org.id) as { organization: Organization; entitlements: Entitlement[] };
      setOrgDetail(result);
      setOutletOverride(result.organization.max_outlets_override != null ? String(result.organization.max_outlets_override) : '');
      setOutletMessage(null);
    } catch (err) {
      setError('Failed to load organization detail');
    } finally {
      setDetailLoading(false);
    }
  };

  const handleBypass = async (orgId: number, appSlug: string) => {
    try {
      await overrideEntitlement({ organization_id: orgId, app_slug: appSlug, status: 'active' });
      // Reload detail
      if (selectedOrg) await loadOrgDetail(selectedOrg);
    } catch (err) {
      setError('Failed to bypass entitlement');
    }
  };

  useEffect(() => {
    void loadOrganizations();
  }, [searchTerm, statusFilter]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Organization Management</h1>
        <p className="text-zinc-600 mt-1">Manage organizations and their app entitlements</p>
      </div>

      {/* Filters */}
      <div className="flex gap-4">
        <div className="flex-1 relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-zinc-400 w-4 h-4" />
          <input
            type="text"
            placeholder="Search organizations..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border border-zinc-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="px-4 py-2 border border-zinc-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
        >
          <option value="all">All Status</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
        </select>
        <button
          onClick={() => void loadOrganizations()}
          className="px-4 py-2 bg-zinc-100 hover:bg-zinc-200 rounded-lg transition-colors"
        >
          <RefreshCw className="w-4 h-4" />
        </button>
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">
          {error}
        </div>
      )}

      {/* Organizations List */}
      <div className="bg-white border border-zinc-200 rounded-xl overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-zinc-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Organization</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Status</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Users</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Created</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-200">
          {loading ? (
            <tr>
              <td colSpan={5} className="px-6 py-4 text-center text-zinc-500">Loading...</td>
            </tr>
          ) : organizations.length === 0 ? (
            <tr>
              <td colSpan={5} className="px-6 py-12 text-center text-zinc-500">
              No organizations found. Make sure users have registered and organizations exist.
            </td>
            </tr>
          ) : (
                organizations.map((org) => (
                  <tr key={org.id} className="hover:bg-zinc-50">
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <div className="p-2 bg-zinc-100 rounded-lg">
                          <Building2 className="w-4 h-4 text-zinc-600" />
                        </div>
                        <div>
                          <p className="font-medium text-zinc-900">{org.name}</p>
                          <p className="text-sm text-zinc-500">{org.slug}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className={cn(
                        "inline-flex px-2 py-1 text-xs font-medium rounded-full",
                        org.status === 'active' ? "bg-green-100 text-green-800" : "bg-red-100 text-red-800"
                      )}>
                        {org.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-sm text-zinc-900">{org.users_count}</td>
                    <td className="px-6 py-4 text-sm text-zinc-500">
                      {new Date(org.created_at).toLocaleDateString('id-ID')}
                    </td>
                    <td className="px-6 py-4">
                      <button
                        onClick={() => void loadOrgDetail(org)}
                        className="inline-flex items-center gap-2 px-3 py-1 text-sm bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg transition-colors"
                      >
                        <Eye className="w-4 h-4" />
                        Detail
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Organization Detail Modal */}
      {selectedOrg && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl max-w-2xl w-full max-h-[80vh] overflow-y-auto">
            <div className="p-6 border-b border-zinc-200">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-zinc-900">Organization Detail</h3>
                <button
                  onClick={() => setSelectedOrg(null)}
                  className="text-zinc-400 hover:text-zinc-600"
                >
                  ×
                </button>
              </div>
              <p className="text-sm text-zinc-600 mt-1">{selectedOrg.name} ({selectedOrg.slug})</p>
            </div>

            <div className="p-6">
              {detailLoading ? (
                <p className="text-center text-zinc-500">Loading details...</p>
              ) : orgDetail ? (
                <div className="space-y-4">
                  <h4 className="font-medium text-zinc-900">App Entitlements</h4>
                  {orgDetail.entitlements.length === 0 ? (
                    <p className="text-sm text-zinc-500">No entitlements found</p>
                  ) : (
                    <div className="space-y-3">
                      {orgDetail.entitlements.map((ent) => (
                        <div key={ent.id} className="flex items-center justify-between p-3 border border-zinc-200 rounded-lg">
                          <div>
                            <p className="font-medium text-zinc-900">{ent.app.name}</p>
                            <p className="text-sm text-zinc-500">Plan: {ent.plan.name}</p>
                            <p className="text-xs text-zinc-400">
                              Status: {ent.status} | Starts: {ent.starts_at || 'N/A'} | Ends: {ent.ends_at || 'N/A'}
                            </p>
                          </div>
                          {ent.status !== 'active' && (
                            <button
                              onClick={() => void handleBypass(orgDetail.organization.id, ent.app.slug)}
                              className="inline-flex items-center gap-2 px-3 py-2 text-sm bg-green-50 hover:bg-green-100 text-green-700 rounded-lg transition-colors"
                            >
                              <CheckCircle className="w-4 h-4" />
                              Bypass
                            </button>
                          )}
                        </div>
                      ))}
                    </div>
                  )}

                  <div className="border-t border-zinc-200 pt-4">
                    <h4 className="font-medium text-zinc-900">Batas Outlet (POS)</h4>
                    <p className="mt-1 text-sm text-zinc-500">
                      Override batas jumlah outlet untuk organisasi ini. Kosongkan untuk memakai batas dari paket POS-nya.
                    </p>
                    <div className="mt-3 flex items-center gap-2">
                      <input
                        type="number"
                        min={1}
                        value={outletOverride}
                        onChange={(e) => setOutletOverride(e.target.value)}
                        placeholder="Pakai batas paket"
                        className="w-44 rounded-lg border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-amber-400"
                      />
                      <button
                        onClick={() => void saveOutletOverride()}
                        disabled={outletSaving}
                        className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 disabled:opacity-60"
                      >
                        {outletSaving ? 'Menyimpan…' : 'Simpan'}
                      </button>
                    </div>
                    {outletMessage && <p className="mt-2 text-xs text-zinc-600">{outletMessage}</p>}
                  </div>
                </div>
              ) : (
                <p className="text-center text-zinc-500">Failed to load details</p>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
