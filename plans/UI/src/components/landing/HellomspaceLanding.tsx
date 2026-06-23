import { motion } from 'framer-motion';
import {
  ArrowRight,
  BriefcaseBusiness,
  Cpu,
  ExternalLink,
  Grid2X2,
  Home,
  Layers3,
  Menu,
  Moon,
  PenTool,
  ShoppingCart,
  Sparkles,
  Sun,
  UserRound,
  X,
  Zap,
  Check,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import type { BrandSettings } from '@/hooks/useBrand';
import {
  getImageUrl,
  getPublicLandingContent,
  getPublicProducts,
  getPublicShowcaseClients,
  getPublicShowcasePortfolios,
  getSessionUser,
  getToken,
} from '@/lib/hellomApi';
import { savePendingCheckoutIntent } from '@/lib/checkoutIntent';
import { COMPANY_INFO, formatCompanyAddress } from '@/lib/companyInfo';

type Theme = 'light' | 'dark';

/** Theme palette exposed as CSS variables on the page root. Gold accent stays brand-consistent. */
const THEME_VARS: Record<Theme, Record<string, string>> = {
  light: {
    '--bg': '#ffffff',
    '--bg-soft': '#f7f6f3',
    '--text': '#16161a',
    '--muted': '#5f6066',
    '--surface': '#ffffff',
    '--surface-soft': '#f6f5f2',
    '--surface-strong': '#ecebe6',
    '--line': 'rgba(0,0,0,0.10)',
    '--line-soft': 'rgba(0,0,0,0.06)',
    '--navbar-bg': 'rgba(255,255,255,0.82)',
    '--gold': '#B7860B',
  },
  dark: {
    '--bg': '#050505',
    '--bg-soft': '#0a0a0a',
    '--text': '#F5F5F2',
    '--muted': '#8B8B90',
    '--surface': '#0E0E11',
    '--surface-soft': 'rgba(255,255,255,0.03)',
    '--surface-strong': 'rgba(255,255,255,0.10)',
    '--line': 'rgba(255,255,255,0.08)',
    '--line-soft': 'rgba(255,255,255,0.06)',
    '--navbar-bg': 'rgba(5,5,5,0.55)',
    '--gold': '#F6B400',
  },
};

const THEME_KEY = 'hellom_landing_theme';

type Product = {
  id: number;
  slug: string;
  name: string;
  tagline?: string | null;
  category?: string | null;
  type?: string | null;
  price?: number | string | null;
  thumbnail_url?: string | null;
};

type Portfolio = {
  id: number;
  title: string;
  slug?: string | null;
  category?: string | null;
  thumbnail_url?: string | null;
  description?: string | null;
  full_description?: string | null;
  video_url?: string | null;
  client_name?: string | null;
  project_year?: string | null;
  project_url?: string | null;
};

type Client = {
  id: number;
  name: string;
  logo_url?: string | null;
  website_url?: string | null;
};

type Service = {
  id: number;
  title: string;
  slug?: string | null;
  icon?: string | null;
  short_description?: string | null;
};

type Article = {
  id: number;
  title: string;
  slug?: string | null;
  thumbnail?: string | null;
  excerpt?: string | null;
  category?: string | null;
  published_at?: string | null;
  read_time?: number | null;
};

type AboutContent = {
  title?: string | null;
  subtitle?: string | null;
  description?: string | null;
  years_experience?: number | null;
  projects_completed?: number | null;
  happy_clients?: number | null;
  support_label?: string | null;
};

type LandingContent = {
  about?: AboutContent | null;
  products?: {
    label?: string | null;
    heading?: string | null;
    description?: string | null;
    cta_label?: string | null;
  } | null;
  services?: Service[];
  articles?: Article[];
};

const heroImagePath = '/assets/profile/hero.png';
const workspaceImagePath = '/assets/profile/workspace.png';

const fallbackClients: Client[] = [
  { id: 1, name: 'Maco Studio' },
  { id: 2, name: 'BRAND.ID' },
  { id: 3, name: 'Sadewa Coffee' },
  { id: 4, name: 'Trackon' },
  { id: 5, name: 'bukanstudio' },
  { id: 6, name: 'pixelgrain' },
];

const fallbackServices: Service[] = [
  { id: 1, title: 'Branding & Identity', icon: 'PenTool', short_description: 'Membangun identitas brand yang kuat, estetik, dan berkesan.' },
  { id: 2, title: 'Web & UI Design', icon: 'Layers3', short_description: 'Desain website dan antarmuka yang modern, responsif, dan user-friendly.' },
  { id: 3, title: 'Digital Product', icon: 'Cpu', short_description: 'Template, tools, dan produk digital siap pakai untuk bisnismu.' },
  { id: 4, title: 'Automation & System', icon: 'Zap', short_description: 'Sistem kerja otomatis untuk efisiensi dan skala bisnis.' },
  { id: 5, title: 'Consulting & Strategy', icon: 'BriefcaseBusiness', short_description: 'Strategi branding, digital, dan produk yang tepat untuk pertumbuhan.' },
];

const fallbackPortfolios: Portfolio[] = [
  { id: 1, title: 'Maco Studio', category: 'Branding & Web Design', description: 'Identitas studio kreatif dengan sistem visual premium.' },
  { id: 2, title: 'Sadewa Coffee & Eatery', category: 'Branding & Website', description: 'Website dan experience digital untuk hospitality brand.' },
  { id: 3, title: 'Trackon Dashboard', category: 'System', description: 'Dashboard operasional dengan interface yang tajam.' },
];

const fallbackArticles: Article[] = [
  { id: 1, title: 'Cara membangun brand yang autentik dan berkesan', published_at: '2026-05-20', read_time: 5 },
  { id: 2, title: 'Sistem kerja kreatif yang bikin lebih produktif', published_at: '2026-05-15', read_time: 6 },
  { id: 3, title: 'Kenapa desain yang baik bisa meningkatkan penjualan', published_at: '2026-05-10', read_time: 7 },
  { id: 4, title: 'Tools digital yang wajib dimiliki kreator modern', published_at: '2026-05-06', read_time: 4 },
];

const iconMap = {
  PenTool,
  Layers3,
  Cpu,
  Zap,
  BriefcaseBusiness,
  Sparkles,
};

const fadeUp = {
  hidden: { opacity: 0, y: 22 },
  visible: { opacity: 1, y: 0 },
};

const stagger = {
  hidden: {},
  visible: {
    transition: { staggerChildren: 0.08 },
  },
};

const formatPrice = (product: Product) => {
  const price = Number(product.price || 0);
  if (product.type === 'free' || price <= 0) return 'Gratis';
  return `Rp ${price.toLocaleString('id-ID')}`;
};

const formatDate = (date?: string | null) => {
  if (!date) return 'Mei 2026';
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};

const ImageFrame = ({ src, alt, className }: { src: string; alt: string; className: string }) => {
  const [failed, setFailed] = useState(false);

  if (failed) {
    return (
      <div className={`${className} flex items-center justify-center bg-[radial-gradient(circle_at_45%_20%,rgba(246,180,0,.24),transparent_34%),linear-gradient(135deg,#17130a,#050505_68%)]`}>
        <div className="text-center">
          <div className="mx-auto mb-4 h-20 w-20 rounded-full border border-[#F6B400]/25 bg-[#F6B400]/10" />
          <p className="font-display text-3xl text-[#F5F5F2]">Muhammad Rizki</p>
          <p className="mt-2 text-xs uppercase tracking-[0.35em] text-[#F6B400]">Founder of Hellom</p>
        </div>
      </div>
    );
  }

  return <img src={src} alt={alt} onError={() => setFailed(true)} className={className} />;
};

const SectionLabel = ({ children }: { children: string }) => (
  <p className="mb-5 text-[10px] font-bold uppercase tracking-[0.46em] text-[var(--gold)]">{children}</p>
);

export const HellomspaceLanding = ({ brand, logoSrc }: { brand: BrandSettings; logoSrc: string | null }) => {
  const [theme, setTheme] = useState<Theme>(() => {
    if (typeof window === 'undefined') return 'light';
    return window.localStorage.getItem(THEME_KEY) === 'dark' ? 'dark' : 'light';
  });
  const toggleTheme = () => {
    setTheme((current) => {
      const next: Theme = current === 'light' ? 'dark' : 'light';
      try { window.localStorage.setItem(THEME_KEY, next); } catch { /* ignore */ }
      return next;
    });
  };

  const [clients, setClients] = useState<Client[]>(fallbackClients);
  const [products, setProducts] = useState<Product[]>([]);
  const [portfolios, setPortfolios] = useState<Portfolio[]>(fallbackPortfolios);
  const [content, setContent] = useState<LandingContent>({});
  const [activeFilter, setActiveFilter] = useState('All');
  const [selectedPortfolio, setSelectedPortfolio] = useState<Portfolio | null>(null);
  const isAuthenticated = Boolean(getToken() && getSessionUser());
  const posAccessHref = isAuthenticated ? '/dashboard/apps/pos?subscribe=1' : '/login?app=pos&subscribe=1';
  const builderHref = isAuthenticated ? '/dashboard/apps/landing-builder' : '/login?app=landing_builder';
  const about = content.about || {};
  const productSection = content.products || {};
  const services = content.services?.length ? content.services : fallbackServices;
  const articles = content.articles?.length ? content.articles : fallbackArticles;
  const visibleProducts = products;
  const productSectionLabel = productSection.label || 'Products';
  const productSectionHeading = productSection.heading || 'Produk digital premium untuk hasil maksimal.';
  const productSectionDescription = productSection.description || 'Pilih produk digital yang bisa langsung dipakai atau dibeli. Template, ekstensi, dan produk lain tampil otomatis dari katalog.';
  const productSectionCta = productSection.cta_label || 'Lihat semua produk';
  const filters = ['All', 'Branding', 'Web Design', 'Digital Product', 'System'];
  const filteredPortfolios = activeFilter === 'All'
    ? portfolios
    : portfolios.filter((item) => `${item.category || ''}`.toLowerCase().includes(activeFilter.toLowerCase()));

  const featuredApps = [
    {
      badge: 'Aplikasi #1',
      icon: PenTool,
      title: 'Landing Page Builder',
      desc: 'Bangun landing page & halaman jualan online tanpa coding. Susun halaman dengan cara drag-and-drop, lalu langsung publish.',
      points: ['Editor drag-and-drop yang mudah', 'Banyak komponen: form, testimoni, FAQ, countdown', 'Langsung publish & bisa dibagikan'],
      cta: 'Buka Builder',
      href: builderHref,
    },
    {
      badge: 'Aplikasi #2',
      icon: ShoppingCart,
      title: 'Sistem POS Hellom',
      desc: 'Bantu UMKM kelola operasional dari satu tempat: catat & rekap order, pantau keuangan, kelola staf, sampai pesanan mandiri pelanggan.',
      points: ['Kelola & rekap order harian', 'Laporan keuangan otomatis', 'Absensi staf & fitur self-order pelanggan'],
      cta: 'Coba POS',
      href: posAccessHref,
    },
  ];

  useEffect(() => {
    const load = async () => {
      const [clientItems, productItems, portfolioItems, landingContent] = await Promise.allSettled([
        getPublicShowcaseClients(),
        getPublicProducts(),
        getPublicShowcasePortfolios(),
        getPublicLandingContent(),
      ]);

      if (clientItems.status === 'fulfilled' && clientItems.value.length) setClients(clientItems.value as Client[]);
      if (productItems.status === 'fulfilled') setProducts(productItems.value as Product[]);
      if (portfolioItems.status === 'fulfilled' && portfolioItems.value.length) setPortfolios(portfolioItems.value as Portfolio[]);
      if (landingContent.status === 'fulfilled') setContent(landingContent.value as LandingContent);
    };

    void load();
  }, []);

  useEffect(() => {
    document.title = 'Hellomspace | Muhammad Rizki';
  }, []);

  const stats = [
    { value: `${about.happy_clients || 100}+`, label: 'Happy Clients' },
    { value: `${about.years_experience || 5}+`, label: 'Years Experience' },
    { value: `${about.projects_completed || 100}+`, label: 'Projects Completed' },
    { value: about.support_label || '24/7', label: 'Support & Growth' },
  ];

  const heroScrim = theme === 'dark'
    ? 'radial-gradient(circle at 55% 18%,rgba(246,180,0,.26),transparent 36%),linear-gradient(90deg,rgba(5,5,5,.95),transparent 30%,rgba(5,5,5,.50))'
    : 'radial-gradient(circle at 55% 18%,rgba(246,180,0,.16),transparent 42%),linear-gradient(90deg,rgba(255,255,255,.92),transparent 34%,rgba(255,255,255,.30))';

  return (
    <div
      id="top"
      style={THEME_VARS[theme] as React.CSSProperties}
      className="min-h-screen overflow-x-hidden bg-[var(--bg)] text-[var(--text)] transition-colors duration-300"
    >
      {theme === 'dark' ? (
        <>
          <div className="pointer-events-none fixed inset-0 z-0 opacity-[0.035]" style={{ backgroundImage: 'url("data:image/svg+xml,%3Csvg viewBox=%270 0 220 220%27 xmlns=%27http://www.w3.org/2000/svg%27%3E%3Cfilter id=%27n%27%3E%3CfeTurbulence type=%27fractalNoise%27 baseFrequency=%270.7%27 numOctaves=%273%27 stitchTiles=%27stitch%27/%3E%3C/filter%3E%3Crect width=%27100%25%27 height=%27100%25%27 filter=%27url(%23n)%27/%3E%3C/svg%3E")' }} />
          <div className="pointer-events-none fixed inset-0 z-0 bg-[radial-gradient(circle_at_72%_6%,rgba(246,180,0,.20),transparent_30%),radial-gradient(circle_at_15%_0%,rgba(255,204,71,.08),transparent_24%),linear-gradient(180deg,transparent,rgba(0,0,0,.52))]" />
        </>
      ) : (
        <div className="pointer-events-none fixed inset-0 z-0 bg-[radial-gradient(circle_at_78%_2%,rgba(246,180,0,.10),transparent_28%)]" />
      )}

      <Navbar brand={brand} logoSrc={logoSrc} isAuthenticated={isAuthenticated} theme={theme} onToggleTheme={toggleTheme} />

      <main className="relative z-10 pb-32 md:pb-0">
        <section className="relative min-h-screen border-b border-[var(--line)] px-5 pt-24 md:px-10 lg:px-16">
          <div className="mx-auto grid max-w-[1500px] items-center gap-0 md:grid-cols-[0.83fr_1.17fr] md:gap-10">
            <motion.div initial="hidden" animate="visible" variants={stagger} className="relative z-10 order-2 -mt-80 max-w-2xl px-6 pb-10 md:order-none md:mt-0 md:px-0 md:pb-0">
              <motion.div variants={fadeUp} className="mb-8 flex items-center gap-3">
                <span className="h-px w-12 bg-[var(--line)]" />
                <span className="text-[10px] font-bold uppercase tracking-[0.45em] text-[var(--gold)]">Creator · Designer · Builder</span>
                <span className="h-1.5 w-1.5 rounded-full bg-[#F6B400] shadow-[0_0_16px_#F6B400]" />
              </motion.div>
              <motion.h1 variants={fadeUp} className="font-display text-[3.55rem] font-semibold leading-[0.92] tracking-normal text-[var(--text)] sm:text-8xl lg:text-[8.4rem]">
                Hellom
                <span className="block text-[var(--gold)]">Space.</span>
              </motion.h1>
              <motion.p variants={fadeUp} className="mt-5 font-serif text-2xl italic text-[var(--text)] md:text-3xl">
                Your Creative Business Partner
              </motion.p>
              <motion.p variants={fadeUp} className="mt-6 max-w-xl text-sm leading-8 text-[var(--muted)] md:text-base">
                Saya membantu bisnis dan kreator membangun brand, sistem, dan produk digital yang estetik, fungsional, dan berdampak.
              </motion.p>
              <motion.div variants={fadeUp} className="mt-9 flex flex-col gap-4 sm:flex-row">
                <a href="#apps" className="group inline-flex h-14 items-center justify-center gap-3 rounded-lg bg-[#F6B400] px-8 text-sm font-bold text-black shadow-[0_0_32px_rgba(246,180,0,.24)] transition hover:bg-[#FFCC47]">
                  Lihat Aplikasi <ArrowRight className="h-4 w-4 transition group-hover:translate-x-1" />
                </a>
                <a href="#portfolio" className="group inline-flex h-14 items-center justify-center gap-3 rounded-lg border border-[var(--line)] bg-[var(--surface-soft)] px-8 text-sm font-bold text-[var(--text)] transition hover:border-[#F6B400]">
                  Lihat Portfolio <ArrowRight className="h-4 w-4" />
                </a>
              </motion.div>
              <motion.div variants={fadeUp} className="mt-10 grid max-w-xl grid-cols-3 gap-5">
                {stats.slice(0, 3).map((stat) => (
                  <div key={stat.label} className="border-r border-[var(--line)] last:border-r-0">
                    <p className="font-display text-2xl text-[var(--gold)]">{stat.value}</p>
                    <p className="mt-1 text-xs text-[var(--muted)]">{stat.label}</p>
                  </div>
                ))}
              </motion.div>
            </motion.div>

            <motion.div initial={{ opacity: 0, scale: 1.02 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.9 }} className="relative order-1 min-h-[720px] overflow-hidden rounded-[28px] border border-[#F6B400]/15 bg-[#0E0E11] md:order-none md:min-h-[700px] md:rounded-none md:border-0 md:bg-transparent">
              <div className="absolute inset-0" style={{ backgroundImage: heroScrim }} />
              <ImageFrame src={heroImagePath} alt="Muhammad Rizki" className="h-full min-h-[720px] w-full object-cover object-[62%_center] md:min-h-[700px] md:object-center" />
              <div className="absolute inset-x-0 bottom-0 h-1/2" style={{ background: 'linear-gradient(to top, var(--bg) 0%, var(--bg) 22%, transparent 100%)' }} />
              <div className="absolute left-8 bottom-[20.875rem] md:hidden">
                <p className="font-signature text-3xl text-[#F6B400]">Muhammad Rizki</p>
                <p className="mt-2 text-[9px] font-bold uppercase tracking-[0.38em] text-[var(--text)]">Founder of Hellom</p>
              </div>
              <div className="absolute bottom-16 right-8 hidden text-right md:right-24 md:block">
                <p className="font-signature text-4xl text-[#F6B400] md:text-5xl">Muhammad Rizki</p>
                <p className="mt-3 text-[10px] font-bold uppercase tracking-[0.48em] text-white drop-shadow-[0_1px_4px_rgba(0,0,0,0.6)]">Founder of Hellom</p>
              </div>
              <div className="absolute bottom-8 right-8 hidden text-right text-[10px] text-[var(--muted)] md:block">
                Scroll Down
                <ArrowRight className="ml-auto mt-2 h-4 w-4 rotate-90" />
              </div>
            </motion.div>
          </div>
        </section>

        <section className="border-b border-[var(--line)] px-5 py-5 md:px-10 lg:px-16">
          <div className="mx-auto flex max-w-[1500px] items-center gap-10 overflow-hidden">
            <p className="shrink-0 text-[10px] font-bold uppercase tracking-[0.4em] text-[var(--gold)]">Trusted by</p>
            <div className="flex min-w-0 flex-1 items-center justify-between gap-10 overflow-x-auto scrollbar-hide">
              {clients.map((client) => (
                <a key={client.id} href={client.website_url || '#'} className="shrink-0 opacity-60 grayscale transition hover:opacity-100 hover:grayscale-0">
                  {client.logo_url ? <img src={getImageUrl(client.logo_url)} alt={client.name} className="h-8 max-w-[150px] object-contain" /> : <span className="font-display text-xl text-[var(--muted)]">{client.name}</span>}
                </a>
              ))}
            </div>
          </div>
        </section>

        {/* Featured apps — flagship products of Hellom */}
        <section id="apps" className="border-b border-[var(--line)] px-5 py-16 md:px-10 lg:px-16">
          <div className="mx-auto max-w-[1500px]">
            <SectionLabel>Aplikasi Unggulan</SectionLabel>
            <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
              <div>
                <h2 className="max-w-3xl font-display text-4xl font-medium leading-tight md:text-5xl">
                  Dua aplikasi untuk bantu bisnismu <span className="text-[var(--gold)]">naik kelas.</span>
                </h2>
                <p className="mt-5 max-w-2xl text-sm leading-7 text-[var(--muted)]">
                  Solusi siap pakai dari Hellom: bikin halaman jualan online dulu, lalu kelola operasional usahamu dari satu tempat.
                </p>
              </div>
              <Link to={isAuthenticated ? '/dashboard' : '/login'} className="inline-flex h-12 w-fit items-center gap-4 rounded-lg border border-[var(--line)] px-7 text-sm font-bold transition hover:bg-[#F6B400]/10 hover:border-[#F6B400]">
                {isAuthenticated ? 'Buka Dashboard' : 'Mulai Sekarang'} <ArrowRight className="h-4 w-4 text-[var(--gold)]" />
              </Link>
            </div>

            <div className="mt-10 grid gap-5 md:grid-cols-2">
              {featuredApps.map((app) => {
                const Icon = app.icon;
                return (
                  <motion.div
                    key={app.title}
                    whileHover={{ y: -6 }}
                    className="group relative overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface-soft)] p-8 transition hover:border-[#F6B400]/50 hover:shadow-[0_0_50px_rgba(246,180,0,.10)]"
                  >
                    <div className="absolute right-6 top-6 text-[10px] font-bold uppercase tracking-[0.28em] text-[var(--gold)]">{app.badge}</div>
                    <div className="mb-6 flex h-14 w-14 items-center justify-center rounded-xl bg-[#F6B400]/12 text-[#F6B400]">
                      <Icon className="h-7 w-7" />
                    </div>
                    <h3 className="font-display text-2xl text-[var(--text)]">{app.title}</h3>
                    <p className="mt-3 text-sm leading-7 text-[var(--muted)]">{app.desc}</p>
                    <ul className="mt-6 space-y-2.5">
                      {app.points.map((point) => (
                        <li key={point} className="flex items-start gap-3 text-sm text-[var(--text)]">
                          <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#F6B400]/15 text-[#F6B400]">
                            <Check className="h-3 w-3" />
                          </span>
                          {point}
                        </li>
                      ))}
                    </ul>
                    <Link
                      to={app.href}
                      className="mt-8 inline-flex h-12 items-center gap-3 rounded-lg bg-[#F6B400] px-7 text-sm font-bold text-black transition hover:bg-[#FFCC47]"
                    >
                      {app.cta} <ArrowRight className="h-4 w-4 transition group-hover:translate-x-1" />
                    </Link>
                  </motion.div>
                );
              })}
            </div>
          </div>
        </section>

        <section id="about" className="grid border-b border-[var(--line)] md:grid-cols-[0.85fr_1.15fr]">
          <div className="border-b border-[var(--line)] px-5 py-16 md:border-b-0 md:border-r md:px-10 lg:px-16">
            <SectionLabel>About me</SectionLabel>
            <h2 className="max-w-lg font-display text-4xl font-medium leading-tight md:text-5xl">
              {(about.title || 'Membangun dengan strategi, berkarya dengan estetika.').split('estetika')[0]}
              <span className="text-[var(--gold)]">estetika.</span>
            </h2>
            <p className="mt-7 max-w-lg text-sm leading-7 text-[var(--muted)]">
              {about.description || 'Berpengalaman 5+ tahun di dunia kreatif dan digital. Saya fokus membantu bisnis dan kreator mengubah ide menjadi sistem dan produk digital yang siap digunakan, terukur, dan berkelanjutan.'}
            </p>
            <a href="#contact" className="mt-9 inline-flex h-12 items-center gap-4 rounded-lg border border-[var(--line)] px-7 text-sm font-bold transition hover:bg-[#F6B400]/10 hover:border-[#F6B400]">
              Selengkapnya tentang saya <ArrowRight className="h-4 w-4 text-[var(--gold)]" />
            </a>
            <ImageFrame src={workspaceImagePath} alt="Hellom workspace" className="mt-14 aspect-[1.55] w-full rounded-xl object-cover" />
          </div>

          <div id="services" className="px-5 py-16 md:px-10 lg:px-16">
            <SectionLabel>What I do</SectionLabel>
            <h2 className="max-w-4xl font-display text-4xl font-medium leading-tight md:text-5xl">
              Solusi kreatif untuk kebutuhan <span className="text-[var(--gold)]">digitalmu.</span>
            </h2>
            <div className="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
              {services.slice(0, 5).map((service) => {
                const Icon = iconMap[(service.icon || 'Sparkles') as keyof typeof iconMap] || Sparkles;
                return (
                  <motion.article whileHover={{ y: -6 }} key={service.id} className="rounded-lg border border-[var(--line)] bg-[var(--surface-soft)] p-6 transition hover:border-[#F6B400]/45 hover:shadow-[0_0_40px_rgba(246,180,0,.08)]">
                    <Icon className="mb-7 h-7 w-7 text-[#F6B400]" />
                    <h3 className="font-display text-lg text-[var(--text)]">{service.title}</h3>
                    <p className="mt-4 min-h-20 text-sm leading-6 text-[var(--muted)]">{service.short_description}</p>
                    <a href="#contact" className="mt-5 inline-flex items-center gap-2 text-xs font-bold text-[var(--gold)]">Lihat layanan <ArrowRight className="h-3.5 w-3.5" /></a>
                  </motion.article>
                );
              })}
            </div>

            <section id="portfolio" className="mt-16">
              <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div>
                  <SectionLabel>Featured works</SectionLabel>
                  <h2 className="font-display text-4xl font-medium leading-tight md:text-5xl">Beberapa karya dan <span className="text-[var(--gold)]">proyek pilihan.</span></h2>
                </div>
                <div className="flex gap-2 overflow-x-auto scrollbar-hide">
                  {filters.map((filter) => (
                    <button key={filter} type="button" onClick={() => setActiveFilter(filter)} className={`h-10 shrink-0 rounded-full px-5 text-xs font-bold transition ${activeFilter === filter ? 'bg-[#F6B400] text-black' : 'bg-[var(--surface-soft)] text-[var(--muted)] hover:bg-[var(--surface-strong)]'}`}>
                      {filter}
                    </button>
                  ))}
                </div>
              </div>
              <div className="mt-8 grid gap-4 lg:grid-cols-3">
                {filteredPortfolios.slice(0, 6).map((item) => (
                  <motion.article
                    whileHover={{ y: -6 }}
                    key={item.id}
                    onClick={() => setSelectedPortfolio(item)}
                    className="group cursor-pointer overflow-hidden rounded-lg border border-[var(--line)] bg-[var(--surface-soft)] transition hover:border-[#F6B400]/50"
                  >
                    <div className="relative aspect-[1.65] overflow-hidden bg-[#0E0E11]">
                      {item.thumbnail_url
                        ? <img src={getImageUrl(item.thumbnail_url)} alt={item.title} className="h-full w-full object-cover transition duration-700 group-hover:scale-105" />
                        : <div className="h-full w-full bg-[radial-gradient(circle_at_35%_30%,rgba(246,180,0,.22),transparent_33%),linear-gradient(135deg,#131313,#050505)]" />
                      }
                      <div className="absolute inset-0 bg-gradient-to-t from-black via-black/15 to-transparent" />
                      {item.video_url && (
                        <div className="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                          <div className="w-14 h-14 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/30">
                            <svg viewBox="0 0 24 24" className="w-6 h-6 text-white fill-white ml-1"><polygon points="5,3 19,12 5,21" /></svg>
                          </div>
                        </div>
                      )}
                      <ExternalLink className="absolute bottom-4 right-4 h-9 w-9 rounded-lg bg-black/55 p-2 text-white backdrop-blur opacity-0 group-hover:opacity-100 transition-opacity" />
                    </div>
                    <div className="p-5">
                      <h3 className="font-display text-lg text-[var(--text)]">{item.title}</h3>
                      <p className="mt-1 text-sm text-[var(--muted)]">{item.category || item.description || 'Creator ecosystem project'}</p>
                      {(item.client_name || item.project_year) && (
                        <p className="mt-2 text-xs text-[var(--gold)]">{[item.client_name, item.project_year].filter(Boolean).join(' · ')}</p>
                      )}
                    </div>
                  </motion.article>
                ))}
              </div>
            </section>
          </div>
        </section>

        <section id="products" className="border-b border-[var(--line)] px-5 py-16 md:px-10 lg:px-16">
          <div className="mx-auto max-w-[1500px]">
            <SectionLabel>{productSectionLabel}</SectionLabel>
            <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
              <div>
                <h2 className="max-w-3xl font-display text-4xl font-medium leading-tight md:text-5xl">{productSectionHeading}</h2>
                <p className="mt-5 max-w-2xl text-sm leading-7 text-[var(--muted)]">{productSectionDescription}</p>
              </div>
              <Link to="/produk" className="inline-flex h-12 w-fit items-center gap-4 rounded-lg border border-[var(--line)] px-7 text-sm font-bold transition hover:bg-[#F6B400]/10 hover:border-[#F6B400]">{productSectionCta} <ArrowRight className="h-4 w-4 text-[var(--gold)]" /></Link>
            </div>
            <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {(visibleProducts.length ? visibleProducts : []).map((product) => {
                const detailUrl = `/dashboard/products/${product.slug}/checkout`;
                const isFree = product.type === 'free' || Number(product.price || 0) <= 0;

                return (
                <Link
                  key={product.id}
                  to={isAuthenticated ? detailUrl : '/login'}
                  onClick={() => {
                    if (!isAuthenticated) {
                      savePendingCheckoutIntent({
                        kind: 'digital_product',
                        product_id: product.id,
                        product_slug: product.slug,
                        return_to: detailUrl,
                      });
                    }
                  }}
                  className="group overflow-hidden rounded-lg border border-[var(--line)] bg-[var(--surface-soft)] transition hover:-translate-y-1 hover:border-[#F6B400]/45"
                >
                  <div className="aspect-[1.15] overflow-hidden bg-[#0E0E11]">
                    {product.thumbnail_url ? <img src={getImageUrl(product.thumbnail_url)} alt={product.name} className="h-full w-full object-cover transition duration-700 group-hover:scale-105" /> : <div className="h-full w-full bg-[radial-gradient(circle_at_45%_20%,rgba(246,180,0,.18),transparent_35%),#111]" />}
                  </div>
                  <div className="p-5">
                    <p className="text-[10px] uppercase tracking-[0.28em] text-[var(--gold)]">{product.category || 'Digital Product'}</p>
                    <h3 className="mt-3 min-h-12 font-display text-lg text-[var(--text)]">{product.name}</h3>
                    {product.tagline ? <p className="mt-2 min-h-10 text-sm leading-5 text-[var(--muted)]">{product.tagline}</p> : null}
                    <p className="mt-3 text-sm text-[var(--gold)]">{formatPrice(product)}</p>
                    <div className="mt-5 flex items-center justify-between gap-3">
                      <span className="text-xs font-bold text-[var(--text)]">{isFree ? 'Aktifkan gratis' : 'Beli sekarang'}</span>
                      <ShoppingCart className="h-9 w-9 rounded-lg border border-[var(--line)] p-2 text-[#F6B400]" />
                    </div>
                  </div>
                </Link>
                );
              })}
              {!visibleProducts.length && (
                <div className="rounded-lg border border-dashed border-[var(--line)] p-8 text-[var(--muted)] sm:col-span-2 lg:col-span-4">Produk akan tampil otomatis setelah katalog produk diisi.</div>
              )}
            </div>
          </div>
        </section>

        <section id="insights" className="grid border-b border-[var(--line)] md:grid-cols-[1fr_1fr]">
          <div className="px-5 py-16 md:px-10 lg:px-16">
            <SectionLabel>Insights</SectionLabel>
            <h2 className="max-w-xl font-display text-4xl font-medium leading-tight md:text-5xl">Berbagi <span className="font-serif italic text-[var(--gold)]">insight</span> seputar digital, branding, dan produktivitas.</h2>
          </div>
          <div className="px-5 py-16 md:px-10 lg:px-16">
            <div className="space-y-5">
              {articles.slice(0, 4).map((article) => {
                const card = (
                  <article className="grid grid-cols-[112px_1fr] gap-5">
                    <div className="aspect-[1.35] overflow-hidden rounded-lg bg-[#0E0E11]">
                      {article.thumbnail ? <img src={getImageUrl(article.thumbnail)} alt={article.title} className="h-full w-full object-cover" /> : <div className="h-full w-full bg-[radial-gradient(circle_at_45%_20%,rgba(246,180,0,.24),transparent_35%),#111]" />}
                    </div>
                    <div>
                      <p className="text-xs text-[var(--gold)]">{formatDate(article.published_at)}</p>
                      <h3 className="mt-2 text-base leading-6 text-[var(--text)]">{article.title}</h3>
                      <p className="mt-2 text-xs text-[var(--muted)]">{article.read_time || 5} min read</p>
                    </div>
                  </article>
                );

                return article.slug ? (
                  <Link key={article.id} to={`/insights/${article.slug}`} className="block transition hover:opacity-90">
                    {card}
                  </Link>
                ) : (
                  <div key={article.id}>{card}</div>
                );
              })}
            </div>
            <Link to="/insights" className="mt-9 inline-flex h-12 items-center gap-4 rounded-lg border border-[var(--line)] px-7 text-sm font-bold transition hover:bg-[#F6B400]/10 hover:border-[#F6B400]">Lihat semua artikel <ArrowRight className="h-4 w-4 text-[var(--gold)]" /></Link>
          </div>
        </section>

        <section id="contact" className="relative overflow-hidden px-5 py-24 text-center md:px-10 lg:px-16">
          <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,rgba(246,180,0,.18),transparent_35%)]" />
          <div className="relative mx-auto max-w-3xl">
            <h2 className="font-display text-5xl font-medium leading-tight md:text-7xl">Let’s build something meaningful.</h2>
            <div className="mt-9 flex flex-col items-center justify-center gap-4 sm:flex-row">
              <a href="https://wa.me/6280000000000" className="inline-flex h-14 items-center justify-center gap-3 rounded-lg bg-[#F6B400] px-8 text-sm font-bold text-black hover:bg-[#FFCC47] transition">Mulai Project <ArrowRight className="h-4 w-4" /></a>
              <a href="mailto:hello@hellomspace.com" className="inline-flex h-14 items-center justify-center gap-3 rounded-lg border border-[var(--line)] px-8 text-sm font-bold text-[var(--text)]">Hubungi Saya</a>
            </div>
          </div>
        </section>
      </main>

      <Footer brand={brand} logoSrc={logoSrc} />
      <MobileBottomNav isAuthenticated={isAuthenticated} />

      {/* Portfolio Detail Modal */}
      {selectedPortfolio && (() => {
        const p = selectedPortfolio;
        const ytId = p.video_url?.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/)?.[1];
        const vimeoId = p.video_url?.match(/vimeo\.com\/(\d+)/)?.[1];
        return (
          <div
            className="fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-black/80 backdrop-blur-sm"
            onClick={() => setSelectedPortfolio(null)}
          >
            <div
              className="w-full max-w-3xl max-h-[95vh] overflow-y-auto bg-[#0E0E11] rounded-t-2xl sm:rounded-2xl border border-white/[0.08] shadow-2xl"
              onClick={e => e.stopPropagation()}
            >
              <div className="sticky top-0 z-10 flex justify-end p-4 bg-[#0E0E11]/90 backdrop-blur-sm">
                <button
                  onClick={() => setSelectedPortfolio(null)}
                  className="w-9 h-9 rounded-lg bg-white/[0.06] flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 transition"
                  aria-label="Close"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <div className="px-4 sm:px-6 -mt-4">
                {ytId ? (
                  <div className="aspect-video w-full rounded-xl overflow-hidden bg-black">
                    <iframe
                      src={`https://www.youtube.com/embed/${ytId}?autoplay=1&rel=0`}
                      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                      allowFullScreen
                      className="w-full h-full"
                    />
                  </div>
                ) : vimeoId ? (
                  <div className="aspect-video w-full rounded-xl overflow-hidden bg-black">
                    <iframe
                      src={`https://player.vimeo.com/video/${vimeoId}?autoplay=1`}
                      allow="autoplay; fullscreen; picture-in-picture"
                      allowFullScreen
                      className="w-full h-full"
                    />
                  </div>
                ) : p.video_url ? (
                  <div className="aspect-video w-full rounded-xl overflow-hidden bg-black">
                    <video src={p.video_url} controls autoPlay className="w-full h-full object-contain" poster={p.thumbnail_url ?? undefined} />
                  </div>
                ) : p.thumbnail_url ? (
                  <div className="aspect-[1.65] w-full rounded-xl overflow-hidden bg-[#050505]">
                    <img src={getImageUrl(p.thumbnail_url)} alt={p.title} className="w-full h-full object-cover" />
                  </div>
                ) : (
                  <div className="aspect-[1.65] w-full rounded-xl bg-[radial-gradient(circle_at_35%_30%,rgba(246,180,0,.22),transparent_33%),linear-gradient(135deg,#131313,#050505)]" />
                )}
              </div>

              <div className="p-6 sm:p-8 text-[#F5F5F2]">
                {p.category && (
                  <p className="text-[10px] font-bold uppercase tracking-[0.3em] text-[#F6B400] mb-3">{p.category}</p>
                )}
                <h2 className="font-display text-2xl sm:text-3xl text-white leading-snug">{p.title}</h2>

                {(p.client_name || p.project_year) && (
                  <div className="mt-3 flex items-center gap-3 text-sm text-[#8B8B90]">
                    {p.client_name && <span>Klien: <span className="text-white/80">{p.client_name}</span></span>}
                    {p.client_name && p.project_year && <span className="text-white/20">·</span>}
                    {p.project_year && <span>Tahun: <span className="text-white/80">{p.project_year}</span></span>}
                  </div>
                )}

                {(p.full_description || p.description) && (
                  <p className="mt-5 text-sm leading-7 text-[#B6B6B8] whitespace-pre-line">
                    {p.full_description || p.description}
                  </p>
                )}

                {p.project_url && (
                  <div className="mt-8">
                    <a
                      href={p.project_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex h-12 items-center gap-3 rounded-lg bg-[#F6B400] px-7 text-sm font-bold text-black hover:bg-[#FFCC47] transition"
                    >
                      <ExternalLink className="w-4 h-4" /> Lihat Website
                    </a>
                  </div>
                )}
              </div>
            </div>
          </div>
        );
      })()}
    </div>
  );
};

