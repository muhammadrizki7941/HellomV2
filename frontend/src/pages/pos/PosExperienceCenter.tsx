import { useCallback, useEffect, useMemo, useState, type ChangeEvent, type FormEvent, type ReactNode } from 'react';
import {
  CalendarDays,
  Copy,
  ExternalLink,
  Gift,
  ImagePlus,
  Pencil,
  Plus,
  QrCode,
  RefreshCw,
  Store,
  TableProperties,
  Ticket,
  Trash2,
  X,
} from 'lucide-react';
import {
  createPosExperiencePromo,
  createPosExperienceSpace,
  deletePosExperiencePromo,
  deletePosExperienceSpace,
  getPosExperienceDashboard,
  type PosExperienceDashboard,
  type PosExperiencePromo,
  type PosExperienceReservation,
  type PosExperienceSpace,
  updatePosExperiencePromo,
  updatePosExperienceReservationStatus,
  updatePosExperienceSpace,
} from '@/lib/hellomApi';
import { getPosTables } from '@/lib/hellomApi';

type PosTable = {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
  public_id: string;
};

type PromoFormState = {
  title: string;
  promo_code: string;
  description: string;
  terms: string;
  link_url: string;
  bonus_points: string;
  minimum_spend: string;
  claim_limit: string;
  starts_at: string;
  ends_at: string;
  sort_order: string;
  is_active: boolean;
  requires_reservation: boolean;
  thumbnail: File | null;
  remove_thumbnail: boolean;
};

type SpaceItemForm = {
  id?: number;
  product_id: string;
  qty: string;
  sort_order: string;
  is_required: boolean;
};

type SpaceFormState = {
  name: string;
  location: string;
  capacity: string;
  description: string;
  rent_price: string;
  rent_enabled: boolean;
  min_menu_total: string;
  sort_order: string;
  is_active: boolean;
  items: SpaceItemForm[];
  images: File[];
  delete_image_ids: number[];
};

function formatCurrency(value: number) {
  return `Rp ${value.toLocaleString('id-ID')}`;
}

function toDateTimeInput(value: string | null | undefined) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function buildCustomerUrl(publicId: string, hash?: string) {
  const base = `${window.location.origin}/customer/order/${publicId}`;
  return hash ? `${base}#${hash}` : base;
}

function getQrPreviewUrl(targetUrl: string) {
  return `https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=${encodeURIComponent(targetUrl)}`;
}

function emptyPromoForm(): PromoFormState {
  return {
    title: '',
    promo_code: '',
    description: '',
    terms: '',
    link_url: '',
    bonus_points: '0',
    minimum_spend: '0',
    claim_limit: '',
    starts_at: '',
    ends_at: '',
    sort_order: '0',
    is_active: true,
    requires_reservation: false,
    thumbnail: null,
    remove_thumbnail: false,
  };
}

function emptySpaceForm(): SpaceFormState {
  return {
    name: '',
    location: '',
    capacity: '',
    description: '',
    rent_price: '0',
    rent_enabled: true,
    min_menu_total: '0',
    sort_order: '0',
    is_active: true,
    items: [],
    images: [],
    delete_image_ids: [],
  };
}

const inputClassName =
  'w-full rounded-2xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 outline-none transition focus:border-amber-300 focus:ring-2 focus:ring-amber-200';

