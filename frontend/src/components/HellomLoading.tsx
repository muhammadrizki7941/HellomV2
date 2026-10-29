import { cn } from '@/lib/utils';
import { useEffect, useState } from 'react';
import HellomMascot from './HellomMascot';
import './hellom-loading.css';

type HellomLoadingProps = {
  label?: string;
  mode?: 'page' | 'overlay' | 'inline';
  className?: string;
};

export default function HellomLoading({
  label = 'Memuat...',
  mode = 'page',
  className,
}: HellomLoadingProps) {
  const [slow, setSlow] = useState(false);
  useEffect(() => {
    setSlow(false);
    const timer = window.setTimeout(() => setSlow(true), 6000);
    return () => window.clearTimeout(timer);
  }, [label]);
  return (
    <div
      className={cn(
        'hellom-loader',
        mode === 'page' && 'hellom-loader--page',
        mode === 'overlay' && 'hellom-loader--overlay',
        mode === 'inline' && 'hellom-loader--inline',
        className
      )}
      role="status"
      aria-live="polite"
      aria-label={slow ? 'Proses membutuhkan waktu lebih lama' : label}
    >
      <div className="hellom-loader__content">
        <div className="hellom-mascot-road" aria-hidden="true">
          <HellomMascot mood={slow ? 'loading' : 'trolley'} />
        </div>
        <p className="hellom-loader__label">{slow ? 'Masih diproses, terima kasih sudah menunggu…' : label}</p>
        {slow && <p className="max-w-xs px-4 text-center text-xs text-stone-500">Proses ini lebih lama dari biasanya. Jangan ulangi transaksi sebelum statusnya jelas.</p>}
        <span className="hellom-loader__track" aria-hidden="true">
          <span className="hellom-loader__progress" />
        </span>
      </div>
    </div>
  );
}
