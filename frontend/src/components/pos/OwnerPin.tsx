import { useEffect, useId, useRef, useState } from 'react';
import { KeyRound, Loader2, ShieldCheck, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ApiError, createPosOwnerPin } from '@/lib/hellomApi';

// Owner PIN UI for POS: the 6-digit field, the approval dialog used for void/refund (the owner
// types the PIN on the cashier's screen), and the first-visit "buat PIN" dialog for owners.

export const onlyDigits = (raw: string) => raw.replace(/\D/g, '').slice(0, 6);

export function PinField({ label, value, onChange, error, autoFocus, name, hint }: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string | null;
  autoFocus?: boolean;
  name: string;
  hint?: string;
}) {
  const id = useId();
  return (
    <label htmlFor={id} className="block text-sm font-medium text-gray-700">
      {label}
      <input
        id={id}
        name={name}
        type="password"
        inputMode="numeric"
        autoComplete="off"
        maxLength={6}
        autoFocus={autoFocus}
        value={value}
        onChange={(e) => onChange(onlyDigits(e.target.value))}
        aria-invalid={error ? true : undefined}
        placeholder="••••••"
        className={cn(
          'mt-1 min-h-14 w-full rounded-2xl border px-4 text-center text-2xl tracking-[0.6em] text-gray-900 outline-none placeholder:text-gray-300',
          error ? 'border-red-400 bg-red-50/40 focus:border-red-500' : 'border-gray-300 focus:border-gray-900'
        )}
      />
      {hint && !error && <span className="mt-1 block text-xs font-normal text-gray-500">{hint}</span>}
      {error && <span role="alert" className="mt-1 block text-xs font-normal text-red-600">{error}</span>}
    </label>
  );
}

function Shell({ titleId, onClose, busy, children }: { titleId: string; onClose?: () => void; busy: boolean; children: React.ReactNode }) {
  useEffect(() => {
    if (!onClose) return undefined;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && !busy) onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [busy, onClose]);

  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 sm:items-center sm:p-4" onClick={() => onClose && !busy && onClose()}>
      <div role="dialog" aria-modal="true" aria-labelledby={titleId} onClick={(e) => e.stopPropagation()}
        className="max-h-[92vh] w-full max-w-md overflow-y-auto rounded-t-3xl bg-white p-5 shadow-xl sm:rounded-3xl"
        style={{ paddingBottom: 'calc(1.25rem + env(safe-area-inset-bottom))' }}>
        {children}
      </div>
    </div>
  );
}

/**
 * Owner approval for a risky action. The cashier writes the reason, the owner types the PIN.
 * onSubmit throws ApiError on failure; field errors (reason / approval_pin) land on the fields.
 */
