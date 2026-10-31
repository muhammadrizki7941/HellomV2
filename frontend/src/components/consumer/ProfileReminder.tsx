import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { UserRound, ArrowRight } from 'lucide-react';
import { getSessionUser, getSessionEventName } from '@/lib/hellomApi';

export default function ProfileReminder() {
  const [user, setUser] = useState(() => getSessionUser<{ name?: string; phone?: string }>());
  const { pathname } = useLocation();
  useEffect(() => {
    const sync = () => setUser(getSessionUser());
    window.addEventListener(getSessionEventName(), sync);
    return () => window.removeEventListener(getSessionEventName(), sync);
  }, []);
  if (!user || (user.name?.trim() && user.phone?.trim()) || pathname === '/dashboard/profile') return null;
  return <Link to="/dashboard/profile" className="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
    <UserRound className="mt-0.5 h-5 w-5 shrink-0" /><div className="min-w-0 flex-1"><p className="text-sm font-bold">Lengkapi Profile Anda</p><p className="mt-1 text-xs leading-5">Tambahkan {!user.name?.trim() ? 'nama dan ' : ''}nomor HP agar informasi akun Anda lengkap.</p></div><ArrowRight className="mt-1 h-4 w-4 shrink-0" />
  </Link>;
}
