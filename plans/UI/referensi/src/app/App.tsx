import { Navbar } from './components/Navbar';
import { Hero } from './components/Hero';
import { Ticker } from './components/Ticker';
import { Stats } from './components/Stats';
import { Categories } from './components/Categories';
import { Products } from './components/Products';
import { Why } from './components/Why';
import { Pricing } from './components/Pricing';
import { Articles } from './components/Articles';
import { Testimonials } from './components/Testimonials';
import { CTA } from './components/CTA';
import { Footer } from './components/Footer';
import { BottomNav } from './components/BottomNav';

export default function App() {
  return (
    <div className="min-h-screen bg-black text-white antialiased" style={{ fontFamily: 'Inter, sans-serif' }}>
      <Navbar />

      {/* Main Content - Add padding bottom for mobile bottom nav */}
      <div className="pb-0 md:pb-0">
        <Hero />
        <Ticker />
        <Stats />
        <Categories />
        <Products />
        <Why />
        <Pricing />
        <Articles />
        <Testimonials />
        <CTA />
        <Footer />
      </div>

      {/* Mobile Bottom Navigation */}
      <BottomNav />
    </div>
  );
}