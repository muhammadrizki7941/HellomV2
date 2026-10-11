import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, KeyRound, Loader2 } from 'lucide-react';
import { ApiError, getPosPinReset, resetPosOwnerPin } from '@/lib/hellomApi';
import { PinField } from '@/components/pos/OwnerPin';

/**
 * Link from the "Reset PIN POS" email (/reset-pin-pos?token=…), sent only to the shop's primary
 * owner. Sets a new owner PIN; the link works once and for 30 minutes.
 */
export default function PosPinResetPage() {
  const [searchParams] = useSearchParams();
  const token = (searchParams.get('token') || '').trim();
  const [shop, setShop] = useState<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(token ? null : 'Link reset tidak lengkap. Buka lagi tombol di email.');
  const [pin, setPin] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<{ pin?: string; confirm?: string; form?: string }>({});
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    document.title = 'Reset PIN POS | Hellom';
    if (!token) return;
    getPosPinReset(token).then((info) => setShop(info.organization)).catch((err) => setLoadError(err instanceof Error ? err.message : 'Link tidak bisa dibuka.'));
  }, [token]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (pin.length !== 6) { setErrors({ pin: 'PIN harus 6 angka.' }); return; }
    if (confirm !== pin) { setErrors({ confirm: 'Konfirmasi PIN belum sama.' }); return; }
    setBusy(true);
    setErrors({});
    try {
      await resetPosOwnerPin(token, pin, confirm);
      setDone(true);
    } catch (err) {
      const fieldError = err instanceof ApiError ? err.fieldErrors.pin?.[0] : undefined;
      setErrors(fieldError ? { pin: fieldError } : { form: err instanceof Error ? err.message : 'PIN belum tersimpan.' });
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-gray-50 p-4">
      <div className="w-full max-w-sm rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
        {loadError ? (
          <>
            <h1 className="text-xl font-bold text-gray-900">Link tidak bisa dipakai</h1>
            <p className="mt-2 text-sm text-gray-600">{loadError}</p>
            <Link to="/pos/audit" className="mt-5 flex min-h-12 items-center justify-center rounded-2xl bg-gray-900 font-bold text-white">Ke POS</Link>
          </>
        ) : !shop ? (
          <div className="flex justify-center p-6"><Loader2 className="h-6 w-6 animate-spin text-gray-400" /></div>
        ) : done ? (
          <div data-pin-reset-done>
            <CheckCircle2 className="h-12 w-12 text-emerald-600" />
            <h1 className="mt-3 text-xl font-bold text-gray-900">PIN baru tersimpan</h1>
            <p className="mt-1 text-sm text-gray-600">Pakai PIN baru untuk membuka Log Audit dan menyetujui pembatalan di POS {shop}.</p>
            <Link to="/pos/audit" className="mt-5 flex min-h-12 items-center justify-center rounded-2xl bg-gray-900 font-bold text-white">Buka POS</Link>
          </div>
        ) : (
          <form onSubmit={(e) => void submit(e)} noValidate data-pin-reset>
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-700"><KeyRound className="h-6 w-6" /></span>
            <h1 className="mt-3 text-xl font-bold text-gray-900">Buat PIN baru</h1>
            <p className="mt-1 text-sm text-gray-600">PIN pemilik untuk POS <strong>{shop}</strong>. PIN lama tidak berlaku lagi setelah ini.</p>
            <div className="mt-4 space-y-3">
              <PinField label="PIN baru (6 angka)" name="pin" value={pin} onChange={setPin} error={errors.pin} autoFocus
                hint="Hindari angka sama atau berurutan." />
              <PinField label="Ulangi PIN" name="pin_confirmation" value={confirm} onChange={setConfirm} error={errors.confirm} />
            </div>
            {errors.form && <p role="alert" className="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}
            <button type="submit" disabled={busy} className="mt-5 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gray-900 font-bold text-white disabled:opacity-60">
              {busy && <Loader2 className="h-5 w-5 animate-spin" />} Simpan PIN
            </button>
          </form>
        )}
      </div>
    </main>
  );
}
