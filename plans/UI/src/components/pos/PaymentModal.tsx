import React, { useEffect, useState } from 'react';
import { ExternalLink, Send } from 'lucide-react';
import { confirmPosOrderPayment } from '@/lib/hellomApi';

type PaymentMethod = 'cash' | 'transfer' | 'qris' | 'dana' | 'gopay';

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

const BASE = import.meta.env.BASE_URL ?? '/hellom/';

const METHODS: { key: PaymentMethod; label: string; icon?: string; img?: string; color?: string }[] = [
  { key: 'cash', icon: '💵', label: 'Tunai' },
  { key: 'transfer', icon: '🏦', label: 'Transfer' },
  { key: 'qris', img: `${BASE}assets/QRIS-ICON.webp`, label: 'QRIS' },
  { key: 'dana', img: `${BASE}assets/DANA-ICON.png`, label: 'Dana', color: '#118EEA' },
  { key: 'gopay', img: `${BASE}assets/GOPAY-ICON.jpg`, label: 'GoPay', color: '#00AA13' },
];

const EWALLET_LINKS: Record<string, string> = {
  dana: 'https://link.dana.id/',
  gopay: 'https://gojek.onelink.me/iAHD/gopay',
};

const PaymentModal = ({ isOpen, onClose, onSuccess, order }: PaymentModalProps) => {
  const [method, setMethod] = useState<PaymentMethod>('cash');
  const [payAmount, setPayAmount] = useState('');
  const [payNote, setPayNote] = useState('');
  const [isProcessing, setIsProcessing] = useState(false);

  useEffect(() => {
    if (!isOpen || !order) return;
    setMethod('cash');
    setPayAmount('');
    setPayNote('');
  }, [isOpen, order]);

  useEffect(() => {
    if (!order) return;
    const amount = order.final_amount ?? order.total_amount ?? 0;
    if (method !== 'cash' && amount > 0) {
      setPayAmount(String(amount));
    } else {
      setPayAmount('');
    }
  }, [method, order]);

  if (!isOpen || !order) return null;

  const totalToPay = order.final_amount ?? order.total_amount ?? 0;
  const payAmountNum = parseInt(payAmount.replace(/\D/g, ''), 10) || 0;
  const change = Math.max(0, payAmountNum - totalToPay);
  const isShort = method === 'cash' && payAmountNum > 0 && payAmountNum < totalToPay;

  const fmt = (n: number) => n.toLocaleString('id-ID');

  const quickAmounts = [
    totalToPay,
    Math.ceil(totalToPay / 5000) * 5000,
    Math.ceil(totalToPay / 10000) * 10000,
    Math.ceil(totalToPay / 20000) * 20000,
    50000,
    100000,
  ]
    .filter((v, i, arr) => v >= totalToPay && arr.indexOf(v) === i)
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

  const openEwallet = () => {
    const url = EWALLET_LINKS[method];
    if (url) window.open(url, '_blank', 'noopener,noreferrer');
  };

  const currentMethod = METHODS.find((m) => m.key === method);
  const isEwallet = method === 'dana' || method === 'gopay';

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/55 p-4 pb-4 md:items-center">
      <div
        className="max-h-[90vh] w-full overflow-hidden rounded-t-3xl bg-white shadow-2xl md:max-w-md md:rounded-2xl"
        style={{ paddingBottom: 'max(env(safe-area-inset-bottom), 0px)' }}
      >
        {/* Header */}
        <div className="border-b border-gray-200 bg-white px-6 py-3">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-semibold text-gray-900">Konfirmasi Pembayaran</h2>
            <button onClick={onClose} className="text-gray-400 transition hover:text-gray-600">✕</button>
          </div>
          <div className="mt-1 text-xs text-gray-500">{order.order_number}</div>
        </div>

        <div className="max-h-[calc(90vh-84px)] overflow-y-auto">
          <div className="space-y-4 p-5 pb-20">

            {/* Total */}
            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-gray-600">Total Pembayaran</span>
                <span className="text-lg font-semibold text-gray-900">Rp {fmt(totalToPay)}</span>
              </div>
            </div>

            {/* Payment Methods — 2 rows of 3 columns (last col in row 2 is empty) */}
            <div>
              <label className="mb-2 block text-xs font-semibold text-gray-700">Metode Pembayaran</label>
              <div className="grid grid-cols-3 gap-2">
                {METHODS.map((m) => {
                  const active = method === m.key;
                  return (
                    <button
                      key={m.key}
                      onClick={() => setMethod(m.key)}
                      className={`flex flex-col items-center gap-1.5 rounded-lg py-2.5 text-xs font-medium transition ${
                        active
                          ? 'bg-amber-400 text-gray-900 shadow-sm ring-2 ring-amber-400'
                          : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                      }`}
                    >
                      {m.img ? (
                        <img
                          src={m.img}
                          alt={m.label}
                          className="h-6 w-auto max-w-[48px] rounded object-contain"
                          onError={(e) => {
                            (e.currentTarget as HTMLImageElement).style.display = 'none';
                          }}
                        />
                      ) : (
                        <span className="text-base leading-none">{m.icon}</span>
                      )}
                      <span>{m.label}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Cash */}
            {method === 'cash' && (
              <div>
                <label className="mb-2 block text-xs font-semibold text-gray-700">Jumlah Diterima</label>
                <div className="mb-3 flex flex-wrap gap-2">
                  {quickAmounts.map((amount) => (
                    <button
                      key={amount}
                      onClick={() => setPayAmount(String(amount))}
                      className={`rounded-md border px-2.5 py-1.5 text-xs font-medium transition ${
                        payAmountNum === amount
                          ? 'border-amber-400 bg-amber-50 text-amber-700'
                          : 'border-gray-300 bg-white text-gray-600 hover:border-gray-400'
                      }`}
                    >
                      Rp {fmt(amount)}
                    </button>
                  ))}
                </div>
                <input
                  type="number"
                  value={payAmount}
                  onChange={(e) => setPayAmount(e.target.value)}
                  placeholder={`Min. Rp ${fmt(totalToPay)}`}
                  className={`w-full rounded-lg border px-3 py-2 text-right text-sm font-medium text-gray-900 outline-none transition ${
                    isShort
                      ? 'border-red-300 bg-red-50'
                      : 'border-gray-300 bg-white focus:border-amber-400 focus:ring-1 focus:ring-amber-400'
                  }`}
                />
                {payAmountNum >= totalToPay && (
                  <div className="mt-3 rounded-lg border border-green-200 bg-green-50 p-2.5">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-medium text-green-700">Kembalian</span>
                      <span className="text-sm font-semibold text-green-800">Rp {fmt(change)}</span>
                    </div>
                  </div>
                )}
                {isShort && (
                  <p className="mt-2 text-center text-xs text-red-500">
                    Kurang Rp {fmt(totalToPay - payAmountNum)}
                  </p>
                )}
              </div>
            )}

            {/* Transfer */}
            {method === 'transfer' && (
              <div className="rounded-lg border border-gray-300 bg-white p-3">
                <div className="mb-1 text-xs font-semibold text-gray-900">Konfirmasi Transfer</div>
                <p className="mb-3 text-xs leading-relaxed text-gray-600">
                  Pastikan transfer <strong>Rp {fmt(totalToPay)}</strong> sudah diterima sebelum konfirmasi.
                </p>
                <input
                  value={payNote}
                  onChange={(e) => setPayNote(e.target.value)}
                  placeholder="Referensi transfer (opsional)"
                  className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs text-gray-900 outline-none focus:border-amber-400 focus:ring-1 focus:ring-amber-400"
                />
              </div>
            )}

            {/* QRIS */}
            {method === 'qris' && (
              <div className="rounded-lg border border-gray-300 bg-white p-3">
                <div className="mb-1 text-xs font-semibold text-gray-900">Konfirmasi QRIS</div>
                <p className="mb-3 text-xs leading-relaxed text-gray-600">
                  Pastikan pembayaran QRIS <strong>Rp {fmt(totalToPay)}</strong> sudah berhasil.
                </p>
                <input
                  value={payNote}
                  onChange={(e) => setPayNote(e.target.value)}
                  placeholder="Referensi QRIS (opsional)"
                  className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs text-gray-900 outline-none focus:border-amber-400 focus:ring-1 focus:ring-amber-400"
                />
              </div>
            )}

            {/* Dana / GoPay */}
            {isEwallet && (
              <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div
                  className="px-4 py-3 text-white"
                  style={{ backgroundColor: currentMethod?.color ?? '#555' }}
                >
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold">
                      Bayar via {currentMethod?.label}
                    </span>
                    <span className="text-sm font-bold">Rp {fmt(totalToPay)}</span>
                  </div>
                  <p className="mt-1 text-xs opacity-80">
                    Klik tombol di bawah untuk membuka aplikasi {currentMethod?.label}
                  </p>
                </div>

                <div className="p-3 space-y-3">
                  <button
                    type="button"
                    onClick={openEwallet}
                    className="flex w-full items-center justify-center gap-2 rounded-lg py-3 text-sm font-semibold text-white transition active:opacity-80"
                    style={{ backgroundColor: currentMethod?.color ?? '#555' }}
                  >
                    <ExternalLink className="h-4 w-4" />
                    Buka Aplikasi {currentMethod?.label}
                  </button>

                  <input
                    value={payNote}
                    onChange={(e) => setPayNote(e.target.value)}
                    placeholder={`ID transaksi ${currentMethod?.label} (opsional)`}
                    className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs text-gray-900 outline-none focus:border-amber-400 focus:ring-1 focus:ring-amber-400"
                  />

                  <p className="text-[11px] text-center text-gray-400">
                    Setelah pelanggan membayar, klik "Konfirmasi Pembayaran" di bawah
                  </p>
                </div>
              </div>
            )}
          </div>

          {/* Confirm Button */}
          <div
            className="sticky bottom-0 border-t border-gray-200 bg-white px-5 py-3"
            style={{ paddingBottom: 'calc(max(env(safe-area-inset-bottom), 0px) + 0.75rem)' }}
          >
            <button
              onClick={() => void handleConfirm()}
              disabled={isProcessing || isShort}
              className="flex w-full items-center justify-center gap-2 rounded-lg bg-amber-400 px-4 py-2.5 text-sm font-semibold text-gray-900 transition hover:bg-amber-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <Send className="h-4 w-4" />
              {isProcessing ? 'Memproses...' : 'Konfirmasi Pembayaran'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default PaymentModal;
