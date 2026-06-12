import { useEffect, useState } from 'react';
import {
  AlertCircle,
  CreditCard,
  Globe,
  Plus,
  RefreshCw,
  Save,
  Shield,
  Trash2,
  Users,
  Wallet,
} from 'lucide-react';
import {
  getAdminManualPaymentConfig,
  getAdminPaymentGatewayConfig,
  getAuthMe,
  getImageUrl,
  getOrganizationTeam,
  inviteOrganizationMember,
  removeOrganizationMember,
  resetIpaymuGatewayConfig,
  updateAdminManualPaymentConfig,
  updateAdminPaymentGatewayConfig,
  updateCheckoutRuntimeConfig,
} from '@/lib/hellomApi';

type TeamMember = {
  id: number;
  name: string;
  email: string;
  role: string;
  joined_at?: string | null;
};

type CheckoutMode = 'manual_confirmation' | 'gateway_automatic';
type ProviderKey = 'xendit' | 'ipaymu' | 'doku';

type ProviderCard = {
  provider: string;
  mode: 'sandbox' | 'production';
  is_ready: boolean;
  webhook: {
    path: string;
    callback_token_configured: boolean;
  };
  balance?: {
    currency: string;
    amount: number | null;
    error?: string;
  } | null;
};

export default function AdminSettings() {
  const [activeTab, setActiveTab] = useState<'payment' | 'landing' | 'team'>('payment');
  const [statusMessage, setStatusMessage] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [loadingTeam, setLoadingTeam] = useState(false);
  const [loadingPayment, setLoadingPayment] = useState(false);
  const [savingPayment, setSavingPayment] = useState(false);
  const [adminEmail, setAdminEmail] = useState('admin@hellom.id');

  const [paymentConfig, setPaymentConfig] = useState<{
    active_provider: ProviderKey;
    checkout_mode: CheckoutMode;
    member_wallet_enabled: boolean;
    providers: {
      xendit: ProviderCard & {
        secret_key_masked: string | null;
        callback_token_masked: string | null;
        va_channels: string[];
      };
      ipaymu: ProviderCard & {
        va_masked: string | null;
        api_key_masked: string | null;
        callback_token_masked: string | null;
      };
      doku: ProviderCard & {
        client_id_masked: string | null;
        secret_key_masked: string | null;
        callback_token_masked: string | null;
        payment_method_types: string[];
      };
    };
    manual_payment?: Record<string, unknown>;
  }>({
    active_provider: 'xendit',
    checkout_mode: 'manual_confirmation',
    member_wallet_enabled: false,
    providers: {
      xendit: {
        provider: 'xendit',
        mode: 'sandbox',
        is_ready: false,
        secret_key_masked: null,
        callback_token_masked: null,
        va_channels: [],
        webhook: {
          path: '/api/v1/hellom/webhooks/xendit',
          callback_token_configured: false,
        },
        balance: null,
      },
      ipaymu: {
        provider: 'ipaymu',
        mode: 'sandbox',
        is_ready: false,
        va_masked: null,
        api_key_masked: null,
        callback_token_masked: null,
        webhook: {
          path: '/api/v1/hellom/webhooks/ipaymu',
          callback_token_configured: false,
        },
        balance: null,
      },
      doku: {
        provider: 'doku',
        mode: 'sandbox',
        is_ready: false,
        client_id_masked: null,
        secret_key_masked: null,
        callback_token_masked: null,
        payment_method_types: [],
        webhook: {
          path: '/api/v1/hellom/webhooks/doku',
          callback_token_configured: false,
        },
        balance: null,
      },
    },
  });

  const [xenditForm, setXenditForm] = useState({
    secret_key: '',
    callback_token: '',
    is_production: false,
    va_channels: '',
  });
  const [ipaymuForm, setIpaymuForm] = useState({
    va: '',
    api_key: '',
    callback_token: '',
    is_production: false,
  });
  const [dokuForm, setDokuForm] = useState({
    client_id: '',
    secret_key: '',
    callback_token: '',
    payment_method_types: 'VIRTUAL_ACCOUNT_BCA, VIRTUAL_ACCOUNT_BANK_MANDIRI, QRIS',
    is_production: false,
  });
  const [manualPaymentForm, setManualPaymentForm] = useState({
    enabled: false,
    notes: '',
    bank_enabled: false,
    bank_label: 'Transfer Bank',
    bank_name: '',
    bank_account_name: '',
    bank_account_number: '',
    bank_instructions: '',
    gopay_enabled: false,
    gopay_label: 'GoPay',
    gopay_account_name: '',
    gopay_account_number: '',
    gopay_instructions: '',
    dana_enabled: false,
    dana_label: 'DANA',
    dana_account_name: '',
    dana_account_number: '',
    dana_instructions: '',
    qris_enabled: false,
    qris_label: 'QRIS Static',
    qris_instructions: '',
  });
  const [manualPaymentFiles, setManualPaymentFiles] = useState<Record<string, File | null>>({
    bank_transfer: null,
    gopay: null,
    dana: null,
    qris: null,
  });
  const [manualPaymentPreviews, setManualPaymentPreviews] = useState<Record<string, string | null>>({
    bank_transfer: null,
    gopay: null,
    dana: null,
    qris: null,
  });

  const [landingConfig, setLandingConfig] = useState({
    siteName: 'Hellom Platform',
    heroTitle: 'Satu Akun untuk Semua Bisnis Kamu',
    heroSubtitle: 'Dapatkan akses ke berbagai aplikasi bisnis dalam satu platform. Mulai dari Landing Page, WhatsApp CRM, hingga Toko Online.',
    ctaText: 'Mulai Digitalisasi Sekarang',
    showFeatures: true,
    showPricing: true,
    showTestimonials: true,
  });

  const [team, setTeam] = useState<TeamMember[]>([]);
  const [showAddMember, setShowAddMember] = useState(false);
  const [newMember, setNewMember] = useState({ email: '', role: 'member' as 'admin' | 'member' });

  useEffect(() => {
    const loadAuth = async () => {
      try {
        const me = await getAuthMe();
        if (me.email) {
          setAdminEmail(me.email);
        }
      } catch {
      }
    };

    void loadAuth();
  }, []);

  const loadTeam = async () => {
    setLoadingTeam(true);
    setErrorMessage(null);
    try {
      const result = await getOrganizationTeam();
      setTeam(result.items || []);
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat data team';
      setErrorMessage(message);
    } finally {
      setLoadingTeam(false);
    }
  };

  const loadPayment = async () => {
    setLoadingPayment(true);
    setErrorMessage(null);
    try {
      const config = await getAdminPaymentGatewayConfig();
      const manualConfig = await getAdminManualPaymentConfig().catch(() => null);
      setPaymentConfig({
        active_provider: config.active_provider,
        checkout_mode: config.checkout_mode,
        member_wallet_enabled: config.member_wallet_enabled,
        providers: config.providers,
        manual_payment: manualConfig || undefined,
      });
      setXenditForm({
        secret_key: '',
        callback_token: '',
        is_production: config.providers.xendit.mode === 'production',
        va_channels: (config.providers.xendit.va_channels || []).join(', '),
      });
      setIpaymuForm({
        va: '',
        api_key: '',
        callback_token: '',
        is_production: config.providers.ipaymu.mode === 'production',
      });
      setDokuForm({
        client_id: '',
        secret_key: '',
        callback_token: '',
        payment_method_types: (config.providers.doku.payment_method_types || []).join(', '),
        is_production: config.providers.doku.mode === 'production',
      });
      if (manualConfig && typeof manualConfig === 'object') {
        const methods = (manualConfig as any).methods || {};
        setManualPaymentForm({
          enabled: Boolean((manualConfig as any).enabled),
          notes: String((manualConfig as any).notes || ''),
          bank_enabled: Boolean(methods.bank_transfer?.enabled),
          bank_label: String(methods.bank_transfer?.label || 'Transfer Bank'),
          bank_name: String(methods.bank_transfer?.bank_name || ''),
          bank_account_name: String(methods.bank_transfer?.account_name || ''),
          bank_account_number: String(methods.bank_transfer?.account_number || ''),
          bank_instructions: String(methods.bank_transfer?.instructions || ''),
          gopay_enabled: Boolean(methods.gopay?.enabled),
          gopay_label: String(methods.gopay?.label || 'GoPay'),
          gopay_account_name: String(methods.gopay?.account_name || ''),
          gopay_account_number: String(methods.gopay?.account_number || ''),
          gopay_instructions: String(methods.gopay?.instructions || ''),
          dana_enabled: Boolean(methods.dana?.enabled),
          dana_label: String(methods.dana?.label || 'DANA'),
          dana_account_name: String(methods.dana?.account_name || ''),
          dana_account_number: String(methods.dana?.account_number || ''),
          dana_instructions: String(methods.dana?.instructions || ''),
          qris_enabled: Boolean(methods.qris?.enabled),
          qris_label: String(methods.qris?.label || 'QRIS Static'),
          qris_instructions: String(methods.qris?.instructions || ''),
        });
        setManualPaymentPreviews({
          bank_transfer: methods.bank_transfer?.image_path ? getImageUrl(methods.bank_transfer.image_path) : null,
          gopay: methods.gopay?.image_path ? getImageUrl(methods.gopay.image_path) : null,
          dana: methods.dana?.image_path ? getImageUrl(methods.dana.image_path) : null,
          qris: methods.qris?.image_path ? getImageUrl(methods.qris.image_path) : null,
        });
      }
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat payment settings';
      setErrorMessage(message);
    } finally {
      setLoadingPayment(false);
    }
  };

  useEffect(() => {
    if (activeTab === 'team') {
      void loadTeam();
    }
    if (activeTab === 'payment') {
      void loadPayment();
    }
  }, [activeTab]);

  const handleSaveRuntime = async () => {
    setSavingPayment(true);
    setErrorMessage(null);
    setStatusMessage(null);
    try {
      const result = await updateCheckoutRuntimeConfig({
        active_provider: paymentConfig.active_provider,
        checkout_mode: paymentConfig.checkout_mode,
        member_wallet_enabled: paymentConfig.member_wallet_enabled,
      });

      const providerLabel = result.active_provider === 'ipaymu' ? 'iPaymu' : result.active_provider === 'doku' ? 'DOKU' : 'Xendit';
      setStatusMessage(
        `Runtime pembayaran disimpan. Gateway aktif sekarang ${providerLabel}, mode checkout ${
          result.checkout_mode === 'manual_confirmation' ? 'manual confirmation' : 'otomatis'
        }, dan wallet member ${result.member_wallet_enabled ? 'ditampilkan' : 'disembunyikan'}.`
      );
      await loadPayment();
    } catch (saveError) {
      const message = saveError instanceof Error ? saveError.message : 'Gagal menyimpan runtime payment settings';
      setErrorMessage(message);
    } finally {
      setSavingPayment(false);
    }
  };

  const handleSaveGatewayConfig = async (provider: ProviderKey) => {
    setSavingPayment(true);
    setErrorMessage(null);
    setStatusMessage(null);
    try {
      const result = await updateAdminPaymentGatewayConfig(
        provider === 'ipaymu'
          ? {
              provider,
              va: ipaymuForm.va || undefined,
              api_key: ipaymuForm.api_key || undefined,
              callback_token: ipaymuForm.callback_token || undefined,
              is_production: ipaymuForm.is_production,
            }
          : provider === 'doku'
            ? {
                provider,
                client_id: dokuForm.client_id || undefined,
                secret_key: dokuForm.secret_key || undefined,
                callback_token: dokuForm.callback_token || undefined,
                is_production: dokuForm.is_production,
                payment_method_types: dokuForm.payment_method_types,
              }
          : {
              provider,
              secret_key: xenditForm.secret_key || undefined,
              callback_token: xenditForm.callback_token || undefined,
              is_production: xenditForm.is_production,
              va_channels: xenditForm.va_channels,
            }
      );

      const providerLabel = provider === 'ipaymu' ? 'iPaymu' : provider === 'doku' ? 'DOKU' : 'Xendit';
      setStatusMessage(
        result.generated_callback_token
          ? `Kredensial ${providerLabel} tersimpan. Simpan callback token ini di webhook provider: ${result.generated_callback_token}`
          : `Kredensial ${providerLabel} berhasil disimpan.`
      );

      if (provider === 'ipaymu') {
        setIpaymuForm((current) => ({ ...current, va: '', api_key: '', callback_token: '' }));
      } else if (provider === 'doku') {
        setDokuForm((current) => ({ ...current, client_id: '', secret_key: '', callback_token: '' }));
      } else {
        setXenditForm((current) => ({ ...current, secret_key: '', callback_token: '' }));
      }

      await loadPayment();
    } catch (saveError) {
      const message = saveError instanceof Error ? saveError.message : `Gagal menyimpan kredensial ${provider}`;
      setErrorMessage(message);
    } finally {
      setSavingPayment(false);
    }
  };

  const handleSaveManualPayment = async () => {
    setSavingPayment(true);
    setErrorMessage(null);
    setStatusMessage(null);

    try {
      const formData = new FormData();
      formData.append('enabled', manualPaymentForm.enabled ? '1' : '0');
      formData.append('notes', manualPaymentForm.notes);
      formData.append('methods[bank_transfer][enabled]', manualPaymentForm.bank_enabled ? '1' : '0');
      formData.append('methods[bank_transfer][label]', manualPaymentForm.bank_label);
      formData.append('methods[bank_transfer][bank_name]', manualPaymentForm.bank_name);
      formData.append('methods[bank_transfer][account_name]', manualPaymentForm.bank_account_name);
      formData.append('methods[bank_transfer][account_number]', manualPaymentForm.bank_account_number);
      formData.append('methods[bank_transfer][instructions]', manualPaymentForm.bank_instructions);
      formData.append('methods[gopay][enabled]', manualPaymentForm.gopay_enabled ? '1' : '0');
      formData.append('methods[gopay][label]', manualPaymentForm.gopay_label);
      formData.append('methods[gopay][account_name]', manualPaymentForm.gopay_account_name);
      formData.append('methods[gopay][account_number]', manualPaymentForm.gopay_account_number);
      formData.append('methods[gopay][instructions]', manualPaymentForm.gopay_instructions);
      formData.append('methods[dana][enabled]', manualPaymentForm.dana_enabled ? '1' : '0');
      formData.append('methods[dana][label]', manualPaymentForm.dana_label);
      formData.append('methods[dana][account_name]', manualPaymentForm.dana_account_name);
      formData.append('methods[dana][account_number]', manualPaymentForm.dana_account_number);
      formData.append('methods[dana][instructions]', manualPaymentForm.dana_instructions);
      formData.append('methods[qris][enabled]', manualPaymentForm.qris_enabled ? '1' : '0');
      formData.append('methods[qris][label]', manualPaymentForm.qris_label);
      formData.append('methods[qris][instructions]', manualPaymentForm.qris_instructions);

      Object.entries(manualPaymentFiles).forEach(([key, file]) => {
        if (file) {
          formData.append(`images[${key}]`, file);
        }
      });

      await updateAdminManualPaymentConfig(formData);
      setStatusMessage('Konfigurasi manual payment berhasil disimpan.');
      await loadPayment();
    } catch (saveError) {
      const message = saveError instanceof Error ? saveError.message : 'Gagal menyimpan manual payment';
      setErrorMessage(message);
    } finally {
      setSavingPayment(false);
    }
  };

  const handleResetIpaymuConfig = async () => {
    if (!confirm('Reset semua konfigurasi iPaymu? Ini akan mengosongkan VA, API key, dan callback token.')) {
      return;
    }

    setSavingPayment(true);
    setErrorMessage(null);
    setStatusMessage(null);
    try {
      await resetIpaymuGatewayConfig();
      setIpaymuForm({ va: '', api_key: '', callback_token: '', is_production: false });
      setStatusMessage('Konfigurasi iPaymu berhasil direset.');
      await loadPayment();
    } catch (resetError) {
      const message = resetError instanceof Error ? resetError.message : 'Gagal mereset konfigurasi iPaymu';
      setErrorMessage(message);
    } finally {
      setSavingPayment(false);
    }
  };

  const handleSaveLanding = () => {
    setErrorMessage(null);
    setStatusMessage('Konfigurasi landing masih UI-only. Gunakan Landing Builder untuk publish konten.');
  };

  const handleAddMember = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    try {
      await inviteOrganizationMember({
        email: newMember.email,
        role: newMember.role,
      });
      setStatusMessage('Member berhasil diundang/ditambahkan ke organisasi.');
      setNewMember({ email: '', role: 'member' });
      setShowAddMember(false);
      await loadTeam();
    } catch (submitError) {
      const message = submitError instanceof Error ? submitError.message : 'Gagal menambah member';
      setErrorMessage(message);
    }
  };

  const handleDeleteMember = async (id: number) => {
    if (confirm('Remove this team member?')) {
      setErrorMessage(null);
      try {
        await removeOrganizationMember(id);
        setStatusMessage('Member berhasil dihapus dari organisasi.');
        await loadTeam();
      } catch (deleteError) {
        const message = deleteError instanceof Error ? deleteError.message : 'Gagal menghapus member';
        setErrorMessage(message);
      }
    }
  };

  const renderProviderCard = (provider: ProviderKey) => {
    const providerConfig = paymentConfig.providers[provider];
    const isIpaymu = provider === 'ipaymu';
    const isDoku = provider === 'doku';
    const title = isIpaymu ? 'iPaymu' : isDoku ? 'DOKU' : 'Xendit';
    const isActive = paymentConfig.active_provider === provider;

    return (
      <div className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm" key={provider}>
        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
          <div>
            <div className="flex items-center gap-3">
              <div className={`flex h-11 w-11 items-center justify-center rounded-2xl font-bold text-white ${isIpaymu ? 'bg-emerald-600' : isDoku ? 'bg-blue-600' : 'bg-zinc-950'}`}>
                {isIpaymu ? 'IP' : isDoku ? 'DK' : 'X'}
              </div>
              <div>
                <h3 className="text-lg font-bold text-zinc-900">{title}</h3>
                <p className="text-sm text-zinc-500">
                  {isIpaymu
                    ? 'Redirect payment untuk subscription dan top up. Channel e-wallet di sisi iPaymu akan ikut tampil bila akun merchant Anda memang mengaktifkannya.'
                    : isDoku
                      ? 'Checkout page multi-channel DOKU. Untuk kebutuhan ini, fokuskan ke VA dan QRIS tanpa e-wallet.'
                      : 'Tetap tersedia sebagai gateway lama dan bisa dipilih kembali kapan saja.'}
                </p>
              </div>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            <span className={`rounded-full border px-3 py-1 text-xs font-semibold ${providerConfig.is_ready ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-800'}`}>
              {providerConfig.is_ready ? `${title} ready` : `${title} belum ready`}
            </span>
            <span className={`rounded-full border px-3 py-1 text-xs font-semibold ${providerConfig.mode === 'production' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-sky-200 bg-sky-50 text-sky-700'}`}>
              {providerConfig.mode === 'production' ? 'Production' : 'Sandbox'}
            </span>
            {isActive && (
              <span className="rounded-full border border-zinc-900 bg-zinc-900 px-3 py-1 text-xs font-semibold text-white">
                Provider aktif
              </span>
            )}
          </div>
        </div>

        <div className="mt-5 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
          <p className="font-semibold text-zinc-900">Webhook</p>
          <p className="mt-1 break-all">{providerConfig.webhook.path}</p>
          <p className="mt-1 text-xs text-zinc-500">
            Callback token {providerConfig.webhook.callback_token_configured ? 'sudah tersimpan' : 'belum tersimpan'}.
          </p>
        </div>

        {!isIpaymu && !isDoku && (
          <div className="mt-5 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
            <p className="font-semibold text-zinc-900">Ringkasan Xendit</p>
            <p className="mt-1">Secret key: {paymentConfig.providers.xendit.secret_key_masked || 'Belum ada'}</p>
            <p className="mt-1">Callback token: {paymentConfig.providers.xendit.callback_token_masked || 'Belum ada'}</p>
            <p className="mt-1">VA channels: {(paymentConfig.providers.xendit.va_channels || []).join(', ') || 'Belum diatur'}</p>
            <p className="mt-1">
              Balance: {providerConfig.balance?.amount !== null && providerConfig.balance?.amount !== undefined
                ? `Rp ${Number(providerConfig.balance.amount).toLocaleString('id-ID')}`
                : 'Tidak tersedia'}
            </p>
          </div>
        )}

        {isIpaymu && (
          <div className="mt-5 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
            <p className="font-semibold text-zinc-900">Ringkasan iPaymu</p>
            <p className="mt-1">VA: {paymentConfig.providers.ipaymu.va_masked || 'Belum ada'}</p>
            <p className="mt-1">API key: {paymentConfig.providers.ipaymu.api_key_masked || 'Belum ada'}</p>
            <p className="mt-1">Callback token: {paymentConfig.providers.ipaymu.callback_token_masked || 'Belum ada'}</p>
          </div>
        )}

        {isDoku && (
          <div className="mt-5 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
            <p className="font-semibold text-zinc-900">Ringkasan DOKU</p>
            <p className="mt-1">Client ID: {paymentConfig.providers.doku.client_id_masked || 'Belum ada'}</p>
            <p className="mt-1">Secret key: {paymentConfig.providers.doku.secret_key_masked || 'Belum ada'}</p>
            <p className="mt-1">Callback token: {paymentConfig.providers.doku.callback_token_masked || 'Belum ada'}</p>
            <p className="mt-1">Payment methods: {(paymentConfig.providers.doku.payment_method_types || []).join(', ') || 'Belum diatur'}</p>
          </div>
        )}

        <div className="mt-5 grid gap-4 md:grid-cols-2">
          {isIpaymu ? (
            <>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">Virtual Account (VA)</label>
                <input
                  type="text"
                  value={ipaymuForm.va}
                  onChange={(event) => setIpaymuForm((current) => ({ ...current, va: event.target.value }))}
                  placeholder={paymentConfig.providers.ipaymu.va_masked || 'Contoh: 1179000000'}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">API Key</label>
                <input
                  type="password"
                  value={ipaymuForm.api_key}
                  onChange={(event) => setIpaymuForm((current) => ({ ...current, api_key: event.target.value }))}
                  placeholder={paymentConfig.providers.ipaymu.api_key_masked || 'Tempel API key iPaymu'}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">Callback Token</label>
                <input
                  type="text"
                  value={ipaymuForm.callback_token}
                  onChange={(event) => setIpaymuForm((current) => ({ ...current, callback_token: event.target.value }))}
                  placeholder={paymentConfig.providers.ipaymu.callback_token_masked || 'Auto-generate jika dikosongkan'}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
              </div>
              <label className="flex items-center gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
                <input
                  type="checkbox"
                  checked={ipaymuForm.is_production}
                  onChange={(event) => setIpaymuForm((current) => ({ ...current, is_production: event.target.checked }))}
                />
                Gunakan environment production
              </label>
            </>
          ) : isDoku ? (
            <>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">Client ID</label>
                <input
                  type="text"
                  value={dokuForm.client_id}
                  onChange={(event) => setDokuForm((current) => ({ ...current, client_id: event.target.value }))}
                  placeholder={paymentConfig.providers.doku.client_id_masked || 'MCH-...'}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">Secret Key</label>
                <input
                  type="password"
                  value={dokuForm.secret_key}
                  onChange={(event) => setDokuForm((current) => ({ ...current, secret_key: event.target.value }))}
                  placeholder={paymentConfig.providers.doku.secret_key_masked || 'Tempel secret key DOKU'}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">Callback Token</label>
                <input
                  type="text"
                  value={dokuForm.callback_token}
                  onChange={(event) => setDokuForm((current) => ({ ...current, callback_token: event.target.value }))}
                  placeholder={paymentConfig.providers.doku.callback_token_masked || 'Auto-generate jika dikosongkan'}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">Payment Method Types</label>
                <input
                  type="text"
                  value={dokuForm.payment_method_types}
                  onChange={(event) => setDokuForm((current) => ({ ...current, payment_method_types: event.target.value }))}
                  placeholder="VIRTUAL_ACCOUNT_BCA, VIRTUAL_ACCOUNT_BANK_MANDIRI, QRIS"
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
                <p className="mt-1 text-xs text-zinc-500">Jangan isi metode `EMONEY_*` karena flow ini memang tanpa e-wallet.</p>
              </div>
              <label className="flex items-center gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700 md:col-span-2">
                <input
                  type="checkbox"
                  checked={dokuForm.is_production}
                  onChange={(event) => setDokuForm((current) => ({ ...current, is_production: event.target.checked }))}
                />
                Gunakan environment production
              </label>
            </>
          ) : (
            <>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">Secret API Key</label>
                <input
                  type="password"
                  value={xenditForm.secret_key}
                  onChange={(event) => setXenditForm((current) => ({ ...current, secret_key: event.target.value }))}
                  placeholder={paymentConfig.providers.xendit.secret_key_masked || 'xnd_development_...'}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">Callback Token</label>
                <input
                  type="text"
                  value={xenditForm.callback_token}
                  onChange={(event) => setXenditForm((current) => ({ ...current, callback_token: event.target.value }))}
                  placeholder={paymentConfig.providers.xendit.callback_token_masked || 'Auto-generate jika dikosongkan'}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-zinc-700">VA Channels</label>
                <input
                  type="text"
                  value={xenditForm.va_channels}
                  onChange={(event) => setXenditForm((current) => ({ ...current, va_channels: event.target.value }))}
                  placeholder="BCA, BNI, BRI"
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
              </div>
              <label className="flex items-center gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
                <input
                  type="checkbox"
                  checked={xenditForm.is_production}
                  onChange={(event) => setXenditForm((current) => ({ ...current, is_production: event.target.checked }))}
                />
                Gunakan environment production
              </label>
            </>
          )}
        </div>

        <div className="mt-5 flex flex-wrap justify-end gap-3">
          {isIpaymu && (
            <button
              type="button"
              onClick={() => void handleResetIpaymuConfig()}
              disabled={savingPayment}
              className="inline-flex items-center gap-2 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-100 disabled:opacity-60"
            >
              <Trash2 className="h-4 w-4" />
              Reset iPaymu
            </button>
          )}
          <button
            type="button"
            onClick={() => void handleSaveGatewayConfig(provider)}
            disabled={savingPayment}
            className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-60"
          >
            <Save className="h-4 w-4" />
            Simpan {title}
          </button>
        </div>
      </div>
    );
  };

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Platform Settings</h1>
        <p className="text-zinc-500">Configure payments, landing page, and team access.</p>
      </div>

      {errorMessage && (
        <div className="flex items-center gap-2 rounded-2xl border border-red-100 bg-red-50 p-3 text-sm text-red-600">
          <AlertCircle className="h-4 w-4" /> {errorMessage}
        </div>
      )}
      {statusMessage && (
        <div className="rounded-2xl border border-green-100 bg-green-50 p-3 text-sm text-green-700">{statusMessage}</div>
      )}

      <div className="flex overflow-x-auto border-b border-zinc-200">
        {[
          { key: 'payment', label: 'Payment Gateways', icon: CreditCard },
          { key: 'landing', label: 'Landing Page', icon: Globe },
          { key: 'team', label: 'Team & Account', icon: Users },
        ].map((tab) => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key as typeof activeTab)}
            className={`flex items-center gap-2 whitespace-nowrap border-b-2 px-6 py-3 text-sm font-medium transition-colors ${
              activeTab === tab.key ? 'border-zinc-900 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-700'
            }`}
          >
            <tab.icon className="h-4 w-4" /> {tab.label}
          </button>
        ))}
      </div>

      {activeTab === 'payment' && (
        <div className="space-y-6">
          <div className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <h2 className="text-lg font-bold text-zinc-900">Runtime pembayaran owner</h2>
                <p className="mt-1 text-sm text-zinc-500">
                  Pilih provider aktif, tentukan mode checkout langsung, dan hide/show wallet member dari sini.
                </p>
              </div>
              <button
                type="button"
                onClick={() => void loadPayment()}
                className="inline-flex items-center gap-2 self-start rounded-2xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
              >
                <RefreshCw className={`h-4 w-4 ${loadingPayment ? 'animate-spin' : ''}`} />
                Refresh
              </button>
            </div>

            <div className="mt-6 grid gap-4 md:grid-cols-2">
              {(['xendit', 'ipaymu', 'doku'] as ProviderKey[]).map((provider) => {
                const selected = paymentConfig.active_provider === provider;
                const providerLabel = provider === 'ipaymu' ? 'iPaymu' : provider === 'doku' ? 'DOKU' : 'Xendit';
                return (
                  <label
                    key={provider}
                    className={`cursor-pointer rounded-3xl border p-5 transition ${
                      selected ? 'border-zinc-900 bg-zinc-50' : 'border-zinc-200 hover:bg-zinc-50'
                    }`}
                  >
                    <div className="flex items-start gap-3">
                      <input
                        type="radio"
                        name="active_provider"
                        checked={selected}
                        onChange={() => setPaymentConfig((current) => ({ ...current, active_provider: provider }))}
                        className="mt-1"
                      />
                      <div>
                        <p className="font-semibold text-zinc-900">{providerLabel} sebagai gateway aktif</p>
                        <p className="mt-1 text-sm text-zinc-600">
                          Semua checkout otomatis akan memakai provider ini. Konfigurasi provider lain tetap tersimpan sebagai fallback.
                        </p>
                      </div>
                    </div>
                  </label>
                );
              })}
            </div>

            <div className="mt-5 grid gap-4 md:grid-cols-2">
              <label className={`cursor-pointer rounded-3xl border p-5 transition ${
                paymentConfig.checkout_mode === 'manual_confirmation' ? 'border-amber-300 bg-amber-50' : 'border-zinc-200 hover:bg-zinc-50'
              }`}>
                <div className="flex items-start gap-3">
                  <input
                    type="radio"
                    name="checkout_mode"
                    checked={paymentConfig.checkout_mode === 'manual_confirmation'}
                    onChange={() => setPaymentConfig((current) => ({ ...current, checkout_mode: 'manual_confirmation' }))}
                    className="mt-1"
                  />
                  <div>
                    <p className="font-semibold text-zinc-900">Konfirmasi manual owner</p>
                    <p className="mt-1 text-sm text-zinc-600">Checkout langsung dibuat, lalu owner review dulu sebelum aplikasi dibuka.</p>
                  </div>
                </div>
              </label>

              <label className={`cursor-pointer rounded-3xl border p-5 transition ${
                paymentConfig.checkout_mode === 'gateway_automatic' ? 'border-blue-300 bg-blue-50' : 'border-zinc-200 hover:bg-zinc-50'
              }`}>
                <div className="flex items-start gap-3">
                  <input
                    type="radio"
                    name="checkout_mode"
                    checked={paymentConfig.checkout_mode === 'gateway_automatic'}
                    onChange={() => setPaymentConfig((current) => ({ ...current, checkout_mode: 'gateway_automatic' }))}
                    className="mt-1"
                  />
                  <div>
                    <p className="font-semibold text-zinc-900">Checkout otomatis ke gateway aktif</p>
                    <p className="mt-1 text-sm text-zinc-600">Buyer langsung diarahkan ke payment link provider aktif yang dipilih owner.</p>
                  </div>
                </div>
              </label>
            </div>

            <div className="mt-5 rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
              <div className="flex items-start gap-4">
                <div className={`flex h-11 w-11 items-center justify-center rounded-2xl ${paymentConfig.member_wallet_enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-zinc-200 text-zinc-700'}`}>
                  <Wallet className="h-5 w-5" />
                </div>
                <div className="flex-1">
                  <p className="font-semibold text-zinc-900">Wallet / e-wallet member</p>
                  <p className="mt-1 text-sm text-zinc-600">
                    Saat dimatikan, menu pembayaran wallet, top up, dan panel saldo member akan disembunyikan dari dashboard pembeli.
                  </p>
                </div>
                <label className="inline-flex items-center gap-3 rounded-full border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-700">
                  <input
                    type="checkbox"
                    checked={paymentConfig.member_wallet_enabled}
                    onChange={(event) => setPaymentConfig((current) => ({ ...current, member_wallet_enabled: event.target.checked }))}
                  />
                  {paymentConfig.member_wallet_enabled ? 'Ditampilkan' : 'Disembunyikan'}
                </label>
              </div>
            </div>

            <div className="mt-5 flex justify-end">
              <button
                type="button"
                onClick={() => void handleSaveRuntime()}
                disabled={savingPayment}
                className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-60"
              >
                <Shield className="h-4 w-4" />
                Simpan Runtime Payment
              </button>
            </div>
          </div>

          {renderProviderCard('xendit')}
          {renderProviderCard('ipaymu')}
          {renderProviderCard('doku')}

          <div className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h3 className="text-lg font-bold text-zinc-900">Manual payment fallback</h3>
            <p className="mt-1 text-sm text-zinc-500">Publikasikan rekening bank, GoPay, DANA, dan QRIS static ke dashboard pembeli sebagai backup ketika gateway bermasalah.</p>

            <div className="mt-5 grid gap-4 md:grid-cols-2">
              <label className="flex items-center gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700 md:col-span-2">
                <input
                  type="checkbox"
                  checked={manualPaymentForm.enabled}
                  onChange={(event) => setManualPaymentForm((current) => ({ ...current, enabled: event.target.checked }))}
                />
                Aktifkan jalur manual payment
              </label>

              <label className="space-y-2 text-sm md:col-span-2">
                <span className="font-medium text-zinc-700">Catatan umum</span>
                <textarea
                  rows={3}
                  value={manualPaymentForm.notes}
                  onChange={(event) => setManualPaymentForm((current) => ({ ...current, notes: event.target.value }))}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 outline-none focus:ring-2 focus:ring-zinc-900"
                  placeholder="Contoh: Setelah transfer, tunggu verifikasi owner maksimal 1x24 jam."
                />
              </label>
            </div>

            {[
              { key: 'bank', title: 'Transfer Bank', enabledKey: 'bank_enabled', labelKey: 'bank_label', nameKey: 'bank_account_name', numberKey: 'bank_account_number', instructionKey: 'bank_instructions', bankNameKey: 'bank_name', uploadKey: 'bank_transfer' },
              { key: 'gopay', title: 'GoPay', enabledKey: 'gopay_enabled', labelKey: 'gopay_label', nameKey: 'gopay_account_name', numberKey: 'gopay_account_number', instructionKey: 'gopay_instructions', uploadKey: 'gopay' },
              { key: 'dana', title: 'DANA', enabledKey: 'dana_enabled', labelKey: 'dana_label', nameKey: 'dana_account_name', numberKey: 'dana_account_number', instructionKey: 'dana_instructions', uploadKey: 'dana' },
              { key: 'qris', title: 'QRIS Static', enabledKey: 'qris_enabled', labelKey: 'qris_label', instructionKey: 'qris_instructions', uploadKey: 'qris' },
            ].map((item) => (
              <div key={item.key} className="mt-5 rounded-2xl border border-zinc-200 p-4">
                <label className="flex items-center gap-3 text-sm font-semibold text-zinc-900">
                  <input
                    type="checkbox"
                    checked={(manualPaymentForm as any)[item.enabledKey]}
                    onChange={(event) => setManualPaymentForm((current) => ({ ...current, [item.enabledKey]: event.target.checked }))}
                  />
                  {item.title}
                </label>
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                  <input
                    value={(manualPaymentForm as any)[item.labelKey]}
                    onChange={(event) => setManualPaymentForm((current) => ({ ...current, [item.labelKey]: event.target.value }))}
                    placeholder="Label tampilan"
                    className="rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                  />
                  {'bankNameKey' in item && item.bankNameKey ? (
                    <input
                      value={(manualPaymentForm as any)[item.bankNameKey]}
                      onChange={(event) => setManualPaymentForm((current) => ({ ...current, [item.bankNameKey]: event.target.value }))}
                      placeholder="Nama bank"
                      className="rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                    />
                  ) : <div />}
                  {'nameKey' in item && item.nameKey ? (
                    <input
                      value={(manualPaymentForm as any)[item.nameKey]}
                      onChange={(event) => setManualPaymentForm((current) => ({ ...current, [item.nameKey]: event.target.value }))}
                      placeholder="Nama akun"
                      className="rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                    />
                  ) : <div />}
                  {'numberKey' in item && item.numberKey ? (
                    <input
                      value={(manualPaymentForm as any)[item.numberKey]}
                      onChange={(event) => setManualPaymentForm((current) => ({ ...current, [item.numberKey]: event.target.value }))}
                      placeholder="Nomor akun"
                      className="rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                    />
                  ) : <div />}
                  <textarea
                    rows={3}
                    value={(manualPaymentForm as any)[item.instructionKey]}
                    onChange={(event) => setManualPaymentForm((current) => ({ ...current, [item.instructionKey]: event.target.value }))}
                    placeholder="Instruksi pembayaran"
                    className="rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900 md:col-span-2"
                  />
                  {manualPaymentPreviews[item.uploadKey] ? (
                    <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 md:col-span-2">
                      <p className="text-xs font-semibold text-zinc-600">Preview gambar tersimpan</p>
                      <img
                        src={manualPaymentPreviews[item.uploadKey] as string}
                        alt={`${item.title} preview`}
                        className="mt-3 h-40 w-40 rounded-xl border border-zinc-200 object-contain bg-white p-2"
                      />
                    </div>
                  ) : null}
                  <input
                    type="file"
                    accept="image/*"
                    onChange={(event) => {
                      const file = event.target.files?.[0] || null;
                      setManualPaymentFiles((current) => ({ ...current, [item.uploadKey]: file }));
                      if (file) {
                        const previewUrl = URL.createObjectURL(file);
                        setManualPaymentPreviews((current) => ({ ...current, [item.uploadKey]: previewUrl }));
                      }
                    }}
                    className="rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900 md:col-span-2"
                  />
                </div>
              </div>
            ))}

            <div className="mt-5 flex justify-end">
              <button
                type="button"
                onClick={() => void handleSaveManualPayment()}
                disabled={savingPayment}
                className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-60"
              >
                <Save className="h-4 w-4" />
                Simpan Manual Payment
              </button>
            </div>
          </div>
        </div>
      )}

      {activeTab === 'landing' && (
        <div className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
          <h2 className="text-lg font-bold text-zinc-900">Landing Page Settings</h2>
          <div className="mt-6 grid gap-4 md:grid-cols-2">
            <label className="space-y-2 text-sm">
              <span className="font-medium text-zinc-700">Site Name</span>
              <input
                value={landingConfig.siteName}
                onChange={(event) => setLandingConfig((current) => ({ ...current, siteName: event.target.value }))}
                className="w-full rounded-2xl border border-zinc-300 px-4 py-3 outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </label>
            <label className="space-y-2 text-sm">
              <span className="font-medium text-zinc-700">CTA Text</span>
              <input
                value={landingConfig.ctaText}
                onChange={(event) => setLandingConfig((current) => ({ ...current, ctaText: event.target.value }))}
                className="w-full rounded-2xl border border-zinc-300 px-4 py-3 outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </label>
            <label className="space-y-2 text-sm md:col-span-2">
              <span className="font-medium text-zinc-700">Hero Title</span>
              <input
                value={landingConfig.heroTitle}
                onChange={(event) => setLandingConfig((current) => ({ ...current, heroTitle: event.target.value }))}
                className="w-full rounded-2xl border border-zinc-300 px-4 py-3 outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </label>
            <label className="space-y-2 text-sm md:col-span-2">
              <span className="font-medium text-zinc-700">Hero Subtitle</span>
              <textarea
                value={landingConfig.heroSubtitle}
                onChange={(event) => setLandingConfig((current) => ({ ...current, heroSubtitle: event.target.value }))}
                rows={4}
                className="w-full rounded-2xl border border-zinc-300 px-4 py-3 outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </label>
          </div>
          <div className="mt-6 flex justify-end">
            <button
              type="button"
              onClick={handleSaveLanding}
              className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
            >
              <Save className="h-4 w-4" />
              Simpan Landing
            </button>
          </div>
        </div>
      )}

      {activeTab === 'team' && (
        <div className="space-y-6">
          <div className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
              <div>
                <h2 className="text-lg font-bold text-zinc-900">Team & Account</h2>
                <p className="text-sm text-zinc-500">Owner utama saat ini: {adminEmail}</p>
              </div>
              <button
                type="button"
                onClick={() => setShowAddMember((current) => !current)}
                className="inline-flex items-center gap-2 rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
              >
                <Plus className="h-4 w-4" />
                Tambah Member
              </button>
            </div>

            {showAddMember && (
              <form onSubmit={handleAddMember} className="mt-6 grid gap-4 md:grid-cols-[1fr_180px_auto]">
                <input
                  type="email"
                  required
                  value={newMember.email}
                  onChange={(event) => setNewMember((current) => ({ ...current, email: event.target.value }))}
                  placeholder="email@contoh.com"
                  className="rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
                <select
                  value={newMember.role}
                  onChange={(event) => setNewMember((current) => ({ ...current, role: event.target.value as 'admin' | 'member' }))}
                  className="rounded-2xl border border-zinc-300 px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                >
                  <option value="member">Member</option>
                  <option value="admin">Admin</option>
                </select>
                <button type="submit" className="rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                  Simpan
                </button>
              </form>
            )}
          </div>

          <div className="rounded-3xl border border-zinc-200 bg-white shadow-sm">
            <div className="border-b border-zinc-200 px-6 py-4">
              <h3 className="font-bold text-zinc-900">Daftar Team</h3>
            </div>
            <div className="divide-y divide-zinc-100">
              {loadingTeam && (
                <div className="px-6 py-8 text-sm text-zinc-500">Memuat data team...</div>
              )}
              {!loadingTeam && team.length === 0 && (
                <div className="px-6 py-8 text-sm text-zinc-500">Belum ada member di organisasi ini.</div>
              )}
              {!loadingTeam && team.map((member) => (
                <div key={member.id} className="flex items-center justify-between gap-4 px-6 py-4">
                  <div>
                    <p className="font-semibold text-zinc-900">{member.name || 'Tanpa nama'}</p>
                    <p className="text-sm text-zinc-500">{member.email}</p>
                    <p className="text-xs uppercase tracking-[0.14em] text-zinc-400">{member.role}</p>
                  </div>
                  <button
                    type="button"
                    onClick={() => void handleDeleteMember(member.id)}
                    className="inline-flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-100"
                  >
                    <Trash2 className="h-4 w-4" />
                    Hapus
                  </button>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
