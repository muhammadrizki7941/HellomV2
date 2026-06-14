import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Mail, ArrowRight, CheckCircle, Lock, KeyRound, RotateCcw } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { forgotPassword, resetPassword } from '@/lib/hellomApi';
import useBrand from '@/hooks/useBrand';

const emailSchema = z.object({
  email: z.string().email('Email tidak valid'),
});

const otpSchema = z.object({
  token: z.string().min(10, 'Token reset tidak valid'),
});

const passwordSchema = z.object({
  password: z.string().min(8, 'Password minimal 8 karakter'),
  confirmPassword: z.string(),
}).refine((data) => data.password === data.confirmPassword, {
  message: 'Password tidak cocok',
  path: ['confirmPassword'],
});

type EmailForm = z.infer<typeof emailSchema>;
type TokenForm = z.infer<typeof otpSchema>;
type PasswordForm = z.infer<typeof passwordSchema>;

const STEPS = [
  { id: 1, label: 'Email' },
  { id: 2, label: 'Token' },
  { id: 3, label: 'Password' },
];

function StepIndicator({ current }: { current: number }) {
  if (current === 4) return null;
  return (
    <div className="flex items-center justify-center gap-0 mb-8">
      {STEPS.map((s, i) => (
        <React.Fragment key={s.id}>
          <div className="flex flex-col items-center">
            <div
              className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-300"
              style={{
                backgroundColor: current >= s.id ? 'rgba(212,166,72,1)' : 'rgba(255,255,255,0.1)',
                color: current >= s.id ? '#1a1a1a' : 'rgba(255,255,255,0.4)',
                border: current === s.id ? '2px solid rgba(212,166,72,0.6)' : '2px solid transparent',
                boxShadow: current === s.id ? '0 0 0 4px rgba(212,166,72,0.15)' : 'none',
              }}
            >
              {current > s.id ? '✓' : s.id}
            </div>
            <span
              className="text-[10px] mt-1 font-medium"
              style={{ color: current >= s.id ? 'rgba(212,166,72,0.9)' : 'rgba(255,255,255,0.3)' }}
            >
              {s.label}
            </span>
          </div>
          {i < STEPS.length - 1 && (
            <div
              className="h-[2px] w-12 mx-1 mb-4 rounded-full transition-all duration-500"
              style={{
                backgroundColor: current > s.id ? 'rgba(212,166,72,0.8)' : 'rgba(255,255,255,0.1)',
              }}
            />
          )}
        </React.Fragment>
      ))}
    </div>
  );
}

// Reusable styled input field for dark card
function DarkInput({
  icon,
  error,
  ...props
}: React.InputHTMLAttributes<HTMLInputElement> & {
  icon?: React.ReactNode;
  error?: string;
}) {
  return (
    <div>
      <div className="relative">
        {icon && (
          <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
            <span style={{ color: 'rgba(255,255,255,0.3)' }}>{icon}</span>
          </div>
        )}
        <input
          {...props}
          className={[
            'block w-full py-3 pr-3 border rounded-xl text-sm transition-all outline-none',
            'placeholder:text-white/20 text-white',
            icon ? 'pl-10' : 'pl-4',
            error
              ? 'border-red-500/50 bg-red-900/10 focus:border-red-400 focus:ring-2 focus:ring-red-400/20'
              : 'border-white/10 bg-white/5 focus:border-amber-400/60 focus:ring-2 focus:ring-amber-400/10 hover:border-white/20',
          ].join(' ')}
          style={{ caretColor: '#d4a648' }}
        />
      </div>
      {error && (
        <p className="mt-1.5 text-xs text-red-400 flex items-center gap-1">
          <span className="inline-block w-1 h-1 rounded-full bg-red-400 flex-shrink-0" />
          {error}
        </p>
      )}
    </div>
  );
}

const fadeSlide = {
  initial: { opacity: 0, y: 12 },
  animate: { opacity: 1, y: 0 },
  exit: { opacity: 0, y: -8 },
  transition: { duration: 0.22, ease: 'easeOut' },
};

