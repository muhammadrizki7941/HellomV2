import React, { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { 
  User, Lock, Shield, Smartphone, Upload, 
  Check, AlertCircle, KeyRound, LogOut, Building2
} from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import {
  changePassword,
  clearSession,
  getAuthMe,
  getCurrentOrganization,
  getOrganizations,
  getSessionUser,
  getToken,
  logout,
  setSession,
  updateProfile,
} from '@/lib/hellomApi';

// --- SCHEMAS ---

const profileSchema = z.object({
  username: z.string().min(3, 'Username minimal 3 karakter'),
  email: z.string().email('Email tidak valid'),
  fullName: z.string().min(2, 'Nama lengkap minimal 2 karakter'),
});

const passwordSchema = z.object({
  currentPassword: z.string().min(1, 'Password saat ini harus diisi'),
  newPassword: z.string().min(8, 'Password baru minimal 8 karakter'),
  confirmPassword: z.string()
}).refine((data) => data.newPassword === data.confirmPassword, {
  message: "Password baru tidak cocok",
  path: ["confirmPassword"],
});

const twoFactorSchema = z.object({
  code: z.string().length(6, 'Kode harus 6 digit'),
});

type ProfileForm = z.infer<typeof profileSchema>;
type PasswordForm = z.infer<typeof passwordSchema>;
type TwoFactorForm = z.infer<typeof twoFactorSchema>;

export default function MemberProfile() {
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState<'profile' | 'security'>('profile');
  const [is2FAEnabled, setIs2FAEnabled] = useState(false);
  const [show2FASetup, setShow2FASetup] = useState(false);
  const [loadingProfile, setLoadingProfile] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [organizations, setOrganizations] = useState<Array<{ id: number; name: string; role: string }>>([]);
  const [currentOrganizationId, setCurrentOrganizationId] = useState<number | null>(null);

  // Mock User Data
  const [user, setUser] = useState({
    username: 'member_demo',
    email: 'member@example.com',
    fullName: 'Demo Member',
    avatar: 'https://picsum.photos/seed/user/200/200'
  });

  useEffect(() => {
    const loadProfile = async () => {
      setLoadingProfile(true);
      setErrorMessage(null);
      try {
        const me = await getAuthMe();
        const nextUser = {
          username: me.email?.split('@')[0] || 'member',
          email: me.email || '',
          fullName: me.name || '',
          avatar: `https://picsum.photos/seed/${me.id || 'user'}/200/200`,
        };
        setUser(nextUser);
        profileForm.reset(nextUser);

        const token = getToken();
        if (token) {
          setSession(token, me);
        }
      } catch (loadError) {
        const cachedUser = getSessionUser<{ name?: string; email?: string; id?: number }>();
        if (cachedUser) {
          const fallback = {
            username: cachedUser.email?.split('@')[0] || 'member',
            email: cachedUser.email || 'member@example.com',
            fullName: cachedUser.name || 'Member',
            avatar: `https://picsum.photos/seed/${cachedUser.id || 'user'}/200/200`,
          };
          setUser(fallback);
          profileForm.reset(fallback);
        } else {
          const message = loadError instanceof Error ? loadError.message : 'Gagal memuat profil';
          setErrorMessage(message);
        }
      } finally {
        setLoadingProfile(false);
      }
    };

    void loadProfile();
  }, []);

  const loadOrganizations = async () => {
    try {
      const [orgs, currentOrg] = await Promise.all([
        getOrganizations(),
        getCurrentOrganization(),
      ]);

      setOrganizations((orgs || []).map((org) => ({ id: org.id, name: org.name, role: org.role })));
      setCurrentOrganizationId(currentOrg?.id ?? null);
    } catch {
    }
  };

  useEffect(() => {
    void loadOrganizations();
  }, []);

  // Forms
  const profileForm = useForm<ProfileForm>({
    resolver: zodResolver(profileSchema),
    defaultValues: user
  });

  const passwordForm = useForm<PasswordForm>({
    resolver: zodResolver(passwordSchema)
  });

  const twoFactorForm = useForm<TwoFactorForm>({
    resolver: zodResolver(twoFactorSchema)
  });

  // Handlers
  const onProfileUpdate = async (data: ProfileForm) => {
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      const updated = await updateProfile({ name: data.fullName, email: data.email });
      const nextUser = {
        username: updated.email?.split('@')[0] || 'member',
        email: updated.email || '',
        fullName: updated.name || '',
        avatar: user.avatar,
      };
      setUser(nextUser);
      profileForm.reset(nextUser);
      const token = getToken();
      if (token) {
        setSession(token, updated);
      }
      setSuccessMessage('Profil berhasil diperbarui.');
    } catch (err) {
      setErrorMessage(err instanceof Error ? err.message : 'Gagal memperbarui profil');
    }
  };

  const onPasswordUpdate = async (data: PasswordForm) => {
    if (!data.currentPassword || !data.newPassword) return;
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      await changePassword({
        current_password: data.currentPassword,
        password: data.newPassword,
        password_confirmation: data.confirmPassword,
      });
      passwordForm.reset();
      setSuccessMessage('Password berhasil diubah.');
    } catch (err) {
      setErrorMessage(err instanceof Error ? err.message : 'Gagal mengubah password');
    }
  };

  const onEnable2FA = (data: TwoFactorForm) => {
    if (!data.code) {
      return;
    }
    setIs2FAEnabled(true);
    setShow2FASetup(false);
    setSuccessMessage('2FA mode demo aktif pada UI.');
  };

  const handleLogout = async () => {
    setErrorMessage(null);
    try {
      await logout();
    } catch {
    } finally {
      clearSession();
      navigate('/login', { replace: true });
    }
  };

  const handleAvatarUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = (event) => {
        setUser({ ...user, avatar: event.target?.result as string });
      };
      reader.readAsDataURL(file);
    }
  };

  return (
    <div className="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <div className="mb-8">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-zinc-900">Pengaturan Akun</h1>
            <p className="text-zinc-500">Kelola profil, keamanan, dan preferensi akun Anda.</p>
          </div>
          <button
            type="button"
            onClick={() => void handleLogout()}
            className="px-4 py-2 border border-zinc-300 rounded-lg text-sm font-medium text-zinc-700 hover:bg-zinc-50 flex items-center gap-2"
          >
            <LogOut className="w-4 h-4" /> Logout
          </button>
        </div>
      </div>

      {loadingProfile && (
        <div className="mb-4 p-3 rounded-lg bg-zinc-50 border border-zinc-200 text-sm text-zinc-600">Memuat profil...</div>
      )}
      {errorMessage && (
        <div className="mb-4 p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600 flex items-center gap-2">
          <AlertCircle className="w-4 h-4" /> {errorMessage}
        </div>
      )}
      {successMessage && (
        <div className="mb-4 p-3 rounded-lg bg-green-50 border border-green-100 text-sm text-green-700 flex items-center gap-2">
          <Check className="w-4 h-4" /> {successMessage}
        </div>
      )}

      <div className="bg-white rounded-2xl shadow-sm border border-zinc-200 overflow-hidden">
        <div className="flex border-b border-zinc-200">
          <button
            onClick={() => setActiveTab('profile')}
            className={`flex-1 py-4 text-sm font-medium text-center border-b-2 transition-colors ${
              activeTab === 'profile' 
                ? 'border-yellow-400 text-zinc-900 bg-yellow-50/50' 
                : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50'
            }`}
          >
            <div className="flex items-center justify-center gap-2">
              <User className="w-4 h-4" /> Profil Saya
            </div>
          </button>
          <button
            onClick={() => setActiveTab('security')}
            className={`flex-1 py-4 text-sm font-medium text-center border-b-2 transition-colors ${
              activeTab === 'security' 
                ? 'border-yellow-400 text-zinc-900 bg-yellow-50/50' 
                : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:bg-zinc-50'
            }`}
          >
            <div className="flex items-center justify-center gap-2">
              <Shield className="w-4 h-4" /> Keamanan & Password
            </div>
          </button>
        </div>

        <div className="p-6 md:p-8">
          {/* --- PROFILE TAB --- */}
          {activeTab === 'profile' && (
            <div className="space-y-8">
              {/* Avatar Section */}
              <div className="flex items-center gap-6">
                <div className="relative group">
                  <img 
                    src={user.avatar} 
                    alt={user.fullName} 
                    className="w-24 h-24 rounded-full object-cover border-4 border-zinc-100 shadow-sm"
                  />
                  <label className="absolute bottom-0 right-0 p-2 bg-white rounded-full shadow-md border border-zinc-200 cursor-pointer hover:bg-zinc-50 transition-colors">
                    <Upload className="w-4 h-4 text-zinc-600" />
                    <input type="file" className="hidden" accept="image/*" onChange={handleAvatarUpload} />
                  </label>
                </div>
                <div>
                  <h3 className="text-lg font-bold text-zinc-900">Foto Profil</h3>
                  <p className="text-sm text-zinc-500 mb-2">Format: JPG, PNG. Maks 2MB.</p>
                </div>
              </div>

              <form onSubmit={profileForm.handleSubmit(onProfileUpdate)} className="space-y-6 max-w-lg">
                <div className="grid grid-cols-1 gap-6">
                  <div>
                    <label className="block text-sm font-medium text-zinc-700 mb-1">Username</label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span className="text-zinc-400 text-sm">@</span>
                      </div>
                      <input
                        {...profileForm.register('username')}
                        type="text"
                        className="block w-full pl-8 pr-3 py-2.5 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 sm:text-sm"
                      />
                    </div>
                    {profileForm.formState.errors.username && (
                      <p className="mt-1 text-sm text-red-600">{profileForm.formState.errors.username.message}</p>
                    )}
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-zinc-700 mb-1">Nama Lengkap</label>
                    <input
                      {...profileForm.register('fullName')}
                      type="text"
                      className="block w-full px-3 py-2.5 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 sm:text-sm"
                    />
                    {profileForm.formState.errors.fullName && (
                      <p className="mt-1 text-sm text-red-600">{profileForm.formState.errors.fullName.message}</p>
                    )}
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-zinc-700 mb-1">Email Address</label>
                    <input
                      {...profileForm.register('email')}
                      type="email"
                      className="block w-full px-3 py-2.5 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 sm:text-sm bg-zinc-50 text-zinc-500 cursor-not-allowed"
                      readOnly
                    />
                    <p className="mt-1 text-xs text-zinc-500">Email tidak dapat diubah.</p>
                  </div>
                </div>

                <div className="pt-4">
                  <button
                    type="submit"
                    disabled={profileForm.formState.isSubmitting}
                    className="px-6 py-2.5 bg-zinc-900 text-white font-bold rounded-lg hover:bg-zinc-800 transition-colors shadow-sm disabled:opacity-70"
                  >
                    {profileForm.formState.isSubmitting ? 'Menyimpan...' : 'Simpan Perubahan'}
                  </button>
                </div>
              </form>

              <div className="max-w-2xl pt-2 space-y-5">
                <div className="p-5 bg-zinc-50 rounded-xl border border-zinc-200">
                  <h3 className="text-lg font-bold text-zinc-900 mb-3 flex items-center gap-2">
                    <Building2 className="w-5 h-5 text-zinc-500" /> Organization Access
                  </h3>
                  <p className="text-sm text-zinc-500 mb-4">Akun hanya boleh terhubung ke satu organisasi aktif untuk mencegah akses silang data.</p>

                  {organizations.length > 0 ? (
                    <div>
                      <label className="block text-sm font-medium text-zinc-700 mb-1">Organisasi Terhubung</label>
                      <div className="w-full px-3 py-2.5 border border-zinc-200 rounded-lg bg-white text-sm text-zinc-800">
                        {organizations.find((org) => org.id === currentOrganizationId)?.name ?? organizations[0]?.name}
                        {' '}
                        ({organizations.find((org) => org.id === currentOrganizationId)?.role ?? organizations[0]?.role})
                      </div>
                    </div>
                  ) : (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800">
                      Akun ini belum terhubung ke organisasi mana pun.
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* --- SECURITY TAB --- */}
          {activeTab === 'security' && (
            <div className="space-y-10">
              
              {/* Change Password */}
              <div className="max-w-lg">
                <h3 className="text-lg font-bold text-zinc-900 mb-4 flex items-center gap-2">
                  <KeyRound className="w-5 h-5 text-zinc-500" /> Ubah Password
                </h3>
                <form onSubmit={passwordForm.handleSubmit(onPasswordUpdate)} className="space-y-4 p-5 bg-zinc-50 rounded-xl border border-zinc-200">
                  <div>
                    <label className="block text-sm font-medium text-zinc-700 mb-1">Password Saat Ini</label>
                    <input
                      {...passwordForm.register('currentPassword')}
                      type="password"
                      className="block w-full px-3 py-2.5 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 sm:text-sm"
                    />
                    {passwordForm.formState.errors.currentPassword && (
                      <p className="mt-1 text-sm text-red-600">{passwordForm.formState.errors.currentPassword.message}</p>
                    )}
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-zinc-700 mb-1">Password Baru</label>
                    <input
                      {...passwordForm.register('newPassword')}
                      type="password"
                      className="block w-full px-3 py-2.5 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 sm:text-sm"
                    />
                    {passwordForm.formState.errors.newPassword && (
                      <p className="mt-1 text-sm text-red-600">{passwordForm.formState.errors.newPassword.message}</p>
                    )}
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-zinc-700 mb-1">Konfirmasi Password Baru</label>
                    <input
                      {...passwordForm.register('confirmPassword')}
                      type="password"
                      className="block w-full px-3 py-2.5 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 sm:text-sm"
                    />
                    {passwordForm.formState.errors.confirmPassword && (
                      <p className="mt-1 text-sm text-red-600">{passwordForm.formState.errors.confirmPassword.message}</p>
                    )}
                  </div>
                  <div className="pt-2">
                    <button
                      type="submit"
                      className="px-4 py-2 bg-white border border-zinc-300 text-zinc-900 font-medium rounded-lg hover:bg-zinc-50 transition-colors shadow-sm"
                    >
                      Update Password
                    </button>
                  </div>
                </form>
              </div>

              <div className="h-px bg-zinc-200 w-full" />

              {/* Two-Factor Authentication */}
              <div className="max-w-lg">
                <div className="flex items-start justify-between mb-4">
                  <div>
                    <h3 className="text-lg font-bold text-zinc-900 flex items-center gap-2">
                      <Smartphone className="w-5 h-5 text-zinc-500" /> Verifikasi 2 Langkah (2FA)
                    </h3>
                    <p className="text-sm text-zinc-500 mt-1">
                      Amankan akun Anda dengan Google Authenticator atau aplikasi serupa.
                    </p>
                  </div>
                  {is2FAEnabled ? (
                    <span className="px-3 py-1 bg-green-100 text-green-700 text-xs font-bold rounded-full flex items-center gap-1">
                      <Check className="w-3 h-3" /> Aktif
                    </span>
                  ) : (
                    <span className="px-3 py-1 bg-zinc-100 text-zinc-500 text-xs font-bold rounded-full">
                      Nonaktif
                    </span>
                  )}
                </div>

                {!is2FAEnabled && !show2FASetup && (
                  <button
                    onClick={() => setShow2FASetup(true)}
                    className="px-4 py-2 bg-zinc-900 text-white font-medium rounded-lg hover:bg-zinc-800 transition-colors shadow-sm"
                  >
                    Aktifkan 2FA
                  </button>
                )}

                {show2FASetup && !is2FAEnabled && (
                  <div className="p-5 bg-blue-50 border border-blue-100 rounded-xl space-y-6 animate-in fade-in slide-in-from-top-4">
                    <div className="text-center">
                      <div className="bg-white p-4 inline-block rounded-xl border border-zinc-200 shadow-sm mb-4">
                        {/* Placeholder QR Code */}
                        <div className="w-40 h-40 bg-zinc-900 flex items-center justify-center text-white text-xs">
                          [QR CODE HERE]
                        </div>
                      </div>
                      <p className="text-sm text-blue-800 font-medium">Scan QR Code ini dengan aplikasi Authenticator Anda.</p>
                      <p className="text-xs text-blue-600 mt-1">Atau masukkan kode manual: <span className="font-mono font-bold bg-blue-100 px-1 rounded">J7X9-K2M4-P9L1</span></p>
                    </div>

                    <form onSubmit={twoFactorForm.handleSubmit(onEnable2FA)} className="space-y-4">
                      <div>
                        <label className="block text-sm font-medium text-zinc-700 mb-1">Masukkan Kode 6 Digit</label>
                        <input
                          {...twoFactorForm.register('code')}
                          type="text"
                          maxLength={6}
                          className="block w-full text-center text-xl tracking-widest font-mono px-3 py-2.5 border border-zinc-300 rounded-lg focus:ring-blue-400 focus:border-blue-400"
                          placeholder="000000"
                        />
                        {twoFactorForm.formState.errors.code && (
                          <p className="mt-1 text-sm text-red-600">{twoFactorForm.formState.errors.code.message}</p>
                        )}
                      </div>
                      <div className="flex gap-3">
                        <button
                          type="button"
                          onClick={() => setShow2FASetup(false)}
                          className="flex-1 px-4 py-2 bg-white border border-zinc-300 text-zinc-700 font-medium rounded-lg hover:bg-zinc-50"
                        >
                          Batal
                        </button>
                        <button
                          type="submit"
                          className="flex-1 px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 shadow-sm"
                        >
                          Verifikasi & Aktifkan
                        </button>
                      </div>
                    </form>
                  </div>
                )}

                {is2FAEnabled && (
                  <div className="p-4 bg-green-50 border border-green-100 rounded-xl flex items-start gap-3">
                    <Check className="w-5 h-5 text-green-600 mt-0.5" />
                    <div>
                      <h4 className="text-sm font-bold text-green-800">Akun Anda Terlindungi</h4>
                      <p className="text-xs text-green-700 mt-1">
                        Verifikasi 2 langkah aktif. Anda akan diminta memasukkan kode OTP setiap kali login dari perangkat baru.
                      </p>
                      <button 
                        onClick={() => {
                          if(confirm('Apakah Anda yakin ingin menonaktifkan 2FA? Akun Anda akan menjadi kurang aman.')) {
                            setIs2FAEnabled(false);
                            setSuccessMessage('2FA mode demo dinonaktifkan.');
                          }
                        }}
                        className="mt-3 text-xs font-bold text-red-600 hover:text-red-700 underline"
                      >
                        Nonaktifkan 2FA
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