const Navbar = ({ brand, logoSrc, isAuthenticated, theme, onToggleTheme }: { brand: BrandSettings; logoSrc: string | null; isAuthenticated: boolean; theme: Theme; onToggleTheme: () => void }) => {
  const businessName = brand.app_name || brand.business_name || 'Hellom';
  const nav = [
    ['Home', '#top'],
    ['About', '#about'],
    ['Services', '#services'],
    ['Apps', '#apps'],
    ['Products', '#products'],
    ['Portfolio', '#portfolio'],
    ['Insights', '#insights'],
    ['Contact', '#contact'],
  ];

  return (
    <header className="fixed inset-x-0 top-0 z-50 border-b border-[var(--line-soft)] bg-[var(--navbar-bg)] backdrop-blur-xl">
      <div className="mx-auto flex h-20 max-w-[1500px] items-center justify-between px-5 md:px-10 lg:px-16">
        <Link to="/" className="flex items-center gap-3">
          {logoSrc ? <img src={logoSrc} alt={businessName} className="h-8 w-auto object-contain" /> : <span className="text-2xl font-black tracking-normal text-[var(--text)]">Hell<span className="text-[#F6B400]">om</span></span>}
        </Link>
        <nav className="hidden items-center gap-7 xl:flex">
          {nav.map(([label, href], index) => (
            <a key={label} href={href} className={`text-xs font-semibold transition hover:text-[#F6B400] ${index === 0 ? 'text-[var(--gold)]' : 'text-[var(--muted)]'}`}>{label}</a>
          ))}
        </nav>
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={onToggleTheme}
            title={theme === 'light' ? 'Mode gelap' : 'Mode terang'}
            aria-label="Ganti tema"
            className="flex h-11 w-11 items-center justify-center rounded-lg border border-[var(--line)] text-[var(--text)] transition hover:border-[#F6B400] hover:text-[#F6B400]"
          >
            {theme === 'light' ? <Moon className="h-5 w-5" /> : <Sun className="h-5 w-5" />}
          </button>
          <Link to={isAuthenticated ? '/dashboard' : '/login'} className="hidden h-11 items-center rounded-lg px-4 text-xs font-bold text-[var(--muted)] transition hover:text-[var(--text)] md:inline-flex">
            {isAuthenticated ? 'Dashboard' : 'Login'}
          </Link>
          <a href="https://wa.me/6280000000000" className="hidden h-11 items-center gap-3 rounded-lg border border-[#F6B400]/45 px-6 text-xs font-bold text-[var(--text)] transition hover:bg-[#F6B400]/10 md:inline-flex">Let’s Talk <ArrowRight className="h-3.5 w-3.5 text-[#F6B400]" /></a>
          <button type="button" className="rounded-lg p-2 text-[var(--text)] xl:hidden" aria-label="Menu"><Menu className="h-6 w-6" /></button>
        </div>
      </div>
    </header>
  );
};

