import { useEffect, useState } from 'react';
import {
  Mail, Save, Send, AlertCircle, CheckCircle, RefreshCw
} from 'lucide-react';
import {
  getAdminMailSettings,
  updateAdminMailSettings,
  sendAdminMailTest,
} from '@/lib/hellomApi';
import MailDomainPanel from './MailDomainPanel';
import MailLogPanel from './MailLogPanel';

type MailSettings = {
  enabled: boolean;
  provider: string;
  resend_configured: boolean;
  from_address: string;
  from_name: string;
  reply_to_address: string;
  reply_to_name: string;
  is_ready: boolean;
  owner_email: string;
  owner_email_effective: string[];
};

export default function EmailSetting() {
  const [settings, setSettings] = useState<MailSettings>({
    enabled: false,
    provider: 'resend',
    resend_configured: false,
    from_address: '',
    from_name: '',
    reply_to_address: '',
    reply_to_name: '',
    is_ready: false,
    owner_email: '',
    owner_email_effective: [],
  });

  const [form, setForm] = useState<{
    enabled: boolean;
    from_address: string;
    from_name: string;
    reply_to_address: string;
    reply_to_name: string;
    owner_email: string;
  }>({
    enabled: false,
    from_address: '',
    from_name: '',
    reply_to_address: '',
    reply_to_name: '',
    owner_email: '',
  });

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [statusMessage, setStatusMessage] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [testEmail, setTestEmail] = useState('');

  const loadSettings = async () => {
    setLoading(true);
    setErrorMessage(null);
    try {
      const data = await getAdminMailSettings();
      setSettings(data.mail);
      setForm({
        enabled: data.mail.enabled,
        from_address: data.mail.from_address,
        from_name: data.mail.from_name,
        reply_to_address: data.mail.reply_to_address,
        reply_to_name: data.mail.reply_to_name,
        owner_email: data.mail.owner_email ?? '',
      });
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat email settings';
      setErrorMessage(message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadSettings();
  }, []);

  const handleSave = async () => {
    setSaving(true);
    setErrorMessage(null);
    setStatusMessage(null);
    try {
      const result = await updateAdminMailSettings({
        enabled: form.enabled,
        from_address: form.from_address,
        from_name: form.from_name,
        reply_to_address: form.reply_to_address,
        reply_to_name: form.reply_to_name,
        owner_email: form.owner_email,
      });
      setStatusMessage('Email settings berhasil disimpan.');
      setSettings(result.mail);
      await loadSettings();
    } catch (saveError) {
      const message = saveError instanceof Error ? saveError.message : 'Gagal menyimpan email settings';
      setErrorMessage(message);
    } finally {
      setSaving(false);
    }
  };

  const handleTestEmail = async () => {
    if (!testEmail) {
      setErrorMessage('Masukkan email untuk test');
      return;
    }
    setTesting(true);
    setErrorMessage(null);
    setStatusMessage(null);
    try {
      const result = await sendAdminMailTest(testEmail);
      if (result.delivery.sent) {
        setStatusMessage(`Email test berhasil dikirim ke ${testEmail} via ${result.delivery.mailer || 'unknown'}`);
      } else {
        setErrorMessage(`Email test gagal: ${result.delivery.error || 'Unknown error'}`);
      }
    } catch (testError) {
      const message = testError instanceof Error ? testError.message : 'Gagal mengirim email test';
      setErrorMessage(message);
    } finally {
      setTesting(false);
    }
  };

  if (loading) {
    return (
      <div className="space-y-6 max-w-4xl mx-auto">
        <div className="animate-pulse">
          <div className="h-8 bg-zinc-200 rounded w-1/4 mb-4"></div>
          <div className="h-64 bg-zinc-100 rounded-xl"></div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Pengaturan Email</h1>
        <p className="text-zinc-500">Resend mengirim semua email platform ke pengguna (verifikasi & login, reset password, penjualan, pembayaran, penarikan dana), dan <strong>email owner</strong> menerima salinan setiap aktivitas penting untuk super admin.</p>
      </div>

      {errorMessage && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600 flex items-center gap-2">
          <AlertCircle className="w-4 h-4" /> {errorMessage}
        </div>
      )}
      {statusMessage && (
        <div className="p-3 rounded-lg bg-green-50 border border-green-100 text-sm text-green-700 flex items-center gap-2">
          <CheckCircle className="w-4 h-4" /> {statusMessage}
        </div>
      )}

      <div className="bg-white p-6 rounded-xl border border-zinc-200 shadow-sm space-y-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className={`w-10 h-10 rounded-lg flex items-center justify-center text-white font-bold text-xs ${
              settings.is_ready ? 'bg-green-600' : 'bg-zinc-400'
            }`}>
              <Mail className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-bold text-zinc-900">Email resmi (pengirim)</h3>
              <p className="text-sm text-zinc-500">Resend mengirim semua email Hellom ke pengguna, penjual, dan pembeli.</p>
            </div>
          </div>
          <span className={`px-3 py-1 rounded-full text-xs font-semibold border ${
            settings.is_ready
              ? 'bg-green-50 border-green-200 text-green-700'
              : 'bg-amber-50 border-amber-200 text-amber-800'
          }`}>
            {settings.is_ready ? 'Ready' : 'Not Ready'}
          </span>
        </div>

        <div className="grid md:grid-cols-2 gap-6">
          <div className="space-y-4">
            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={form.enabled}
                onChange={(e) => setForm({ ...form, enabled: e.target.checked })}
                className="w-4 h-4 text-green-600 rounded border-zinc-300 focus:ring-green-500"
              />
              <span className="text-sm font-medium text-zinc-700">Aktifkan email notifikasi</span>
            </label>

            <p className="rounded-lg bg-zinc-50 p-3 text-sm text-zinc-600">
              Provider: <strong>Resend</strong>. API key dibaca aman dari <code>RESEND_API_KEY</code> di environment backend dan tidak disimpan di database atau browser.
              {!settings.resend_configured ? ' API key belum dikonfigurasi.' : ''}
            </p>
          </div>

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">Email resmi pengirim (From)</label>
              <input
                type="email"
                value={form.from_address}
                onChange={(e) => setForm({ ...form, from_address: e.target.value })}
                placeholder="noreply@yourdomain.com"
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">Nama pengirim</label>
              <input
                type="text"
                value={form.from_name}
                onChange={(e) => setForm({ ...form, from_name: e.target.value })}
                placeholder="Hellom Platform"
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">Reply To Address</label>
              <input
                type="email"
                value={form.reply_to_address}
                onChange={(e) => setForm({ ...form, reply_to_address: e.target.value })}
                placeholder="support@yourdomain.com"
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">Nama balasan (Reply-To)</label>
              <input
                type="text"
                value={form.reply_to_name}
                onChange={(e) => setForm({ ...form, reply_to_name: e.target.value })}
                placeholder="Hellom Support"
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </div>
          </div>
        </div>

        <div className="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50 p-4" data-owner-email>
          <label className="block text-sm font-bold text-zinc-900" htmlFor="owner-email">Email owner (notifikasi aktivitas)</label>
          <p className="text-sm text-zinc-600">Email pribadi owner. Setiap aktivitas yang masuk ke notifikasi super admin — <strong>penarikan dana yang menunggu persetujuan</strong>, pendaftar baru, pembayaran & konfirmasi manual, pembelian produk, peringatan batas waktu penarikan — juga dikirim ke sini. Beberapa email pisahkan dengan koma.</p>
          <input
            id="owner-email"
            type="text"
            inputMode="email"
            value={form.owner_email}
            onChange={(e) => setForm({ ...form, owner_email: e.target.value })}
            placeholder="emailpribadi@gmail.com"
            className="w-full min-h-11 px-3 py-2 border border-zinc-300 rounded-lg text-base outline-none focus:ring-2 focus:ring-zinc-900"
          />
          <p className="text-xs text-zinc-500">
            Saat ini dikirim ke: {settings.owner_email_effective.length > 0 ? settings.owner_email_effective.join(', ') : 'belum ada'}
            {!settings.owner_email && settings.owner_email_effective.length > 0 ? ' (email akun super admin, karena kolom ini masih kosong)' : ''}.
            {!settings.is_ready ? ' Email terkirim setelah Resend API key dikonfigurasi dan email diaktifkan.' : ''}
          </p>
        </div>

        <div className="flex justify-end gap-3">
          <button
            onClick={() => void loadSettings()}
            className="px-5 py-2 bg-white border border-zinc-200 text-zinc-700 font-semibold rounded-lg hover:bg-zinc-50 transition-colors flex items-center gap-2"
          >
            <RefreshCw className="w-4 h-4" /> Reload
          </button>
          <button
            onClick={() => void handleSave()}
            disabled={saving}
            className="px-6 py-2 bg-zinc-900 text-white font-bold rounded-lg hover:bg-zinc-800 transition-colors flex items-center gap-2 disabled:opacity-70"
          >
            <Save className="w-4 h-4" /> {saving ? 'Menyimpan...' : 'Simpan Settings'}
          </button>
        </div>
      </div>

      <div className="bg-white p-6 rounded-xl border border-zinc-200 shadow-sm">
        <div className="flex items-center gap-3 mb-4">
          <div className="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-xs">
            <Send className="w-5 h-5" />
          </div>
          <div>
            <h3 className="font-bold text-zinc-900">Kirim email tes</h3>
            <p className="text-sm text-zinc-500">Kirim email tes lewat Resend untuk memastikan konfigurasi benar.</p>
          </div>
        </div>

        <div className="flex gap-3">
          <input
            type="email"
            value={testEmail}
            onChange={(e) => setTestEmail(e.target.value)}
            placeholder="test@example.com"
            className="flex-1 px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500"
          />
          <button
            onClick={() => void handleTestEmail()}
            disabled={testing || !settings.is_ready}
            className="px-6 py-2 bg-blue-600 text-white font-bold rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-2 disabled:opacity-70 disabled:bg-zinc-400"
          >
            <Send className="w-4 h-4" /> {testing ? 'Mengirim...' : 'Kirim Test Email'}
          </button>
        </div>

        {!settings.is_ready && (
          <p className="mt-2 text-xs text-amber-700">Atur RESEND_API_KEY di environment backend, aktifkan email, lalu pastikan From memakai domain terverifikasi.</p>
        )}
      </div>

      <MailDomainPanel />

      <MailLogPanel />

      <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
        <p className="font-semibold text-zinc-900">Catatan keamanan</p>
        <div className="mt-2 space-y-2">
          <p>API key Resend hanya disimpan di environment backend. Jangan masukkan API key ke halaman ini, frontend, atau chat.</p>
          <p>Gunakan alamat pengirim dari domain yang sudah diverifikasi di Resend. Status terkirim berarti Resend menerima permintaan pengiriman, bukan jaminan masuk inbox.</p>
          <p>Test email akan dikirim dari konfigurasi yang sudah tersimpan.</p>
        </div>
      </div>
    </div>
  );
}
