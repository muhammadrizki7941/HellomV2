import { useEffect, useState } from 'react';
import {
  Mail, Save, Send, AlertCircle, CheckCircle, RefreshCw,
  Shield, Eye, EyeOff
} from 'lucide-react';
import {
  getAdminMailSettings,
  updateAdminMailSettings,
  sendAdminMailTest,
} from '@/lib/hellomApi';

type MailSettings = {
  enabled: boolean;
  host: string;
  port: number;
  username: string;
  password_masked: string | null;
  encryption: string;
  from_address: string;
  from_name: string;
  reply_to_address: string;
  reply_to_name: string;
  is_ready: boolean;
};

type EncryptionOption = 'tls' | 'ssl' | 'none';

export default function EmailSetting() {
  const [settings, setSettings] = useState<MailSettings>({
    enabled: false,
    host: '',
    port: 587,
    username: '',
    password_masked: null,
    encryption: 'tls',
    from_address: '',
    from_name: '',
    reply_to_address: '',
    reply_to_name: '',
    is_ready: false,
  });

  const [form, setForm] = useState<{
    enabled: boolean;
    host: string;
    port: number;
    username: string;
    password: string;
    encryption: EncryptionOption;
    from_address: string;
    from_name: string;
    reply_to_address: string;
    reply_to_name: string;
  }>({
    enabled: false,
    host: '',
    port: 587,
    username: '',
    password: '',
    encryption: 'tls',
    from_address: '',
    from_name: '',
    reply_to_address: '',
    reply_to_name: '',
  });

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [statusMessage, setStatusMessage] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [testEmail, setTestEmail] = useState('');
  const [showPassword, setShowPassword] = useState(false);

  const loadSettings = async () => {
    setLoading(true);
    setErrorMessage(null);
    try {
      const data = await getAdminMailSettings();
      setSettings(data.mail);
      setForm({
        enabled: data.mail.enabled,
        host: data.mail.host,
        port: data.mail.port,
        username: data.mail.username,
        password: '',
        encryption: data.mail.encryption as EncryptionOption,
        from_address: data.mail.from_address,
        from_name: data.mail.from_name,
        reply_to_address: data.mail.reply_to_address,
        reply_to_name: data.mail.reply_to_name,
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
        host: form.host,
        port: form.port,
        username: form.username,
        password: form.password || undefined,
        encryption: form.encryption,
        from_address: form.from_address,
        from_name: form.from_name,
        reply_to_address: form.reply_to_address,
        reply_to_name: form.reply_to_name,
      });
      setStatusMessage('Email settings berhasil disimpan.');
      setSettings(result.mail);
      setForm((current) => ({
        ...current,
        password: '', // clear password after save
      }));
      await loadSettings(); // reload to get updated masked password
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

  const encryptionOptions: Array<{ value: EncryptionOption; label: string }> = [
    { value: 'tls', label: 'TLS (Port 587)' },
    { value: 'ssl', label: 'SSL (Port 465)' },
    { value: 'none', label: 'None (Port 25)' },
  ];

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
        <h1 className="text-2xl font-bold text-zinc-900">Email Settings</h1>
        <p className="text-zinc-500">Konfigurasi SMTP untuk notifikasi email owner.</p>
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
              <h3 className="font-bold text-zinc-900">SMTP Configuration</h3>
              <p className="text-sm text-zinc-500">Pengaturan koneksi email untuk notifikasi.</p>
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
              <span className="text-sm font-medium text-zinc-700">Enable Email Notifications</span>
            </label>

            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">SMTP Host</label>
              <input
                type="text"
                value={form.host}
                onChange={(e) => setForm({ ...form, host: e.target.value })}
                placeholder="smtp.gmail.com"
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">Port</label>
              <input
                type="number"
                value={form.port}
                onChange={(e) => setForm({ ...form, port: parseInt(e.target.value) || 587 })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">Username</label>
              <input
                type="text"
                value={form.username}
                onChange={(e) => setForm({ ...form, username: e.target.value })}
                placeholder="your-email@gmail.com"
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">Password</label>
              <div className="relative">
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={form.password}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  placeholder={settings.password_masked ? 'Leave empty to keep current' : 'Enter password'}
                  className="w-full px-3 py-2 pr-10 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-2.5 text-zinc-400 hover:text-zinc-600"
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              {settings.password_masked && (
                <p className="mt-1 text-xs text-zinc-500">Current: {settings.password_masked}</p>
              )}
            </div>

            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">Encryption</label>
              <select
                value={form.encryption}
                onChange={(e) => setForm({ ...form, encryption: e.target.value as EncryptionOption })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
              >
                {encryptionOptions.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">From Address</label>
              <input
                type="email"
                value={form.from_address}
                onChange={(e) => setForm({ ...form, from_address: e.target.value })}
                placeholder="noreply@yourdomain.com"
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-zinc-900"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-zinc-700 mb-2">From Name</label>
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
              <label className="block text-sm font-medium text-zinc-700 mb-2">Reply To Name</label>
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
            <h3 className="font-bold text-zinc-900">Test Email</h3>
            <p className="text-sm text-zinc-500">Kirim email test untuk memastikan konfigurasi benar.</p>
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
          <p className="mt-2 text-xs text-amber-700">Email settings belum ready. Simpan dulu sebelum test.</p>
        )}
      </div>

      <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
        <p className="font-semibold text-zinc-900">Catatan keamanan</p>
        <div className="mt-2 space-y-2">
          <p>Kredensial SMTP disimpan terenkripsi di database. Password tidak pernah dikirim ke frontend.</p>
          <p>Gunakan app password jika menggunakan Gmail. Jangan gunakan password akun utama.</p>
          <p>Test email akan dikirim dari konfigurasi yang sudah tersimpan.</p>
        </div>
      </div>
    </div>
  );
}