export default function ForgotPassword() {
  const { brand, logoSrc } = useBrand();
  const [step, setStep] = useState<1 | 2 | 3 | 4>(1);
  const [email, setEmail] = useState('');
  const [apiError, setApiError] = useState<string | null>(null);
  const [debugToken, setDebugToken] = useState<string | null>(null);

  const emailForm = useForm<EmailForm>({ resolver: zodResolver(emailSchema) });
  const tokenForm = useForm<TokenForm>({ resolver: zodResolver(otpSchema) });
  const passwordForm = useForm<PasswordForm>({ resolver: zodResolver(passwordSchema) });

  useEffect(() => {
    document.title = `Reset password | ${brand.app_name}`;
  }, [brand]);

  const onEmailSubmit = async (data: EmailForm) => {
    setApiError(null);
    try {
      const result = await forgotPassword(data.email);
      setEmail(data.email);
      setDebugToken(result.debug_reset_token ?? null);
      setStep(2);
    } catch (error) {
      setApiError(error instanceof Error ? error.message : 'Gagal mengirim reset password');
    }
  };

  const onTokenSubmit = async () => {
    setApiError(null);
    setStep(3);
  };

  const onPasswordSubmit = async (data: PasswordForm) => {
    setApiError(null);
    try {
      await resetPassword({
        email,
        token: tokenForm.getValues('token'),
        password: data.password,
        password_confirmation: data.confirmPassword,
      });
      setStep(4);
    } catch (error) {
      setApiError(error instanceof Error ? error.message : 'Gagal reset password');
    }
  };

  const primaryGold = brand.primary_color || '#d4a648';

  return (
    <div
      className="min-h-screen flex flex-col items-center justify-center px-4 py-12"
      style={{
        background: `linear-gradient(160deg, ${brand.background_color || '#0f0f0f'} 0%, ${brand.secondary_color || '#1a1200'} 100%)`,
      }}
    >
      {/* Logo */}
      <Link to="/" className="flex justify-center mb-6">
        {logoSrc ? (
          <img
            src={logoSrc}
            alt={brand.app_name}
            draggable={false}
            loading="lazy"
            className="h-10 w-auto max-w-[160px] object-contain"
          />
        ) : (
          <div
            className="h-10 w-10 rounded-xl flex items-center justify-center text-sm font-black text-white shadow-lg"
            style={{ backgroundColor: primaryGold }}
          >
            {brand.app_name.slice(0, 2).toUpperCase()}
          </div>
        )}
      </Link>

      {/* Card */}
      <div
        className="w-full max-w-sm rounded-2xl overflow-hidden"
        style={{
          background: 'rgba(255,255,255,0.04)',
          border: '1px solid rgba(255,255,255,0.09)',
          backdropFilter: 'blur(24px)',
          WebkitBackdropFilter: 'blur(24px)',
        }}
      >
        <div className="px-7 pt-7 pb-2">
          <StepIndicator current={step} />
        </div>

        <AnimatePresence mode="wait">
          {/* ── STEP 1 ── */}
          {step === 1 && (
            <motion.div key="step1" {...fadeSlide} className="px-7 pb-7">
              <div className="mb-6">
                <h1 className="text-xl font-bold text-white mb-1">Lupa password?</h1>
                <p className="text-sm" style={{ color: 'rgba(255,255,255,0.45)' }}>
                  Masukkan email kamu dan kami akan kirimkan instruksi reset.
                </p>
              </div>

              {apiError && (
                <div className="mb-4 text-xs text-red-300 bg-red-900/20 border border-red-500/20 rounded-lg px-3.5 py-2.5 flex items-start gap-2">
                  <span className="text-red-400 mt-0.5">✕</span>
                  {apiError}
                </div>
              )}

              <form onSubmit={emailForm.handleSubmit(onEmailSubmit)} className="space-y-4">
                <div>
                  <label className="block text-xs font-medium mb-1.5" style={{ color: 'rgba(255,255,255,0.55)' }}>
                    Email Address
                  </label>
                  <DarkInput
                    {...emailForm.register('email')}
                    type="email"
                    placeholder="nama@email.com"
                    icon={<Mail className="h-4 w-4" />}
                    error={emailForm.formState.errors.email?.message}
                  />
                </div>

                <button
                  type="submit"
                  disabled={emailForm.formState.isSubmitting}
                  className="w-full py-3 rounded-xl text-sm font-bold transition-all duration-200 disabled:opacity-60 active:scale-[0.98]"
                  style={{ backgroundColor: primaryGold, color: '#ffae00' }}
                >
                  {emailForm.formState.isSubmitting ? 'Mengirim...' : 'Kirim Kode Verifikasi →'}
                </button>
              </form>

              <div className="mt-6 text-center">
                <span className="text-xs" style={{ color: 'rgba(255,255,255,0.5)' }}>Sudah ingat password? </span>
                <Link
                  to="/login"
                  className="text-xs font-semibold underline underline-offset-2 transition-colors"
                  style={{ color: '#ffffff' }}
                >
                  Kembali ke Login
                </Link>
              </div>
            </motion.div>
          )}

          {/* ── STEP 2 ── */}
          {step === 2 && (
            <motion.div key="step2" {...fadeSlide} className="px-7 pb-7">
              <div className="mb-6">
                <h1 className="text-xl font-bold text-white mb-1">Masukkan token reset</h1>
                <p className="text-sm" style={{ color: 'rgba(255,255,255,0.45)' }}>
                  Token dikirim ke <span className="font-medium text-white/70">{email}</span>
                </p>
              </div>

              {apiError && (
                <div className="mb-4 text-xs text-red-300 bg-red-900/20 border border-red-500/20 rounded-lg px-3.5 py-2.5">
                  {apiError}
                </div>
              )}

              <form onSubmit={tokenForm.handleSubmit(onTokenSubmit)} className="space-y-4">
                <div>
                  <label className="block text-xs font-medium mb-1.5" style={{ color: 'rgba(255,255,255,0.55)' }}>
                    Token Reset Password
                  </label>
                  <DarkInput
                    {...tokenForm.register('token')}
                    type="text"
                    placeholder="PASTE TOKEN DARI EMAIL"
                    icon={<KeyRound className="h-4 w-4" />}
                    error={tokenForm.formState.errors.token?.message}
                    style={{ textTransform: 'uppercase', letterSpacing: '0.1em', fontFamily: 'monospace' }}
                  />
                </div>

                {debugToken && (
                  <div
                    className="rounded-xl px-3.5 py-3 text-xs"
                    style={{ background: 'rgba(255,255,255,0.05)', border: '1px solid rgba(255,255,255,0.08)' }}
                  >
                    <p className="font-semibold text-white/50 mb-1">Debug token (local only):</p>
                    <p className="font-mono break-all" style={{ color: primaryGold }}>{debugToken}</p>
                  </div>
                )}

                <button
                  type="submit"
                  disabled={tokenForm.formState.isSubmitting}
                  className="w-full py-3 rounded-xl text-sm font-bold transition-all duration-200 disabled:opacity-60 active:scale-[0.98]"
                  style={{ backgroundColor: primaryGold, color: '#ffbb00' }}
                >
                  Verifikasi →
                </button>
              </form>

              <div className="mt-5 text-center">
                <span className="text-xs" style={{ color: 'rgba(255,255,255,0.35)' }}>Belum menerima email? </span>
                <button
                  type="button"
                  onClick={() => { setApiError(null); setStep(1); }}
                  className="text-xs font-semibold inline-flex items-center gap-1 transition-colors"
                  style={{ color: primaryGold }}
                >
                  <RotateCcw className="h-3 w-3" /> Kirim Ulang
                </button>
              </div>
            </motion.div>
          )}

          {/* ── STEP 3 ── */}
          {step === 3 && (
            <motion.div key="step3" {...fadeSlide} className="px-7 pb-7">
              <div className="mb-6">
                <h1 className="text-xl font-bold text-white mb-1">Buat password baru</h1>
                <p className="text-sm" style={{ color: 'rgba(255, 145, 0, 0.83)' }}>
                  Password baru harus berbeda dari sebelumnya.
                </p>
              </div>

              {apiError && (
                <div className="mb-4 text-xs text-red-300 bg-red-900/20 border border-red-500/20 rounded-lg px-3.5 py-2.5">
                  {apiError}
                </div>
              )}

              <form onSubmit={passwordForm.handleSubmit(onPasswordSubmit)} className="space-y-4">
                <div>
                  <label className="block text-xs font-medium mb-1.5" style={{ color: 'rgba(255,255,255,0.55)' }}>
                    Password Baru
                  </label>
                  <DarkInput
                    {...passwordForm.register('password')}
                    type="password"
                    placeholder="Minimal 8 karakter"
                    icon={<Lock className="h-4 w-4" />}
                    error={passwordForm.formState.errors.password?.message}
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium mb-1.5" style={{ color: 'rgba(255,255,255,0.55)' }}>
                    Konfirmasi Password
                  </label>
                  <DarkInput
                    {...passwordForm.register('confirmPassword')}
                    type="password"
                    placeholder="Ulangi password"
                    icon={<Lock className="h-4 w-4" />}
                    error={passwordForm.formState.errors.confirmPassword?.message}
                  />
                </div>

                <button
                  type="submit"
                  disabled={passwordForm.formState.isSubmitting}
                  className="w-full py-3 rounded-xl text-sm font-bold transition-all duration-200 disabled:opacity-60 active:scale-[0.98]"
                  style={{ backgroundColor: primaryGold, color: '#ffc400' }}
                >
                  {passwordForm.formState.isSubmitting ? 'Menyimpan...' : 'Reset Password →'}
                </button>
              </form>
            </motion.div>
          )}

          {/* ── STEP 4 ── */}
          {step === 4 && (
            <motion.div key="step4" {...fadeSlide} className="px-7 pb-7 text-center">
              <motion.div
                initial={{ scale: 0, opacity: 0 }}
                animate={{ scale: 1, opacity: 1 }}
                transition={{ type: 'spring', stiffness: 260, damping: 20, delay: 0.1 }}
                className="mx-auto mb-5 flex items-center justify-center h-16 w-16 rounded-full"
                style={{ background: 'rgba(34,197,94,0.12)', border: '1.5px solid rgba(34,197,94,0.3)' }}
              >
                <CheckCircle className="h-8 w-8 text-emerald-400" />
              </motion.div>
              <h1 className="text-xl font-bold text-white mb-2">Password berhasil diubah!</h1>
              <p className="text-sm mb-7" style={{ color: 'rgba(255,255,255,0.45)' }}>
                Silakan login kembali menggunakan password baru kamu.
              </p>
              <Link
                to="/login"
                className="w-full flex justify-center items-center gap-2 py-3 px-4 rounded-xl text-sm font-bold transition-all duration-200 active:scale-[0.98]"
                style={{ backgroundColor: primaryGold, color: '#111111' }}
              >
                Login Sekarang <ArrowRight className="w-4 h-4" />
              </Link>
            </motion.div>
          )}
        </AnimatePresence>
      </div>

      <p className="mt-6 text-center text-xs" style={{ color: 'rgba(255,255,255,0.25)' }}>
        {brand.footer_text}
      </p>
    </div>
  );
}