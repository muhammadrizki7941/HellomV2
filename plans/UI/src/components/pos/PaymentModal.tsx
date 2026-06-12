import React, { useEffect, useState } from 'react';
import { Send } from 'lucide-react';
import { confirmPosOrderPayment } from '@/lib/hellomApi';

interface PaymentModalOrder {
  id: number;
  order_number: string;
  total_amount: number;
  final_amount?: number;
}

interface PaymentModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: (paymentData: any) => void;
  order: PaymentModalOrder | null;
}

const PaymentModal = ({ isOpen, onClose, onSuccess, order }: PaymentModalProps) => {
  const [method, setMethod] = useState<'cash' | 'transfer' | 'qris'>('cash');
  const [payAmount, setPayAmount] = useState('');
  const [payNote, setPayNote] = useState('');
  const [isProcessing, setIsProcessing] = useState(false);

  useEffect(() => {
    if (!isOpen || !order) {
      return;
    }

    setMethod('cash');
    setPayAmount('');
    setPayNote('');
  }, [isOpen, order]);

  useEffect(() => {
    if (!order) {
      return;
    }

    const amount = order.final_amount ?? order.total_amount ?? 0;
    if (method !== 'cash' && amount > 0) {
      setPayAmount(String(amount));
      return;
    }

    setPayAmount('');
  }, [method, order]);

  if (!isOpen || !order) return null;

  const totalToPay = order.final_amount ?? order.total_amount ?? 0;
  const payAmountNum = parseInt(payAmount.replace(/\D/g, ''), 10) || 0;
  const change = Math.max(0, payAmountNum - totalToPay);
  const isShort = method === 'cash' && payAmountNum > 0 && payAmountNum < totalToPay;

  const formatNumber = (num: number) => num.toLocaleString('id-ID');

  const quickAmounts = [
    totalToPay,
    Math.ceil(totalToPay / 5000) * 5000,
    Math.ceil(totalToPay / 10000) * 10000,
    Math.ceil(totalToPay / 20000) * 20000,
    50000,
    100000,
  ]
    .filter((value, index, arr) => value >= totalToPay && arr.indexOf(value) === index)
    .slice(0, 4);

  const handleConfirm = async () => {
    setIsProcessing(true);
    try {
      const res = await confirmPosOrderPayment(order.id, {
        payment_method: method,
        payment_amount: payAmountNum || totalToPay,
        payment_note: payNote || null,
      });
      onSuccess(res);
    } catch {
      alert('Gagal konfirmasi pembayaran, coba lagi ya');
    } finally {
      setIsProcessing(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/55 p-4 pb-24 md:items-center md:pb-4">
      <div
        className="max-h-[75vh] w-full overflow-hidden rounded-t-3xl bg-white shadow-2xl md:max-w-md md:rounded-2xl md:max-h-[88vh]"
        style={{ paddingBottom: 'max(env(safe-area-inset-bottom), 0px)' }}
      >
        <div className="border-b border-amber-100 bg-gradient-to-r from-amber-50 via-yellow-50 to-white px-6 py-4">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-bold text-zinc-900">Payment Confirmation</h2>
            <button onClick={onClose} className="text-zinc-400 transition hover:text-amber-700">
              x
            </button>
          </div>
          <div className="mt-1 text-sm font-medium text-zinc-500">{order.order_number}</div>
        </div>

        <div className="max-h-[calc(88vh-84px)] overflow-y-auto">
          <div className="space-y-5 p-6 pb-28">
          <div className="rounded-3xl border border-amber-100 bg-gradient-to-br from-amber-50 via-yellow-50 to-white p-4 text-center shadow-sm">
            <div className="mb-1 text-sm font-semibold text-amber-700">Total Payment</div>
            <div className="text-3xl font-black tracking-tight text-amber-900">Rp {formatNumber(totalToPay)}</div>
          </div>

          <div>
            <label className="mb-2 block text-sm font-semibold text-zinc-700">Payment Method</label>
            <div className="grid grid-cols-3 gap-2">
              {[
                { key: 'cash', icon: '💵', label: 'Cash' },
                { key: 'transfer', icon: '🏦', label: 'Transfer' },
                { key: 'qris', icon: '📱', label: 'QRIS' },
              ].map((item) => (
                <button
                  key={item.key}
                  onClick={() => setMethod(item.key as 'cash' | 'transfer' | 'qris')}
                  className={`flex flex-col items-center gap-1 rounded-xl py-3 text-sm font-semibold transition ${
                    method === item.key
                      ? 'bg-amber-400 text-zinc-950 shadow-md shadow-amber-400/30'
                      : 'bg-zinc-100 text-zinc-600 hover:bg-amber-50 hover:text-amber-900'
                  }`}
                >
                  <span className="text-sm">{item.icon}</span>
                  {item.label}
                </button>
              ))}
            </div>
          </div>

          {method === 'cash' && (
            <div>
              <label className="mb-2 block text-sm font-semibold text-zinc-700">Amount Received</label>

              <div className="mb-3 flex flex-wrap gap-2">
                {quickAmounts.map((amount) => (
                  <button
                    key={amount}
                    onClick={() => setPayAmount(String(amount))}
                    className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
                      payAmountNum === amount
                        ? 'border-amber-400 bg-amber-400 text-zinc-950'
                        : 'border-zinc-300 bg-white text-zinc-600 hover:border-amber-300 hover:bg-amber-50'
                    }`}
                  >
                    Rp {formatNumber(amount)}
                  </button>
                ))}
              </div>

              <input
                type="number"
                value={payAmount}
                onChange={(e) => setPayAmount(e.target.value)}
                placeholder={`Min. Rp ${formatNumber(totalToPay)}`}
                className={`w-full rounded-2xl border px-4 py-3 text-right text-lg font-bold text-zinc-900 outline-none ${
                  isShort ? 'border-red-400 bg-red-50' : 'border-amber-200 bg-amber-50/40 focus:border-amber-400'
                }`}
              />

              {payAmountNum >= totalToPay && (
                <div className="mt-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-center">
                  <div className="text-sm font-semibold text-emerald-700">Change</div>
                  <div className="text-2xl font-black text-emerald-800">Rp {formatNumber(change)}</div>
                </div>
              )}

              {isShort && (
                <div className="mt-2 text-center text-sm text-red-500">
                  Short by Rp {formatNumber(totalToPay - payAmountNum)}
                </div>
              )}
            </div>
          )}

          {method === 'transfer' && (
            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4">
              <div className="mb-1 text-sm font-semibold text-amber-900">Transfer Confirmation</div>
              <p className="text-xs leading-5 text-amber-800">
                Pastikan transfer sebesar <strong>Rp {formatNumber(totalToPay)}</strong> sudah diterima sebelum konfirmasi.
              </p>
              <input
                value={payNote}
                onChange={(e) => setPayNote(e.target.value)}
                placeholder="No. referensi transfer (opsional)"
                className="mt-3 w-full rounded-xl border border-amber-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-amber-400"
              />
            </div>
          )}

          {method === 'qris' && (
            <div className="rounded-2xl border border-amber-200 bg-gradient-to-br from-amber-50 via-yellow-50 to-white p-4 text-center">
              <div className="text-sm font-semibold text-amber-900">QRIS Confirmation</div>
              <div className="mt-1 text-xs leading-5 text-amber-800">
                Ensure QRIS payment of <strong>Rp {formatNumber(totalToPay)}</strong> has been successful before confirmation.
              </div>
              <input
                value={payNote}
                onChange={(e) => setPayNote(e.target.value)}
                placeholder="QRIS payment note (optional)"
                className="mt-3 w-full rounded-xl border border-amber-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-amber-400"
              />
            </div>
          )}
          </div>

          <div
            className="sticky bottom-0 border-t border-amber-100 bg-white/95 px-6 pb-6 pt-4 backdrop-blur"
            style={{ paddingBottom: 'calc(max(env(safe-area-inset-bottom), 0px) + 1rem)' }}
          >
            <button
              onClick={() => void handleConfirm()}
              disabled={isProcessing || isShort}
              className="flex w-full items-center justify-center gap-2 rounded-2xl bg-amber-400 px-4 py-3.5 font-bold text-zinc-950 shadow-lg shadow-amber-400/30 transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <Send className="h-4 w-4" />
              {isProcessing ? 'Processing...' : 'Confirm Payment'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default PaymentModal;
