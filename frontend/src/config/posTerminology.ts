import type { PosBusinessType } from '@/services/api/pos';

export type PosTerminology = {
  product: string;
  category: string;
  transaction: string;
  customer: string;
  cashier: string;
  table: string;
};

const terminology: Record<PosBusinessType['key'], PosTerminology> = {
  fnb: {
    product: 'Menu',
    category: 'Kategori Menu',
    transaction: 'Pesanan',
    customer: 'Pelanggan',
    cashier: 'Kasir',
    table: 'Meja',
  },
  retail: {
    product: 'Produk',
    category: 'Kategori Produk',
    transaction: 'Transaksi',
    customer: 'Pelanggan',
    cashier: 'Kasir',
    table: 'Meja',
  },
  konter_hp: {
    product: 'Produk',
    category: 'Kategori Produk',
    transaction: 'Transaksi',
    customer: 'Pelanggan',
    cashier: 'Kasir',
    table: 'Meja',
  },
  sparepart: {
    product: 'Produk',
    category: 'Kategori Produk',
    transaction: 'Transaksi',
    customer: 'Pelanggan',
    cashier: 'Kasir',
    table: 'Meja',
  },
  jasa: {
    product: 'Layanan',
    category: 'Kategori Layanan',
    transaction: 'Transaksi',
    customer: 'Klien',
    cashier: 'Kasir',
    table: 'Meja',
  },
};

export function getPosTerminology(businessType: PosBusinessType['key']): PosTerminology {
  return terminology[businessType];
}
