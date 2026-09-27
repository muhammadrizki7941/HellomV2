import { useState, useEffect, useRef } from 'react';
import { getImageUrl, getToken, HELLOM_API_BASE } from '@/lib/hellomApi';

interface OrganizationSettings {
  id: number;
  name: string;
  slug: string;
  logo_path?: string;
  logo_url?: string;
  banner_path?: string;
  banner_url?: string;
  address?: string;
  phone?: string;
  email?: string;
  description?: string;
  website?: string;
}

function resolvePreviewUrl(value: string | null | undefined) {
  if (!value) return null;
  if (
    value.startsWith('http://') ||
    value.startsWith('https://') ||
    value.startsWith('blob:') ||
    value.startsWith('data:')
  ) {
    return value;
  }

  return getImageUrl(value) || value;
}

export default function PosSettings() {
  const [activeTab, setActiveTab] = useState<'identity' | 'payment' | 'tables'>('identity');
  const [settings, setSettings] = useState<OrganizationSettings | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [paymentStatus, setPaymentStatus] = useState<string | null>(null);
  const [paymentSettings, setPaymentSettings] = useState({
    qris_static_enabled: true,
    qris_static_image_url: null as string | null,
    require_paid_before_submit: true,
  });
  const [posPaymentSettings, setPosPaymentSettings] = useState({
    cash_enabled: true,
    cash_label: 'Tunai',
    transfer_enabled: false,
    transfer_bank_name: '',
    transfer_account_number: '',
    transfer_account_name: '',
    gopay_enabled: false,
    gopay_number: '',
    gopay_name: '',
    dana_enabled: false,
    dana_number: '',
    dana_name: '',
    qris_enabled: false,
    qris_label: 'QRIS',
    qris_image_url: null as string | null,
  });
  const [posPaymentSaving, setPosPaymentSaving] = useState(false);
  const [qrisFile, setQrisFile] = useState<File | null>(null);
  const [form, setForm] = useState({
    name: '',
    description: '',
    address: '',
    phone: '',
    email: '',
    website: '',
  });
  const [logoFile, setLogoFile] = useState<File | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [bannerFile, setBannerFile] = useState<File | null>(null);
  const [bannerPreview, setBannerPreview] = useState<string | null>(null);
  const [qrFile, setQrFile] = useState<File | null>(null);
  const [qrPreview, setQrPreview] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const bannerInputRef = useRef<HTMLInputElement>(null);
  const qrInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    safeLoadSettings();
    safeLoadPaymentSettings();
    loadPosPaymentSettings();
  }, []);

  const loadPosPaymentSettings = async () => {
    try {
      const token = getToken();
      const response = await fetch(`${HELLOM_API_BASE}/pos/payment-settings`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      const data = await response.json();
      if (data.success && data.data?.payment_settings) {
        const s = data.data.payment_settings;
        setPosPaymentSettings({
          cash_enabled: s.cash_enabled ?? true,
          cash_label: s.cash_label ?? 'Tunai',
          transfer_enabled: s.transfer_enabled ?? false,
          transfer_bank_name: s.transfer_bank_name ?? '',
          transfer_account_number: s.transfer_account_number ?? '',
          transfer_account_name: s.transfer_account_name ?? '',
          gopay_enabled: s.gopay_enabled ?? false,
          gopay_number: s.gopay_number ?? '',
          gopay_name: s.gopay_name ?? '',
          dana_enabled: s.dana_enabled ?? false,
          dana_number: s.dana_number ?? '',
          dana_name: s.dana_name ?? '',
          qris_enabled: s.qris_enabled ?? false,
          qris_label: s.qris_label ?? 'QRIS',
          qris_image_url: resolvePreviewUrl(s.qris_image_url),
        });
      }
    } catch (err) {
      console.error('Failed to load POS payment settings:', err);
    }
  };

  const safeLoadSettings = async () => {
    try {
      await loadSettings();
    } catch (err) {
      console.error('Safe load settings error:', err);
      setError('Gagal memuat pengaturan');
    }
  };

  const safeLoadPaymentSettings = async () => {
    try {
      await loadPaymentSettings();
    } catch (err) {
      console.error('Safe load payment settings error:', err);
    }
  };

  const loadSettings = async () => {
    try {
      setLoading(true);
      const token = getToken();
      const response = await fetch(`${HELLOM_API_BASE}/organizations/current/settings`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      const data = await response.json();

      if (data.success && data.data?.organization) {
        const org = data.data.organization;
        setSettings(org);

        setForm({
          name: org.name || '',
          description: org.description || '',
          address: org.address || '',
          phone: org.phone || '',
          email: org.email || '',
          website: org.website || '',
        });

        if (org.logo_url) {
          setLogoPreview(org.logo_url);
        }

        if (org.banner_url) {
          setBannerPreview(org.banner_url);
        }
      } else {
        setError('Gagal memuat pengaturan');
      }
    } catch (err) {
      setError('Gagal memuat pengaturan');
      console.error('Failed to load settings:', err);
    } finally {
      setLoading(false);
    }
  };

  const loadPaymentSettings = async () => {
    try {
      const token = getToken();
      const response = await fetch(`${HELLOM_API_BASE}/pos/payment-settings`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
      });

      const data = await response.json();

      if (data.success && data.data) {
        setPaymentSettings({
          qris_static_enabled: data.data.qris_static_enabled ?? false,
          qris_static_image_url: data.data.qris_static_image_url ?? null,
          require_paid_before_submit: data.data.require_paid_before_submit ?? false,
        });
        setQrPreview(getImageUrl(data.data.qris_static_image_url) || null);
      }
    } catch (err) {
      console.error('Failed to load payment settings:', err);
    }
  };

  const handleLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      if (file.size > 2 * 1024 * 1024) { // 2MB
        alert('Ukuran file maksimal 2MB');
        return;
      }

      if (!file.type.match('image/(jpg|jpeg|png)')) {
        alert('Format file harus JPG atau PNG');
        return;
      }

      setLogoFile(file);
      const reader = new FileReader();
      reader.onload = () => setLogoPreview(reader.result as string);
      reader.readAsDataURL(file);
    }
  };

  const handleBannerChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('Maksimal 5MB'); return; }
    if (!file.type.match('image/(jpg|jpeg|png)')) { alert('Harus JPG/PNG'); return; }
    setBannerFile(file);
    const reader = new FileReader();
    reader.onload = () => setBannerPreview(reader.result as string);
    reader.readAsDataURL(file);
  };

  const handleQrChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 4 * 1024 * 1024) { alert('Maksimal 4MB'); return; }
    if (!file.type.match('image/(jpg|jpeg|png)')) { alert('Harus JPG/PNG'); return; }
    setQrFile(file);
    const reader = new FileReader();
    reader.onload = () => setQrPreview(reader.result as string);
    reader.readAsDataURL(file);
  };

  const handleRemoveLogo = () => {
    setLogoFile(null);
    setLogoPreview(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  const handleRemoveBanner = () => {
    setBannerFile(null);
    setBannerPreview(null);
    if (bannerInputRef.current) {
      bannerInputRef.current.value = '';
    }
  };

  const handleRemoveQr = () => {
    setQrFile(null);
    setQrPreview(null);
    if (qrInputRef.current) {
      qrInputRef.current.value = '';
    }
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      const formData = new FormData();

      // Append semua field
      if (form.name) formData.append('name', form.name);
      if (form.address !== undefined) formData.append('address', form.address || '');
      if (form.phone !== undefined) formData.append('phone', form.phone || '');
      if (form.email !== undefined) formData.append('email', form.email || '');
      if (form.description !== undefined) formData.append('description', form.description || '');
      if (form.website !== undefined) formData.append('website', form.website || '');

      // Append logo jika ada file baru
      if (logoFile) {
        formData.append('logo', logoFile);
      }

      // Append banner jika ada file baru
      if (bannerFile) {
        formData.append('banner', bannerFile);
      }

      // Kirim sebagai POST dengan FormData
      const token = getToken();

      const response = await fetch(
        `${HELLOM_API_BASE}/organizations/current/settings`,
        {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json',
            // JANGAN set Content-Type untuk FormData
          },
          body: formData,
        }
      );

      const data = await response.json();

      if (data.success) {
        alert('Pengaturan berhasil disimpan!');
        // Update local state dengan data terbaru
        setForm({
          name: data.data.organization.name,
          address: data.data.organization.address || '',
          phone: data.data.organization.phone || '',
          email: data.data.organization.email || '',
          description: data.data.organization.description || '',
          website: data.data.organization.website || '',
        });
        setLogoPreview(data.data.organization.logo_url);
        setLogoFile(null);
        setBannerPreview(data.data.organization.banner_url || null);
        setBannerFile(null);
      } else {
        alert('Gagal menyimpan: ' + data.message);
      }
    } catch (error) {
      alert('Terjadi kesalahan, coba lagi ya');
    } finally {
      setSaving(false);
    }
  };

  const handleSavePaymentSettings = async () => {
    setSaving(true);
    setPaymentStatus(null);
    setError(null);

    if (!qrFile && !paymentSettings.qris_static_image_url) {
      setError('QR pembayaran wajib diunggah.');
      setSaving(false);
      return;
    }

    try {
      const formData = new FormData();
      formData.append('qris_static_enabled', paymentSettings.qris_static_enabled ? '1' : '0');
      formData.append('require_paid_before_submit', paymentSettings.require_paid_before_submit ? '1' : '0');
      if (qrFile) formData.append('qris_static_image', qrFile);

      const token = getToken();
      const response = await fetch(`${HELLOM_API_BASE}/pos/payment-settings`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
        body: formData,
      });

      const data = await response.json();

      if (data.success) {
        setPaymentSettings({
          qris_static_enabled: data.data.qris_static_enabled,
          qris_static_image_url: data.data.qris_static_image_url,
          require_paid_before_submit: data.data.require_paid_before_submit,
        });
        setQrPreview(getImageUrl(data.data.qris_static_image_url) || null);
        setPaymentStatus('QR pembayaran berhasil disimpan.');
      } else {
        setError(data.message || 'Gagal menyimpan QR pembayaran');
      }
    } catch (err) {
      setError('Gagal menyimpan QR pembayaran');
    } finally {
      setSaving(false);
    }
  };

  const handleSavePosPaymentSettings = async () => {
    setPosPaymentSaving(true);
    try {
      const formData = new FormData();
      Object.entries(posPaymentSettings).forEach(([k, v]) => {
        if (v !== null && v !== undefined) {
          formData.append(k, String(v));
        }
      });
      if (qrisFile) formData.append('qris_image', qrisFile);

      const token = getToken();
      const response = await fetch(`${HELLOM_API_BASE}/pos/payment-settings`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        },
        body: formData,
      });

      const data = await response.json();
      if (data.success) {
        alert('Setting pembayaran disimpan! ✅');
        loadPosPaymentSettings();
      } else {
        alert('Gagal menyimpan: ' + (data.message || 'Unknown error'));
      }
    } catch (err) {
      alert('Terjadi kesalahan, coba lagi ya');
    } finally {
      setPosPaymentSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-amber-400"></div>
      </div>
    );
  }

  return (
    <div className="max-w-2xl mx-auto p-6">
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-gray-900">Pengaturan POS</h1>
        <p className="text-gray-600 mt-1">Kelola informasi usaha dan metode pembayaran</p>
        {settings ? (
          <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <div className="font-semibold">Organisasi aktif: {settings.name}</div>
            <div className="mt-1">Slug publik: <span className="font-mono">{settings.slug}</span></div>
            <div className="mt-1">
              Halaman customer publik:
              {' '}
              <a
                href={`/customer/${settings.slug}`}
                target="_blank"
                rel="noreferrer"
                className="font-semibold underline"
              >
                /customer/{settings.slug}
              </a>
            </div>
            <div className="mt-2 text-xs text-amber-800">
              Semua perubahan payment di halaman ini hanya berlaku untuk organisasi aktif di atas.
            </div>
          </div>
        ) : null}
      </div>

      {/* Tab Navigation */}
      <div className="flex border-b border-gray-200 mb-6">
        <button
          onClick={() => setActiveTab('identity')}
          className={`px-4 py-2 font-medium text-sm border-b-2 transition-colors ${
            activeTab === 'identity'
              ? 'border-blue-500 text-blue-600'
              : 'border-transparent text-gray-500 hover:text-gray-700'
          }`}
        >
          Identitas Usaha
        </button>
        <button
          onClick={() => setActiveTab('payment')}
          className={`px-4 py-2 font-medium text-sm border-b-2 transition-colors ${
            activeTab === 'payment'
              ? 'border-blue-500 text-blue-600'
              : 'border-transparent text-gray-500 hover:text-gray-700'
          }`}
        >
          Pembayaran
        </button>
        <button
          onClick={() => setActiveTab('tables')}
          className={`px-4 py-2 font-medium text-sm border-b-2 transition-colors ${
            activeTab === 'tables'
              ? 'border-blue-500 text-blue-600'
              : 'border-transparent text-gray-500 hover:text-gray-700'
          }`}
        >
          Meja & QR
        </button>
      </div>

      {error && (
        <div className="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{error}</p>
        </div>
      )}

      {paymentStatus && (
        <div className="mb-6 bg-emerald-50 border border-emerald-200 rounded-lg p-4">
          <p className="text-emerald-800">{paymentStatus}</p>
        </div>
      )}

      {/* Tab Content */}
      {activeTab === 'identity' && (
        <>
          {/* Identitas Usaha */}
          <section className="bg-white rounded-2xl p-6 shadow-sm mb-4">
        <h2 className="text-lg font-bold text-gray-900 mb-4">
          Identitas Usaha
        </h2>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Nama Usaha *
            </label>
            <input
              type="text"
              value={form.name}
              onChange={e => setForm({...form, name: e.target.value})}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:ring-2 focus:ring-amber-300"
              placeholder="e.g: Pak Budi's Store"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Deskripsi
            </label>
            <textarea
              value={form.description}
              onChange={e => setForm({...form, description: e.target.value})}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:ring-2 focus:ring-amber-300"
              placeholder="Deskripsi singkat tentang usaha Anda"
            />
          </div>
        </div>
      </section>

      {/* Kontak */}
      <section className="bg-white rounded-2xl p-6 shadow-sm mb-4">
        <h2 className="text-lg font-bold text-gray-900 mb-4">
          Kontak
        </h2>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Nomor HP / Telp
            </label>
            <input
              type="tel"
              value={form.phone}
              onChange={e => setForm({...form, phone: e.target.value})}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:ring-2 focus:ring-amber-300"
              placeholder="cth: 08123456789"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Email
            </label>
            <input
              type="email"
              value={form.email}
              onChange={e => setForm({...form, email: e.target.value})}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:ring-2 focus:ring-amber-300"
              placeholder="cth: info@warungpakbudi.com"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Website
            </label>
            <input
              type="url"
              value={form.website}
              onChange={e => setForm({...form, website: e.target.value})}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:ring-2 focus:ring-amber-300"
              placeholder="cth: https://warungpakbudi.com"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Alamat
            </label>
            <textarea
              value={form.address}
              onChange={e => setForm({...form, address: e.target.value})}
              rows={2}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:ring-2 focus:ring-amber-300"
              placeholder="Alamat lengkap usaha Anda"
            />
          </div>
        </div>
      </section>

      {/* Logo Usaha */}
      <section className="bg-white rounded-2xl p-6 shadow-sm mb-6">
        <h2 className="text-lg font-bold text-gray-900 mb-4">
          Logo Usaha
        </h2>

        {/* Preview Logo */}
        {logoPreview && (
          <div className="mb-4">
            <div className="inline-block p-4 bg-gray-50 rounded-lg">
              <img
                src={logoPreview}
                alt="Logo preview"
                className="w-24 h-24 object-contain"
              />
            </div>
          </div>
        )}

        {/* Upload Controls */}
        <div className="flex items-center gap-4">
          <input
            ref={fileInputRef}
            type="file"
            accept="image/jpg,image/jpeg,image/png"
            onChange={handleLogoChange}
            className="hidden"
          />
          <button
            onClick={() => fileInputRef.current?.click()}
            className="px-4 py-2 bg-amber-400 text-[#111111] rounded-lg hover:bg-amber-500 transition-colors"
          >
            {logoPreview ? 'Ganti Logo' : 'Upload Logo'}
          </button>

          {logoPreview && (
            <button
              onClick={handleRemoveLogo}
              className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
            >
              Hapus Logo
            </button>
          )}
        </div>

        <p className="text-sm text-gray-500 mt-2">
          Format: JPG, PNG, max 2MB
        </p>
      </section>

      {/* Banner Hero */}
      <section className="bg-white rounded-2xl p-6 shadow-sm mb-6">
        <h2 className="text-lg font-bold text-gray-900 mb-4">
          Banner Hero
        </h2>
        <p className="text-sm text-gray-600 mb-4">
          Gambar latar belakang yang tampil di halaman order pelanggan. Gunakan foto suasana resto yang menarik.
        </p>

        {/* Preview Banner */}
        {bannerPreview && (
          <div className="mb-4">
            <img
              src={bannerPreview}
              alt="Banner preview"
              className="w-full h-40 object-cover rounded-xl"
            />
          </div>
        )}

        {/* Upload Controls */}
        <div className="flex items-center gap-4">
          <input
            ref={bannerInputRef}
            type="file"
            accept="image/jpg,image/jpeg,image/png"
            onChange={handleBannerChange}
            className="hidden"
          />
          <button
            onClick={() => bannerInputRef.current?.click()}
            className="px-4 py-2 bg-amber-400 text-[#111111] rounded-lg hover:bg-amber-500 transition-colors"
          >
            {bannerPreview ? 'Ganti Banner' : 'Upload Banner'}
          </button>

          {bannerPreview && (
            <button
              onClick={handleRemoveBanner}
              className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
            >
              Hapus Banner
            </button>
          )}
        </div>

        <p className="text-sm text-gray-500 mt-2">
          Format: JPG, PNG, max 5MB. Rekomendasi rasio 16:9.
        </p>
      </section>

      {/* Save Button */}
      <div className="flex justify-center">
        <button
          onClick={handleSave}
          disabled={saving}
          className="w-full max-w-md py-3 bg-[#111111] text-white rounded-xl font-semibold hover:bg-[#2a241d] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {saving ? 'Menyimpan...' : 'Simpan Pengaturan'}
        </button>
      </div>
        </>
      )}

      {activeTab === 'payment' && (
        <PaymentSettingsTab
          settings={posPaymentSettings}
          setSettings={setPosPaymentSettings}
          onSave={handleSavePosPaymentSettings}
          saving={posPaymentSaving}
          qrisFile={qrisFile}
          setQrisFile={setQrisFile}
        />
      )}

      {activeTab === 'tables' && (
        <>
          {/* Meja & QR content - existing */}
        </>
      )}
    </div>
  );
}

