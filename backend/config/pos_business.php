<?php

return [
    'types' => [
        'fnb' => [
            'label' => 'F&B',
            'examples' => 'Warung, cafe, restoran',
        ],
        'retail' => [
            'label' => 'Retail',
            'examples' => 'Toko kelontong dan toko umum',
        ],
        'konter_hp' => [
            'label' => 'Konter HP',
            'examples' => 'Konter HP dan aksesori',
        ],
        'sparepart' => [
            'label' => 'Sparepart',
            'examples' => 'Toko sparepart dan bengkel',
        ],
        'jasa' => [
            'label' => 'Jasa',
            'examples' => 'Usaha jasa dan servis',
        ],
    ],

    'modules' => [
        'tables_selforder' => [
            'label' => 'Meja & pesan mandiri',
            'description' => 'Kelola meja, QR self-order, dan alur pesanan dapur.',
        ],
        'variants' => [
            'label' => 'Varian produk',
            'description' => 'Kelola pilihan produk seperti ukuran, warna, atau tipe.',
        ],
        'barcode' => [
            'label' => 'Barcode',
            'description' => 'Cari produk dan buat label menggunakan barcode.',
        ],
        'serial_imei' => [
            'label' => 'Serial/IMEI',
            'description' => 'Catat nomor serial atau IMEI setiap unit produk.',
        ],
        'warranty' => [
            'label' => 'Garansi',
            'description' => 'Simpan masa garansi dan cari riwayat klaim penjualan.',
        ],
        'price_tiers' => [
            'label' => 'Harga bertingkat',
            'description' => 'Atur harga eceran dan grosir.',
        ],
        'suppliers_purchasing' => [
            'label' => 'Supplier & pembelian',
            'description' => 'Catat supplier dan barang masuk.',
        ],
        'stock_opname' => [
            'label' => 'Stok opname',
            'description' => 'Bandingkan stok fisik dengan stok sistem.',
        ],
        'service_tickets' => [
            'label' => 'Tiket servis',
            'description' => 'Catat unit servis, keluhan, status, dan biaya pengerjaan.',
        ],
    ],

    'presets' => [
        'fnb' => ['tables_selforder'],
        'retail' => ['barcode', 'variants', 'price_tiers'],
        'konter_hp' => ['barcode', 'serial_imei', 'warranty', 'variants', 'suppliers_purchasing'],
        'sparepart' => ['barcode', 'variants', 'price_tiers', 'suppliers_purchasing', 'stock_opname'],
        'jasa' => [],
    ],
];
