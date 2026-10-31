import { useSearchParams } from 'react-router-dom';
import { ShoppingCart } from 'lucide-react';
import CheckoutModal from '@/components/CheckoutModal';
import { getToken } from '@/services/api/client';

export default function CheckoutQueryLauncher() {
  const [params, setParams] = useSearchParams();
  const isCheckout = params.get('checkout') === 'pos';
  if (!isCheckout) return null;

  const close = () => {
    const next = new URLSearchParams(params);
    next.delete('checkout');
    setParams(next, { replace: true });
  };

  return (
    <CheckoutModal
      isOpen
      onClose={close}
      appName="POS"
      appSlug="pos"
      appIcon={ShoppingCart}
      context={getToken() ? 'member' : 'guest'}
      initialPlanSlug={params.get('paket') || undefined}
      initialPromo={params.get('promo') || undefined}
    />
  );
}
