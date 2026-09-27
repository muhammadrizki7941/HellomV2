import { FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { CheckCircle2, KeyRound, LogIn, XCircle } from 'lucide-react';
import { acceptOrganizationInvitation, getAuthMe, getToken, setSession, setActiveOutletId } from '@/lib/hellomApi';
import { BRAND_LOGO_PATH, BRAND_NAME } from '@/lib/branding';

export default function InvitationAcceptPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const tokenFromQuery = (searchParams.get('token') || '').trim();

  const [token, setToken] = useState(tokenFromQuery);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  useEffect(() => {
    setToken(tokenFromQuery);
  }, [tokenFromQuery]);

  const authToken = getToken();
  const loginPath = `${window.location.pathname.startsWith('/hellom') ? '/hellom' : ''}/login?inviteToken=${encodeURIComponent(token || tokenFromQuery)}`;

  const handleAccept = async (event: FormEvent) => {
    event.preventDefault();
    const inviteToken = token.trim();
    if (!inviteToken) {
      setErrorMessage('Token invitation wajib diisi.');
      return;
    }

    if (!authToken) {
      setErrorMessage('Silakan login dulu sebelum menerima invitation.');
      return;
    }

    setLoading(true);
    setErrorMessage(null);
    setSuccessMessage(null);

    try {
      const accepted = await acceptOrganizationInvitation({ token: inviteToken });
      const me = await getAuthMe();
      const latestToken = getToken();
      if (latestToken) {
        setSession(latestToken, me);
      }

      setSuccessMessage(`Berhasil bergabung ke organisasi ${accepted.organization.name}.`);
      const posAccess = (me as { pos_access?: { is_cashier?: boolean; outlet_id?: number | null } } | null)?.pos_access;
      setTimeout(() => {
        if (posAccess?.is_cashier) {
          if (posAccess.outlet_id) setActiveOutletId(posAccess.outlet_id);
          navigate('/pos/orders', { replace: true });
        } else {
          navigate('/dashboard/profile', { replace: true });
        }
      }, 1000);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Gagal menerima invitation';
      setErrorMessage(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-zinc-50 flex items-center justify-center p-4 font-sans selection:bg-yellow-400 selection:text-black">
      <div className="w-full max-w-md bg-white border border-zinc-100 rounded-2xl shadow-xl p-8">
        <div className="text-center mb-6">
          <img src={BRAND_LOGO_PATH} alt={BRAND_NAME} draggable={false} loading="lazy" className="w-12 h-12 rounded-lg border border-zinc-200 mx-auto mb-3 object-cover" />
          <h1 className="text-2xl font-bold text-zinc-900">Accept Organization Invitation</h1>
          <p className="text-sm text-zinc-500 mt-1">Masukkan token invitation untuk bergabung ke organisasi.</p>
        </div>

        {errorMessage && (
          <div className="mb-4 rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-sm text-red-600 flex items-center gap-2">
            <XCircle className="w-4 h-4" /> {errorMessage}
          </div>
        )}

        {successMessage && (
          <div className="mb-4 rounded-lg border border-green-100 bg-green-50 px-3 py-2 text-sm text-green-700 flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4" /> {successMessage}
          </div>
        )}

        <form onSubmit={handleAccept} className="space-y-4">
          <label className="block text-sm font-medium text-zinc-700">Invitation Token</label>
          <div className="relative">
            <KeyRound className="w-4 h-4 text-zinc-400 absolute left-3 top-3" />
            <textarea
              value={token}
              onChange={(event) => setToken(event.target.value)}
              rows={3}
              className="w-full rounded-lg border border-zinc-300 pl-10 pr-3 py-2.5 text-sm font-mono focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
              placeholder="Paste token invitation"
            />
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full py-2.5 rounded-lg bg-black text-white font-bold hover:bg-zinc-800 transition-colors disabled:opacity-60"
          >
            {loading ? 'Memproses...' : 'Accept Invitation'}
          </button>
        </form>

        {!authToken && (
          <Link
            to={loginPath}
            className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
          >
            <LogIn className="w-4 h-4" /> Login dulu untuk lanjut
          </Link>
        )}
      </div>
    </div>
  );
}
