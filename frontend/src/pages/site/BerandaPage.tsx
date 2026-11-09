import { Link } from 'react-router-dom';
import { ArrowRight, Camera, Store, UserRound, Play } from 'lucide-react';
import { usePageMeta } from '@/components/site/PageHero';
import useSiteData from '@/components/site/useSiteData';
import useFlagshipApps from '@/components/site/useFlagshipApps';
import PublicBannerSlot from '@/components/site/PublicBannerSlot';
import DemoVideo from '@/components/site/DemoVideo';
import { SITE_PAGES } from '@/data/siteNav';

export default function BerandaPage() {
  const { content, clients } = useSiteData();
  const { apps } = useFlagshipApps();
  const about = content.about;
  const demoVisible = Boolean(about?.demo_video_enabled && about.demo_video_url);
  usePageMeta('Ide besar, mulai dari langkah kecil.', 'Aplikasi dan layanan digital untuk individu, kreator, dan pemilik usaha. Temukan aplikasi yang tepat bersama Hellom Space.', '/');
  return (
    <div className="hellom-home">
      <section className="home-hero">
        <h1>Ide besar,<br /><em>mulai dari langkah kecil.</em></h1>
        <p>Aplikasi dan layanan digital untuk individu,<br className="hidden sm:block" /> kreator, dan pemilik usaha.</p>
        <div className="home-actions">
          <Link to="/aplikasi" className="home-button">Jelajahi Aplikasi <ArrowRight /></Link>
          {demoVisible && <a href="#video-demo" className="home-demo-link"><Play size={17} /> Tonton demo</a>}
        </div>
        <div className="home-illustration"><img src="/images/hellom-home-reference.jpg" width="1024" height="1536" alt="Kreator dengan Landing Page Builder di ponsel dan pemilik usaha dengan Hellom POS di tablet" fetchPriority="high" /></div>
      </section>
      <div className="home-container"><PublicBannerSlot position="hero" /></div>
      <section className="home-container home-apps">
        <h2>Temukan aplikasi untuk <em>kebutuhanmu</em></h2>
        <div className="home-app-grid">
          {apps.map((app) => { const Icon = app.icon; return (
            <article key={app.key} className="home-app-card">
              <div className="home-app-copy"><h3><Icon />{app.name}</h3><p>{app.summary}</p><Link className="home-outline" to={`/aplikasi/${app.key}`}>Pelajari {app.name}<ArrowRight size={18} /></Link></div>
              {app.banner ? <img className="home-app-banner" src={app.banner} alt={app.name} loading="lazy" /> : ['pos', 'landing-page-builder'].includes(app.key) ? <div className={`home-product-art ${app.key === 'pos' ? 'is-pos' : 'is-builder'}`} aria-hidden="true"><img src="/images/hellom-home-reference.jpg" alt="" loading="lazy" /></div> : <Icon className="ml-auto h-24 w-24 text-amber-500" aria-hidden />}
            </article>
          ); })}
        </div>
      </section>
      <section className="home-container home-audience">
        <h2>Untuk individu, kreator, dan pemilik usaha</h2>
        <div>{[
          { icon: UserRound, title: 'Individu', text: 'Wujudkan ide dan proyek pribadi.' },
          { icon: Camera, title: 'Kreator', text: 'Bangun karya dan jangkau audiens lebih luas.' },
          { icon: Store, title: 'Pemilik Usaha', text: 'Kelola dan kembangkan bisnis dengan lebih mudah.' },
        ].map(({ icon: Icon, title, text }) => <article key={title}><span><Icon /></span><div><h3>{title}</h3><p>{text}</p></div></article>)}</div>
      </section>
      {demoVisible && <section id="video-demo" className="home-container home-demo"><span className="home-eyebrow">LIHAT CARA KERJANYA</span><h2>{about?.demo_video_title || 'Kenalan lebih dekat dengan Hellom'}</h2>{about?.demo_video_description && <p>{about.demo_video_description}</p>}<DemoVideo url={about!.demo_video_url!} title={about?.demo_video_title || 'Video demo Hellom'} /></section>}
      <section className="home-container home-explore"><span className="home-eyebrow">LEBIH BANYAK DI HELLOM SPACE</span><h2>Setiap ide punya ruang untuk tumbuh.</h2><nav aria-label="Jelajahi seluruh halaman">{SITE_PAGES.filter(page => page.path !== '/').map(page => <Link to={page.path} key={page.path}>{page.label}<ArrowRight size={18} /></Link>)}</nav></section>
      <section className="home-container home-trust"><p>Dipercaya oleh</p><div>{clients.map(client => <span key={client.id}>{client.name}</span>)}</div></section>
      <section className="home-closing"><h2>Siap memulai?</h2><p>Langkah kecil hari ini, kemungkinan besar esok hari.</p><div className="home-actions"><Link to="/aplikasi" className="home-button">Jelajahi Aplikasi <ArrowRight /></Link><Link to="/kontak" className="home-outline">Mulai Proyek <ArrowRight size={18} /></Link></div></section>
    </div>
  );
}
