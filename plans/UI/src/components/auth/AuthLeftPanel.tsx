import type { BrandSettings } from '@/hooks/useBrand';
import { LayoutTemplate, CreditCard, Package, MonitorSmartphone, Sparkles, ShieldCheck, Users } from 'lucide-react';
import { PromoBanner } from './PromoBanner';
import { usePromoBanners } from './usePromoBanners';

type AuthLeftPanelProps = {
  brand: BrandSettings;
  logoSrc?: string | null;
  variant: 'login' | 'register';
};

const highlightItems = [
  {
    title: 'Landing Page Gratis',
    description: 'Dapat landing page siap pakai',
    icon: LayoutTemplate,
  },
  {
    title: 'POS Kasir & Manajemen',
    description: 'Kelola transaksi dan bisnis',
    icon: CreditCard,
  },
  {
    title: 'Produk Digital',
    description: 'Aplikasi, tools, extension Chrome',
    icon: Package,
  },
  {
    title: 'Custom Website',
    description: 'Pembuatan sesuai kebutuhan',
    icon: MonitorSmartphone,
  },
];

const trustItems = [
  { label: '12.000+ pengguna aktif', value: '12k+' },
  { label: 'Support UMKM Indonesia', value: '24/7' },
  { label: 'Data aman & terenkripsi', value: 'Secure' },
];

const ecosystemItems = [
  { label: 'Landing Builder', icon: Sparkles },
  { label: 'POS Kasir', icon: CreditCard },
  { label: 'Produk Digital', icon: Package },
  { label: 'Member & Loyalty', icon: Users },
];

export const AuthLeftPanel = ({ brand, logoSrc, variant }: AuthLeftPanelProps) => {
  const { items } = usePromoBanners('header');

  return (
    <div className="space-y-6">
      <div className="hidden lg:block">
        <PromoBanner items={items} variant={variant} />
      </div>

      <div className="flex items-center gap-3">
        {logoSrc ? (
          <img src={logoSrc} alt={brand.app_name} className="h-10 w-auto" />
        ) : (
          <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#FACC15] text-sm font-bold text-[#111111]">
            {brand.app_name.slice(0, 2).toUpperCase()}
          </div>
        )}
        <div>
          <p className="text-xs uppercase tracking-[0.3em] text-[#F6B400]">Hellom Ecosystem</p>
          <p className="text-sm font-semibold text-white">Creator-led digital system</p>
        </div>
      </div>

      <div className="space-y-4">
        <p className="max-w-xl text-sm text-white/70 sm:text-base">
          Masuk atau daftar untuk mengakses dashboard, POS payment, produk digital, dan sistem bisnis yang dibangun dalam ekosistem Hellom.
        </p>
      </div>

      <div className="flex flex-wrap gap-2 lg:hidden">
        {highlightItems.map((item) => (
          <span key={item.title} className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white/80">
            {item.title}
          </span>
        ))}
      </div>

      <div className="hidden lg:grid grid-cols-2 gap-4">
        {highlightItems.map((item) => (
          <div key={item.title} className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-4">
            <div className="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-[#F6B400]/10 text-[#F6B400]">
              <item.icon className="h-5 w-5" />
            </div>
            <p className="text-sm font-semibold text-white">{item.title}</p>
            <p className="mt-1 text-xs text-white/60">{item.description}</p>
          </div>
        ))}
      </div>

      <div className="hidden lg:grid grid-cols-3 gap-3">
        {trustItems.map((item) => (
          <div key={item.label} className="rounded-2xl border border-white/[0.08] bg-white/[0.03] px-4 py-3">
            <p className="text-sm font-semibold text-white">{item.value}</p>
            <p className="text-[11px] text-white/60">{item.label}</p>
          </div>
        ))}
      </div>

      <div className="hidden lg:flex items-start gap-3 rounded-2xl border border-white/[0.08] bg-white/[0.03] p-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 text-[#F6B400]">
          <ShieldCheck className="h-5 w-5" />
        </div>
        <div>
          <p className="text-sm font-semibold text-white">"Hellom bantu saya merapikan penjualan digital dalam satu dashboard."</p>
          <p className="mt-1 text-xs text-white/60">Budi Santoso · Pemilik UMKM Kopi Senja</p>
        </div>
      </div>

      <div className="hidden lg:block">
        <p className="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-white/50">Preview Ecosystem</p>
        <div className="grid grid-cols-2 gap-3">
          {ecosystemItems.map((item) => (
            <div key={item.label} className="flex items-center gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-sm text-white/80">
              <item.icon className="h-4 w-4 text-[#F6B400]" />
              {item.label}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};