export function OwnerApprovalDialog({ title, summary, confirmLabel, reasonLabel, reasonPlaceholder, onSubmit, onClose }: {
  title: string;
  summary: React.ReactNode;
  confirmLabel: string;
  reasonLabel: string;
  reasonPlaceholder?: string;
  onSubmit: (reason: string, pin: string) => Promise<void>;
  onClose: () => void;
}) {
  const titleId = useId();
  const [reason, setReason] = useState('');
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<{ reason?: string; pin?: string; form?: string }>({});
  const reasonRef = useRef<HTMLTextAreaElement>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const next: typeof errors = {};
    if (!reason.trim()) next.reason = 'Tulis alasannya dulu.';
    if (pin.length !== 6) next.pin = 'PIN pemilik 6 angka.';
    setErrors(next);
    if (next.reason) { reasonRef.current?.focus(); return; }
    if (next.pin) return;

    setBusy(true);
    try {
      await onSubmit(reason.trim(), pin);
    } catch (err) {
      const fields = err instanceof ApiError ? err.fieldErrors : {};
      const pinError = fields.approval_pin?.[0];
      const reasonError = fields.reason?.[0];
      const message = err instanceof Error ? err.message : 'Belum berhasil. Coba lagi.';
      setErrors({ pin: pinError, reason: reasonError, form: pinError || reasonError ? undefined : message });
      if (pinError) setPin('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Shell titleId={titleId} onClose={onClose} busy={busy}>
      <form onSubmit={(e) => void submit(e)} noValidate data-approval-dialog>
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600"><ShieldCheck className="h-5 w-5" /></span>
            <div>
              <h2 id={titleId} className="text-lg font-bold text-gray-900">{title}</h2>
              <p className="text-xs text-gray-500">Perlu persetujuan PIN pemilik toko</p>
            </div>
          </div>
          <button type="button" onClick={onClose} disabled={busy} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-4 rounded-2xl bg-gray-50 p-4 text-sm text-gray-700">{summary}</div>

        <label className="mt-4 block text-sm font-medium text-gray-700">
          {reasonLabel}
          <textarea ref={reasonRef} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} rows={2}
            placeholder={reasonPlaceholder} aria-invalid={errors.reason ? true : undefined} name="reason"
            className={cn('mt-1 w-full rounded-2xl border px-4 py-3 text-base outline-none', errors.reason ? 'border-red-400 bg-red-50/40' : 'border-gray-300 focus:border-gray-900')} />
          {errors.reason && <span role="alert" className="mt-1 block text-xs font-normal text-red-600">{errors.reason}</span>}
        </label>

        <div className="mt-3">
          <PinField label="PIN pemilik toko" name="approval_pin" value={pin} onChange={setPin} error={errors.pin}
            hint="Serahkan perangkat ke pemilik untuk mengetik PIN. Tercatat di log audit." />
        </div>

        {errors.form && <p role="alert" className="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}

        <div className="mt-5 grid grid-cols-2 gap-3">
          <button type="button" onClick={onClose} disabled={busy} className="min-h-12 rounded-2xl bg-gray-100 text-base font-semibold text-gray-700 hover:bg-gray-200">Batal</button>
          <button type="submit" disabled={busy} className="flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-red-600 text-base font-bold text-white hover:bg-red-700 disabled:opacity-60">
            {busy && <Loader2 className="h-5 w-5 animate-spin" />} {confirmLabel}
          </button>
        </div>
      </form>
    </Shell>
  );
}

/** First POS visit of an owner without a PIN: must create one before using POS. */
export function OwnerPinSetupDialog({ resetEmail, onDone }: { resetEmail: string | null; onDone: () => void }) {
  const titleId = useId();
  const [pin, setPin] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<{ pin?: string; confirm?: string; form?: string }>({});

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (pin.length !== 6) { setErrors({ pin: 'PIN harus 6 angka.' }); return; }
    if (confirm !== pin) { setErrors({ confirm: 'Konfirmasi PIN belum sama.' }); return; }
    setBusy(true);
    setErrors({});
    try {
      await createPosOwnerPin(pin, confirm);
      onDone();
    } catch (err) {
      const fieldError = err instanceof ApiError ? err.fieldErrors.pin?.[0] : undefined;
      setErrors(fieldError ? { pin: fieldError } : { form: err instanceof Error ? err.message : 'PIN belum tersimpan. Coba lagi.' });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Shell titleId={titleId} busy={busy}>
      <form onSubmit={(e) => void submit(e)} noValidate data-pin-setup>
        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-700"><KeyRound className="h-6 w-6" /></span>
        <h2 id={titleId} className="mt-3 text-xl font-bold text-gray-900">Buat PIN pemilik</h2>
        <p className="mt-1 text-sm text-gray-600">
          PIN ini hanya untuk kamu sebagai pemilik toko: membuka <strong>Log Audit</strong> dan menyetujui pembatalan atau refund di kasir.
          Jangan bagikan ke kasir.
        </p>
        <div className="mt-4 space-y-3">
          <PinField label="PIN baru (6 angka)" name="pin" value={pin} onChange={setPin} error={errors.pin} autoFocus
            hint="Hindari angka sama atau berurutan seperti 111111 atau 123456." />
          <PinField label="Ulangi PIN" name="pin_confirmation" value={confirm} onChange={setConfirm} error={errors.confirm} />
        </div>
        {resetEmail && <p className="mt-3 text-xs text-gray-500">Kalau lupa, link reset dikirim ke email pemilik utama: {resetEmail}</p>}
        {errors.form && <p role="alert" className="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}
        <button type="submit" disabled={busy} className="mt-5 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gray-900 text-base font-bold text-white disabled:opacity-60">
          {busy && <Loader2 className="h-5 w-5 animate-spin" />} Simpan PIN
        </button>
      </form>
    </Shell>
  );
}
