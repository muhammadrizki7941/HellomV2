import { Layout, Smartphone, ShoppingCart } from 'lucide-react';

export const APPS_DATA = [
  {
    id: 'app_1',
    name: 'Landing Page Builder',
    path: '/dashboard/apps/landing-builder',
    icon: Layout,
    isLocked: false,
    description: 'Buat halaman promosi profesional tanpa koding.',
    version: 'v1.0.0'
  },
  {
    id: 'app_2',
    name: 'WhatsApp CRM',
    path: '/dashboard/apps/whatsapp-crm',
    icon: Smartphone,
    isLocked: true,
    description: 'Kelola pelanggan dan broadcast pesan otomatis.',
    version: 'v2.4.0'
  },
  {
    id: 'app_3',
    name: 'E-Commerce Store',
    path: '/dashboard/apps/ecommerce',
    icon: ShoppingCart,
    isLocked: true,
    description: 'Toko online lengkap dengan sistem pembayaran otomatis.',
    version: 'v1.2.0'
  }
];

export const PROMOS = [
  {
    id: 1,
    title: 'Diskon Spesial 50%',
    description: 'Gunakan kode HELLOM50 untuk pembelian WhatsApp CRM tahunan. Berakhir dalam 2 hari!',
    type: 'offer'
  },
  {
    id: 2,
    title: 'Maintenance Jadwal',
    description: 'Sistem akan maintenance pada tanggal 5 Maret pukul 00:00 - 02:00 WIB.',
    type: 'info'
  }
];

export const PURCHASE_HISTORY = [
  {
    id: 'INV-001',
    date: '2024-03-01',
    item: 'Landing Page Builder (Monthly)',
    amount: 0,
    status: 'Active',
    nextBilling: '2024-04-01'
  },
  {
    id: 'INV-002',
    date: '2024-02-15',
    item: 'Wallet Deposit',
    amount: 500000,
    status: 'Success',
    nextBilling: '-'
  }
];
