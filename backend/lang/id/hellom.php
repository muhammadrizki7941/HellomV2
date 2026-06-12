<?php

return [
    // ─── Auth ───
    'unauthorized' => 'Tidak berwenang',
    'invalid_credentials' => 'Kredensial tidak valid',
    'registered' => 'Terdaftar',
    'logged_in' => 'Berhasil masuk',
    'logged_out' => 'Berhasil keluar',
    'forgot_password_sent' => 'Jika akun ada, instruksi reset telah dikirim',
    'password_reset_success' => 'Password berhasil direset',
    'password_reset_failed' => 'Reset password gagal',
    'profile_updated' => 'Profil diperbarui',
    'password_changed' => 'Password diubah',
    'invalid_current_password' => 'Password saat ini salah',

    // ─── Organization ───
    'no_active_organization' => 'Tidak ada organisasi aktif',
    'organization_created' => 'Organisasi dibuat',
    'organization_switched' => 'Organisasi diganti',
    'organization_not_found' => 'Organisasi tidak ditemukan',

    // ─── Team ───
    'member_invited' => 'Anggota diundang',
    'member_role_updated' => 'Peran anggota diperbarui',
    'member_removed' => 'Anggota dihapus',
    'already_member' => 'Pengguna sudah menjadi anggota organisasi ini',
    'user_not_found' => 'Pengguna tidak ditemukan. Pengguna harus daftar terlebih dahulu.',
    'insufficient_role' => 'Anda tidak memiliki peran yang cukup untuk tindakan ini',
    'owner_role_locked' => 'Peran pemilik tidak dapat diubah melalui endpoint ini',
    'cannot_remove_self' => 'Anda tidak dapat menghapus diri sendiri dari organisasi saat ini melalui endpoint ini',

    // ─── Billing ───
    'wallet_topup_success' => 'Top-up dompet berhasil',
    'checkout_confirmed' => 'Checkout dikonfirmasi',
    'intent_not_found' => 'Intent checkout tidak ditemukan',
    'insufficient_wallet_balance' => 'Saldo dompet tidak mencukupi untuk checkout',
    'subscription_renewed' => 'Langganan diperpanjang',
    'subscription_cancelled' => 'Langganan dibatalkan',

    // ─── Landing Builder ───
    'page_created' => 'Halaman dibuat',
    'page_updated' => 'Halaman diperbarui',
    'page_deleted' => 'Halaman dihapus',
    'page_published' => 'Halaman dipublikasikan',
    'page_unpublished' => 'Halaman diunpublikasikan',
    'page_duplicated' => 'Halaman diduplikasi',
    'template_applied' => 'Template diterapkan',

    // ─── File Assets ───
    'file_uploaded' => 'File aset berhasil diunggah',
    'file_duplicate_reused' => 'File duplikat — menggunakan aset yang sudah ada',
    'storage_quota_exceeded' => 'Kuota penyimpanan organisasi terlampaui (100 MB)',

    // ─── Super Admin ───
    'admin_only' => 'Tindakan ini memerlukan hak akses super admin',
    'org_suspended' => 'Organisasi ditangguhkan',
    'org_reactivated' => 'Organisasi diaktifkan kembali',
    'user_suspended' => 'Pengguna ditangguhkan',
    'user_reactivated' => 'Pengguna diaktifkan kembali',
    'app_updated' => 'Aplikasi diperbarui',
    'plan_updated' => 'Paket diperbarui',
    'plan_created' => 'Paket dibuat',
    'plan_deleted' => 'Paket dihapus',
    'entitlement_overridden' => 'Entitlement di-override',

    // ─── Purchase Settings ───
    'purchase_setting_created' => 'Pengaturan pembelian dibuat',
    'purchase_setting_updated' => 'Pengaturan pembelian diperbarui',
    'purchase_setting_deleted' => 'Pengaturan pembelian dihapus',
    'service_type_exists' => 'Jenis layanan sudah ada',
    'setting_not_found' => 'Pengaturan tidak ditemukan',

    // ─── Promo ───
    'promo_created' => 'Kampanye promo dibuat',
    'promo_updated' => 'Kampanye promo diperbarui',
    'promo_deleted' => 'Kampanye promo dihapus',
    'promo_invalid' => 'Kode promo tidak valid atau sudah kedaluwarsa',
    'promo_max_slots_reached' => 'Promo ini sudah mencapai batas maksimal',

    // ─── Locale ───
    'locale_switched' => 'Bahasa diganti',
];