const Footer = ({ brand, logoSrc }: { brand: BrandSettings; logoSrc: string | null }) => {
  const name = brand.app_name || brand.business_name || 'Hellom';
  const email = brand.support_email || COMPANY_INFO.fallbackEmail;
  const phone = brand.support_phone || COMPANY_INFO.fallbackPhone;
  const address = formatCompanyAddress();
  return (
    <footer className="relative z-10 border-t border-[var(--line)] px-5 py-10 md:px-10 lg:px-16">
      <div className="mx-auto max-w-[1500px] space-y-8">
        <div className="flex flex-col justify-between gap-8 md:flex-row md:items-start">
          <div>
            {logoSrc ? <img src={logoSrc} alt={name} className="h-8 w-auto object-contain" /> : <p className="text-2xl font-black text-[var(--text)]">Hell<span className="text-[#F6B400]">om</span></p>}
            <p className="mt-3 text-sm text-[var(--muted)]">Your Creative Business Partner</p>
            {address ? <p className="mt-2 max-w-xs text-xs leading-relaxed text-[var(--muted)]">{address}</p> : null}
          </div>
          <div className="flex flex-wrap gap-5 text-sm text-[var(--muted)]">
            <a href="#apps" className="hover:text-[var(--text)]">Aplikasi</a>
            <a href="#portfolio" className="hover:text-[var(--text)]">Portfolio</a>
            <Link to="/produk" className="hover:text-[var(--text)]">Products</Link>
            <Link to="/insights" className="hover:text-[var(--text)]">Insights</Link>
            <a href={`mailto:${email}`} className="hover:text-[var(--text)]">{email}</a>
            {phone ? <span>{phone}</span> : null}
          </div>
        </div>
        <div className="flex flex-col items-center justify-between gap-4 border-t border-[var(--line)] pt-6 md:flex-row">
          <div className="flex flex-wrap gap-5 text-xs text-[var(--muted)]">
            <Link to="/faq" className="hover:text-[var(--text)]">FAQ</Link>
            <Link to="/refund-policy" className="hover:text-[var(--text)]">Kebijakan Refund</Link>
            <Link to="/terms" className="hover:text-[var(--text)]">Syarat &amp; Ketentuan</Link>
            <Link to="/contact" className="hover:text-[var(--text)]">Kontak</Link>
          </div>
          <p className="text-xs text-[var(--muted)]">© {new Date().getFullYear()} {name}.</p>
        </div>
      </div>
    </footer>
  );
};

