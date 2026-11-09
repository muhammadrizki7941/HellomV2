import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import HellomLoading from './HellomLoading';

export default function RouteFallback({ mode = 'page' }: { mode?: 'page' | 'inline' }) {
  const { pathname } = useLocation();
  const [showLoader, setShowLoader] = useState(false);

  useEffect(() => {
    const timer = window.setTimeout(() => setShowLoader(true), 500);
    return () => window.clearTimeout(timer);
  }, []);

  if (!showLoader) return null;
  return <HellomLoading mode={mode} label={/^\/pos(\/|$)/.test(pathname) ? 'Memuat POS...' : 'Menyiapkan halaman...'} />;
}
