import { useEffect, useMemo, useState } from 'react';
import {
  AlertCircle,
  BadgePercent,
  CalendarDays,
  CheckCircle2,
  Clock3,
  Edit3,
  LayoutTemplate,
  Package,
  Plus,
  Rocket,
  Save,
  ShoppingCart,
  Sparkles,
  Tag,
  Trash2,
  Wallet,
  X,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  createAdminPlan,
  createAdminPromo,
  deleteAdminPlan,
  deleteAdminPromo,
  getAdminApps,
  getAdminPlans,
  getAdminPromos,
  updateAdminApp,
  updateAdminPlan,
  updateAdminPromo,
} from '@/lib/hellomApi';

type AppCatalogItem = {
  id: number;
  slug: string;
  name: string;
  is_active: boolean;
  active_count: number;
};

type AdminPlanItem = {
  id: number;
  slug: string;
  name: string;
  type: string;
  price: number;
  description?: string | null;
  features?: unknown;
  billing_cycles?: string[] | null;
  duration_days?: number | null;
  is_visible?: boolean;
  is_recommended?: boolean;
  sort_order?: number;
  is_active: boolean;
  subscriptions_count: number;
};

type PromoItem = {
  id: number;
  code: string;
  name: string;
  description: string | null;
  type: 'percentage' | 'fixed';
  value: number;
  max_slots: number | null;
  used_slots: number;
  app_id: number | null;
  plan_id: number | null;
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
};

type PlanFormState = {
  slug: string;
  name: string;
  type: 'free' | 'subscription' | 'one_time' | 'lifetime';
  price: number;
  description: string;
  featuresText: string;
  billingMonthly: boolean;
  billingYearly: boolean;
  duration_days: string;
  is_visible: boolean;
  is_recommended: boolean;
  sort_order: number;
  is_active: boolean;
};

type PromoFormState = {
  id: number | null;
  code: string;
  name: string;
  description: string;
  type: 'percentage' | 'fixed';
  value: number;
  max_slots: string;
  app_id: string;
  plan_id: string;
  is_active: boolean;
  starts_at: string;
  ends_at: string;
};

const INITIAL_PLAN_FORM: PlanFormState = {
  slug: '',
  name: '',
  type: 'subscription',
  price: 0,
  description: '',
  featuresText: '',
  billingMonthly: true,
  billingYearly: false,
  duration_days: '30',
  is_visible: true,
  is_recommended: false,
  sort_order: 0,
  is_active: true,
};

const INITIAL_PROMO_FORM: PromoFormState = {
  id: null,
  code: '',
  name: '',
  description: '',
  type: 'percentage',
  value: 10,
  max_slots: '',
  app_id: '',
  plan_id: '',
  is_active: true,
  starts_at: '',
  ends_at: '',
};

function formatCurrency(amount: number) {
  return `Rp ${amount.toLocaleString('id-ID')}`;
}

function featuresToText(features: unknown): string {
  if (Array.isArray(features)) {
    return features
      .map((feature) => {
        if (typeof feature === 'string') return feature;
        if (feature && typeof feature === 'object' && 'label' in feature) {
          return String((feature as { label?: unknown }).label || '');
        }
        return '';
      })
      .filter(Boolean)
      .join('\n');
  }

  if (features && typeof features === 'object') {
    return Object.entries(features as Record<string, unknown>)
      .filter(([, value]) => value === true || typeof value === 'number' || typeof value === 'string')
      .map(([key, value]) => {
        const label = key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
        if (value === true) return label;
        if (value === -1) return `${label}: unlimited`;
        return `${label}: ${String(value)}`;
      })
      .join('\n');
  }

  return '';
}

function featuresTextToArray(value: string): string[] {
  return value
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);
}

function looksLikePosPlan(form: Pick<PlanFormState, 'slug' | 'name' | 'description'>): boolean {
  return /^pos_/i.test(form.slug.trim())
    || /\bpos\b/i.test(form.name)
    || /\bpos\b/i.test(form.description);
}

function isVisibleInPosCheckout(plan: Pick<AdminPlanItem, 'slug' | 'is_active' | 'is_visible'>): boolean {
  return /^pos_/i.test(plan.slug) && Boolean(plan.is_active) && Boolean(plan.is_visible ?? true);
}

function toDateInput(value: string | null) {
  return value ? value.slice(0, 10) : '';
}

