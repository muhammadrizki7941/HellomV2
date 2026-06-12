import { useEffect, useMemo, useState } from 'react';
import { Loader2, Save, Upload } from 'lucide-react';
import { getToken, HELLOM_API_BASE } from '@/lib/hellomApi';
import { DEFAULT_BRAND, resetBrandCache, type BrandSettings } from '@/hooks/useBrand';
import { getAdminMailSettings, sendAdminMailTest, updateAdminMailSettings } from '@/lib/hellomApi';

type Notice = { type: 'success' | 'error'; text: string } | null;
type BannerItem = {
  id: number;
  title: string;
  subtitle: string | null;
  cta_text?: string | null;
  badge?: string | null;
  background_from?: string | null;
  background_to?: string | null;
  image: string | null;
  image_url: string | null;
  media_type?: 'image' | 'video' | null;
  video_url?: string | null;
  link: string | null;
  is_active: boolean;
  position: 'header' | 'hero' | 'sidebar';
  order: number;
  starts_at: string | null;
  ends_at: string | null;
};

function normalizeBrand(input?: Partial<BrandSettings> | null): BrandSettings {
  return {
    ...DEFAULT_BRAND,
    ...(input ?? {}),
    app_name: input?.app_name || input?.business_name || DEFAULT_BRAND.app_name,
    business_name: input?.business_name || input?.app_name || DEFAULT_BRAND.business_name,
  };
}

const textInputClassName =
  'w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-slate-400 focus:outline-none';

