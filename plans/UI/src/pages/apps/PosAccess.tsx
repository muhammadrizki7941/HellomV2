import { Link } from 'react-router-dom';
import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, ExternalLink, Monitor, RefreshCw, Store, Tablet } from 'lucide-react';
import { getPosAccess } from '@/lib/hellomApi';

type PosAccessPayload = {
  app: string;
  organization: {
    id: number;
    name: string;
    slug: string;
    pos_tenant_slug: string;
    pos_tenant_name: string;
    pos_provisioned_at: string | null;
  };
  access: {
    admin_url: string;
    cashier_url: string;
    customer_url: string;
    order_url: string;
    requires_legacy_admin_auth: boolean;
  };
};

export default function PosAccess() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [payload, setPayload] = useState<PosAccessPayload | null>(null);

  const loadPosAccess = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const result = await getPosAccess();
      setPayload(result);
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat akses POS.';
      setError(message);
      setPayload(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadPosAccess();
  }, [loadPosAccess]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">POS Access Center</h1>
        <p className="text-zinc-600 mt-1">
          Akses cepat ke aplikasi POS legacy yang sudah terhubung dengan entitlement Hellom.
        </p>
      </div>

      {loading && (
        <div className="rounded-xl border border-zinc-200 bg-white p-6 text-sm text-zinc-600">
          Menyiapkan akses POS...
        </div>
      )}

      {!loading && error && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
          <div className="flex items-start gap-3">
            <AlertTriangle className="mt-0.5 h-5 w-5" />
            <div>
              <p className="font-semibold">POS sedang disiapkan</p>
              <p className="mt-1 text-sm">
                {error.includes('provision') || error.includes('siap')
                  ? 'Akses POS sudah aktif, tetapi data tenant POS belum selesai dibuat. Klik Siapkan Ulang POS agar sistem membuat mapping tenant otomatis.'
                  : error}
              </p>
              <button
                onClick={() => void loadPosAccess()}
                className="mt-3 inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800"
              >
                <RefreshCw className="h-4 w-4" /> Siapkan Ulang POS
              </button>
            </div>
          </div>
        </div>
      )}

      {!loading && payload && (
        <>
          <div className="rounded-xl border border-zinc-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Organization Mapping</p>
            <div className="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
              <div>
                <p className="text-zinc-500">Organization</p>
                <p className="font-semibold text-zinc-900">{payload.organization.name}</p>
              </div>
              <div>
                <p className="text-zinc-500">POS Tenant Slug</p>
                <p className="font-semibold text-zinc-900">{payload.organization.pos_tenant_slug || '-'}</p>
              </div>
              <div>
                <p className="text-zinc-500">Provisioned At</p>
                <p className="font-semibold text-zinc-900">
                  {payload.organization.pos_provisioned_at
                    ? new Date(payload.organization.pos_provisioned_at).toLocaleString('id-ID')
                    : '-'}
                </p>
              </div>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Link
              to={payload.access.admin_url}
              className="rounded-xl border border-zinc-200 bg-white p-5 hover:border-zinc-300 hover:shadow-sm transition"
            >
              <div className="inline-flex p-2 rounded-lg bg-zinc-100 text-zinc-800">
                <Monitor className="w-5 h-5" />
              </div>
              <h3 className="mt-3 font-semibold text-zinc-900">Admin POS</h3>
              <p className="text-sm text-zinc-600 mt-1">Kelola menu, order, report, dan konfigurasi kasir.</p>
              <p className="mt-4 inline-flex items-center gap-2 text-sm font-medium text-zinc-900">
                Buka Admin <ExternalLink className="w-4 h-4" />
              </p>
            </Link>

            <Link
              to={payload.access.cashier_url}
              className="rounded-xl border border-zinc-200 bg-white p-5 hover:border-zinc-300 hover:shadow-sm transition"
            >
              <div className="inline-flex p-2 rounded-lg bg-blue-100 text-blue-700">
                <Tablet className="w-5 h-5" />
              </div>
              <h3 className="mt-3 font-semibold text-zinc-900">Cashier POS</h3>
              <p className="text-sm text-zinc-600 mt-1">Masuk ke antarmuka kasir untuk operasional transaksi harian.</p>
              <p className="mt-4 inline-flex items-center gap-2 text-sm font-medium text-zinc-900">
                Buka Cashier <ExternalLink className="w-4 h-4" />
              </p>
            </Link>

            <Link
              to="/dashboard/apps/pos/customer"
              className="rounded-xl border border-zinc-200 bg-white p-5 hover:border-zinc-300 hover:shadow-sm transition"
            >
              <div className="inline-flex p-2 rounded-lg bg-emerald-100 text-emerald-700">
                <Store className="w-5 h-5" />
              </div>
              <h3 className="mt-3 font-semibold text-zinc-900">Customer POS</h3>
              <p className="text-sm text-zinc-600 mt-1">Kelola link publik dan QR self-order customer per meja.</p>
              <p className="mt-4 inline-flex items-center gap-2 text-sm font-medium text-zinc-900">
                Buka Customer Access <ExternalLink className="w-4 h-4" />
              </p>
            </Link>
          </div>

          {payload.access.requires_legacy_admin_auth ? (
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
              Jalur POS saat ini masih memakai autentikasi legacy admin/cashier. Integrasi SSO Hellom bisa ditambahkan pada fase berikutnya.
            </div>
          ) : (
            <div className="rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
              SSO Hellom aktif. Akses POS tanpa login ulang.
            </div>
          )}
        </>
      )}
    </div>
  );
}