export default function AppManagement() {
  const [apps, setApps] = useState<AppCatalogItem[]>([]);
  const [plans, setPlans] = useState<AdminPlanItem[]>([]);
  const [promos, setPromos] = useState<PromoItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [planModalOpen, setPlanModalOpen] = useState(false);
  const [promoModalOpen, setPromoModalOpen] = useState(false);
  const [editingPlanId, setEditingPlanId] = useState<number | null>(null);
  const [editingPromoId, setEditingPromoId] = useState<number | null>(null);
  const [planForm, setPlanForm] = useState<PlanFormState>(INITIAL_PLAN_FORM);
  const [promoForm, setPromoForm] = useState<PromoFormState>(INITIAL_PROMO_FORM);

  const loadData = async () => {
    setLoading(true);
    setErrorMessage(null);
    try {
      const [appsRes, plansRes, promosRes] = await Promise.all([
        getAdminApps(),
        getAdminPlans(),
        getAdminPromos(),
      ]);

      setApps((appsRes.items || []) as AppCatalogItem[]);
      setPlans((plansRes.items || []) as AdminPlanItem[]);
      setPromos((promosRes.items || []) as PromoItem[]);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal memuat pricing setup');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadData();
  }, []);

  const totals = useMemo(() => {
    const activeApps = apps.filter((app) => app.is_active).length;
    const activePlans = plans.filter((plan) => plan.is_active).length;
    const activePromos = promos.filter((promo) => promo.is_active).length;
    const subscriptions = plans.reduce((sum, plan) => sum + (plan.subscriptions_count || 0), 0);

    return {
      activeApps,
      activePlans,
      activePromos,
      subscriptions,
    };
  }, [apps, plans, promos]);

  const promoDecorated = useMemo(() => {
    const appMap = new Map(apps.map((app) => [app.id, app]));
    const planMap = new Map(plans.map((plan) => [plan.id, plan]));

    return promos.map((promo) => ({
      ...promo,
      appName: promo.app_id ? appMap.get(promo.app_id)?.name ?? 'Semua aplikasi' : 'Semua aplikasi',
      planName: promo.plan_id ? planMap.get(promo.plan_id)?.name ?? 'Semua plan' : 'Semua plan',
    }));
  }, [apps, plans, promos]);

  const resetPlanModal = () => {
    setEditingPlanId(null);
    setPlanForm(INITIAL_PLAN_FORM);
    setPlanModalOpen(false);
  };

  const resetPromoModal = () => {
    setEditingPromoId(null);
    setPromoForm(INITIAL_PROMO_FORM);
    setPromoModalOpen(false);
  };

  const openPlanModal = (plan?: AdminPlanItem) => {
    if (plan) {
      setEditingPlanId(plan.id);
      setPlanForm({
        slug: plan.slug,
        name: plan.name,
        type: (plan.type as PlanFormState['type']) || 'subscription',
        price: plan.price,
        description: plan.description || '',
        featuresText: featuresToText(plan.features),
        billingMonthly: Boolean(plan.billing_cycles?.includes('monthly')) || (plan.type === 'subscription' && !plan.billing_cycles?.length),
        billingYearly: Boolean(plan.billing_cycles?.includes('yearly')),
        duration_days: plan.duration_days ? String(plan.duration_days) : '',
        is_visible: plan.is_visible ?? true,
        is_recommended: Boolean(plan.is_recommended),
        sort_order: Number(plan.sort_order || 0),
        is_active: plan.is_active,
      });
    } else {
      setEditingPlanId(null);
      setPlanForm(INITIAL_PLAN_FORM);
    }

    setPlanModalOpen(true);
  };

  const openPromoModal = (promo?: PromoItem) => {
    if (promo) {
      setEditingPromoId(promo.id);
      setPromoForm({
        id: promo.id,
        code: promo.code,
        name: promo.name,
        description: promo.description || '',
        type: promo.type,
        value: promo.value,
        max_slots: promo.max_slots ? String(promo.max_slots) : '',
        app_id: promo.app_id ? String(promo.app_id) : '',
        plan_id: promo.plan_id ? String(promo.plan_id) : '',
        is_active: promo.is_active,
        starts_at: toDateInput(promo.starts_at),
        ends_at: toDateInput(promo.ends_at),
      });
    } else {
      setEditingPromoId(null);
      setPromoForm(INITIAL_PROMO_FORM);
    }

    setPromoModalOpen(true);
  };

  const handlePlanSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrorMessage(null);
    setSuccessMessage(null);

    try {
      const normalizedSlug = planForm.slug.trim();
      if (looksLikePosPlan(planForm) && !/^pos_/i.test(normalizedSlug)) {
        setErrorMessage('Plan POS wajib memakai slug diawali "pos_" agar tampil di modal checkout POS.');
        return;
      }

      const billingCycles = [
        ...(planForm.billingMonthly ? ['monthly'] : []),
        ...(planForm.billingYearly ? ['yearly'] : []),
      ];
      const payload = {
        name: planForm.name,
        type: planForm.type,
        price: planForm.price,
        description: planForm.description.trim() || null,
        features: featuresTextToArray(planForm.featuresText),
        billing_cycles: billingCycles,
        duration_days: planForm.duration_days ? Number(planForm.duration_days) : null,
        is_visible: planForm.is_visible,
        is_recommended: planForm.is_recommended,
        sort_order: planForm.sort_order,
        is_active: planForm.is_active,
      };

      if (editingPlanId) {
        await updateAdminPlan(editingPlanId, payload);
        setSuccessMessage(`Plan "${planForm.name}" berhasil diperbarui.`);
      } else {
        await createAdminPlan({
          ...payload,
          slug: normalizedSlug,
        });
        setSuccessMessage(`Plan "${planForm.name}" berhasil dibuat.`);
      }

      resetPlanModal();
      await loadData();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal menyimpan plan');
    } finally {
      setSaving(false);
    }
  };

  const handleDeletePlan = async (plan: AdminPlanItem) => {
    if (!window.confirm(`Hapus plan ${plan.name}?`)) return;

    setSaving(true);
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      await deleteAdminPlan(plan.id);
      setSuccessMessage(`Plan "${plan.name}" berhasil dihapus.`);
      await loadData();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal menghapus plan');
    } finally {
      setSaving(false);
    }
  };

  const handlePromoSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrorMessage(null);
    setSuccessMessage(null);

    const payload = {
      code: promoForm.code.trim().toUpperCase(),
      name: promoForm.name.trim(),
      description: promoForm.description.trim() || undefined,
      type: promoForm.type,
      value: promoForm.value,
      max_slots: promoForm.max_slots ? Number(promoForm.max_slots) : undefined,
      app_id: promoForm.app_id ? Number(promoForm.app_id) : undefined,
      plan_id: promoForm.plan_id ? Number(promoForm.plan_id) : undefined,
      is_active: promoForm.is_active,
      starts_at: promoForm.starts_at || undefined,
      ends_at: promoForm.ends_at || undefined,
    };

    try {
      if (editingPromoId) {
        await updateAdminPromo(editingPromoId, payload);
        setSuccessMessage(`Promo "${promoForm.name}" berhasil diperbarui.`);
      } else {
        await createAdminPromo(payload);
        setSuccessMessage(`Promo "${promoForm.name}" berhasil dibuat.`);
      }

      resetPromoModal();
      await loadData();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal menyimpan promo');
    } finally {
      setSaving(false);
    }
  };

  const handleDeletePromo = async (promo: PromoItem) => {
    if (!window.confirm(`Hapus promo ${promo.code}?`)) return;

    setSaving(true);
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      await deleteAdminPromo(promo.id);
      setSuccessMessage(`Promo "${promo.code}" berhasil dihapus.`);
      await loadData();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal menghapus promo');
    } finally {
      setSaving(false);
    }
  };

  const toggleAppStatus = async (app: AppCatalogItem) => {
    setSaving(true);
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      await updateAdminApp(app.id, { is_active: !app.is_active });
      setSuccessMessage(`Status aplikasi "${app.name}" berhasil diperbarui.`);
      await loadData();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal memperbarui status aplikasi');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="rounded-3xl border border-amber-200 bg-gradient-to-br from-amber-50 via-white to-orange-50 p-6 shadow-sm">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div className="max-w-3xl">
            <div className="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">
              <Sparkles className="h-3.5 w-3.5" />
              Pricing Control Center
            </div>
            <h1 className="mt-4 text-3xl font-bold tracking-tight text-zinc-950">App pricing, promo, dan status penjualan</h1>
            <p className="mt-3 max-w-2xl text-sm leading-6 text-zinc-600">
              Halaman ini sekarang menjadi pusat setup harga aplikasi, kampanye diskon, dan kesiapan jalur pembayaran sebelum gateway Xendit diaktifkan penuh.
            </p>
          </div>

          <div className="flex flex-wrap gap-3">
            <button
              onClick={() => openPlanModal()}
              className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
            >
              <Plus className="h-4 w-4" />
              Plan Baru
            </button>
            <button
              onClick={() => openPromoModal()}
              className="inline-flex items-center gap-2 rounded-2xl border border-amber-300 bg-white px-4 py-3 text-sm font-semibold text-amber-800 transition hover:bg-amber-50"
            >
              <BadgePercent className="h-4 w-4" />
              Promo Baru
            </button>
          </div>
        </div>
      </div>

      {(errorMessage || successMessage) && (
        <div className="space-y-3">
          {errorMessage && (
            <div className="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
              <span>{errorMessage}</span>
            </div>
          )}
          {successMessage && (
            <div className="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
              <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
              <span>{successMessage}</span>
            </div>
          )}
        </div>
      )}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {[
          { label: 'Aplikasi aktif', value: totals.activeApps, icon: Rocket, tone: 'amber' },
          { label: 'Plan aktif', value: totals.activePlans, icon: Wallet, tone: 'emerald' },
          { label: 'Promo berjalan', value: totals.activePromos, icon: Tag, tone: 'sky' },
          { label: 'Total subscription', value: totals.subscriptions, icon: ShoppingCart, tone: 'violet' },
        ].map((item) => (
          <div key={item.label} className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
            <div className="flex items-center gap-3">
              <div
                className={cn(
                  'flex h-12 w-12 items-center justify-center rounded-2xl',
                  item.tone === 'amber' && 'bg-amber-100 text-amber-700',
                  item.tone === 'emerald' && 'bg-emerald-100 text-emerald-700',
                  item.tone === 'sky' && 'bg-sky-100 text-sky-700',
                  item.tone === 'violet' && 'bg-violet-100 text-violet-700'
                )}
              >
                <item.icon className="h-5 w-5" />
              </div>
              <div>
                <p className="text-sm font-medium text-zinc-500">{item.label}</p>
                <p className="text-2xl font-bold text-zinc-950">{item.value.toLocaleString('id-ID')}</p>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="grid gap-6 xl:grid-cols-[1.25fr_1fr]">
        <section className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
          <div className="mb-5 flex items-center justify-between gap-4">
            <div>
              <h2 className="text-xl font-bold text-zinc-950">Subscription plans</h2>
              <p className="mt-1 text-sm text-zinc-500">Kelola harga dasar aplikasi yang nantinya dipakai untuk wallet charge, invoice fallback, dan gateway checkout.</p>
            </div>
            <button
              onClick={() => openPlanModal()}
              className="inline-flex items-center gap-2 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-2.5 text-sm font-semibold text-zinc-800 transition hover:bg-zinc-100"
            >
              <Plus className="h-4 w-4" />
              Tambah plan
            </button>
          </div>

          <div className="mb-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Plan POS yang tampil di modal aktivasi POS adalah plan aktif, visible, dan slug diawali <span className="font-semibold">pos_</span>. Deskripsi, fasilitas, badge recommended, dan urutan tampil di bawah akan langsung dipakai oleh checkout POS.
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            {plans.map((plan) => (
              <article key={plan.id} className="rounded-3xl border border-zinc-200 bg-zinc-50/70 p-5">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                      <LayoutTemplate className="h-3.5 w-3.5" />
                      {plan.slug}
                    </div>
                    <h3 className="mt-3 text-lg font-bold text-zinc-950">{plan.name}</h3>
                    <p className="mt-1 text-sm text-zinc-500">Tipe {plan.type} • {plan.subscriptions_count} subscription</p>
                  </div>
                  <div className="flex flex-col items-end gap-2">
                    {plan.is_recommended ? (
                      <span className="rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">
                        Recommended
                      </span>
                    ) : null}
                    {isVisibleInPosCheckout(plan) ? (
                      <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                        POS Checkout
                      </span>
                    ) : null}
                    <span className={cn(
                      'rounded-full px-3 py-1 text-xs font-semibold',
                      plan.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-zinc-200 text-zinc-600'
                    )}>
                      {plan.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </div>
                </div>

                <div className="mt-5 rounded-2xl bg-white p-4">
                  <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Harga saat ini</p>
                  <p className="mt-2 text-2xl font-bold text-zinc-950">{formatCurrency(plan.price)}</p>
                  {plan.description ? (
                    <p className="mt-2 text-sm leading-5 text-zinc-600">{plan.description}</p>
                  ) : null}
                </div>

                {featuresToText(plan.features) ? (
                  <div className="mt-4 rounded-2xl border border-zinc-200 bg-white p-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Fasilitas tampil di checkout</p>
                    <ul className="mt-2 space-y-1 text-sm text-zinc-700">
                      {featuresToText(plan.features).split('\n').slice(0, 4).map((feature) => (
                        <li key={feature} className="flex gap-2">
                          <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                          <span>{feature}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : null}

                <div className="mt-5 flex items-center justify-between gap-3">
                  <button
                    onClick={() => openPlanModal(plan)}
                    className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800"
                  >
                    <Edit3 className="h-4 w-4" />
                    Edit
                  </button>
                  <button
                    onClick={() => void handleDeletePlan(plan)}
                    className="inline-flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-100 disabled:opacity-60"
                    disabled={saving}
                  >
                    <Trash2 className="h-4 w-4" />
                    Hapus
                  </button>
                </div>
              </article>
            ))}

            {!loading && plans.length === 0 && (
              <div className="rounded-3xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-500 md:col-span-2">
                Belum ada plan. Tambahkan plan bulanan/tahunan agar halaman checkout member langsung punya harga.
              </div>
            )}
          </div>
        </section>

        <section className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
          <div className="mb-5 flex items-center justify-between gap-4">
            <div>
              <h2 className="text-xl font-bold text-zinc-950">Promo campaigns</h2>
              <p className="mt-1 text-sm text-zinc-500">Diskon sekarang benar-benar tersambung ke endpoint promo admin dan siap divalidasi saat checkout.</p>
            </div>
            <button
              onClick={() => openPromoModal()}
              className="inline-flex items-center gap-2 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800 transition hover:bg-amber-100"
            >
              <Plus className="h-4 w-4" />
              Tambah promo
            </button>
          </div>

          <div className="space-y-4">
            {promoDecorated.map((promo) => {
              const slotsLeft = promo.max_slots ? Math.max(0, promo.max_slots - promo.used_slots) : null;
              return (
                <article key={promo.id} className="rounded-3xl border border-zinc-200 bg-zinc-50/80 p-5">
                  <div className="flex items-start justify-between gap-4">
                    <div>
                      <div className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-bold uppercase tracking-wide text-zinc-700">
                        <Tag className="h-3.5 w-3.5" />
                        {promo.code}
                      </div>
                      <h3 className="mt-3 text-lg font-bold text-zinc-950">{promo.name}</h3>
                      <p className="mt-1 text-sm text-zinc-500">{promo.description || 'Tanpa deskripsi tambahan.'}</p>
                    </div>
                    <span className={cn(
                      'rounded-full px-3 py-1 text-xs font-semibold',
                      promo.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-zinc-200 text-zinc-600'
                    )}>
                      {promo.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </div>

                  <div className="mt-4 grid gap-3 sm:grid-cols-2">
                    <div className="rounded-2xl bg-white p-4">
                      <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Diskon</p>
                      <p className="mt-2 text-xl font-bold text-zinc-950">
                        {promo.type === 'percentage' ? `${promo.value}%` : formatCurrency(promo.value)}
                      </p>
                    </div>
                    <div className="rounded-2xl bg-white p-4">
                      <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Target</p>
                      <p className="mt-2 text-sm font-semibold text-zinc-900">{promo.appName}</p>
                      <p className="text-sm text-zinc-500">{promo.planName}</p>
                    </div>
                  </div>

                  <div className="mt-4 flex flex-wrap gap-3 text-xs text-zinc-600">
                    <span className="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1">
                      <Clock3 className="h-3.5 w-3.5" />
                      Slot sisa: {slotsLeft === null ? 'Unlimited' : slotsLeft}
                    </span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1">
                      <CalendarDays className="h-3.5 w-3.5" />
                      {promo.starts_at ? toDateInput(promo.starts_at) : 'Now'} - {promo.ends_at ? toDateInput(promo.ends_at) : 'No end'}
                    </span>
                  </div>

                  <div className="mt-5 flex items-center justify-between gap-3">
                    <button
                      onClick={() => openPromoModal(promo)}
                      className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800"
                    >
                      <Edit3 className="h-4 w-4" />
                      Edit
                    </button>
                    <button
                      onClick={() => void handleDeletePromo(promo)}
                      className="inline-flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-100 disabled:opacity-60"
                      disabled={saving}
                    >
                      <Trash2 className="h-4 w-4" />
                      Hapus
                    </button>
                  </div>
                </article>
              );
            })}

            {!loading && promoDecorated.length === 0 && (
              <div className="rounded-3xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-500">
                Belum ada promo aktif. Buat kampanye diskon untuk POS atau Landing Builder dari sini.
              </div>
            )}
          </div>
        </section>
      </div>

      <section className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
        <div className="mb-5 flex items-center justify-between gap-4">
          <div>
            <h2 className="text-xl font-bold text-zinc-950">App catalog status</h2>
            <p className="mt-1 text-sm text-zinc-500">Aktif/nonaktifkan akses app yang tampil di dashboard member dan checkout funnel.</p>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {apps.map((app) => (
            <article key={app.id} className="rounded-3xl border border-zinc-200 bg-zinc-50/70 p-5">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-zinc-950 text-white">
                    {app.slug.includes('landing') ? <LayoutTemplate className="h-5 w-5" /> : <Package className="h-5 w-5" />}
                  </div>
                  <div>
                    <h3 className="text-lg font-bold text-zinc-950">{app.name}</h3>
                    <p className="text-sm text-zinc-500">{app.slug}</p>
                  </div>
                </div>
                <span className={cn(
                  'rounded-full px-3 py-1 text-xs font-semibold',
                  app.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-zinc-200 text-zinc-600'
                )}>
                  {app.is_active ? 'Visible' : 'Hidden'}
                </span>
              </div>

              <div className="mt-5 rounded-2xl bg-white p-4">
                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Pengguna aktif</p>
                <p className="mt-2 text-2xl font-bold text-zinc-950">{app.active_count.toLocaleString('id-ID')}</p>
              </div>

              <button
                onClick={() => void toggleAppStatus(app)}
                disabled={saving}
                className={cn(
                  'mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl px-4 py-3 text-sm font-semibold transition disabled:opacity-60',
                  app.is_active
                    ? 'border border-zinc-300 bg-white text-zinc-800 hover:bg-zinc-100'
                    : 'bg-zinc-950 text-white hover:bg-zinc-800'
                )}
              >
                {app.is_active ? 'Sembunyikan dari catalog' : 'Aktifkan kembali'}
              </button>
            </article>
          ))}
        </div>
      </section>

      {planModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
          <div className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-3xl border border-zinc-200 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
              <div>
                <h3 className="text-xl font-bold text-zinc-950">{editingPlanId ? 'Edit plan' : 'Tambah plan'}</h3>
                <p className="text-sm text-zinc-500">Atur harga aplikasi untuk checkout dan auto-renew.</p>
              </div>
              <button onClick={resetPlanModal} className="rounded-2xl p-2 text-zinc-500 transition hover:bg-zinc-100">
                <X className="h-5 w-5" />
              </button>
            </div>

            <form onSubmit={handlePlanSubmit} className="space-y-5 px-6 py-5">
              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Plan slug</span>
                  <input
                    value={planForm.slug}
                    onChange={(event) => setPlanForm((current) => ({ ...current, slug: event.target.value }))}
                    disabled={Boolean(editingPlanId)}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    placeholder="pos_monthly"
                    required
                  />
                  <p className="text-xs text-zinc-500">
                    Untuk paket POS, slug wajib diawali <span className="font-semibold">pos_</span> agar muncul di modal checkout POS.
                  </p>
                </label>
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Nama plan</span>
                  <input
                    value={planForm.name}
                    onChange={(event) => setPlanForm((current) => ({ ...current, name: event.target.value }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    placeholder="POS Monthly"
                    required
                  />
                </label>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Tipe plan</span>
                  <select
                    value={planForm.type}
                    onChange={(event) => setPlanForm((current) => ({ ...current, type: event.target.value as PlanFormState['type'] }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  >
                    <option value="subscription">Subscription</option>
                    <option value="free">Free</option>
                    <option value="one_time">One time</option>
                    <option value="lifetime">Lifetime</option>
                  </select>
                </label>
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Harga (IDR)</span>
                  <input
                    type="number"
                    min="0"
                    value={planForm.price}
                    onChange={(event) => setPlanForm((current) => ({ ...current, price: Number(event.target.value) || 0 }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    required
                  />
                </label>
              </div>

              <label className="space-y-2 text-sm">
                <span className="font-medium text-zinc-700">Deskripsi kartu checkout</span>
                <textarea
                  rows={3}
                  value={planForm.description}
                  onChange={(event) => setPlanForm((current) => ({ ...current, description: event.target.value }))}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  placeholder="Contoh: Paket operasional POS untuk kasir, meja, laporan, dan self-order."
                />
              </label>

              <label className="space-y-2 text-sm">
                <span className="font-medium text-zinc-700">Fasilitas yang didapatkan</span>
                <textarea
                  rows={6}
                  value={planForm.featuresText}
                  onChange={(event) => setPlanForm((current) => ({ ...current, featuresText: event.target.value }))}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  placeholder={'Tulis satu fasilitas per baris\nContoh:\nFull app access\n500 produk menu\nLaporan omzet harian\nQR self-order meja'}
                />
                <p className="text-xs text-zinc-500">Setiap baris akan tampil sebagai checklist di kartu checkout.</p>
              </label>

              <div className="grid gap-4 md:grid-cols-2">
                <div className="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                  <p className="text-sm font-semibold text-zinc-900">Billing cycle</p>
                  <div className="mt-3 flex flex-wrap gap-4">
                    <label className="inline-flex items-center gap-2 text-sm text-zinc-700">
                      <input
                        type="checkbox"
                        checked={planForm.billingMonthly}
                        onChange={(event) => setPlanForm((current) => ({ ...current, billingMonthly: event.target.checked }))}
                        className="h-4 w-4 rounded border-zinc-300 text-amber-500 focus:ring-amber-400"
                      />
                      Monthly
                    </label>
                    <label className="inline-flex items-center gap-2 text-sm text-zinc-700">
                      <input
                        type="checkbox"
                        checked={planForm.billingYearly}
                        onChange={(event) => setPlanForm((current) => ({ ...current, billingYearly: event.target.checked }))}
                        className="h-4 w-4 rounded border-zinc-300 text-amber-500 focus:ring-amber-400"
                      />
                      Yearly
                    </label>
                  </div>
                </div>

                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Durasi akses (hari)</span>
                  <input
                    type="number"
                    min="0"
                    value={planForm.duration_days}
                    onChange={(event) => setPlanForm((current) => ({ ...current, duration_days: event.target.value }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    placeholder="30 untuk bulanan, 365 untuk tahunan, kosong untuk lifetime"
                  />
                </label>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Urutan tampil</span>
                  <input
                    type="number"
                    min="0"
                    value={planForm.sort_order}
                    onChange={(event) => setPlanForm((current) => ({ ...current, sort_order: Number(event.target.value) || 0 }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  />
                </label>

                <div className="grid gap-2">
                  <label className="flex items-center justify-between rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                    <div>
                      <p className="text-sm font-semibold text-zinc-900">Recommended</p>
                      <p className="text-xs text-zinc-500">Tampilkan badge biru di checkout.</p>
                    </div>
                    <input
                      type="checkbox"
                      checked={planForm.is_recommended}
                      onChange={(event) => setPlanForm((current) => ({ ...current, is_recommended: event.target.checked }))}
                      className="h-5 w-5 rounded border-zinc-300 text-amber-500 focus:ring-amber-400"
                    />
                  </label>
                </div>
              </div>

              <div className="grid gap-3 md:grid-cols-2">
                <label className="flex items-center justify-between rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                  <div>
                    <p className="text-sm font-semibold text-zinc-900">Plan aktif</p>
                    <p className="text-xs text-zinc-500">Nonaktifkan agar tidak bisa dipakai checkout.</p>
                  </div>
                  <input
                    type="checkbox"
                    checked={planForm.is_active}
                    onChange={(event) => setPlanForm((current) => ({ ...current, is_active: event.target.checked }))}
                    className="h-5 w-5 rounded border-zinc-300 text-amber-500 focus:ring-amber-400"
                  />
                </label>

                <label className="flex items-center justify-between rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                  <div>
                    <p className="text-sm font-semibold text-zinc-900">Tampil di checkout</p>
                    <p className="text-xs text-zinc-500">Sembunyikan jika belum ingin dijual.</p>
                  </div>
                  <input
                    type="checkbox"
                    checked={planForm.is_visible}
                    onChange={(event) => setPlanForm((current) => ({ ...current, is_visible: event.target.checked }))}
                    className="h-5 w-5 rounded border-zinc-300 text-amber-500 focus:ring-amber-400"
                  />
                </label>
              </div>

              <div className="flex justify-end gap-3 border-t border-zinc-200 pt-4">
                <button type="button" onClick={resetPlanModal} className="rounded-2xl border border-zinc-200 px-4 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50">
                  Batal
                </button>
                <button type="submit" disabled={saving} className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-60">
                  <Save className="h-4 w-4" />
                  {saving ? 'Menyimpan...' : 'Simpan plan'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {promoModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
          <div className="w-full max-w-2xl rounded-3xl border border-zinc-200 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
              <div>
                <h3 className="text-xl font-bold text-zinc-950">{editingPromoId ? 'Edit promo' : 'Tambah promo'}</h3>
                <p className="text-sm text-zinc-500">Promo ini akan dipakai untuk validasi diskon saat checkout subscription.</p>
              </div>
              <button onClick={resetPromoModal} className="rounded-2xl p-2 text-zinc-500 transition hover:bg-zinc-100">
                <X className="h-5 w-5" />
              </button>
            </div>

            <form onSubmit={handlePromoSubmit} className="space-y-5 px-6 py-5">
              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Kode promo</span>
                  <input
                    value={promoForm.code}
                    onChange={(event) => setPromoForm((current) => ({ ...current, code: event.target.value.toUpperCase() }))}
                    disabled={Boolean(editingPromoId)}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    placeholder="WELCOME25"
                    required
                  />
                </label>
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Nama promo</span>
                  <input
                    value={promoForm.name}
                    onChange={(event) => setPromoForm((current) => ({ ...current, name: event.target.value }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    placeholder="Welcome discount"
                    required
                  />
                </label>
              </div>

              <label className="space-y-2 text-sm">
                <span className="font-medium text-zinc-700">Deskripsi</span>
                <textarea
                  rows={3}
                  value={promoForm.description}
                  onChange={(event) => setPromoForm((current) => ({ ...current, description: event.target.value }))}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  placeholder="Promo onboarding untuk langganan baru."
                />
              </label>

              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Jenis diskon</span>
                  <select
                    value={promoForm.type}
                    onChange={(event) => setPromoForm((current) => ({ ...current, type: event.target.value as PromoFormState['type'] }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  >
                    <option value="percentage">Percentage</option>
                    <option value="fixed">Fixed amount</option>
                  </select>
                </label>
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Nilai diskon</span>
                  <input
                    type="number"
                    min="1"
                    value={promoForm.value}
                    onChange={(event) => setPromoForm((current) => ({ ...current, value: Number(event.target.value) || 0 }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    required
                  />
                </label>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Target aplikasi</span>
                  <select
                    value={promoForm.app_id}
                    onChange={(event) => setPromoForm((current) => ({ ...current, app_id: event.target.value }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  >
                    <option value="">Semua aplikasi</option>
                    {apps.map((app) => (
                      <option key={app.id} value={app.id}>{app.name}</option>
                    ))}
                  </select>
                </label>
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Target plan</span>
                  <select
                    value={promoForm.plan_id}
                    onChange={(event) => setPromoForm((current) => ({ ...current, plan_id: event.target.value }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  >
                    <option value="">Semua plan</option>
                    {plans.map((plan) => (
                      <option key={plan.id} value={plan.id}>{plan.name}</option>
                    ))}
                  </select>
                </label>
              </div>

              <div className="grid gap-4 md:grid-cols-3">
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Maks slot</span>
                  <input
                    type="number"
                    min="1"
                    value={promoForm.max_slots}
                    onChange={(event) => setPromoForm((current) => ({ ...current, max_slots: event.target.value }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    placeholder="Kosongkan jika unlimited"
                  />
                </label>
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Mulai</span>
                  <input
                    type="date"
                    value={promoForm.starts_at}
                    onChange={(event) => setPromoForm((current) => ({ ...current, starts_at: event.target.value }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  />
                </label>
                <label className="space-y-2 text-sm">
                  <span className="font-medium text-zinc-700">Berakhir</span>
                  <input
                    type="date"
                    value={promoForm.ends_at}
                    onChange={(event) => setPromoForm((current) => ({ ...current, ends_at: event.target.value }))}
                    className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                  />
                </label>
              </div>

              <label className="flex items-center justify-between rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                <div>
                  <p className="text-sm font-semibold text-zinc-900">Promo aktif</p>
                  <p className="text-xs text-zinc-500">Jika nonaktif, kode tetap tersimpan tetapi tidak bisa dipakai saat checkout.</p>
                </div>
                <input
                  type="checkbox"
                  checked={promoForm.is_active}
                  onChange={(event) => setPromoForm((current) => ({ ...current, is_active: event.target.checked }))}
                  className="h-5 w-5 rounded border-zinc-300 text-amber-500 focus:ring-amber-400"
                />
              </label>

              <div className="flex justify-end gap-3 border-t border-zinc-200 pt-4">
                <button type="button" onClick={resetPromoModal} className="rounded-2xl border border-zinc-200 px-4 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50">
                  Batal
                </button>
                <button type="submit" disabled={saving} className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-60">
                  <Save className="h-4 w-4" />
                  {saving ? 'Menyimpan...' : 'Simpan promo'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