export default function BrandSettingsPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<Notice>(null);
  const [form, setForm] = useState<BrandSettings>(DEFAULT_BRAND);
  const [logoFile, setLogoFile] = useState<File | null>(null);
  const [faviconFile, setFaviconFile] = useState<File | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [faviconPreview, setFaviconPreview] = useState<string | null>(null);
  const [logoLoadError, setLogoLoadError] = useState(false);
  const [faviconLoadError, setFaviconLoadError] = useState(false);
  const [banners, setBanners] = useState<BannerItem[]>([]);
  const [bannerSaving, setBannerSaving] = useState(false);
  const [bannerImageFile, setBannerImageFile] = useState<File | null>(null);
  const [bannerImagePreview, setBannerImagePreview] = useState<string | null>(null);
  const [editingBannerId, setEditingBannerId] = useState<number | null>(null);
  const [bannerForm, setBannerForm] = useState({
    title: '',
    subtitle: '',
    cta_text: '',
    badge: '',
    link: '',
    position: 'header' as 'header' | 'hero' | 'sidebar',
    media_type: 'image' as 'image' | 'video',
    video_url: '',
    background_from: '#111111',
    background_to: '#0B0B0C',
    order: 0,
    is_active: true,
    starts_at: '',
    ends_at: '',
    remove_image: false,
  });
  const [mailForm, setMailForm] = useState({
    enabled: false,
    host: '',
    port: 587,
    username: '',
    password: '',
    password_masked: '',
    encryption: 'tls',
    from_address: '',
    from_name: '',
    reply_to_address: '',
    reply_to_name: '',
    is_ready: false,
    test_email: '',
  });

  useEffect(() => {
    document.title = 'Branding utama Hellom | Super Admin';
  }, []);

  useEffect(() => {
    let active = true;
    const token = getToken();

    if (!token) {
      setMessage({ type: 'error', text: 'Token login tidak ditemukan. Silakan login ulang.' });
      setLoading(false);
      return;
    }

    fetch(`${HELLOM_API_BASE}/admin/brand`, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })
      .then(async (response) => {
        const payload = await response.json();
        if (!response.ok || !payload?.success) {
          throw new Error(payload?.message || 'Gagal memuat branding');
        }
        return normalizeBrand(payload?.data?.brand);
      })
      .then((brand) => {
        if (!active) {
          return;
        }
        setForm(brand);
        setLogoPreview(brand.logo_base64 || brand.logo_url);
        setFaviconPreview(brand.favicon_url);
      })
      .catch((error) => {
        if (active) {
          setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Gagal memuat branding' });
        }
      })
      .finally(() => {
        if (active) {
          setLoading(false);
        }
      });

    getAdminMailSettings()
      .then((result) => {
        if (!active) return;
        setMailForm((current) => ({
          ...current,
          ...result.mail,
          password: '',
          password_masked: result.mail.password_masked || '',
          test_email: form.support_email || result.mail.from_address || '',
        }));
      })
      .catch(() => undefined);

    fetch(`${HELLOM_API_BASE}/admin/banners`, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })
      .then(async (response) => {
        const payload = await response.json();
        if (!response.ok || !payload?.success) {
          throw new Error(payload?.message || 'Gagal memuat banner');
        }
        return (payload?.data?.items || []) as BannerItem[];
      })
      .then((items) => {
        if (active) {
          setBanners(items);
        }
      })
      .catch(() => undefined);

    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (!logoFile) {
      return;
    }

    const objectUrl = URL.createObjectURL(logoFile);
    setLogoPreview(objectUrl);
    setLogoLoadError(false);

    return () => URL.revokeObjectURL(objectUrl);
  }, [logoFile]);

  useEffect(() => {
    if (!faviconFile) {
      return;
    }

    const objectUrl = URL.createObjectURL(faviconFile);
    setFaviconPreview(objectUrl);
    setFaviconLoadError(false);

    return () => URL.revokeObjectURL(objectUrl);
  }, [faviconFile]);

  useEffect(() => {
    if (!bannerImageFile) {
      return;
    }

    const objectUrl = URL.createObjectURL(bannerImageFile);
    setBannerImagePreview(objectUrl);

    return () => URL.revokeObjectURL(objectUrl);
  }, [bannerImageFile]);

  const previewBrand = useMemo(
    () => ({
      ...form,
      logo_url: logoPreview,
      favicon_url: faviconPreview,
    }),
    [faviconPreview, form, logoPreview],
  );

  const handleChange = (key: keyof BrandSettings, value: string) => {
    setForm((current) => ({
      ...current,
      [key]: value,
      ...(key === 'app_name' ? { business_name: value } : {}),
    }));
  };

  const handleSave = async () => {
    const token = getToken();
    if (!token) {
      setMessage({ type: 'error', text: 'Token login tidak ditemukan. Silakan login ulang.' });
      return;
    }

    setSaving(true);
    setMessage(null);

    try {
      const formData = new FormData();
      const payload: Record<string, string | null> = {
        app_name: form.app_name,
        tagline: form.tagline,
        primary_color: form.primary_color,
        secondary_color: form.secondary_color,
        accent_color: form.accent_color,
        background_color: form.background_color,
        login_title: form.login_title,
        login_subtitle: form.login_subtitle,
        register_title: form.register_title,
        register_subtitle: form.register_subtitle,
        footer_text: form.footer_text,
        support_email: form.support_email,
        support_phone: form.support_phone,
        social_instagram: form.social_instagram,
        social_facebook: form.social_facebook,
        social_tiktok: form.social_tiktok,
        meta_title: form.meta_title,
        meta_description: form.meta_description,
      };

      Object.entries(payload).forEach(([key, value]) => {
        if (value !== null && value !== undefined) {
          formData.append(key, value);
        }
      });

      if (logoFile) {
        formData.append('logo', logoFile);
      }

      if (faviconFile) {
        formData.append('favicon', faviconFile);
      }

      const response = await fetch(`${HELLOM_API_BASE}/admin/brand`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: formData,
      });

      const payloadJson = await response.json();
      if (!response.ok || !payloadJson?.success) {
        throw new Error(payloadJson?.message || 'Gagal menyimpan branding');
      }

      const nextBrand = normalizeBrand(payloadJson?.data?.brand);
      setForm(nextBrand);
      setLogoFile(null);
      setFaviconFile(null);
      setLogoPreview(nextBrand.logo_base64 || nextBrand.logo_url);
      setFaviconPreview(nextBrand.favicon_url);
      setLogoLoadError(false);
      setFaviconLoadError(false);
      resetBrandCache();
      setMessage({ type: 'success', text: 'Branding berhasil disimpan dan cache brand sudah direset.' });
    } catch (error) {
      setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Gagal menyimpan branding' });
    } finally {
      setSaving(false);
    }
  };

  const handleSaveMail = async () => {
    setSaving(true);
    setMessage(null);
    try {
      const result = await updateAdminMailSettings({
        enabled: mailForm.enabled,
        host: mailForm.host,
        port: Number(mailForm.port) || 587,
        username: mailForm.username,
        password: mailForm.password || undefined,
        encryption: mailForm.encryption,
        from_address: mailForm.from_address,
        from_name: mailForm.from_name,
        reply_to_address: mailForm.reply_to_address,
        reply_to_name: mailForm.reply_to_name,
      });
      setMailForm((current) => ({
        ...current,
        ...result.mail,
        password: '',
        password_masked: result.mail.password_masked || '',
      }));
      setMessage({ type: 'success', text: 'SMTP Hellom berhasil disimpan.' });
    } catch (error) {
      setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Gagal menyimpan SMTP' });
    } finally {
      setSaving(false);
    }
  };

  const resetBannerForm = () => {
    setEditingBannerId(null);
    setBannerImageFile(null);
    setBannerImagePreview(null);
    setBannerForm({
      title: '',
      subtitle: '',
      cta_text: '',
      badge: '',
      link: '',
      position: 'header',
      media_type: 'image',
      video_url: '',
      background_from: '#111111',
      background_to: '#0B0B0C',
      order: 0,
      is_active: true,
      starts_at: '',
      ends_at: '',
      remove_image: false,
    });
  };

  const startEditBanner = (banner: BannerItem) => {
    setEditingBannerId(banner.id);
    setBannerImageFile(null);
    setBannerImagePreview(banner.image_url);
    setBannerForm({
      title: banner.title,
      subtitle: banner.subtitle || '',
      cta_text: banner.cta_text || '',
      badge: banner.badge || '',
      link: banner.link || '',
      position: banner.position,
      media_type: banner.media_type === 'video' ? 'video' : 'image',
      video_url: banner.video_url || '',
      background_from: banner.background_from || '#111111',
      background_to: banner.background_to || '#0B0B0C',
      order: banner.order || 0,
      is_active: banner.is_active,
      starts_at: banner.starts_at ? banner.starts_at.slice(0, 16) : '',
      ends_at: banner.ends_at ? banner.ends_at.slice(0, 16) : '',
      remove_image: false,
    });
  };

  const handleSaveBanner = async () => {
    const token = getToken();
    if (!token) {
      setMessage({ type: 'error', text: 'Token login tidak ditemukan. Silakan login ulang.' });
      return;
    }

    setBannerSaving(true);
    setMessage(null);

    try {
      const formData = new FormData();
      formData.append('title', bannerForm.title);
      formData.append('subtitle', bannerForm.subtitle);
      formData.append('cta_text', bannerForm.cta_text);
      formData.append('badge', bannerForm.badge);
      formData.append('link', bannerForm.link);
      formData.append('position', bannerForm.position);
      formData.append('media_type', bannerForm.media_type);
      formData.append('video_url', bannerForm.video_url);
      formData.append('background_from', bannerForm.background_from);
      formData.append('background_to', bannerForm.background_to);
      formData.append('order', String(bannerForm.order || 0));
      formData.append('is_active', bannerForm.is_active ? '1' : '0');
      if (bannerForm.starts_at) formData.append('starts_at', bannerForm.starts_at);
      if (bannerForm.ends_at) formData.append('ends_at', bannerForm.ends_at);
      if (bannerForm.remove_image) formData.append('remove_image', '1');
      if (bannerImageFile) formData.append('image', bannerImageFile);

      const targetUrl = editingBannerId
        ? `${HELLOM_API_BASE}/admin/banners/${editingBannerId}`
        : `${HELLOM_API_BASE}/admin/banners`;

      const response = await fetch(targetUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: formData,
      });

      const payload = await response.json();
      if (!response.ok || !payload?.success) {
        throw new Error(payload?.message || 'Gagal menyimpan banner');
      }

      const savedBanner = payload.data as BannerItem;
      setBanners((current) => {
        if (editingBannerId) {
          return current.map((item) => item.id === editingBannerId ? savedBanner : item);
        }
        return [savedBanner, ...current];
      });
      resetBannerForm();
      setMessage({ type: 'success', text: 'Banner publik berhasil disimpan.' });
    } catch (error) {
      setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Gagal menyimpan banner' });
    } finally {
      setBannerSaving(false);
    }
  };

  const handleDeleteBanner = async (bannerId: number) => {
    const token = getToken();
    if (!token) {
      setMessage({ type: 'error', text: 'Token login tidak ditemukan. Silakan login ulang.' });
      return;
    }

    if (!window.confirm('Hapus banner ini dari landing page publik?')) {
      return;
    }

    try {
      const response = await fetch(`${HELLOM_API_BASE}/admin/banners/${bannerId}`, {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      });
      const payload = await response.json();
      if (!response.ok || !payload?.success) {
        throw new Error(payload?.message || 'Gagal menghapus banner');
      }
      setBanners((current) => current.filter((item) => item.id !== bannerId));
      if (editingBannerId === bannerId) {
        resetBannerForm();
      }
      setMessage({ type: 'success', text: 'Banner publik berhasil dihapus.' });
    } catch (error) {
      setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Gagal menghapus banner' });
    }
  };

  const handleSendTestMail = async () => {
    if (!mailForm.test_email) {
      setMessage({ type: 'error', text: 'Isi email tujuan untuk tes SMTP.' });
      return;
    }

    setSaving(true);
    setMessage(null);
    try {
      const result = await sendAdminMailTest(mailForm.test_email);
      setMessage({
        type: result.delivery.sent ? 'success' : 'error',
        text: result.delivery.sent
          ? `Email tes berhasil dikirim ke ${mailForm.test_email}.`
          : `Email tes gagal: ${result.delivery.error || 'unknown error'}`,
      });
    } catch (error) {
      setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Gagal kirim email tes' });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex min-h-[320px] items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-slate-400" />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-6xl px-4 py-6 md:px-6">
      <div className="mb-6 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Branding Utama Hellom</h1>
          <p className="mt-1 text-sm text-slate-500">
            Semua perubahan di sini akan dipakai landing page publik, login, register, dan reset password.
          </p>
        </div>
        <button
          type="button"
          onClick={handleSave}
          disabled={saving}
          className="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white disabled:opacity-60"
        >
          {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
          {saving ? 'Menyimpan...' : 'Simpan Branding'}
        </button>
      </div>

      {message && (
        <div
          className={`mb-5 rounded-2xl border px-4 py-3 text-sm ${
            message.type === 'success'
              ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
              : 'border-rose-200 bg-rose-50 text-rose-700'
          }`}
        >
          {message.text}
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <div className="space-y-6">
          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-900">Identitas Aplikasi</h2>
            <div className="mt-5 grid gap-4 md:grid-cols-2">
              <div className="md:col-span-2">
                <label className="mb-2 block text-sm font-medium text-slate-700">Nama aplikasi</label>
                <input
                  value={form.app_name}
                  onChange={(event) => handleChange('app_name', event.target.value)}
                  className={textInputClassName}
                  placeholder="Contoh: Hellom"
                />
              </div>
              <div className="md:col-span-2">
                <label className="mb-2 block text-sm font-medium text-slate-700">Tagline</label>
                <input
                  value={form.tagline || ''}
                  onChange={(event) => handleChange('tagline', event.target.value)}
                  className={textInputClassName}
                  placeholder="Contoh: Solusi kasir modern untuk UMKM"
                />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Logo utama</label>
                <label className="flex min-h-[180px] cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center">
                  {logoPreview && !logoLoadError ? (
                    <img 
                      src={logoPreview} 
                      alt="Preview logo" 
                      className="mb-4 max-h-16 w-auto object-contain" 
                      onError={() => setLogoLoadError(true)}
                    />
                  ) : (
                    <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl text-xl font-black text-white" style={{ backgroundColor: previewBrand.primary_color }}>
                      {previewBrand.app_name.slice(0, 2).toUpperCase()}
                    </div>
                  )}
                  <span className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700 shadow-sm">
                    <Upload className="h-3.5 w-3.5" />
                    Upload logo baru
                  </span>
                  <span className="mt-2 text-xs text-slate-500">PNG, JPG, WEBP, SVG. Maksimal 2 MB.</span>
                  <input type="file" accept="image/*" className="hidden" onChange={(event) => setLogoFile(event.target.files?.[0] || null)} />
                </label>
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Favicon</label>
                <label className="flex min-h-[180px] cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center">
                  {faviconPreview && !faviconLoadError ? (
                    <img 
                      src={faviconPreview} 
                      alt="Preview favicon" 
                      className="mb-4 h-16 w-16 rounded-2xl object-contain" 
                      onError={() => setFaviconLoadError(true)}
                    />
                  ) : (
                    <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-500">
                      ICO
                    </div>
                  )}
                  <span className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700 shadow-sm">
                    <Upload className="h-3.5 w-3.5" />
                    Upload favicon
                  </span>
                  <span className="mt-2 text-xs text-slate-500">PNG, JPG, ICO, WEBP, SVG. Maksimal 512 KB.</span>
                  <input type="file" accept=".png,.jpg,.jpeg,.ico,.webp,.svg,image/*" className="hidden" onChange={(event) => setFaviconFile(event.target.files?.[0] || null)} />
                </label>
              </div>
            </div>
          </section>

          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-900">Warna Brand</h2>
            <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
              {[
                ['primary_color', 'Warna utama'],
                ['secondary_color', 'Warna sekunder'],
                ['accent_color', 'Warna aksen'],
                ['background_color', 'Warna background'],
              ].map(([key, label]) => (
                <div key={key}>
                  <label className="mb-2 block text-sm font-medium text-slate-700">{label}</label>
                  <div className="flex items-center gap-3 rounded-2xl border border-slate-200 px-3 py-3">
                    <input
                      type="color"
                      value={form[key as keyof BrandSettings] as string}
                      onChange={(event) => handleChange(key as keyof BrandSettings, event.target.value)}
                      className="h-11 w-11 cursor-pointer rounded-xl border-0 bg-transparent"
                    />
                    <input
                      value={form[key as keyof BrandSettings] as string}
                      onChange={(event) => handleChange(key as keyof BrandSettings, event.target.value)}
                      className="min-w-0 flex-1 bg-transparent text-sm text-slate-700 outline-none"
                    />
                  </div>
                </div>
              ))}
            </div>
          </section>

          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-900">Teks Halaman Login & Register</h2>
            <div className="mt-5 grid gap-4 md:grid-cols-2">
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Judul login</label>
                <input value={form.login_title} onChange={(event) => handleChange('login_title', event.target.value)} className={textInputClassName} />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Judul register</label>
                <input value={form.register_title} onChange={(event) => handleChange('register_title', event.target.value)} className={textInputClassName} />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Subjudul login</label>
                <textarea value={form.login_subtitle} onChange={(event) => handleChange('login_subtitle', event.target.value)} className={`${textInputClassName} min-h-[110px]`} />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">Subjudul register</label>
                <textarea value={form.register_subtitle} onChange={(event) => handleChange('register_subtitle', event.target.value)} className={`${textInputClassName} min-h-[110px]`} />
              </div>
            </div>
          </section>

          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-900">Kontak & Sosial Media</h2>
            <div className="mt-5 grid gap-4 md:grid-cols-2">
              <input value={form.support_email || ''} onChange={(event) => handleChange('support_email', event.target.value)} className={textInputClassName} placeholder="Email support" />
              <input value={form.support_phone || ''} onChange={(event) => handleChange('support_phone', event.target.value)} className={textInputClassName} placeholder="Nomor support" />
              <input value={form.social_instagram || ''} onChange={(event) => handleChange('social_instagram', event.target.value)} className={textInputClassName} placeholder="URL Instagram" />
              <input value={form.social_facebook || ''} onChange={(event) => handleChange('social_facebook', event.target.value)} className={textInputClassName} placeholder="URL Facebook" />
              <div className="md:col-span-2">
                <input value={form.social_tiktok || ''} onChange={(event) => handleChange('social_tiktok', event.target.value)} className={textInputClassName} placeholder="URL TikTok" />
              </div>
            </div>
          </section>

          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-900">SEO & Footer</h2>
            <div className="mt-5 grid gap-4">
              <input value={form.meta_title} onChange={(event) => handleChange('meta_title', event.target.value)} className={textInputClassName} placeholder="Meta title" />
              <textarea value={form.meta_description || ''} onChange={(event) => handleChange('meta_description', event.target.value)} className={`${textInputClassName} min-h-[120px]`} placeholder="Meta description" />
              <input value={form.footer_text} onChange={(event) => handleChange('footer_text', event.target.value)} className={textInputClassName} placeholder="Footer text" />
            </div>
          </section>

          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
              <div>
                <h2 className="text-lg font-semibold text-slate-900">Banner Landing Publik</h2>
                <p className="text-sm text-slate-500">Banner ini tampil di header atau area hero landing publik (React).</p>
              </div>
              <button type="button" onClick={resetBannerForm} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">
                Banner Baru
              </button>
            </div>

            <div className="mt-5 grid gap-4 md:grid-cols-2">
              <input value={bannerForm.title} onChange={(event) => setBannerForm((current) => ({ ...current, title: event.target.value }))} className={textInputClassName} placeholder="Judul banner" />
              <input value={bannerForm.subtitle} onChange={(event) => setBannerForm((current) => ({ ...current, subtitle: event.target.value }))} className={textInputClassName} placeholder="Subjudul banner" />
              <input value={bannerForm.cta_text} onChange={(event) => setBannerForm((current) => ({ ...current, cta_text: event.target.value }))} className={textInputClassName} placeholder="Teks tombol CTA (opsional)" />
              <input value={bannerForm.badge} onChange={(event) => setBannerForm((current) => ({ ...current, badge: event.target.value }))} className={textInputClassName} placeholder="Badge (contoh: Promo Terbatas)" />
              <input value={bannerForm.link} onChange={(event) => setBannerForm((current) => ({ ...current, link: event.target.value }))} className={textInputClassName} placeholder="Link tujuan (opsional)" />
              <select value={bannerForm.position} onChange={(event) => setBannerForm((current) => ({ ...current, position: event.target.value as 'header' | 'hero' | 'sidebar' }))} className={textInputClassName}>
                <option value="header">Header</option>
                <option value="hero">Hero</option>
                <option value="sidebar">Sidebar</option>
              </select>
              <select value={bannerForm.media_type} onChange={(event) => setBannerForm((current) => ({ ...current, media_type: event.target.value as 'image' | 'video' }))} className={textInputClassName}>
                <option value="image">Media: Gambar</option>
                <option value="video">Media: Video</option>
              </select>
              <input
                value={bannerForm.video_url}
                onChange={(event) => setBannerForm((current) => ({ ...current, video_url: event.target.value }))}
                className={textInputClassName}
                placeholder="URL video (YouTube atau mp4)"
              />
              <div className="flex items-center gap-3 rounded-2xl border border-slate-200 px-3 py-2">
                <input
                  type="color"
                  value={bannerForm.background_from}
                  onChange={(event) => setBannerForm((current) => ({ ...current, background_from: event.target.value }))}
                  className="h-10 w-10 cursor-pointer rounded-xl border-0 bg-transparent"
                />
                <input
                  type="color"
                  value={bannerForm.background_to}
                  onChange={(event) => setBannerForm((current) => ({ ...current, background_to: event.target.value }))}
                  className="h-10 w-10 cursor-pointer rounded-xl border-0 bg-transparent"
                />
                <div className="text-xs text-slate-500">Gradient banner</div>
              </div>
              <input type="number" min={0} value={bannerForm.order} onChange={(event) => setBannerForm((current) => ({ ...current, order: Number(event.target.value) || 0 }))} className={textInputClassName} placeholder="Urutan tampil" />
              <label className="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm text-slate-700">
                <input type="checkbox" checked={bannerForm.is_active} onChange={(event) => setBannerForm((current) => ({ ...current, is_active: event.target.checked }))} />
                Banner aktif
              </label>
              <input type="datetime-local" value={bannerForm.starts_at} onChange={(event) => setBannerForm((current) => ({ ...current, starts_at: event.target.value }))} className={textInputClassName} />
              <input type="datetime-local" value={bannerForm.ends_at} onChange={(event) => setBannerForm((current) => ({ ...current, ends_at: event.target.value }))} className={textInputClassName} />
            </div>

            <div className="mt-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4">
              <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                  <p className="text-sm font-semibold text-slate-900">Gambar banner</p>
                  <p className="text-xs text-slate-500">PNG, JPG, WEBP maksimal 4 MB.</p>
                </div>
                <input type="file" accept="image/*" onChange={(event) => setBannerImageFile(event.target.files?.[0] || null)} className="text-sm text-slate-600" />
              </div>
              {bannerImagePreview && (
                <img src={bannerImagePreview} alt="Banner preview" className="mt-4 h-40 w-full rounded-2xl object-cover" />
              )}
              {editingBannerId && bannerImagePreview && (
                <label className="mt-3 inline-flex items-center gap-2 text-sm text-slate-600">
                  <input type="checkbox" checked={bannerForm.remove_image} onChange={(event) => setBannerForm((current) => ({ ...current, remove_image: event.target.checked }))} />
                  Hapus gambar lama saat simpan
                </label>
              )}
            </div>

            <div className="mt-5 flex flex-wrap gap-3">
              <button type="button" onClick={handleSaveBanner} disabled={bannerSaving || !bannerForm.title.trim()} className="rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white disabled:opacity-60">
                {bannerSaving ? 'Menyimpan banner...' : editingBannerId ? 'Update Banner' : 'Simpan Banner'}
              </button>
              {editingBannerId && (
                <button type="button" onClick={resetBannerForm} className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">
                  Batal Edit
                </button>
              )}
            </div>

            <div className="mt-6 space-y-4">
              {banners.length === 0 ? (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                  Belum ada banner publik. Tambahkan banner agar tampil di landing page Blade.
                </div>
              ) : (
                banners.map((banner) => (
                  <article key={banner.id} className="rounded-2xl border border-slate-200 p-4">
                    <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                      <div className="flex gap-4">
                        {banner.image_url ? (
                          <img src={banner.image_url} alt={banner.title} className="h-20 w-28 rounded-xl object-cover" />
                        ) : (
                          <div className="flex h-20 w-28 items-center justify-center rounded-xl bg-slate-100 text-xs font-semibold text-slate-500">No image</div>
                        )}
                        <div>
                          <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-semibold text-slate-900">{banner.title}</h3>
                            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${banner.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                              {banner.is_active ? 'Aktif' : 'Nonaktif'}
                            </span>
                            <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                              {banner.position}
                            </span>
                          </div>
                          <p className="mt-1 text-sm text-slate-600">{banner.subtitle || 'Tanpa subjudul'}</p>
                          <p className="mt-2 text-xs text-slate-500">
                            Order: {banner.order}
                            {banner.media_type ? ` | Media: ${banner.media_type}` : ''}
                            {banner.cta_text ? ` | CTA: ${banner.cta_text}` : ''}
                            {banner.badge ? ` | Badge: ${banner.badge}` : ''}
                            {banner.video_url ? ` | Video: ${banner.video_url}` : ''}
                            {banner.link ? ` | Link: ${banner.link}` : ''}
                          </p>
                        </div>
                      </div>
                      <div className="flex gap-2">
                        <button type="button" onClick={() => startEditBanner(banner)} className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">
                          Edit
                        </button>
                        <button type="button" onClick={() => void handleDeleteBanner(banner.id)} className="rounded-xl border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-600">
                          Hapus
                        </button>
                      </div>
                    </div>
                  </article>
                ))
              )}
            </div>
          </section>

          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
              <div>
                <h2 className="text-lg font-semibold text-slate-900">SMTP & Email Hellom</h2>
                <p className="text-sm text-slate-500">Dipakai untuk invitation, reset password, welcome mail, promo, dan billing notification.</p>
              </div>
              <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${mailForm.is_ready ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800'}`}>
                {mailForm.is_ready ? 'SMTP Ready' : 'Belum siap'}
              </span>
            </div>

            <div className="mt-5 grid gap-4 md:grid-cols-2">
              <label className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                <div className="flex items-center justify-between gap-4">
                  <div>
                    <p className="font-semibold text-slate-900">Aktifkan SMTP dinamis</p>
                    <p className="mt-1 text-xs text-slate-500">Jika mati, email fallback ke mailer default aplikasi.</p>
                  </div>
                  <input type="checkbox" checked={mailForm.enabled} onChange={(event) => setMailForm((current) => ({ ...current, enabled: event.target.checked }))} />
                </div>
              </label>
              <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                Password tersimpan aman.
                {mailForm.password_masked ? ` Password saat ini: ${mailForm.password_masked}` : ' Belum ada password SMTP tersimpan.'}
              </div>
              <input value={mailForm.host} onChange={(event) => setMailForm((current) => ({ ...current, host: event.target.value }))} className={textInputClassName} placeholder="SMTP host" />
              <input type="number" value={mailForm.port} onChange={(event) => setMailForm((current) => ({ ...current, port: Number(event.target.value) || 587 }))} className={textInputClassName} placeholder="Port" />
              <input value={mailForm.username} onChange={(event) => setMailForm((current) => ({ ...current, username: event.target.value }))} className={textInputClassName} placeholder="SMTP username" />
              <input type="password" value={mailForm.password} onChange={(event) => setMailForm((current) => ({ ...current, password: event.target.value }))} className={textInputClassName} placeholder="Kosongkan jika tidak ganti password" />
              <select value={mailForm.encryption} onChange={(event) => setMailForm((current) => ({ ...current, encryption: event.target.value }))} className={textInputClassName}>
                <option value="tls">TLS</option>
                <option value="ssl">SSL</option>
              </select>
              <div />
              <input value={mailForm.from_address} onChange={(event) => setMailForm((current) => ({ ...current, from_address: event.target.value }))} className={textInputClassName} placeholder="From email" />
              <input value={mailForm.from_name} onChange={(event) => setMailForm((current) => ({ ...current, from_name: event.target.value }))} className={textInputClassName} placeholder="From name" />
              <input value={mailForm.reply_to_address} onChange={(event) => setMailForm((current) => ({ ...current, reply_to_address: event.target.value }))} className={textInputClassName} placeholder="Reply-to email" />
              <input value={mailForm.reply_to_name} onChange={(event) => setMailForm((current) => ({ ...current, reply_to_name: event.target.value }))} className={textInputClassName} placeholder="Reply-to name" />
            </div>

            <div className="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <p className="text-sm font-semibold text-slate-900">Tes pengiriman</p>
              <div className="mt-3 flex flex-col gap-3 md:flex-row">
                <input
                  value={mailForm.test_email}
                  onChange={(event) => setMailForm((current) => ({ ...current, test_email: event.target.value }))}
                  className={textInputClassName}
                  placeholder="Email tujuan tes"
                />
                <button type="button" onClick={handleSendTestMail} disabled={saving} className="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-800">
                  Kirim Tes
                </button>
                <button type="button" onClick={handleSaveMail} disabled={saving} className="rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white">
                  Simpan SMTP
                </button>
              </div>
            </div>
          </section>
        </div>

        <aside className="space-y-6">
          <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-900">Preview Live</h2>
            <p className="mt-1 text-sm text-slate-500">Preview cepat untuk landing, auth, dan footer.</p>

            <div className="mt-5 overflow-hidden rounded-[28px] shadow-sm" style={{ backgroundColor: previewBrand.background_color }}>
              <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
                <div className="flex items-center gap-3 text-white">
                  {previewBrand.logo_url ? (
                    <img src={previewBrand.logo_url} alt={previewBrand.app_name} className="h-8 w-auto object-contain" />
                  ) : (
                    <div className="flex h-8 w-8 items-center justify-center rounded-xl text-xs font-black text-white" style={{ backgroundColor: previewBrand.primary_color }}>
                      {previewBrand.app_name.slice(0, 2).toUpperCase()}
                    </div>
                  )}
                  <div>
                    <p className="text-sm font-semibold">{previewBrand.app_name}</p>
                    <p className="text-xs text-white/70">{previewBrand.tagline}</p>
                  </div>
                </div>
                <span className="rounded-full px-3 py-1 text-xs font-semibold" style={{ backgroundColor: previewBrand.accent_color, color: previewBrand.background_color }}>
                  Landing
                </span>
              </div>

              <div className="space-y-4 px-5 py-6 text-white">
                <div className="rounded-3xl bg-white p-5 text-slate-900">
                  <p className="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Login</p>
                  <h3 className="mt-3 text-xl font-bold">{previewBrand.login_title}</h3>
                  <p className="mt-2 text-sm text-slate-500">{previewBrand.login_subtitle}</p>
                  <button
                    type="button"
                    className="mt-4 rounded-xl px-4 py-2 text-sm font-semibold text-white"
                    style={{ backgroundColor: previewBrand.primary_color }}
                  >
                    Masuk
                  </button>
                </div>

                <div className="rounded-3xl border border-white/10 px-5 py-5">
                  <p className="text-xs uppercase tracking-[0.24em] text-white/60">Register</p>
                  <h3 className="mt-3 text-lg font-bold">{previewBrand.register_title}</h3>
                  <p className="mt-2 text-sm text-white/72">{previewBrand.register_subtitle}</p>
                </div>
              </div>

              <div className="border-t border-white/10 px-5 py-4 text-xs text-white/70">
                {previewBrand.footer_text}
              </div>
            </div>

            <div className="mt-5 grid grid-cols-4 gap-3">
              {[
                ['primary_color', previewBrand.primary_color],
                ['secondary_color', previewBrand.secondary_color],
                ['accent_color', previewBrand.accent_color],
                ['background_color', previewBrand.background_color],
              ].map(([slot, color]) => (
                <div key={slot} className="rounded-2xl border border-slate-200 p-2">
                  <div className="h-10 rounded-xl" style={{ backgroundColor: color }} />
                  <p className="mt-2 truncate text-[11px] font-medium text-slate-500">{color}</p>
                </div>
              ))}
            </div>
          </section>
        </aside>
      </div>
    </div>
  );
}
