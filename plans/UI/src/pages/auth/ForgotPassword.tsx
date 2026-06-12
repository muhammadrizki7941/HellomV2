import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Mail, ArrowRight, CheckCircle, Lock } from 'lucide-react';
import { motion } from 'framer-motion';
import { forgotPassword, resetPassword } from '@/lib/hellomApi';
import useBrand from '@/hooks/useBrand';

// Step 1: Email Request Schema
const emailSchema = z.object({
  email: z.string().email('Email tidak valid'),
});

// Step 2: OTP Verification Schema
const otpSchema = z.object({
  token: z.string().min(10, 'Token reset tidak valid'),
});

// Step 3: New Password Schema
const passwordSchema = z.object({
  password: z.string().min(8, 'Password minimal 8 karakter'),
  confirmPassword: z.string()
}).refine((data) => data.password === data.confirmPassword, {
  message: "Password tidak cocok",
  path: ["confirmPassword"],
});

type EmailForm = z.infer<typeof emailSchema>;
type TokenForm = z.infer<typeof otpSchema>;
type PasswordForm = z.infer<typeof passwordSchema>;

export default function ForgotPassword() {
  const { brand, logoSrc } = useBrand();
  const [step, setStep] = useState<1 | 2 | 3 | 4>(1); // 1: Email, 2: OTP, 3: New Password, 4: Success
  const [email, setEmail] = useState('');
  const [apiError, setApiError] = useState<string | null>(null);
  const [debugToken, setDebugToken] = useState<string | null>(null);

  // Forms
  const emailForm = useForm<EmailForm>({ resolver: zodResolver(emailSchema) });
  const tokenForm = useForm<TokenForm>({ resolver: zodResolver(otpSchema) });
  const passwordForm = useForm<PasswordForm>({ resolver: zodResolver(passwordSchema) });

  useEffect(() => {
    document.title = `Reset password | ${brand.app_name}`;
  }, [brand]);

  // Handlers
  const onEmailSubmit = async (data: EmailForm) => {
    setApiError(null);
    try {
      const result = await forgotPassword(data.email);
      setEmail(data.email);
      setDebugToken(result.debug_reset_token ?? null);
      setStep(2);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Gagal mengirim reset password';
      setApiError(message);
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
      const message = error instanceof Error ? error.message : 'Gagal reset password';
      setApiError(message);
    }
  };

  return (
    <div
      className="min-h-screen flex flex-col justify-center py-12 sm:px-6 lg:px-8"
      style={{
        background: `linear-gradient(135deg, ${brand.background_color} 0%, ${brand.secondary_color} 100%)`,
      }}
    >
      <div className="sm:mx-auto sm:w-full sm:max-w-md">
        <Link to="/" className="flex justify-center mb-6">
          {logoSrc ? (
            <img src={logoSrc} alt={brand.app_name} draggable={false} loading="lazy" className="h-12 w-auto max-w-[180px] object-contain" />
          ) : (
            <div className="flex h-12 w-12 items-center justify-center rounded-xl text-sm font-black text-white shadow-lg" style={{ backgroundColor: brand.primary_color }}>
              {brand.app_name.slice(0, 2).toUpperCase()}
            </div>
          )}
        </Link>
        
        {step === 1 && (
          <>
            <h2 className="text-center text-3xl font-bold tracking-tight text-white">{brand.login_title}</h2>
            <p className="mt-2 text-center text-sm text-zinc-600">
              {brand.login_subtitle}
            </p>
          </>
        )}
        {step === 2 && (
          <>
            <h2 className="text-center text-3xl font-bold tracking-tight text-white">Masukkan Token Reset</h2>
            <p className="mt-2 text-center text-sm text-zinc-200">
              Kami telah mengirim instruksi reset ke <strong>{email}</strong>
            </p>
          </>
        )}
        {step === 3 && (
          <>
            <h2 className="text-center text-3xl font-bold tracking-tight text-white">Buat Password Baru</h2>
            <p className="mt-2 text-center text-sm text-zinc-200">
              Password baru harus berbeda dari password sebelumnya.
            </p>
          </>
        )}
        {step === 4 && (
          <>
            <h2 className="text-center text-3xl font-bold tracking-tight text-white">Berhasil!</h2>
            <p className="mt-2 text-center text-sm text-zinc-200">
              Password Anda telah berhasil diubah. Silakan login kembali.
            </p>
          </>
        )}
      </div>

      <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
        <div className="bg-white py-8 px-4 shadow-xl shadow-zinc-200/50 sm:rounded-xl sm:px-10 border border-zinc-100">
          
          {apiError && (
            <div className="mb-4 text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
              {apiError}
            </div>
          )}

          {/* STEP 1: EMAIL INPUT */}
          {step === 1 && (
            <form onSubmit={emailForm.handleSubmit(onEmailSubmit)} className="space-y-6">
              <div>
                <label htmlFor="email" className="block text-sm font-medium text-zinc-700">Email Address</label>
                <div className="mt-1 relative">
                  <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <Mail className="h-5 w-5 text-zinc-400" />
                  </div>
                  <input
                    {...emailForm.register('email')}
                    type="email"
                    className="block w-full pl-10 pr-3 py-3 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 transition-colors"
                    placeholder="nama@email.com"
                  />
                </div>
                {emailForm.formState.errors.email && (
                  <p className="mt-1 text-sm text-red-600">{emailForm.formState.errors.email.message}</p>
                )}
              </div>

              <button
                type="submit"
                disabled={emailForm.formState.isSubmitting}
                className="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white focus:outline-none focus:ring-2 focus:ring-offset-2 transition-all disabled:opacity-70"
                style={{ backgroundColor: brand.primary_color }}
              >
                {emailForm.formState.isSubmitting ? 'Mengirim...' : 'Kirim Kode Verifikasi'}
              </button>
            </form>
          )}

          {/* STEP 2: TOKEN INPUT */}
          {step === 2 && (
            <form onSubmit={tokenForm.handleSubmit(onTokenSubmit)} className="space-y-6">
              <div>
                <label htmlFor="token" className="block text-sm font-medium text-zinc-700">Token Reset Password</label>
                <input
                  {...tokenForm.register('token')}
                  type="text"
                  className="mt-1 block w-full text-center text-sm tracking-[0.2em] font-mono py-3 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 transition-colors uppercase"
                  placeholder="PASTE TOKEN DARI EMAIL"
                />
                {tokenForm.formState.errors.token && (
                  <p className="mt-1 text-sm text-red-600">{tokenForm.formState.errors.token.message}</p>
                )}
              </div>

              {debugToken && (
                <div className="text-xs p-3 rounded-lg bg-zinc-100 border border-zinc-200">
                  <p className="font-bold text-zinc-700 mb-1">Debug token (local only):</p>
                  <p className="font-mono break-all text-zinc-600">{debugToken}</p>
                </div>
              )}

              <div className="text-center text-sm">
                <span className="text-zinc-500">Belum menerima email? </span>
                <button
                  type="button"
                  onClick={() => setStep(1)}
                  className="font-medium"
                  style={{ color: brand.accent_color }}
                >
                  Kirim Ulang
                </button>
              </div>

              <button
                type="submit"
                disabled={tokenForm.formState.isSubmitting}
                className="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white focus:outline-none focus:ring-2 focus:ring-offset-2 transition-all disabled:opacity-70"
                style={{ backgroundColor: brand.primary_color }}
              >
                Verifikasi
              </button>
            </form>
          )}

          {/* STEP 3: NEW PASSWORD */}
          {step === 3 && (
            <form onSubmit={passwordForm.handleSubmit(onPasswordSubmit)} className="space-y-6">
              <div>
                <label className="block text-sm font-medium text-zinc-700">Password Baru</label>
                <div className="mt-1 relative">
                  <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <Lock className="h-5 w-5 text-zinc-400" />
                  </div>
                  <input
                    {...passwordForm.register('password')}
                    type="password"
                    className="block w-full pl-10 pr-3 py-3 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 transition-colors"
                    placeholder="••••••••"
                  />
                </div>
                {passwordForm.formState.errors.password && (
                  <p className="mt-1 text-sm text-red-600">{passwordForm.formState.errors.password.message}</p>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium text-zinc-700">Konfirmasi Password</label>
                <div className="mt-1 relative">
                  <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <Lock className="h-5 w-5 text-zinc-400" />
                  </div>
                  <input
                    {...passwordForm.register('confirmPassword')}
                    type="password"
                    className="block w-full pl-10 pr-3 py-3 border border-zinc-300 rounded-lg focus:ring-yellow-400 focus:border-yellow-400 transition-colors"
                    placeholder="••••••••"
                  />
                </div>
                {passwordForm.formState.errors.confirmPassword && (
                  <p className="mt-1 text-sm text-red-600">{passwordForm.formState.errors.confirmPassword.message}</p>
                )}
              </div>

              <button
                type="submit"
                disabled={passwordForm.formState.isSubmitting}
                className="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white focus:outline-none focus:ring-2 focus:ring-offset-2 transition-all disabled:opacity-70"
                style={{ backgroundColor: brand.primary_color }}
              >
                Reset Password
              </button>
            </form>
          )}

          {/* STEP 4: SUCCESS */}
          {step === 4 && (
            <div className="text-center py-8">
              <motion.div 
                initial={{ scale: 0 }}
                animate={{ scale: 1 }}
                className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-6"
              >
                <CheckCircle className="h-8 w-8 text-green-600" />
              </motion.div>
              <Link
                to="/login"
                className="w-full flex justify-center items-center gap-2 py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-zinc-900 hover:bg-zinc-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-zinc-900 transition-all"
                style={{ backgroundColor: brand.primary_color }}
              >
                Login Sekarang <ArrowRight className="w-4 h-4" />
              </Link>
            </div>
          )}

          {step === 1 && (
            <div className="mt-6">
              <div className="relative">
                <div className="absolute inset-0 flex items-center">
                  <div className="w-full border-t border-zinc-200" />
                </div>
                <div className="relative flex justify-center text-sm">
                  <span className="px-2 bg-white text-zinc-500">Sudah ingat password?</span>
                </div>
              </div>
              <div className="mt-6 text-center">
                <Link to="/login" className="font-medium text-zinc-900 hover:text-zinc-700">
                  Kembali ke Login
                </Link>
              </div>
            </div>
          )}
        </div>
        <p className="mt-5 text-center text-xs text-white/80">{brand.footer_text}</p>
      </div>
    </div>
  );
}
