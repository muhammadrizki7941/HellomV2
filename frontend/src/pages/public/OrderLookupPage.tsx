import { useEffect, useState } from 'react';
import { Loader2, Search } from 'lucide-react';
import { EMAIL_PATTERN } from '@/lib/emailTypo';
import { lookupLandingOrder } from '@/lib/hellomApi';

// "Cek pesanan saya" (/cek-pesanan): the buyer gets the access link again by email.
// The answer never says whether the order exists (no guessing other people's orders).
export default function OrderLookupPage() {
  const [email, setEmail] = useState('');
  const [reference, setReference] = useState('');
  const [sending, setSending] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { document.title = 'Cek pesanan · Hellom'; }, []);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setMessage(null);
    if (!EMAIL_PATTERN.test(email.trim()) || reference.trim().length < 6) {
      setError('Isi email dan nomor pesanan (contoh: lps_ABC123…).');
      return;
    }
    setSending(true);
    try {
      await lookupLandingOrder(email.trim(), reference.trim());
      setMessage('Kalau data cocok dengan pesanan yang sudah lunas, link akses sudah kami kirim ke email kamu. Cek juga folder Spam/Promosi.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Belum bisa dicek. Coba lagi.');
    } finally {
      setSending(false);
    }
  };

  return (
    <main className="min-h-[100svh] bg-zinc-50 px-4 py-10 text-zinc-900">
      <form onSubmit={submit} noValidate className="mx-auto max-w-md space-y-4 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-zinc-100">
        <div>
          <h1 className="text-xl font-bold">Cek pesanan saya</h1>
          <p className="mt-1 text-sm leading-6 text-zinc-500">Kehilangan email pembelian? Masukkan email dan nomor pesanan, kami kirim ulang link aksesnya.</p>
        </div>
        <label className="block text-sm font-medium">
          Email saat membeli
          <input type="email" inputMode="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} className="mt-1 min-h-12 w-full rounded-xl border border-zinc-300 px-3 text-base outline-none focus:border-zinc-900" />
        </label>
        <label className="block text-sm font-medium">
          Nomor pesanan
          <input value={reference} onChange={(e) => setReference(e.target.value.trim())} placeholder="lps_…" className="mt-1 min-h-12 w-full rounded-xl border border-zinc-300 px-3 font-mono text-base outline-none focus:border-zinc-900" />
          <span className="mt-1 block text-xs font-normal text-zinc-500">Ada di email/invoice atau halaman setelah membayar.</span>
        </label>
        {error && <p className="text-sm text-rose-600">{error}</p>}
        {message && <p role="status" className="rounded-2xl bg-emerald-50 p-3 text-sm text-emerald-800">{message}</p>}
        <button type="submit" disabled={sending} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 font-bold text-white disabled:opacity-50">
          {sending ? <Loader2 className="h-5 w-5 animate-spin" /> : <Search className="h-5 w-5" />} Kirim link akses
        </button>
      </form>
    </main>
  );
}
