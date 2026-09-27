import { useEffect, useMemo, useState } from 'react';
import type { PosMenuProduct } from '@/lib/pos/posApi';

export type CartItem = {
  product: PosMenuProduct;
  quantity: number;
};

const STORAGE_PREFIX = 'hellom-pos-cart';

function getStorageKey(scope: string) {
  return `${STORAGE_PREFIX}:${scope}`;
}

function readInitialCart(scope: string): CartItem[] {
  if (typeof window === 'undefined') {
    return [];
  }

  try {
    const raw = localStorage.getItem(getStorageKey(scope));
    return raw ? (JSON.parse(raw) as CartItem[]) : [];
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
    () => items.reduce((sum, item) => sum + (item.product.price * item.quantity), 0),
    [items]
  );

  const addItem = (product: PosMenuProduct) => {
    setItems((current) => {
      const existing = current.find((entry) => entry.product.id === product.id);
      if (existing) {
        return current.map((entry) =>
          entry.product.id === product.id
            ? { ...entry, quantity: entry.quantity + 1 }
            : entry
        );
      }

      return [...current, { product, quantity: 1 }];
    });
  };

  const updateQuantity = (productId: number, quantity: number) => {
    setItems((current) => {
      if (quantity <= 0) {
        return current.filter((entry) => entry.product.id !== productId);
      }

      return current.map((entry) =>
        entry.product.id === productId
          ? { ...entry, quantity }
          : entry
      );
    });
  };

  const removeItem = (productId: number) => {
    setItems((current) => current.filter((entry) => entry.product.id !== productId));
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
    clear,
  };
}
