import React, { useState } from 'react';
import {
  Wallet, ArrowRight, Banknote,
  Smartphone, CheckCircle, AlertCircle, X
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { getPayoutPolicy } from '@/lib/hellomApi';
import GatewayPaymentFrame from '@/components/GatewayPaymentFrame';

interface TopUpModalProps {
  isOpen: boolean;
  onClose: () => void;
  currentBalance: number;
  onSubmitTopUp?: (payload: { amount: number; channel: string }) => Promise<{
    referenceId: string;
    paymentUrl: string;
    channel: string;
    amount: number;
  }>;
}

const TOPUP_AMOUNTS = [50000, 100000, 250000, 500000, 1000000];

const PAYMENT_METHODS = [
  { id: 'qris', name: 'QRIS Xendit', icon: Smartphone, feeLabel: 'Sesuai channel' },
  { id: 'va', name: 'Virtual Account Xendit', icon: Banknote, feeLabel: 'Sesuai bank' },
];

export default function TopUpModal({ isOpen, onClose, currentBalance, onSubmitTopUp }: TopUpModalProps) {
  const [amount, setAmount] = useState<number | ''>('');
  const [method, setMethod] = useState<string>('qris');
  const [step, setStep] = useState<'amount' | 'payment' | 'success'>('amount');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [estimatedFee, setEstimatedFee] = useState<number | null>(null);
  const [estimatedNet, setEstimatedNet] = useState<number | null>(null);
  const [successPayload, setSuccessPayload] = useState<{
    referenceId: string;
    paymentUrl: string;
    channel: string;
    amount: number;
  } | null>(null);
  const [showFrame, setShowFrame] = useState(false);

  if (!isOpen) return null;

  const handleAmountSelect = (val: number) => {
    setAmount(val);
  };

  const handleNext = () => {
    if (amount && amount >= 10000) {
      setError(null);
      void refreshPolicyEstimate(method, amount);
      setStep('payment');
    }
  };

  const handlePay = async () => {
    if (!amount || amount < 10000) {
      setError('Nominal top up minimal Rp 10.000');
      return;
    }

    if (!onSubmitTopUp) {
      setError('Flow top up Xendit belum terhubung di halaman ini.');
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const result = await onSubmitTopUp({ amount, channel: method });
      setSuccessPayload(result);
      // Jump straight to iframe — skip the intermediate success step
      setShowFrame(true);
    } catch (payError) {
      const message = payError instanceof Error ? payError.message : 'Top up gagal';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  const handleClose = () => {
    setStep('amount');
    setAmount('');
    setEstimatedFee(null);
    setEstimatedNet(null);
    setSuccessPayload(null);
    setShowFrame(false);
    setError(null);
    onClose();
  };

  const refreshPolicyEstimate = async (nextMethod: string, nextAmount: number) => {
    // For top-ups, we don't have a proper fee estimation endpoint yet
    // For now, assume no fees for top-ups (this is more accurate than using payout fees)
    try {
      setEstimatedFee(0);
      setEstimatedNet(nextAmount);
    } catch {
      setEstimatedFee(null);
      setEstimatedNet(null);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden animate-in zoom-in-95 duration-200 relative">
        {/* Header */}
        <div className="px-6 py-4 border-b border-zinc-100 flex items-center justify-between">
          <h3 className="font-bold text-zinc-900">Top Up Wallet</h3>
          <button onClick={handleClose} className="p-2 hover:bg-zinc-100 rounded-full text-zinc-500">
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Step 1: Amount Selection */}
        {step === 'amount' && (
          <div className="p-6 space-y-6">
            <div className="bg-zinc-50 p-4 rounded-xl border border-zinc-100 text-center">
              <p className="text-xs text-zinc-500 mb-1">Saldo Wallet Saat Ini</p>
              <p className="text-2xl font-bold text-zinc-900">Rp {currentBalance.toLocaleString('id-ID')}</p>
            </div>

            <div className="space-y-3">
              <label className="text-sm font-bold text-zinc-700">Masukkan Nominal Top Up</label>
              <div className="relative">
                <span className="absolute left-4 top-3.5 text-zinc-400 font-bold">Rp</span>
                <input 
                  type="number" 
                  value={amount}
                  onChange={(e) => setAmount(parseInt(e.target.value) || '')}
                  className="w-full pl-12 pr-4 py-3 text-lg font-bold border border-zinc-200 rounded-xl focus:ring-2 focus:ring-zinc-900 outline-none"
                  placeholder="0"
                  min="10000"
                />
              </div>
              <p className="text-xs text-zinc-400 flex items-center gap-1">
                <AlertCircle className="w-3 h-3" /> Minimum top up Rp 10.000
              </p>
            </div>

            <div className="grid grid-cols-3 gap-3">
              {TOPUP_AMOUNTS.map((val) => (
                <button
                  key={val}
                  onClick={() => handleAmountSelect(val)}
                  className={cn(
                    "py-2 px-1 text-sm font-medium rounded-lg border transition-all",
                    amount === val 
                      ? "bg-zinc-900 text-white border-zinc-900" 
                      : "bg-white text-zinc-600 border-zinc-200 hover:border-zinc-400"
                  )}
                >
                  {val.toLocaleString('id-ID')}
                </button>
              ))}
            </div>

            <button 
              onClick={handleNext}
              disabled={!amount || amount < 10000}
              className="w-full py-3 bg-yellow-400 text-black font-bold rounded-xl hover:bg-yellow-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
              Lanjutkan <ArrowRight className="w-4 h-4" />
            </button>
          </div>
        )}

        {/* Step 2: Payment Method */}
        {step === 'payment' && (
          <div className="p-6 space-y-6">
            <div className="flex justify-between items-end border-b border-zinc-100 pb-4">
              <div>
                <p className="text-xs text-zinc-500">Total Top Up</p>
                <p className="text-2xl font-bold text-zinc-900">Rp {amount.toLocaleString('id-ID')}</p>
              </div>
              <button onClick={() => setStep('amount')} className="text-sm text-zinc-500 hover:text-zinc-900 underline">
                Ubah
              </button>
            </div>

            <div className="space-y-3">
              <label className="text-sm font-bold text-zinc-700">Pilih Metode Xendit</label>
              <div className="space-y-2">
                {PAYMENT_METHODS.map((pm) => (
                  <label 
                    key={pm.id}
                    className={cn(
                      "flex items-center justify-between p-4 rounded-xl border cursor-pointer transition-all",
                      method === pm.id 
                        ? "border-zinc-900 bg-zinc-50 ring-1 ring-zinc-900" 
                        : "border-zinc-200 hover:border-zinc-300"
                    )}
                  >
                    <div className="flex items-center gap-3">
                      <input 
                        type="radio" 
                        name="payment" 
                        value={pm.id} 
                        checked={method === pm.id}
                        onChange={() => {
                          setMethod(pm.id);
                          if (typeof amount === 'number' && amount > 0) {
                            void refreshPolicyEstimate(pm.id, amount);
                          }
                        }}
                        className="w-4 h-4 text-zinc-900 focus:ring-zinc-900"
                      />
                      <div className="p-2 bg-white rounded-lg border border-zinc-100">
                        <pm.icon className="w-5 h-5 text-zinc-600" />
                      </div>
                      <span className="font-medium text-zinc-900">{pm.name}</span>
                    </div>
                    <span className="text-xs font-medium text-zinc-500">
                      {pm.feeLabel}
                    </span>
                  </label>
                ))}
              </div>
            </div>

            {typeof amount === 'number' && amount > 0 && (
              <div className="text-xs p-3 rounded-lg bg-zinc-50 border border-zinc-200 space-y-1">
                <div className="flex justify-between">
                  <span className="text-zinc-500">Estimasi Fee</span>
                  <span className="font-medium text-zinc-700">
                    {estimatedFee !== null ? `Rp ${estimatedFee.toLocaleString('id-ID')}` : '-'}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-zinc-500">Estimasi Net</span>
                  <span className="font-bold text-zinc-900">
                    {estimatedNet !== null ? `Rp ${estimatedNet.toLocaleString('id-ID')}` : '-'}
                  </span>
                </div>
              </div>
            )}

            {error && (
              <div className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
                {error}
              </div>
            )}

            <button 
              onClick={handlePay}
              disabled={loading}
              className="w-full py-3 bg-zinc-900 text-white font-bold rounded-xl hover:bg-zinc-800 transition-colors flex items-center justify-center gap-2 disabled:opacity-60"
            >
              <Wallet className="w-4 h-4" /> {loading ? 'Membuat sesi Xendit...' : 'Lanjut ke Xendit'}
            </button>
          </div>
        )}

        {/* Step 3: Success — replaced by GatewayPaymentFrame, kept as fallback */}
        {step === 'success' && !showFrame && successPayload && (
          <div className="p-8 text-center space-y-5">
            <div className="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto">
              <CheckCircle className="w-8 h-8" />
            </div>
            <div>
              <h3 className="text-xl font-bold text-zinc-900 mb-1">Sesi Pembayaran Dibuat</h3>
              <p className="text-sm text-zinc-500">
                Klik tombol di bawah untuk membuka halaman pembayaran.
              </p>
            </div>
            <button
              onClick={() => setShowFrame(true)}
              className="w-full py-3 bg-yellow-400 text-black font-bold rounded-xl hover:bg-yellow-500 transition-colors"
            >
              Lanjut Pembayaran
            </button>
            <button onClick={handleClose} className="w-full py-2.5 text-sm text-zinc-500 hover:text-zinc-900">
              Kembali nanti
            </button>
          </div>
        )}
      </div>

      {/* Gateway iframe — opens above this modal */}
      {showFrame && successPayload?.paymentUrl && (
        <GatewayPaymentFrame
          paymentUrl={successPayload.paymentUrl}
          title={`Top Up Rp ${(successPayload.amount).toLocaleString('id-ID')}`}
          onClose={() => {
            setShowFrame(false);
            setStep('success');
          }}
          onPaid={() => {
            setShowFrame(false);
            handleClose();
          }}
        />
      )}
    </div>
  );
}
