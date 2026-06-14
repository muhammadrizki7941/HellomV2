import { useRef, useState, useEffect } from 'react';
import { X, Printer, Copy, MessageCircle } from 'lucide-react';
import { getPosOrderReceipt } from '@/lib/hellomApi';
import html2canvas from 'html2canvas';

interface ReceiptProps {
  isOpen: boolean;
  onClose: () => void;
  orderId: number | null;
}

interface ReceiptData {
  order_number: string;
  created_at: string;
  status: string;
  service_type: string;
  table_code?: string;
  customer_name?: string;
  customer_phone?: string;
  customer_whatsapp?: string;
  notes?: string;
  items: Array<{
    name: string;
    quantity: number;
    price: number;
    subtotal: number;
  }>;
  total_amount: number;
  payment?: {
    method: string;
    amount: number;
    change: number;
    note?: string;
    paid_at?: string;
  };
  organization: {
    name: string;
    logo_path?: string;
    logo_url?: string;
    logo_base64?: string;
    address?: string;
    phone?: string;
  };
}

const ReceiptModal = ({ isOpen, onClose, orderId }: ReceiptProps) => {
  const receiptRef = useRef<HTMLDivElement>(null);
  const [receipt, setReceipt] = useState<ReceiptData | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isDownloading, setIsDownloading] = useState(false);

  useEffect(() => {
    if (isOpen && orderId) {
      fetchReceipt(orderId);
    } else if (!isOpen) {
      setReceipt(null);
      setError(null);
    }
  }, [isOpen, orderId]);



  const fetchReceipt = async (id: number) => {
    try {
      setLoading(true);
      setError(null);
      const response = await getPosOrderReceipt(id);

      setReceipt(response.receipt);
    } catch (err) {
      setError('Gagal memuat kwitansi');
      console.error('Failed to fetch receipt:', err);
    } finally {
      setLoading(false);
    }
  };

  const handlePrint = () => {
    if (!receipt) return;

    const printWindow = window.open('', '_blank');
    if (!printWindow) return;

    printWindow.document.write(`
      <html>
        <head>
          <title>Kwitansi ${receipt.order_number}</title>
          <style>
            body {
              font-family: 'Courier New', Courier, monospace;
              width: 300px;
              margin: 0 auto;
              padding: 10px;
              font-size: 13px;
              line-height: 1.6;
              color: #000;
              background-color: #fff;
            }
            .center { text-align: center; }
            .divider { border-top: 1px dashed #000; margin: 8px 0; height: 0; }
            @media print {
              @page {
                margin: 5mm;
                size: 80mm auto;
              }
              body { width: 100%; padding: 5px; }
            }
          </style>
        </head>
        <body>${receiptRef.current?.innerHTML || ''}</body>
      </html>
    `);
    printWindow.document.close();
    printWindow.print();
  };

  const handleDownload = async () => {
    if (!receipt || !receiptRef.current) return;

    setIsDownloading(true);
    try {
      // Tunggu semua gambar selesai load dulu
      const images = receiptRef.current.querySelectorAll('img');
      await Promise.all(
        Array.from(images).map(img => {
          if (img.complete) return Promise.resolve();
          return new Promise((resolve) => {
            img.onload = resolve;
            img.onerror = resolve; // skip jika gagal load
          });
        })
      );

      const canvas = await html2canvas(receiptRef.current, {
        backgroundColor: '#ffffff',
        scale: 2,
        useCORS: true,        // ← WAJIB jika ada gambar dari URL
        allowTaint: false,    // ← harus false jika useCORS true
        logging: false,
        imageTimeout: 10000,  // timeout 10 detik untuk load image
        onclone: (clonedDoc) => {
          // Pastikan semua img di clone punya crossorigin
          clonedDoc.querySelectorAll('img').forEach(img => {
            img.crossOrigin = 'anonymous';
          });
        },
      });

      const imageUrl = canvas.toDataURL('image/jpeg', 0.92);
      const link = document.createElement('a');
      link.href = imageUrl;
      link.download = `Kwitansi-${receipt.order_number}.jpg`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    } catch (error) {
      console.error('Gagal download:', error);
      alert('Gagal mengunduh kwitansi, coba lagi ya');
    } finally {
      setIsDownloading(false);
    }
  };

  const getReceiptText = (): string => {
    if (!receipt) return '';

    const lines: string[] = [];
    const formatRp = (amount: number) => `Rp ${amount.toLocaleString('id-ID')}`;
    const formatDate = (date: string) => {
      const d = new Date(date);
      return `${d.getDate()} ${d.toLocaleDateString('id-ID', { month: 'long' })} ${d.getFullYear()}, pukul ${d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}`;
    };
    const getServiceTypeLabel = (type: string) => {
      switch (type) {
        case 'dine_in': return 'Dine In';
        case 'takeaway': return 'Take Away';
        default: return type;
      }
    };

    // HEADER
    lines.push(receipt.organization.name);
    if (receipt.organization.address) lines.push(receipt.organization.address);
    if (receipt.organization.phone) lines.push(`Telp: ${receipt.organization.phone}`);
    lines.push(''.padEnd(50, '-'));

    // INFO PESANAN
    lines.push(`No    : ${receipt.order_number}`);
    lines.push(`Tgl   : ${formatDate(receipt.created_at)}`);
    lines.push(`Tipe  : ${getServiceTypeLabel(receipt.service_type)}`);
    if (receipt.table_code) lines.push(`Meja  : ${receipt.table_code}`);
    if (receipt.customer_name) lines.push(`Nama  : ${receipt.customer_name}`);
    if (receipt.customer_phone) lines.push(`HP    : ${receipt.customer_phone}`);
    lines.push(''.padEnd(50, '-'));

    // ITEMS
    receipt.items.forEach((item) => {
      lines.push(item.name);
      lines.push(`${item.quantity} x ${formatRp(item.price)}${' '.repeat(20)}${formatRp(item.subtotal)}`);
    });
    lines.push(''.padEnd(50, '-'));

    // TOTAL
    lines.push(`TOTAL${' '.repeat(40)}${formatRp(receipt.total_amount)}`);

    // PAYMENT INFO
    if (receipt.payment) {
      lines.push(''.padEnd(50, '-'));
      lines.push('Pembayaran:');
      if (receipt.payment.method === 'cash') lines.push('Tunai');
      if (receipt.payment.method === 'transfer') lines.push('Transfer Bank');
      if (receipt.payment.method === 'qris') lines.push('QRIS');
      
      if (receipt.payment.method === 'cash') {
        lines.push(`Bayar${' '.repeat(40)}${formatRp(receipt.payment.amount)}`);
        if (receipt.payment.change > 0) {
          lines.push(`Kembalian${' '.repeat(35)}${formatRp(receipt.payment.change)}`);
        }
      }
      if (receipt.payment.note) lines.push(`Ref: ${receipt.payment.note}`);
      if (receipt.payment.paid_at) lines.push(`Waktu bayar: ${formatDate(receipt.payment.paid_at)}`);
    }

    lines.push(''.padEnd(50, '-'));
    lines.push('Terima kasih sudah mampir! 😊');
    lines.push('Sampai jumpa lagi');

    return lines.join('\n');
  };

  const handleCopy = () => {
    if (!receipt) return;

    const receiptText = getReceiptText();
    
    // Copy ke clipboard
    navigator.clipboard.writeText(receiptText).then(() => {
      alert('Kwitansi berhasil disalin! Siap untuk dikirim ke WhatsApp');
    }).catch((err) => {
      console.error('Gagal menyalin:', err);
      alert('Gagal menyalin kwitansi');
    });
  };

  const handleSendWhatsApp = () => {
    if (!receipt) return;

    // Gunakan customer_whatsapp atau customer_phone
    const whatsappNumber = receipt.customer_whatsapp || receipt.customer_phone;
    
    if (!whatsappNumber) {
      alert('Nomor WhatsApp customer tidak tersedia. Silakan salin kwitansi dan kirim manual ke WhatsApp.');
      return;
    }

    // Generate teks kwitansi
    const receiptText = getReceiptText();

    // Encode teks untuk URL
    const encodedText = encodeURIComponent(receiptText);

    // Format nomor: hapus spasi, tanda strip, kurung. Tambahkan +62 jika diawali 0
    let phone = whatsappNumber.replace(/[\s\-()]/g, '');
    if (phone.startsWith('0')) {
      phone = '62' + phone.slice(1);
    }
    if (!phone.startsWith('+')) {
      phone = '+' + phone;
    }

    // Buat URL WhatsApp
    const whatsappUrl = `https://wa.me/${phone}?text=${encodedText}`;

    // Redirect ke WhatsApp
    window.open(whatsappUrl, '_blank');
  };



  const formatRp = (amount: number) =>
    `Rp ${amount.toLocaleString('id-ID')}`;

  const formatDate = (date: string) => {
    const d = new Date(date);
    return `${d.getDate()} ${d.toLocaleDateString('id-ID', { month: 'long' })} ${d.getFullYear()}, pukul ${d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}`;
  };

  const getServiceTypeLabel = (type: string) => {
    switch (type) {
      case 'dine_in': return 'Dine In';
      case 'takeaway': return 'Take Away';
      default: return type;
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-white/20 backdrop-blur-md">
      <div className="bg-white rounded-xl max-w-md w-full mx-4 max-h-[90vh] overflow-hidden shadow-xl">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b border-gray-200">
          <h3 className="text-lg font-semibold text-gray-900">Kwitansi</h3>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 p-2"
          >
            <X className="w-6 h-6" />
          </button>
        </div>

        {/* Receipt Content */}
        <div className="p-4 max-h-96 overflow-y-auto">
          {loading ? (
            <div className="text-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-amber-400 mx-auto"></div>
              <p className="text-gray-600 mt-2">Memuat kwitansi...</p>
            </div>
          ) : error ? (
            <div className="text-center py-8">
              <div className="text-red-500 mb-2">⚠️</div>
              <p className="text-red-600">{error}</p>
            </div>
          ) : receipt ? (
            <div ref={receiptRef}
              style={{
                width: '300px',
                margin: '0 auto',
                fontFamily: "'Courier New', Courier, monospace",
                fontSize: '13px',
                lineHeight: '1.6',
                color: '#000',
                backgroundColor: '#fff',
                padding: '16px',
              }}
            >
              {/* HEADER */}
              <div style={{ textAlign: 'center', marginBottom: '8px' }}>
              {(receipt.organization.logo_base64 || receipt.organization.logo_url) && (
                <img
                  src={receipt.organization.logo_base64 || receipt.organization.logo_url}
                  style={{ width: '60px', height: '60px', objectFit: 'contain' }}
                  onError={(e) => { e.currentTarget.style.display = 'none'; }}
                />
              )}
                <div style={{ fontWeight: 'bold', fontSize: '14px' }}>
                  {receipt.organization.name}
                </div>
                {receipt.organization.address && (
                  <div style={{ fontSize: '11px' }}>{receipt.organization.address}</div>
                )}
                {receipt.organization.phone && (
                  <div style={{ fontSize: '11px' }}>Telp: {receipt.organization.phone}</div>
                )}
              </div>

              {/* DIVIDER */}
              <div style={{ borderTop: '1px dashed #000', margin: '8px 0', height: '0' }}></div>

              {/* INFO PESANAN */}
              <div>No    : {receipt.order_number}</div>
              <div>Tgl   : {formatDate(receipt.created_at)}</div>
              <div>Tipe  : {getServiceTypeLabel(receipt.service_type)}</div>
              {receipt.table_code && <div>Meja  : {receipt.table_code}</div>}
              {receipt.customer_name && <div>Nama  : {receipt.customer_name}</div>}
              {receipt.customer_phone && <div>HP    : {receipt.customer_phone}</div>}

              {/* DIVIDER */}
              <div style={{ borderTop: '1px dashed #000', margin: '8px 0', height: '0' }}></div>

              {/* ITEMS */}
              {receipt.items.map((item, i) => (
                <div key={i}>
                  <div>{item.name}</div>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span>{item.quantity} x {formatRp(item.price)}</span>
                    <span>{formatRp(item.subtotal)}</span>
                  </div>
                </div>
              ))}

              {/* DIVIDER */}
              <div style={{ borderTop: '1px dashed #000', margin: '8px 0', height: '0' }}></div>

              {/* TOTAL */}
              <div style={{
                display: 'flex',
                justifyContent: 'space-between',
                fontWeight: 'bold',
                fontSize: '14px'
              }}>
                <span>TOTAL</span>
                <span>{formatRp(receipt.total_amount)}</span>
              </div>

              {/* PAYMENT INFO */}
              {receipt.payment && (
                <>
                  <div style={{ borderTop: '1px dashed #000', margin: '8px 0', height: '0' }}></div>
                  <div style={{ fontSize: '12px' }}>
                    <div style={{ fontWeight: 'bold', marginBottom: '4px' }}>Pembayaran:</div>
                    <div>
                      {receipt.payment.method === 'cash' && ' Tunai'}
                      {receipt.payment.method === 'transfer' && ' Transfer Bank'}
                      {receipt.payment.method === 'qris' && ' QRIS'}
                    </div>
                    {receipt.payment.method === 'cash' && (
                      <>
                        <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                          <span>Bayar</span>
                          <span>{formatRp(receipt.payment.amount)}</span>
                        </div>
                        {receipt.payment.change > 0 && (
                          <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 'bold' }}>
                            <span>Kembalian</span>
                            <span>{formatRp(receipt.payment.change)}</span>
                          </div>
                        )}
                      </>
                    )}
                    {receipt.payment.note && (
                      <div>Ref: {receipt.payment.note}</div>
                    )}
                    {receipt.payment.paid_at && (
                      <div>Waktu bayar: {formatDate(receipt.payment.paid_at)}</div>
                    )}
                  </div>
                </>
              )}

              {/* DIVIDER */}
              <div style={{ borderTop: '1px dashed #000', margin: '8px 0', height: '0' }}></div>

              {/* FOOTER */}
              <div style={{ textAlign: 'center', marginTop: '8px' }}>
                <div>Terima kasih sudah mampir! 😊</div>
                <div style={{ fontSize: '11px' }}>Sampai jumpa lagi</div>
              </div>
            </div>
          ) : null}
        </div>

        {/* TOMBOL AKSI */}
        {receipt && !loading && !error && (
          <div className="border-t border-gray-200 bg-gray-50 px-4 py-3">
            <div className="grid grid-cols-5 gap-2">
              <button
                onClick={handlePrint}
                className="flex flex-col items-center gap-1 rounded-lg py-2 px-2 text-xs font-medium text-gray-700 hover:bg-blue-50 transition"
                title="Cetak Kwitansi"
              >
                <Printer className="h-4 w-4 text-blue-600" />
                <span>Cetak</span>
              </button>
              <button
                onClick={handleDownload}
                disabled={isDownloading}
                className="flex flex-col items-center gap-1 rounded-lg py-2 px-2 text-xs font-medium text-gray-700 hover:bg-green-50 transition disabled:opacity-60 disabled:cursor-not-allowed"
                title="Unduh sebagai JPG"
              >
                <span className="text-lg">⬇️</span>
                <span>{isDownloading ? 'Loading' : 'Unduh'}</span>
              </button>
              <button
                onClick={handleSendWhatsApp}
                className="flex flex-col items-center gap-1 rounded-lg py-2 px-2 text-xs font-medium text-gray-700 hover:bg-green-50 transition"
                title="Kirim via WhatsApp"
              >
                <MessageCircle className="h-4 w-4 text-green-600" />
                <span>WhatsApp</span>
              </button>
              <button
                onClick={handleCopy}
                className="flex flex-col items-center gap-1 rounded-lg py-2 px-2 text-xs font-medium text-gray-700 hover:bg-amber-50 transition"
                title="Salin ke clipboard untuk WhatsApp"
              >
                <Copy className="h-4 w-4 text-amber-600" />
                <span>Salin</span>
              </button>
              <button
                onClick={onClose}
                className="flex flex-col items-center gap-1 rounded-lg py-2 px-2 text-xs font-medium text-gray-700 hover:bg-gray-200 transition"
                title="Tutup"
              >
                <X className="h-4 w-4 text-gray-600" />
                <span>Tutup</span>
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default ReceiptModal;