const MobileBottomNav = ({ isAuthenticated }: { isAuthenticated: boolean }) => {
  const items = [
    { label: 'Beranda', href: '#top', icon: Home },
    { label: 'Aplikasi', href: '#apps', icon: Grid2X2 },
    { label: 'Products', href: '#products', icon: Cpu },
    { label: 'Insights', href: '#insights', icon: Layers3 },
    { label: 'Akun', href: isAuthenticated ? '/dashboard' : '/login', icon: UserRound },
  ];
  const [active, setActive] = useState('Beranda');

  return (
    <nav className="fixed inset-x-0 bottom-0 z-50 px-4 pb-[calc(env(safe-area-inset-bottom,0px)+14px)] md:hidden">
      <div className="rounded-full border border-[#F6B400]/30 bg-black/80 px-3 py-2 shadow-[0_0_34px_rgba(246,180,0,.18)] backdrop-blur-xl">
        <div className="grid grid-cols-5 gap-1">
          {items.map((item) => {
            const Icon = item.icon;
            const isActive = active === item.label;
            return (
              <a key={item.label} href={item.href} onClick={() => setActive(item.label)} className={`flex flex-col items-center justify-center gap-1 rounded-full py-2 text-[10px] font-semibold transition ${isActive ? 'text-[#F6B400]' : 'text-white/78'}`}>
                <Icon className={`h-5 w-5 ${isActive ? 'drop-shadow-[0_0_10px_#F6B400]' : ''}`} />
                <span>{item.label}</span>
              </a>
            );
          })}
        </div>
      </div>
    </nav>
  );
};