export default function PosExperienceCenter() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [dashboard, setDashboard] = useState<PosExperienceDashboard | null>(null);
  const [tables, setTables] = useState<PosTable[]>([]);
  const [qrTarget, setQrTarget] = useState<{ label: string; url: string } | null>(null);
  const [copiedValue, setCopiedValue] = useState<string | null>(null);

  const [promoModalOpen, setPromoModalOpen] = useState(false);
  const [editingPromo, setEditingPromo] = useState<PosExperiencePromo | null>(null);
  const [promoForm, setPromoForm] = useState<PromoFormState>(emptyPromoForm);

  const [spaceModalOpen, setSpaceModalOpen] = useState(false);
  const [editingSpace, setEditingSpace] = useState<PosExperienceSpace | null>(null);
  const [spaceForm, setSpaceForm] = useState<SpaceFormState>(emptySpaceForm);

  const loadData = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const [experienceDashboard, tablesResponse] = await Promise.all([
        getPosExperienceDashboard(),
        getPosTables(),
      ]);

      setDashboard(experienceDashboard);
      setTables((tablesResponse.tables || []).filter((table) => table.is_active));
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : 'Gagal memuat data promo dan reservasi POS.');
      setDashboard(null);
      setTables([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  const tableLinks = useMemo(
    () =>
      tables.map((table) => ({
        ...table,
        label: table.name || table.code,
        orderUrl: buildCustomerUrl(table.public_id),
        promoUrl: buildCustomerUrl(table.public_id, 'promo'),
        reservationUrl: buildCustomerUrl(table.public_id, 'reservasi'),
      })),
    [tables]
  );

  const products = dashboard?.products || [];
  const promos = dashboard?.promos || [];
  const claims = dashboard?.promo_claims || [];
  const spaces = dashboard?.spaces || [];
  const reservations = dashboard?.reservations || [];

  const handleCopy = async (value: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopiedValue(value);
      window.setTimeout(() => setCopiedValue((current) => (current === value ? null : current)), 1800);
    } catch {
      setCopiedValue(null);
    }
  };

  const openCreatePromo = () => {
    setEditingPromo(null);
    setPromoForm(emptyPromoForm());
    setPromoModalOpen(true);
  };

  const openEditPromo = (promo: PosExperiencePromo) => {
    setEditingPromo(promo);
    setPromoForm({
      title: promo.title,
      promo_code: promo.promo_code || '',
      description: promo.description || '',
      terms: promo.terms || '',
      link_url: promo.link_url || '',
      bonus_points: String(promo.bonus_points || 0),
      minimum_spend: String(promo.minimum_spend || 0),
      claim_limit: promo.claim_limit ? String(promo.claim_limit) : '',
      starts_at: toDateTimeInput(promo.starts_at),
      ends_at: toDateTimeInput(promo.ends_at),
      sort_order: String(promo.sort_order || 0),
      is_active: promo.is_active,
      requires_reservation: promo.requires_reservation,
      thumbnail: null,
      remove_thumbnail: false,
    });
    setPromoModalOpen(true);
  };

  const openCreateSpace = () => {
    setEditingSpace(null);
    setSpaceForm(emptySpaceForm());
    setSpaceModalOpen(true);
  };

  const openEditSpace = (space: PosExperienceSpace) => {
    setEditingSpace(space);
    setSpaceForm({
      name: space.name,
      location: space.location || '',
      capacity: space.capacity ? String(space.capacity) : '',
      description: space.description || '',
      rent_price: String(space.rent_price || 0),
      rent_enabled: space.rent_enabled,
      min_menu_total: String(space.min_menu_total || 0),
      sort_order: String(space.sort_order || 0),
      is_active: space.is_active,
      items: space.items.map((item) => ({
        id: item.id,
        product_id: String(item.product_id),
        qty: String(item.qty),
        sort_order: String(item.sort_order),
        is_required: item.is_required,
      })),
      images: [],
      delete_image_ids: [],
    });
    setSpaceModalOpen(true);
  };

  const handlePromoSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError(null);

    const form = new FormData();
    form.append('title', promoForm.title);
    form.append('promo_code', promoForm.promo_code);
    form.append('description', promoForm.description);
    form.append('terms', promoForm.terms);
    form.append('link_url', promoForm.link_url);
    form.append('bonus_points', promoForm.bonus_points || '0');
    form.append('minimum_spend', promoForm.minimum_spend || '0');
    if (promoForm.claim_limit) form.append('claim_limit', promoForm.claim_limit);
    if (promoForm.starts_at) form.append('starts_at', promoForm.starts_at);
    if (promoForm.ends_at) form.append('ends_at', promoForm.ends_at);
    form.append('sort_order', promoForm.sort_order || '0');
    form.append('is_active', promoForm.is_active ? '1' : '0');
    form.append('requires_reservation', promoForm.requires_reservation ? '1' : '0');
    if (promoForm.remove_thumbnail) form.append('remove_thumbnail', '1');
    if (promoForm.thumbnail) form.append('thumbnail', promoForm.thumbnail);

    try {
      if (editingPromo) {
        await updatePosExperiencePromo(editingPromo.id, form);
      } else {
        await createPosExperiencePromo(form);
      }
      setPromoModalOpen(false);
      await loadData();
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : 'Gagal menyimpan promo.');
    } finally {
      setSaving(false);
    }
  };

  const handleDeletePromo = async (promo: PosExperiencePromo) => {
    if (!window.confirm(`Hapus promo "${promo.title}"?`)) return;
    try {
      await deletePosExperiencePromo(promo.id);
      await loadData();
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : 'Gagal menghapus promo.');
    }
  };

  const handleSpaceSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError(null);

    const form = new FormData();
    form.append('name', spaceForm.name);
    form.append('location', spaceForm.location);
    if (spaceForm.capacity) form.append('capacity', spaceForm.capacity);
    form.append('description', spaceForm.description);
    form.append('rent_price', spaceForm.rent_price || '0');
    form.append('rent_enabled', spaceForm.rent_enabled ? '1' : '0');
    form.append('min_menu_total', spaceForm.min_menu_total || '0');
    form.append('sort_order', spaceForm.sort_order || '0');
    form.append('is_active', spaceForm.is_active ? '1' : '0');

    spaceForm.items.forEach((item, index) => {
      if (item.id) form.append(`items[${index}][id]`, String(item.id));
      form.append(`items[${index}][product_id]`, item.product_id);
      form.append(`items[${index}][qty]`, item.qty || '1');
      form.append(`items[${index}][sort_order]`, item.sort_order || '0');
      form.append(`items[${index}][is_required]`, item.is_required ? '1' : '0');
    });

    spaceForm.delete_image_ids.forEach((id, index) => {
      form.append(`delete_image_ids[${index}]`, String(id));
    });

    spaceForm.images.forEach((image) => {
      form.append('images[]', image);
    });

    try {
      if (editingSpace) {
        await updatePosExperienceSpace(editingSpace.id, form);
      } else {
        await createPosExperienceSpace(form);
      }
      setSpaceModalOpen(false);
      await loadData();
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : 'Gagal menyimpan space reservasi.');
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteSpace = async (space: PosExperienceSpace) => {
    if (!window.confirm(`Hapus space "${space.name}"?`)) return;
    try {
      await deletePosExperienceSpace(space.id);
      await loadData();
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : 'Gagal menghapus space reservasi.');
    }
  };

  const handleUpdateReservationStatus = async (reservation: PosExperienceReservation, status: PosExperienceReservation['status']) => {
    try {
      await updatePosExperienceReservationStatus(reservation.id, {
        status,
        admin_notes: reservation.admin_notes || '',
      });
      await loadData();
    } catch (statusError) {
      setError(statusError instanceof Error ? statusError.message : 'Gagal memperbarui status reservasi.');
    }
  };

  const updateSpaceItem = (index: number, patch: Partial<SpaceItemForm>) => {
    setSpaceForm((current) => ({
      ...current,
      items: current.items.map((item, itemIndex) => (itemIndex === index ? { ...item, ...patch } : item)),
    }));
  };

  const selectedImageIds = new Set(spaceForm.delete_image_ids);

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="mx-auto max-w-7xl space-y-6">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Promo & Reservasi POS</h1>
            <p className="mt-1 text-gray-600">
              Kelola promo customer, loyalty entry-point, area reservasi, dan status booking dari satu layar.
            </p>
          </div>
          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={openCreatePromo}
              className="inline-flex items-center gap-2 rounded-lg bg-rose-500 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-600"
            >
              <Ticket className="h-4 w-4" />
              Promo Baru
            </button>
            <button
              type="button"
              onClick={openCreateSpace}
              className="inline-flex items-center gap-2 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600"
            >
              <CalendarDays className="h-4 w-4" />
              Space Baru
            </button>
            <button
              type="button"
              onClick={() => void loadData()}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
              <RefreshCw className="h-4 w-4" />
              Refresh
            </button>
          </div>
        </div>

        {error ? (
          <div className="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}</div>
        ) : null}

        <div className="grid gap-4 md:grid-cols-4">
          <MetricCard icon={<Ticket className="h-5 w-5" />} label="Promo aktif" value={dashboard?.summary.active_promos ?? 0} color="rose" />
          <MetricCard icon={<Gift className="h-5 w-5" />} label="Klaim promo" value={dashboard?.summary.promo_claims ?? 0} color="amber" />
          <MetricCard icon={<Store className="h-5 w-5" />} label="Space aktif" value={dashboard?.summary.active_spaces ?? 0} color="emerald" />
          <MetricCard icon={<CalendarDays className="h-5 w-5" />} label="Reservasi pending" value={dashboard?.summary.pending_reservations ?? 0} color="sky" />
        </div>

        <div className="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-gray-900">Link customer per meja</h2>
              <p className="mt-1 text-sm text-gray-600">
                Link ini langsung membuka halaman order, section promo, atau section reservasi customer.
              </p>
            </div>
            <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700">{tableLinks.length} meja aktif</span>
          </div>

          <div className="mt-5 space-y-4">
            {loading ? (
              <PanelEmpty text="Memuat link customer..." />
            ) : tableLinks.length === 0 ? (
              <PanelEmpty text="Belum ada meja aktif. Tambahkan dari modul Tables agar link publik customer tersedia." />
            ) : (
              tableLinks.map((table) => (
                <div key={table.id} className="rounded-2xl border border-gray-200 p-4">
                  <div className="mb-4 flex items-center gap-2">
                    <TableProperties className="h-4 w-4 text-gray-500" />
                    <p className="font-semibold text-gray-900">{table.label}</p>
                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{table.code}</span>
                  </div>

                  <div className="grid gap-3 lg:grid-cols-3">
                    <LinkCard title="Self-order" href={table.orderUrl} copied={copiedValue === table.orderUrl} onCopy={() => void handleCopy(table.orderUrl)} onQr={() => setQrTarget({ label: `${table.label} - Self-order`, url: table.orderUrl })} accent="amber" />
                    <LinkCard title="Promo" href={table.promoUrl} copied={copiedValue === table.promoUrl} onCopy={() => void handleCopy(table.promoUrl)} accent="rose" />
                    <LinkCard title="Reservasi" href={table.reservationUrl} copied={copiedValue === table.reservationUrl} onCopy={() => void handleCopy(table.reservationUrl)} accent="emerald" />
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        <div className="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
          <section className="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
            <div className="flex items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">Promo customer</h2>
                <p className="mt-1 text-sm text-gray-600">Buat promo yang bisa diambil customer, tampil di halaman order, dan bisa memberi bonus poin.</p>
              </div>
              <button type="button" onClick={openCreatePromo} className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100">
                <Plus className="mr-2 inline h-4 w-4" />
                Tambah
              </button>
            </div>

            <div className="mt-5 grid gap-4 lg:grid-cols-2">
              {promos.length === 0 ? (
                <PanelEmpty text="Belum ada promo customer. Mulai dari promo welcome, booking bonus, atau kode promo khusus event." />
              ) : (
                promos.map((promo) => (
                  <article key={promo.id} className="rounded-2xl border border-gray-200 p-4">
                    <div className="flex items-start justify-between gap-4">
                      <div>
                        <div className="inline-flex rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700">
                          {promo.promo_code || 'Promo publik'}
                        </div>
                        <h3 className="mt-3 text-lg font-semibold text-gray-900">{promo.title}</h3>
                        <p className="mt-2 text-sm leading-6 text-gray-600">{promo.description || 'Tanpa deskripsi tambahan.'}</p>
                      </div>
                      <div className="flex gap-2">
                        <button type="button" onClick={() => openEditPromo(promo)} className="rounded-lg border border-gray-200 p-2 text-gray-600 hover:bg-gray-50">
                          <Pencil className="h-4 w-4" />
                        </button>
                        <button type="button" onClick={() => void handleDeletePromo(promo)} className="rounded-lg border border-red-200 p-2 text-red-600 hover:bg-red-50">
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                    </div>

                    <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                      <PromoStat label="Bonus poin" value={promo.bonus_points > 0 ? `${promo.bonus_points} poin` : 'Tidak ada'} />
                      <PromoStat label="Klaim" value={promo.claim_limit ? `${promo.claimed_count}/${promo.claim_limit}` : `${promo.claimed_count}`} />
                      <PromoStat label="Min. belanja" value={promo.minimum_spend > 0 ? formatCurrency(promo.minimum_spend) : 'Tanpa syarat'} />
                      <PromoStat label="Reservasi" value={promo.requires_reservation ? 'Wajib booking' : 'Opsional'} />
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2 text-xs text-gray-500">
                      <span className={`rounded-full px-2 py-1 font-semibold ${promo.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600'}`}>
                        {promo.is_active ? 'Aktif' : 'Nonaktif'}
                      </span>
                      {promo.valid_until ? <span className="rounded-full bg-gray-100 px-2 py-1">Berlaku s.d. {promo.valid_until}</span> : null}
                    </div>
                  </article>
                ))
              )}
            </div>
          </section>

          <section className="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
            <div>
              <h2 className="text-lg font-semibold text-gray-900">Pelanggan yang ambil promo</h2>
              <p className="mt-1 text-sm text-gray-600">Setiap klaim akan membuat atau menghubungkan member loyalty berdasarkan nomor HP / email.</p>
            </div>

            <div className="mt-5 space-y-3">
              {claims.length === 0 ? (
                <PanelEmpty text="Belum ada klaim promo. Setelah customer claim di halaman order, datanya akan tampil di sini." />
              ) : (
                claims.map((claim) => (
                  <div key={claim.id} className="rounded-2xl border border-gray-200 p-4">
                    <div className="flex items-start justify-between gap-4">
                      <div>
                        <p className="text-sm font-semibold text-gray-900">{claim.customer_name}</p>
                        <p className="mt-1 text-sm text-gray-500">{claim.customer_phone || '-'} {claim.customer_email ? `• ${claim.customer_email}` : ''}</p>
                        <p className="mt-2 text-xs font-semibold uppercase tracking-wide text-rose-600">{claim.promo?.title || 'Promo'}</p>
                      </div>
                      <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                        +{claim.bonus_points_awarded} poin
                      </span>
                    </div>
                    {claim.member ? (
                      <div className="mt-3 rounded-2xl bg-gray-50 px-3 py-2 text-xs text-gray-600">
                        Member: {claim.member.name} • Tier {claim.member.tier} • Saldo {claim.member.redeemable_points} poin
                      </div>
                    ) : null}
                  </div>
                ))
              )}
            </div>
          </section>
        </div>

        <div className="grid gap-6 xl:grid-cols-[1.12fr_0.88fr]">
          <section className="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
            <div className="flex items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">Space reservasi & paket add-on</h2>
                <p className="mt-1 text-sm text-gray-600">Atur tempat, foto, harga sewa, minimal menu, dan paket menu yang bisa dipilih customer saat booking.</p>
              </div>
              <button type="button" onClick={openCreateSpace} className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
                <Plus className="mr-2 inline h-4 w-4" />
                Tambah
              </button>
            </div>

            <div className="mt-5 space-y-4">
              {spaces.length === 0 ? (
                <PanelEmpty text="Belum ada area reservasi. Buat area family, VIP room, gathering, atau paket ulang tahun agar langsung bisa dibooking customer." />
              ) : (
                spaces.map((space) => (
                  <article key={space.id} className="overflow-hidden rounded-2xl border border-gray-200">
                    <div className="grid gap-4 p-4 lg:grid-cols-[220px_1fr]">
                      <div className="overflow-hidden rounded-2xl bg-gray-100">
                        {space.cover_image_url ? (
                          <img src={space.cover_image_url} alt={space.name} className="h-full min-h-[180px] w-full object-cover" />
                        ) : (
                          <div className="flex min-h-[180px] items-center justify-center text-gray-400">
                            <ImagePlus className="h-8 w-8" />
                          </div>
                        )}
                      </div>

                      <div>
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <div className="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                              {space.is_active ? 'Aktif' : 'Nonaktif'}
                            </div>
                            <h3 className="mt-3 text-lg font-semibold text-gray-900">{space.name}</h3>
                            <p className="mt-1 text-sm text-gray-500">{space.location || 'Lokasi belum diisi'} • Kapasitas {space.capacity || 0} orang</p>
                            <p className="mt-3 text-sm leading-6 text-gray-600">{space.description || 'Belum ada deskripsi tempat.'}</p>
                          </div>
                          <div className="flex gap-2">
                            <button type="button" onClick={() => openEditSpace(space)} className="rounded-lg border border-gray-200 p-2 text-gray-600 hover:bg-gray-50">
                              <Pencil className="h-4 w-4" />
                            </button>
                            <button type="button" onClick={() => void handleDeleteSpace(space)} className="rounded-lg border border-red-200 p-2 text-red-600 hover:bg-red-50">
                              <Trash2 className="h-4 w-4" />
                            </button>
                          </div>
                        </div>

                        <div className="mt-4 grid gap-3 md:grid-cols-4">
                          <PromoStat label="Sewa tempat" value={space.rent_enabled ? formatCurrency(space.rent_price) : 'Tanpa sewa'} />
                          <PromoStat label="Min. custom menu" value={space.min_menu_total > 0 ? formatCurrency(space.min_menu_total) : 'Tidak wajib'} />
                          <PromoStat label="Poin estimasi" value={`${space.estimated_points} poin`} />
                          <PromoStat label="Foto" value={`${space.images.length} gambar`} />
                        </div>

                        <div className="mt-4">
                          <p className="text-sm font-semibold text-gray-900">Add-on paket</p>
                          <div className="mt-2 flex flex-wrap gap-2">
                            {space.items.length === 0 ? (
                              <span className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-500">Belum ada item paket</span>
                            ) : (
                              space.items.map((item) => (
                                <span key={item.id} className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700">
                                  {item.product_name} x{item.qty} • {formatCurrency(item.line_total)} {item.is_required ? '• wajib' : '• opsional'}
                                </span>
                              ))
                            )}
                          </div>
                        </div>
                      </div>
                    </div>
                  </article>
                ))
              )}
            </div>
          </section>

          <section className="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
            <div>
              <h2 className="text-lg font-semibold text-gray-900">Booking customer masuk</h2>
              <p className="mt-1 text-sm text-gray-600">Pantau booking baru, cek detail paket dan custom menu, lalu ubah status sesuai proses operasional tenant.</p>
            </div>

            <div className="mt-5 space-y-3">
              {reservations.length === 0 ? (
                <PanelEmpty text="Belum ada reservasi masuk." />
              ) : (
                reservations.map((reservation) => (
                  <div key={reservation.id} className="rounded-2xl border border-gray-200 p-4">
                    <div className="flex items-start justify-between gap-4">
                      <div>
                        <p className="text-sm font-semibold text-gray-900">{reservation.customer_name}</p>
                        <p className="mt-1 text-sm text-gray-500">{reservation.space_name} • {reservation.customer_phone}</p>
                        <p className="mt-1 text-xs text-gray-500">{reservation.scheduled_at ? new Date(reservation.scheduled_at).toLocaleString('id-ID') : '-'} • {reservation.guests_count} tamu</p>
                      </div>
                      <select
                        value={reservation.status}
                        onChange={(event) => void handleUpdateReservationStatus(reservation, event.target.value as PosExperienceReservation['status'])}
                        className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700"
                      >
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                      </select>
                    </div>

                    <div className="mt-4 grid gap-3 md:grid-cols-3">
                      <PromoStat label="Total" value={formatCurrency(reservation.total_price)} />
                      <PromoStat label="Add-on paket" value={formatCurrency(reservation.items_total)} />
                      <PromoStat label="Custom menu" value={formatCurrency(reservation.menu_commitment_total)} />
                    </div>

                    <div className="mt-4 space-y-2 text-xs text-gray-600">
                      {reservation.items_snapshot.length > 0 ? (
                        <div className="rounded-2xl bg-gray-50 p-3">
                          Paket: {reservation.items_snapshot.map((item) => `${String((item as { product_name?: string }).product_name || 'Item')} x${String((item as { qty?: number }).qty || 0)}`).join(', ')}
                        </div>
                      ) : null}
                      {reservation.menu_order_snapshot.length > 0 ? (
                        <div className="rounded-2xl bg-gray-50 p-3">
                          Custom menu: {reservation.menu_order_snapshot.map((item) => `${String((item as { product_name?: string }).product_name || 'Menu')} x${String((item as { qty?: number }).qty || 0)}`).join(', ')}
                        </div>
                      ) : null}
                    </div>
                  </div>
                ))
              )}
            </div>
          </section>
        </div>
      </div>

      {promoModalOpen ? (
        <ModalShell title={editingPromo ? 'Edit Promo Customer' : 'Buat Promo Customer'} onClose={() => setPromoModalOpen(false)}>
          <form onSubmit={handlePromoSubmit} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <Field label="Judul promo">
                <input value={promoForm.title} onChange={(event) => setPromoForm((current) => ({ ...current, title: event.target.value }))} required className={inputClassName} />
              </Field>
              <Field label="Kode promo">
                <input value={promoForm.promo_code} onChange={(event) => setPromoForm((current) => ({ ...current, promo_code: event.target.value.toUpperCase() }))} placeholder="WELCOME50" className={inputClassName} />
              </Field>
            </div>

            <Field label="Deskripsi">
              <textarea value={promoForm.description} onChange={(event) => setPromoForm((current) => ({ ...current, description: event.target.value }))} rows={3} className={inputClassName} />
            </Field>

            <Field label="Syarat & ketentuan">
              <textarea value={promoForm.terms} onChange={(event) => setPromoForm((current) => ({ ...current, terms: event.target.value }))} rows={3} className={inputClassName} />
            </Field>

            <div className="grid gap-4 md:grid-cols-3">
              <Field label="Bonus poin">
                <input type="number" min="0" value={promoForm.bonus_points} onChange={(event) => setPromoForm((current) => ({ ...current, bonus_points: event.target.value }))} className={inputClassName} />
              </Field>
              <Field label="Minimal belanja">
                <input type="number" min="0" value={promoForm.minimum_spend} onChange={(event) => setPromoForm((current) => ({ ...current, minimum_spend: event.target.value }))} className={inputClassName} />
              </Field>
              <Field label="Batas klaim">
                <input type="number" min="1" value={promoForm.claim_limit} onChange={(event) => setPromoForm((current) => ({ ...current, claim_limit: event.target.value }))} placeholder="Kosong = tanpa batas" className={inputClassName} />
              </Field>
            </div>

            <div className="grid gap-4 md:grid-cols-3">
              <Field label="Mulai">
                <input type="datetime-local" value={promoForm.starts_at} onChange={(event) => setPromoForm((current) => ({ ...current, starts_at: event.target.value }))} className={inputClassName} />
              </Field>
              <Field label="Berakhir">
                <input type="datetime-local" value={promoForm.ends_at} onChange={(event) => setPromoForm((current) => ({ ...current, ends_at: event.target.value }))} className={inputClassName} />
              </Field>
              <Field label="Urutan tampil">
                <input type="number" min="0" value={promoForm.sort_order} onChange={(event) => setPromoForm((current) => ({ ...current, sort_order: event.target.value }))} className={inputClassName} />
              </Field>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <Field label="Link CTA (opsional)">
                <input value={promoForm.link_url} onChange={(event) => setPromoForm((current) => ({ ...current, link_url: event.target.value }))} placeholder="https://..." className={inputClassName} />
              </Field>
              <Field label="Thumbnail promo">
                <input type="file" accept="image/*" onChange={(event) => setPromoForm((current) => ({ ...current, thumbnail: event.target.files?.[0] || null }))} className={inputClassName} />
              </Field>
            </div>

            <div className="flex flex-wrap gap-4 text-sm">
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={promoForm.is_active} onChange={(event) => setPromoForm((current) => ({ ...current, is_active: event.target.checked }))} />
                Promo aktif
              </label>
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={promoForm.requires_reservation} onChange={(event) => setPromoForm((current) => ({ ...current, requires_reservation: event.target.checked }))} />
                Khusus customer reservasi
              </label>
              {editingPromo?.thumbnail_url ? (
                <label className="inline-flex items-center gap-2">
                  <input type="checkbox" checked={promoForm.remove_thumbnail} onChange={(event) => setPromoForm((current) => ({ ...current, remove_thumbnail: event.target.checked }))} />
                  Hapus thumbnail lama
                </label>
              ) : null}
            </div>

            <ModalActions saving={saving} submitLabel={editingPromo ? 'Simpan Perubahan Promo' : 'Buat Promo'} onClose={() => setPromoModalOpen(false)} />
          </form>
        </ModalShell>
      ) : null}

      {spaceModalOpen ? (
        <ModalShell title={editingSpace ? 'Edit Space Reservasi' : 'Buat Space Reservasi'} onClose={() => setSpaceModalOpen(false)} wide>
          <form onSubmit={handleSpaceSubmit} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <Field label="Nama space">
                <input value={spaceForm.name} onChange={(event) => setSpaceForm((current) => ({ ...current, name: event.target.value }))} required className={inputClassName} />
              </Field>
              <Field label="Lokasi">
                <input value={spaceForm.location} onChange={(event) => setSpaceForm((current) => ({ ...current, location: event.target.value }))} className={inputClassName} />
              </Field>
            </div>

            <Field label="Deskripsi">
              <textarea value={spaceForm.description} onChange={(event) => setSpaceForm((current) => ({ ...current, description: event.target.value }))} rows={3} className={inputClassName} />
            </Field>

            <div className="grid gap-4 md:grid-cols-4">
              <Field label="Kapasitas">
                <input type="number" min="1" value={spaceForm.capacity} onChange={(event) => setSpaceForm((current) => ({ ...current, capacity: event.target.value }))} className={inputClassName} />
              </Field>
              <Field label="Harga sewa">
                <input type="number" min="0" value={spaceForm.rent_price} onChange={(event) => setSpaceForm((current) => ({ ...current, rent_price: event.target.value }))} className={inputClassName} />
              </Field>
              <Field label="Min. custom menu">
                <input type="number" min="0" value={spaceForm.min_menu_total} onChange={(event) => setSpaceForm((current) => ({ ...current, min_menu_total: event.target.value }))} className={inputClassName} />
              </Field>
              <Field label="Urutan tampil">
                <input type="number" min="0" value={spaceForm.sort_order} onChange={(event) => setSpaceForm((current) => ({ ...current, sort_order: event.target.value }))} className={inputClassName} />
              </Field>
            </div>

            <div className="flex flex-wrap gap-4 text-sm">
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={spaceForm.rent_enabled} onChange={(event) => setSpaceForm((current) => ({ ...current, rent_enabled: event.target.checked }))} />
                Sewa tempat aktif
              </label>
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={spaceForm.is_active} onChange={(event) => setSpaceForm((current) => ({ ...current, is_active: event.target.checked }))} />
                Space aktif
              </label>
            </div>

            <div className="rounded-2xl border border-gray-200 p-4">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <h3 className="font-semibold text-gray-900">Add-on paket makanan</h3>
                  <p className="mt-1 text-sm text-gray-600">Pilih produk menu yang boleh dibundling dengan reservasi. Customer nanti bisa pilih paket opsional atau default yang wajib.</p>
                </div>
                <button
                  type="button"
                  onClick={() => setSpaceForm((current) => ({
                    ...current,
                    items: [...current.items, { product_id: products[0] ? String(products[0].id) : '', qty: '1', sort_order: String(current.items.length), is_required: false }],
                  }))}
                  className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700"
                >
                  <Plus className="mr-2 inline h-4 w-4" />
                  Tambah item
                </button>
              </div>

              <div className="mt-4 space-y-3">
                {spaceForm.items.length === 0 ? (
                  <PanelEmpty text="Belum ada item paket. Anda tetap bisa membuat space reservasi tanpa paket bawaan." />
                ) : (
                  spaceForm.items.map((item, index) => (
                    <div key={`${item.id || 'new'}-${index}`} className="grid gap-3 rounded-2xl border border-gray-200 p-3 md:grid-cols-[1.8fr_0.7fr_0.7fr_auto]">
                      <select value={item.product_id} onChange={(event) => updateSpaceItem(index, { product_id: event.target.value })} className={inputClassName}>
                        <option value="">Pilih produk</option>
                        {products.map((product) => (
                          <option key={product.id} value={product.id}>
                            {product.name} • {formatCurrency(product.price)}
                          </option>
                        ))}
                      </select>
                      <input type="number" min="1" value={item.qty} onChange={(event) => updateSpaceItem(index, { qty: event.target.value })} className={inputClassName} placeholder="Qty" />
                      <label className="inline-flex items-center gap-2 rounded-2xl border border-gray-200 px-3 py-2 text-sm text-gray-700">
                        <input type="checkbox" checked={item.is_required} onChange={(event) => updateSpaceItem(index, { is_required: event.target.checked })} />
                        Wajib
                      </label>
                      <button type="button" onClick={() => setSpaceForm((current) => ({ ...current, items: current.items.filter((_, itemIndex) => itemIndex !== index) }))} className="rounded-2xl border border-red-200 px-4 py-2 text-sm font-medium text-red-600">
                        Hapus
                      </button>
                    </div>
                  ))
                )}
              </div>
            </div>

            <div className="rounded-2xl border border-gray-200 p-4">
              <h3 className="font-semibold text-gray-900">Foto tempat</h3>
              <p className="mt-1 text-sm text-gray-600">Upload beberapa foto agar halaman reservasi customer lebih meyakinkan.</p>

              <input
                type="file"
                accept="image/*"
                multiple
                onChange={(event: ChangeEvent<HTMLInputElement>) => {
                  const files = Array.from(event.target.files || []);
                  setSpaceForm((current) => ({ ...current, images: files }));
                }}
                className={`${inputClassName} mt-3`}
              />

              {editingSpace?.images.length ? (
                <div className="mt-4 grid gap-3 md:grid-cols-3">
                  {editingSpace.images.map((image) => (
                    <label key={image.id} className={`overflow-hidden rounded-2xl border ${selectedImageIds.has(image.id) ? 'border-red-300 bg-red-50' : 'border-gray-200'}`}>
                      <img src={image.url} alt={image.caption || editingSpace.name} className="h-28 w-full object-cover" />
                      <div className="flex items-center gap-2 px-3 py-2 text-xs text-gray-700">
                        <input
                          type="checkbox"
                          checked={selectedImageIds.has(image.id)}
                          onChange={(event) =>
                            setSpaceForm((current) => ({
                              ...current,
                              delete_image_ids: event.target.checked
                                ? [...current.delete_image_ids, image.id]
                                : current.delete_image_ids.filter((id) => id !== image.id),
                            }))
                          }
                        />
                        Hapus foto ini
                      </div>
                    </label>
                  ))}
                </div>
              ) : null}
            </div>

            <ModalActions saving={saving} submitLabel={editingSpace ? 'Simpan Perubahan Space' : 'Buat Space'} onClose={() => setSpaceModalOpen(false)} />
          </form>
        </ModalShell>
      ) : null}

      {qrTarget ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h3 className="text-lg font-semibold text-gray-900">QR Self-order</h3>
                <p className="mt-1 text-sm text-gray-600">{qrTarget.label}</p>
              </div>
              <button type="button" onClick={() => setQrTarget(null)} className="rounded-full border border-gray-300 px-3 py-1 text-sm text-gray-600 hover:bg-gray-50">
                Tutup
              </button>
            </div>

            <div className="mt-5 rounded-2xl border border-gray-200 bg-gray-50 p-4">
              <img src={getQrPreviewUrl(qrTarget.url)} alt={`QR ${qrTarget.label}`} className="mx-auto h-64 w-64 rounded-xl bg-white p-3" />
            </div>

            <p className="mt-4 break-all text-sm text-gray-600">{qrTarget.url}</p>

            <div className="mt-5 flex gap-3">
              <button type="button" onClick={() => void handleCopy(qrTarget.url)} className="flex-1 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                {copiedValue === qrTarget.url ? 'Link tersalin' : 'Copy link'}
              </button>
              <a href={qrTarget.url} target="_blank" rel="noreferrer" className="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-50">
                Buka halaman
              </a>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function MetricCard({ icon, label, value, color }: { icon: ReactNode; label: string; value: number; color: 'rose' | 'amber' | 'emerald' | 'sky' }) {
  const classes = {
    rose: 'bg-rose-100 text-rose-700',
    amber: 'bg-amber-100 text-amber-700',
    emerald: 'bg-emerald-100 text-emerald-700',
    sky: 'bg-sky-100 text-sky-700',
  }[color];

  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
      <div className={`inline-flex rounded-2xl p-3 ${classes}`}>{icon}</div>
      <p className="mt-4 text-sm text-gray-500">{label}</p>
      <p className="text-3xl font-bold text-gray-900">{value}</p>
    </div>
  );
}

function PanelEmpty({ text }: { text: string }) {
  return <div className="rounded-2xl border border-dashed border-gray-300 p-6 text-sm text-gray-500">{text}</div>;
}

function PromoStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-2xl bg-gray-50 px-3 py-2">
      <p className="text-xs text-gray-500">{label}</p>
      <p className="mt-1 text-sm font-semibold text-gray-900">{value}</p>
    </div>
  );
}

function LinkCard({
  title,
  href,
  copied,
  onCopy,
  accent,
  onQr,
}: {
  title: string;
  href: string;
  copied: boolean;
  onCopy: () => void;
  accent: 'amber' | 'rose' | 'emerald';
  onQr?: () => void;
}) {
  const accentClass = {
    amber: 'border-amber-200 bg-amber-50/70',
    rose: 'border-rose-200 bg-rose-50/70',
    emerald: 'border-emerald-200 bg-emerald-50/70',
  }[accent];

  return (
    <div className={`rounded-2xl border p-4 ${accentClass}`}>
      <p className="font-semibold text-gray-900">{title}</p>
      <p className="mt-3 break-all text-xs text-gray-500">{href}</p>
      <div className="mt-4 flex flex-wrap gap-2">
        <a href={href} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800">
          <ExternalLink className="h-4 w-4" />
          Buka
        </a>
        <button type="button" onClick={onCopy} className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
          <Copy className="h-4 w-4" />
          {copied ? 'Tersalin' : 'Copy'}
        </button>
        {onQr ? (
          <button type="button" onClick={onQr} className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <QrCode className="h-4 w-4" />
            QR
          </button>
        ) : null}
      </div>
    </div>
  );
}

function ModalShell({
  title,
  children,
  onClose,
  wide = false,
}: {
  title: string;
  children: ReactNode;
  onClose: () => void;
  wide?: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/35 p-4 backdrop-blur-sm">
      <div className={`max-h-[92vh] w-full overflow-y-auto rounded-3xl bg-white shadow-2xl ${wide ? 'max-w-5xl' : 'max-w-3xl'}`}>
        <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-gray-900">{title}</h2>
          <button type="button" onClick={onClose} className="rounded-full border border-gray-200 p-2 text-gray-500 hover:bg-gray-50">
            <X className="h-4 w-4" />
          </button>
        </div>
        <div className="px-6 py-5">{children}</div>
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-medium text-gray-700">{label}</span>
      {children}
    </label>
  );
}

function ModalActions({
  saving,
  submitLabel,
  onClose,
}: {
  saving: boolean;
  submitLabel: string;
  onClose: () => void;
}) {
  return (
    <div className="flex justify-end gap-3 pt-2">
      <button type="button" onClick={onClose} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
        Batal
      </button>
      <button type="submit" disabled={saving} className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:opacity-60">
        {saving ? 'Menyimpan...' : submitLabel}
      </button>
    </div>
  );
}
