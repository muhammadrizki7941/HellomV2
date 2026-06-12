import React, { useEffect, useState } from 'react';

export default function EnvironmentDetails(): JSX.Element {
  const [now, setNow] = useState<string>('');
  useEffect(() => {
    const d = new Date();
    setNow(d.toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' }));
  }, []);

  const apiBase = (import.meta as any).env?.VITE_HELLOM_API_BASE ?? '';

  return (
    <div className="p-4 bg-white border rounded-lg shadow-sm">
      <div className="text-sm font-semibold mb-1">Environment Details</div>
      <div className="text-xs text-zinc-600">Waktu sekarang: {now || '-'}</div>
      <div className="text-xs text-zinc-600 mt-1">Workspace: /</div>
      {apiBase && <div className="text-xs text-zinc-600 mt-1">API Base: {apiBase}</div>}
    </div>
  );
}
