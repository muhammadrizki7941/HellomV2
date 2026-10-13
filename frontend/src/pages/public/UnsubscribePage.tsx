import { useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { MailCheck, MailX } from 'lucide-react';
import { AuthShell, authPrimaryButton } from '@/components/auth/AuthShell';

/**
 * After the "Berhenti berlangganan" link in an info/tips email (the API already saved the
 * choice and redirected here with ?status=ok|invalid). Receipts, password resets and
 * withdrawal emails keep arriving.
 */
export default function UnsubscribePage() {
  const [searchParams] = useSearchParams();
  const ok = searchParams.get('status') === 'ok';

  useEffect(() => {
    document.title = 'Berhenti berlangganan | Hellom';
  }, []);

  return (
    <AuthShell>
      <div className="space-y-4 text-center">
        <div className={`mx-auto flex h-12 w-12 items-center justify-center rounded-2xl ${ok ? 'bg-[#F6B400] text-black' : 'bg-white/10 text-white'}`}>
          {ok ? <MailCheck className="h-6 w-6" /> : <MailX className="h-6 w-6" />}
        </div>
        <h1 className="text-2xl font-black tracking-tight">{ok ? 'Kamu sudah berhenti berlangganan' : 'Tautan tidak valid'}</h1>
        <p className="text-sm leading-6 text-white/70">
          {ok
            ? 'Kami tidak akan mengirim email info & tips lagi. Email penting seperti struk pembayaran, reset kata sandi, dan status penarikan dana tetap dikirim.'
            : 'Tautan ini sudah rusak atau tidak lengkap. Buka lagi tautan "Berhenti berlangganan" di email terbaru dari kami.'}
        </p>
        <Link to="/" className={`${authPrimaryButton} inline-flex justify-center`}>
          Ke beranda
        </Link>
      </div>
    </AuthShell>
  );
}
