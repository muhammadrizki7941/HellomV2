<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Create the VAPID key pair for web push. Prints the lines to put in .env (never writes the file):
 * WEB_PUSH_VAPID_PUBLIC, WEB_PUSH_VAPID_PRIVATE, WEB_PUSH_SUBJECT. Generate once — new keys
 * invalidate every saved browser subscription.
 */
class WebPushVapidCommand extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Buat pasangan kunci VAPID untuk notifikasi push (salin ke .env)';

    public function handle(): int
    {
        if (filled(config('services.webpush.public_key'))) {
            $this->warn('Kunci VAPID sudah terpasang. Kunci baru membuat semua browser harus mengaktifkan notifikasi lagi.');
            if (!$this->confirm('Tetap buat kunci baru?', false)) {
                return self::SUCCESS;
            }
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->error('Gagal membuat kunci: ' . $e->getMessage());
            $this->line('Windows: set OPENSSL_CONF ke openssl.cnf PHP (mis. C:\\xampp\\php\\extras\\openssl\\openssl.cnf).');

            return self::FAILURE;
        }

        $this->info('Tambahkan ke .env lalu jalankan php artisan config:cache:');
        $this->newLine();
        $this->line('WEB_PUSH_VAPID_PUBLIC=' . $keys['publicKey']);
        $this->line('WEB_PUSH_VAPID_PRIVATE=' . $keys['privateKey']);
        $this->line('WEB_PUSH_SUBJECT=mailto:halo@hellomspace.com');

        return self::SUCCESS;
    }
}
