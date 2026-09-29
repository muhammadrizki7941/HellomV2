import { useEffect, useState } from 'react';
import { getSellerProducts } from '@/lib/hellomApi';
import type { SellerProduct } from '@/lib/hellomApi';

// The seller's products, loaded once per editor session (product picker, catalog preview).
let cache: Promise<SellerProduct[]> | null = null;

export function loadSellerProducts(): Promise<SellerProduct[]> {
  cache ??= getSellerProducts().then((r) => r.items).catch((err) => {
    cache = null;
    throw err;
  });
  return cache;
}

/** Forget the cache (after products change in the Produk tab). */
export function resetSellerProducts(): void {
  cache = null;
}

export function useSellerProducts(): { products: SellerProduct[] | null; error: string | null } {
  const [products, setProducts] = useState<SellerProduct[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let alive = true;
    loadSellerProducts().then((p) => { if (alive) setProducts(p); }).catch((e) => { if (alive) setError(e instanceof Error ? e.message : 'Produk belum bisa dimuat'); });
    return () => { alive = false; };
  }, []);
  return { products, error };
}