function PaymentSettingsTab({ settings, setSettings, onSave, saving, qrisFile, setQrisFile }: any) {
  const update = (key: string, val: string | boolean) =>
    setSettings((s: any) => ({ ...s, [key]: val }));

  const toggle = (key: string) => update(key, !settings[key]);

  return (
    <div className="space-y-4">
      {/* TUNAI */}
      <div className="bg-white rounded-2xl p-5 border">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-3">
            <span className="text-2xl">💵</span>
            <div>
              <div className="font-semibold text-gray-900">Tunai</div>
              <div className="text-xs text-gray-500">Pembayaran uang tunai</div>
            </div>
          </div>
          <button
            onClick={() => toggle('cash_enabled')}
            className={`w-12 h-6 rounded-full transition-colors relative ${settings.cash_enabled ? 'bg-green-500' : 'bg-gray-300'}`}
          >
            <span className={`absolute top-1 w-4 h-4 bg-white rounded-full transition-transform ${settings.cash_enabled ? 'translate-x-7' : 'translate-x-1'}`} />
          </button>
        </div>
        {settings.cash_enabled && (
          <input
            value={settings.cash_label}
            onChange={e => update('cash_label', e.target.value)}
            placeholder="Label (cth: Tunai)"
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"
          />
        )}
      </div>

      {/* TRANSFER BANK */}
      <div className="bg-white rounded-2xl p-5 border">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-3">
            <span className="text-2xl">🏦</span>
            <div>
              <div className="font-semibold text-gray-900">Transfer Bank</div>
              <div className="text-xs text-gray-500">BCA, BRI, BNI, dll</div>
            </div>
          </div>
          <button
            onClick={() => toggle('transfer_enabled')}
            className={`w-12 h-6 rounded-full transition-colors relative ${settings.transfer_enabled ? 'bg-green-500' : 'bg-gray-300'}`}
          >
            <span className={`absolute top-1 w-4 h-4 bg-white rounded-full transition-transform ${settings.transfer_enabled ? 'translate-x-7' : 'translate-x-1'}`} />
          </button>
        </div>
        {settings.transfer_enabled && (
          <div className="space-y-2 mt-2">
            <input value={settings.transfer_bank_name} onChange={e => update('transfer_bank_name', e.target.value)} placeholder="Nama bank (cth: BCA)" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" />
            <input value={settings.transfer_account_number} onChange={e => update('transfer_account_number', e.target.value)} placeholder="Nomor rekening (cth: 1234567890)" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" />
            <input value={settings.transfer_account_name} onChange={e => update('transfer_account_name', e.target.value)} placeholder="Nama pemilik rekening" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" />
          </div>
        )}
      </div>

      {/* GOPAY */}
      <div className="bg-white rounded-2xl p-5 border">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-3">
            <span className="text-2xl">🟢</span>
            <div>
              <div className="font-semibold text-gray-900">GoPay</div>
              <div className="text-xs text-gray-500">Link langsung ke app GoPay</div>
            </div>
          </div>
          <button
            onClick={() => toggle('gopay_enabled')}
            className={`w-12 h-6 rounded-full transition-colors relative ${settings.gopay_enabled ? 'bg-green-500' : 'bg-gray-300'}`}
          >
            <span className={`absolute top-1 w-4 h-4 bg-white rounded-full transition-transform ${settings.gopay_enabled ? 'translate-x-7' : 'translate-x-1'}`} />
          </button>
        </div>
        {settings.gopay_enabled && (
          <div className="space-y-2 mt-2">
            <input value={settings.gopay_number} onChange={e => update('gopay_number', e.target.value)} placeholder="Nomor HP GoPay (cth: 08123456789)" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" />
            <input value={settings.gopay_name} onChange={e => update('gopay_name', e.target.value)} placeholder="Nama akun GoPay" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" />
            <div className="text-xs text-green-600 bg-green-50 rounded-lg p-2">💡 Pelanggan akan diarahkan langsung ke app GoPay dengan nominal yang sudah terisi otomatis</div>
          </div>
        )}
      </div>

      {/* DANA */}
      <div className="bg-white rounded-2xl p-5 border">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-3">
            <span className="text-2xl">🔵</span>
            <div>
              <div className="font-semibold text-gray-900">Dana</div>
              <div className="text-xs text-gray-500">Link langsung ke app Dana</div>
            </div>
          </div>
          <button
            onClick={() => toggle('dana_enabled')}
            className={`w-12 h-6 rounded-full transition-colors relative ${settings.dana_enabled ? 'bg-green-500' : 'bg-gray-300'}`}
          >
            <span className={`absolute top-1 w-4 h-4 bg-white rounded-full transition-transform ${settings.dana_enabled ? 'translate-x-7' : 'translate-x-1'}`} />
          </button>
        </div>
        {settings.dana_enabled && (
          <div className="space-y-2 mt-2">
            <input value={settings.dana_number} onChange={e => update('dana_number', e.target.value)} placeholder="Nomor HP Dana (cth: 08123456789)" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" />
            <input value={settings.dana_name} onChange={e => update('dana_name', e.target.value)} placeholder="Nama akun Dana" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" />
            <div className="text-xs text-blue-600 bg-blue-50 rounded-lg p-2">💡 Pelanggan akan diarahkan langsung ke app Dana dengan nominal yang sudah terisi otomatis</div>
          </div>
        )}
      </div>

      {/* QRIS */}
      <div className="bg-white rounded-2xl p-5 border">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-3">
            <span className="text-2xl">📱</span>
            <div>
              <div className="font-semibold text-gray-900">QRIS</div>
              <div className="text-xs text-gray-500">Semua e-wallet & mobile banking</div>
            </div>
          </div>
          <button
            onClick={() => toggle('qris_enabled')}
            className={`w-12 h-6 rounded-full transition-colors relative ${settings.qris_enabled ? 'bg-green-500' : 'bg-gray-300'}`}
          >
            <span className={`absolute top-1 w-4 h-4 bg-white rounded-full transition-transform ${settings.qris_enabled ? 'translate-x-7' : 'translate-x-1'}`} />
          </button>
        </div>
        {settings.qris_enabled && (
          <div className="space-y-3 mt-2">
            <input value={settings.qris_label} onChange={e => update('qris_label', e.target.value)} placeholder="Label QRIS (cth: QRIS)" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" />
            <div>
              <label className="text-xs text-gray-600 mb-1 block">Upload gambar QRIS kamu:</label>
              {settings.qris_image_url && (
                <img src={settings.qris_image_url} alt="QRIS" className="w-40 h-40 object-contain border rounded-xl mb-2 bg-white p-1" />
              )}
              <input
                type="file"
                accept="image/jpg,image/jpeg,image/png"
                onChange={e => {
                  const file = e.target.files?.[0];
                  if (file) {
                    setQrisFile(file);
                    update('qris_image_url', URL.createObjectURL(file));
                  }
                }}
                className="w-full text-sm text-gray-600"
              />
              <div className="text-xs text-gray-400 mt-1">Format: JPG, PNG. Max 2MB.</div>
            </div>
          </div>
        )}
      </div>

      <button onClick={onSave} disabled={saving} className="w-full py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white rounded-2xl font-bold transition">
        {saving ? 'Menyimpan...' : '💾 Simpan Setting Pembayaran'}
      </button>
    </div>
  );
}
