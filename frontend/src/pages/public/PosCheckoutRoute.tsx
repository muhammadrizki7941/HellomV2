import { useNavigate, useSearchParams } from 'react-router-dom';
import { ShoppingCart } from 'lucide-react';
import CheckoutModal from '@/components/CheckoutModal';
import { getToken } from '@/services/api/client';

export default function PosCheckoutRoute() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  return (
    <main className="min-h-screen bg-zinc-50">
      <CheckoutModal
        isOpen
        onClose={() => navigate('/', { replace: true })}
        appName="POS"
        appSlug="pos"
        appIcon={ShoppingCart}
        context={getToken() ? 'member' : 'guest'}
        initialPlanSlug={params.get('paket') || undefined}
        initialPromo={params.get('promo') || undefined}
      />
    </main>
  );
}
