import { useEffect, useMemo, useState } from 'react';
import type { PosMenuProduct } from '@/lib/hellomApi';

export type CartOption = {
  option_id: number;
  value_id: number;
  option_name: string;
  value_name: string;
  price_delta: number;
};

// One cart line = one product with one set of add-ons (same product, other add-ons = other line).
export type CartItem = {
  key: string;
  product: PosMenuProduct;
  options: CartOption[];
  unitPrice: number;
  quantity: number;
};

const STORAGE_PREFIX = 'hellom-pos-cart';

function getStorageKey(scope: string) {
  return `${STORAGE_PREFIX}:${scope}`;
}

export function cartLineKey(productId: number, options: CartOption[]): string {
  const ids = options.map((o) => `${o.option_id}:${o.value_id}`).sort().join(',');
  return ids ? `${productId}|${ids}` : String(productId);
}

function unitPriceOf(product: PosMenuProduct, options: CartOption[]): number {
  return product.price + options.reduce((sum, o) => sum + o.price_delta, 0);
}

// Carts saved before add-ons existed have no key/options: normalise them.
function normalise(raw: unknown): CartItem[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .filter((entry): entry is Partial<CartItem> & { product: PosMenuProduct; quantity: number } =>
      Boolean(entry && typeof entry === 'object' && 'product' in entry && 'quantity' in entry))
    .map((entry) => {
      const options = Array.isArray(entry.options) ? entry.options : [];
      return {
        key: entry.key || cartLineKey(entry.product.id, options),
        product: entry.product,
        options,
        unitPrice: unitPriceOf(entry.product, options),
        quantity: entry.quantity,
      };
    });
}

function readInitialCart(scope: string): CartItem[] {
  if (typeof window === 'undefined') {
    return [];
  }

  try {
    const raw = localStorage.getItem(getStorageKey(scope));
    return raw ? normalise(JSON.parse(raw)) : [];
  } catch {
    return [];
  }
}

export function useCart(scope: string) {
  const [items, setItems] = useState<CartItem[]>(() => readInitialCart(scope));

  useEffect(() => {
    setItems(readInitialCart(scope));
  }, [scope]);

  useEffect(() => {
    localStorage.setItem(getStorageKey(scope), JSON.stringify(items));
  }, [items, scope]);

  const totalItems = useMemo(
    () => items.reduce((sum, item) => sum + item.quantity, 0),
    [items]
  );

  const totalPrice = useMemo(
    () => items.reduce((sum, item) => sum + (item.unitPrice * item.quantity), 0),
    [items]
  );

  const addItem = (product: PosMenuProduct, options: CartOption[] = []) => {
    const key = cartLineKey(product.id, options);
    setItems((current) => {
      const existing = current.find((entry) => entry.key === key);
      if (existing) {
        return current.map((entry) =>
          entry.key === key ? { ...entry, quantity: entry.quantity + 1 } : entry
        );
      }

      return [...current, { key, product, options, unitPrice: unitPriceOf(product, options), quantity: 1 }];
    });
  };

  const updateQuantity = (key: string, quantity: number) => {
    setItems((current) => {
      if (quantity <= 0) {
        return current.filter((entry) => entry.key !== key);
      }

      return current.map((entry) =>
        entry.key === key ? { ...entry, quantity } : entry
      );
    });
  };

  const removeItem = (key: string) => {
    setItems((current) => current.filter((entry) => entry.key !== key));
  };

  // Replace the product data of every line (fresh prices from the server after a change).
  const refreshProducts = (products: Map<number, PosMenuProduct>, removeProductIds: number[] = []) => {
    setItems((current) =>
      current
        .filter((entry) => !removeProductIds.includes(entry.product.id) && products.has(entry.product.id))
        .map((entry) => {
          const product = products.get(entry.product.id) as PosMenuProduct;
          // Take add-on prices from the fresh menu; add-ons that no longer exist are dropped.
          const options = entry.options.flatMap((o) => {
            const value = product.options?.find((opt) => opt.id === o.option_id)?.values.find((v) => v.id === o.value_id);
            return value ? [{ ...o, value_name: value.name, price_delta: value.price_delta }] : [];
          });
          return { ...entry, product, options, key: cartLineKey(product.id, options), unitPrice: unitPriceOf(product, options) };
        })
    );
  };

  const clear = () => {
    setItems([]);
  };

  return {
    items,
    totalItems,
    totalPrice,
    addItem,
    updateQuantity,
    removeItem,
    refreshProducts,
    clear,
  };
}
