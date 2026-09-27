import { useCallback, useEffect, useMemo, useState } from 'react';
import { Copy, ExternalLink, QrCode, RefreshCw, Store, TableProperties } from 'lucide-react';
import { getPosAccess, getPosTables } from '@/lib/hellomApi';

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

type PosTable = {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
  public_id: string;
};

function getPublicOrderUrl(publicId: string) {
  return `${window.location.origin}/customer/order/${publicId}`;
}

function getFriendlyCustomerUrl(organizationSlug: string) {
  return `${window.location.origin}/customer/${organizationSlug}`;
}

function getQrPreviewUrl(targetUrl: string) {
  return `https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=${encodeURIComponent(targetUrl)}`;
}

export default function PosCustomerHub() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [access, setAccess] = useState<PosAccessPayload | null>(null);
  const [tables, setTables] = useState<PosTable[]>([]);
  const [copiedId, setCopiedId] = useState<string | null>(null);
  const [qrTarget, setQrTarget] = useState<{ label: string; url: string } | null>(null);

  const loadData = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const [accessResult, tablesResult] = await Promise.all([
        getPosAccess(),
        getPosTables(),
      ]);

      setAccess(accessResult);
      setTables((tablesResult.tables || []).filter((table) => table.is_active));
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat akses customer POS.';
      setError(message);
      setAccess(null);
      setTables([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  const publicLinks = useMemo(
    () =>
      tables.map((table) => ({
        ...table,
        orderUrl: getPublicOrderUrl(table.public_id),
        label: table.name || table.code,
      })),
    [tables]
  );
  const friendlyCustomerUrl = access ? getFriendlyCustomerUrl(access.organization.slug) : '';

  const handleCopy = async (value: string, id: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopiedId(id);
      window.setTimeout(() => {
        setCopiedId((current) => (current === id ? null : current));
      }, 1800);
    } catch {
      setCopiedId(null);
    }
  };

  const publicBaseUrl = `${window.location.origin}/customer/{organizationSlug}`;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Customer POS Access</h1>
          <p className="mt-1 text-zinc-600">
            Kelola link publik self-order customer sesuai organisasi yang sedang aktif.
          </p>
        </div>
        <button
          type="button"
          onClick={() => void loadData()}
          className="inline-flex items-center gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
        >
          <RefreshCw className="w-4 h-4" />
          Refresh
        </button>
      </div>

      {loading && (
        <div className="rounded-xl border border-zinc-200 bg-white p-6 text-sm text-zinc-600">
          Menyiapkan link customer POS...
        </div>
      )}

      {!loading && error && (
        <div className="rounded-xl border border-red-200 bg-red-50 p-5 text-red-700">
          <p className="font-semibold">Akses customer POS belum siap</p>
          <p className="mt-1 text-sm">{error}</p>
        </div>
      )}

      {!loading && !error && access && (
        <>
          <div className="rounded-xl border border-zinc-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Organization Mapping</p>
            <div className="mt-3 grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
              <div>
                <p className="text-zinc-500">Organization</p>
                <p className="font-semibold text-zinc-900">{access.organization.name}</p>
              </div>
              <div>
                <p className="text-zinc-500">POS Tenant</p>
                <p className="font-semibold text-zinc-900">{access.organization.pos_tenant_slug}</p>
              </div>
              <div>
                <p className="text-zinc-500">Format Public URL</p>
                <p className="font-semibold text-zinc-900 break-all">{publicBaseUrl}</p>
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-amber-200 bg-amber-50 p-5">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <div className="text-sm text-amber-950">
                <p className="font-semibold">Link customer public yang lebih ramah</p>
                <p className="mt-1">
                  Gunakan link organisasi ini untuk customer umum. Sistem akan otomatis memakai meja aktif default tenant, sementara QR per meja tetap bisa dipakai untuk cafe besar.
                </p>
                <p className="mt-3 break-all font-medium">{friendlyCustomerUrl}</p>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <a
                  href={friendlyCustomerUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-3 py-2 text-sm font-medium text-[#111111] hover:bg-amber-400"
                >
                  <ExternalLink className="h-4 w-4" />
                  Buka halaman
                </a>
                <button
                  type="button"
                  onClick={() => void handleCopy(friendlyCustomerUrl, 'friendly-link')}
                  className="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100"
                >
                  <Copy className="h-4 w-4" />
                  {copiedId === 'friendly-link' ? 'Tersalin' : 'Copy link'}
                </button>
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
            <div className="flex items-start gap-3">
              <Store className="mt-0.5 h-5 w-5 text-emerald-700" />
              <div className="text-sm text-emerald-900">
                <p className="font-semibold">Alur customer yang benar</p>
                <p className="mt-1">
                  Customer bisa membuka link organisasi seperti
                  <span className="font-medium"> `/customer/slug-organisasi`</span> untuk halaman publik yang mudah diingat. Link QR meja seperti
                  <span className="font-medium"> `/customer/order/public_id_meja`</span> tetap aktif untuk kebutuhan per-meja.
                </p>
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-zinc-200 bg-white p-5">
            <div className="flex items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-zinc-900">Link self-order per meja</h2>
                <p className="mt-1 text-sm text-zinc-600">
                  Ini yang bisa Anda copy, buka, atau jadikan QR untuk ditempel di meja.
                </p>
              </div>
              <div className="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700">
                {publicLinks.length} meja aktif
              </div>
            </div>

            <div className="mt-5 space-y-4">
              {publicLinks.length === 0 ? (
                <div className="rounded-xl border border-dashed border-zinc-300 p-6 text-sm text-zinc-500">
                  Belum ada meja aktif. Tambahkan meja di modul POS Tables agar link publik customer bisa dibuat.
                </div>
              ) : (
                publicLinks.map((table) => (
                  <div key={table.id} className="rounded-2xl border border-zinc-200 p-4">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                      <div>
                        <div className="flex items-center gap-2">
                          <TableProperties className="h-4 w-4 text-zinc-500" />
                          <p className="font-semibold text-zinc-900">{table.label}</p>
                          <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600">
                            {table.code}
                          </span>
                        </div>
                        <p className="mt-2 break-all text-sm text-zinc-600">{table.orderUrl}</p>
                      </div>

                      <div className="flex flex-wrap items-center gap-2">
                        <a
                          href={table.orderUrl}
                          target="_blank"
                          rel="noreferrer"
                          className="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800"
                        >
                          <ExternalLink className="h-4 w-4" />
                          Buka halaman
                        </a>
                        <button
                          type="button"
                          onClick={() => void handleCopy(table.orderUrl, `table-${table.id}`)}
                          className="inline-flex items-center gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
                        >
                          <Copy className="h-4 w-4" />
                          {copiedId === `table-${table.id}` ? 'Tersalin' : 'Copy link'}
                        </button>
                        <button
                          type="button"
                          onClick={() => setQrTarget({ label: table.label, url: table.orderUrl })}
                          className="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100"
                        >
                          <QrCode className="h-4 w-4" />
                          Lihat QR
                        </button>
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </>
      )}

      {qrTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h3 className="text-lg font-semibold text-zinc-900">QR Self-order</h3>
                <p className="mt-1 text-sm text-zinc-600">{qrTarget.label}</p>
              </div>
              <button
                type="button"
                onClick={() => setQrTarget(null)}
                className="rounded-full border border-zinc-300 px-3 py-1 text-sm text-zinc-600 hover:bg-zinc-50"
              >
                Tutup
              </button>
            </div>

            <div className="mt-5 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
              <img
                src={getQrPreviewUrl(qrTarget.url)}
                alt={`QR self-order ${qrTarget.label}`}
                className="mx-auto h-64 w-64 rounded-xl bg-white p-3"
              />
            </div>

            <p className="mt-4 break-all text-sm text-zinc-600">{qrTarget.url}</p>

            <div className="mt-5 flex gap-3">
              <button
                type="button"
                onClick={() => void handleCopy(qrTarget.url, 'qr-link')}
                className="flex-1 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800"
              >
                {copiedId === 'qr-link' ? 'Link tersalin' : 'Copy link'}
              </button>
              <a
                href={qrTarget.url}
                target="_blank"
                rel="noreferrer"
                className="flex-1 rounded-lg border border-zinc-300 px-4 py-2 text-center text-sm font-medium text-zinc-700 hover:bg-zinc-50"
              >
                Buka halaman
              </a>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
