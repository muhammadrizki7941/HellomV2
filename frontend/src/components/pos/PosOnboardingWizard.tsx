import { useState } from 'react';
import { BriefcaseBusiness, Check, ChevronLeft, ChevronRight, Coffee, Smartphone, Store, Wrench } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useBusinessProfile } from '@/hooks/useBusinessProfile';
import { updatePosBusinessProfile, type PosBusinessType, type PosModuleKey } from '@/services/api/pos';

const businessIcons: Record<PosBusinessType['key'], typeof Store> = {
  fnb: Coffee,
  retail: Store,
  konter_hp: Smartphone,
  sparepart: Wrench,
  jasa: BriefcaseBusiness,
};

export default function PosOnboardingWizard() {
  const navigate = useNavigate();
  const { profile, refresh, terminology } = useBusinessProfile();
  const [step, setStep] = useState(1);
  const [businessType, setBusinessType] = useState<PosBusinessType['key']>(profile?.business_type ?? 'fnb');
  const [modules, setModules] = useState<PosModuleKey[]>(profile?.enabled_modules ?? []);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!profile) return null;

  const continueToModules = () => {
    if (businessType !== profile.business_type) {
      setModules(profile.module_presets[businessType] ?? []);
    }
    setStep(2);
  };

  const startAddingProduct = async () => {
    setSaving(true);
    setError(null);
    try {
      await updatePosBusinessProfile({
        business_type: businessType,
        enabled_modules: modules,
        onboarding_completed: true,
      });
      await refresh();
      navigate('/pos/menu');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Profil usaha gagal disimpan.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4">
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="pos-onboarding-title"
        className="max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:rounded-3xl sm:p-7"
      >
        <div className="mb-5 flex items-center justify-between gap-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-amber-700">Pengaturan awal · Langkah {step} dari 4</p>
            <h2 id="pos-onboarding-title" className="mt-1 text-xl font-bold text-gray-900">
              {step === 1 ? 'Usaha Anda bergerak di bidang apa?' : step === 2 ? 'Pilih fitur yang dibutuhkan' : step === 3 ? 'Panduan singkat menggunakan POS' : 'Siap menambahkan produk pertama?'}
            </h2>
          </div>
        </div>

        {step === 1 && (
          <>
            <p className="mb-4 text-sm text-gray-600">Pilih yang paling sesuai. Istilah menu dan fitur POS akan disesuaikan dengan pilihan ini.</p>
            <div className="grid gap-3 sm:grid-cols-2">
              {profile.business_types.map((type) => {
                const Icon = businessIcons[type.key];
                const selected = businessType === type.key;
                return (
                  <button
                    type="button"
                    key={type.key}
                    aria-pressed={selected}
                    onClick={() => setBusinessType(type.key)}
                    className={`flex min-h-20 items-center gap-3 rounded-2xl border p-4 text-left transition-colors ${selected ? 'border-amber-500 bg-amber-50' : 'border-gray-200 hover:border-amber-300'}`}
                  >
                    <Icon aria-hidden className="h-6 w-6 shrink-0 text-amber-700" />
                    <span className="min-w-0 flex-1">
                      <span className="block font-semibold text-gray-900">{type.label}</span>
                      <span className="mt-0.5 block text-sm text-gray-600">{type.examples}</span>
                    </span>
                    {selected && <Check aria-hidden className="h-5 w-5 shrink-0 text-amber-700" />}
                  </button>
                );
              })}
            </div>
          </>
        )}

        {step === 2 && (
          <div className="space-y-3">
            <p className="text-sm text-gray-600">Preset untuk {profile.business_types.find((type) => type.key === businessType)?.label ?? 'usaha ini'}. Anda tetap bisa mengubahnya kapan saja.</p>
            {profile.modules.map((module) => (
              <label key={module.key} className="flex min-h-14 cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-3">
                <input
                  type="checkbox"
                  checked={modules.includes(module.key)}
                  onChange={(event) => setModules((current) =>
                    event.target.checked
                      ? [...current, module.key]
                      : current.filter((key) => key !== module.key)
                  )}
                  className="mt-1 h-4 w-4 accent-amber-500"
                />
                <span>
                  <span className="block text-sm font-semibold text-gray-900">{module.label}</span>
                  <span className="mt-0.5 block text-xs text-gray-600">{module.description}</span>
                </span>
              </label>
            ))}
          </div>
        )}

        {step === 3 && (
          <div className="space-y-3">
            <p className="text-sm text-gray-600">Ikuti alur sederhana ini. Anda bisa membuka kembali inventori kapan saja dari menu POS.</p>
            {[
              ['1. Siapkan katalog', `Buka ${terminology.product} untuk menambahkan nama, kategori, dan harga jual.`],
              ['2. Atur stok dan fitur', 'Di Inventori, catat barang masuk, varian, harga grosir, atau serial sesuai fitur yang diaktifkan. Setiap perubahan stok tercatat otomatis.'],
              ['3. Mulai berjualan', 'Buka Transaksi, pilih barang, jumlah dan metode pembayaran. Untuk barang ber-IMEI, pilih serial sebelum transaksi disimpan.'],
            ].map(([title, description]) => (
              <article key={title} className="rounded-xl border border-gray-200 bg-white p-4">
                <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
                <p className="mt-1 text-sm leading-6 text-gray-600">{description}</p>
              </article>
            ))}
            <p className="rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-900">
              Jenis usaha dan modul awal ini disimpan satu kali. Anda tetap dapat menyesuaikannya kemudian melalui Pengaturan POS.
            </p>
          </div>
        )}

        {step === 4 && (
          <div className="rounded-2xl bg-amber-50 p-5">
            <p className="font-semibold text-gray-900">
              {profile.business_types.find((type) => type.key === businessType)?.label}
            </p>
            <p className="mt-2 text-sm text-gray-700">
              Simpan pengaturan awal untuk mulai mengisi {terminology.product.toLowerCase()} pertama. Anda akan diarahkan ke katalog.
            </p>
            {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}
          </div>
        )}

        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <div className="flex justify-end gap-2">
            {step > 1 && (
              <button type="button" onClick={() => setStep(step - 1)} className="inline-flex min-h-11 items-center gap-1 rounded-xl border border-gray-300 px-4 text-sm font-semibold text-gray-800">
                <ChevronLeft aria-hidden className="h-4 w-4" /> Kembali
              </button>
            )}
            {step < 4 ? (
              <button
                type="button"
                onClick={() => step === 1 ? continueToModules() : setStep(step + 1)}
                className="inline-flex min-h-11 items-center gap-1 rounded-xl bg-[#111111] px-4 text-sm font-semibold text-white"
              >
                Lanjut <ChevronRight aria-hidden className="h-4 w-4" />
              </button>
            ) : (
              <button
                type="button"
                onClick={() => void startAddingProduct()}
                disabled={saving}
                className="min-h-11 rounded-xl bg-[#111111] px-4 text-sm font-semibold text-white disabled:opacity-50"
              >
                {saving ? 'Menyimpan…' : 'Simpan & mulai tambah produk'}
              </button>
            )}
          </div>
        </div>
      </section>
    </div>
  );
}
