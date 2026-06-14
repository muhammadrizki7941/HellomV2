import { useEffect, useState } from 'react';
import Overview from './landing-builder/Overview';
import Editor from './landing-builder/Editor';
import { Layout, BarChart3, Users, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getLandingPageCustomers } from '@/lib/hellomApi';

function CustomersPanel() {
  const [items, setItems] = useState<Array<Record<string, any>>>([]);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const loadCustomers = async () => {
    setLoading(true);
    setErrorMessage(null);
    try {
      const result = await getLandingPageCustomers();
      setItems((result.items as Array<Record<string, any>>) || []);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal memuat data pelanggan landingpage');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadCustomers();
  }, []);

  return (
    <div className="max-w-6xl mx-auto space-y-5">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Data Pelanggan Landingpage</h1>
          <p className="text-zinc-600">Semua user yang mengisi form pendaftaran di landing page organisasi.</p>
        </div>
        <button
          onClick={() => void loadCustomers()}
          disabled={loading}
          className="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 disabled:opacity-60"
        >
          <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} /> Refresh
        </button>
      </div>

      {errorMessage && (
        <div className="rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">{errorMessage}</div>
      )}

      <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full divide-y divide-zinc-100 text-sm">
            <thead className="bg-zinc-50 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">
              <tr>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap">Waktu</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap">Nama</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap hidden sm:table-cell">Nomor HP</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap hidden md:table-cell">Email</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap hidden lg:table-cell">Form</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap hidden xl:table-cell">Data Tambahan</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100">
              {items.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-3 md:px-4 py-10 text-center text-zinc-500">
                    {loading ? 'Memuat data...' : 'Belum ada data pelanggan landingpage.'}
                  </td>
                </tr>
              ) : items.map((item) => {
                const fields = item.fields && typeof item.fields === 'object' ? item.fields as Record<string, unknown> : {};
                return (
                  <tr key={item.id} className="align-top hover:bg-zinc-50">
                    <td className="whitespace-nowrap px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-500">{item.created_at ? new Date(String(item.created_at)).toLocaleString('id-ID') : '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm font-semibold text-zinc-900">{item.name || fields.name || fields.nama || '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-700 hidden sm:table-cell">{item.phone || fields.phone || fields.nomor_hp || '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-700 hidden md:table-cell">{item.email || fields.email || '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-700 hidden lg:table-cell">{item.form_title || item.landing_page?.title || '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-600 hidden xl:table-cell">
                      <div className="max-w-md space-y-1">
                        {Object.entries(fields).slice(0, 2).map(([key, value]) => (
                          <div key={key}><span className="font-semibold">{key}:</span> {String(value).slice(0, 50)}</div>
                        ))}
                        {Object.keys(fields).length > 2 && <div className="text-xs text-zinc-400">+{Object.keys(fields).length - 2} more</div>}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

export default function LandingBuilder() {
  const [activeTab, setActiveTab] = useState<'overview' | 'editor' | 'customers'>('overview');

  return (
    <div className="space-y-6">
      {/* App Header & Tabs */}
      <div className="flex items-center justify-between border-b border-zinc-200 pb-1">
        <div className="flex gap-6">
          <button
            onClick={() => setActiveTab('overview')}
            className={cn(
              "flex items-center gap-2 pb-3 text-sm font-medium border-b-2 transition-colors",
              activeTab === 'overview' 
                ? "border-yellow-400 text-black" 
                : "border-transparent text-zinc-500 hover:text-zinc-900"
            )}
          >
            <BarChart3 className="w-4 h-4" />
            Overview
          </button>
          <button
            onClick={() => setActiveTab('editor')}
            className={cn(
              "flex items-center gap-2 pb-3 text-sm font-medium border-b-2 transition-colors",
              activeTab === 'editor' 
                ? "border-yellow-400 text-black" 
                : "border-transparent text-zinc-500 hover:text-zinc-900"
            )}
          >
            <Layout className="w-4 h-4" />
            Editor
          </button>
          <button
            onClick={() => setActiveTab('customers')}
            className={cn(
              "flex items-center gap-2 pb-3 text-sm font-medium border-b-2 transition-colors",
              activeTab === 'customers'
                ? "border-yellow-400 text-black"
                : "border-transparent text-zinc-500 hover:text-zinc-900"
            )}
          >
            <Users className="w-4 h-4" />
            Pelanggan
          </button>
        </div>
      </div>

      {/* Content */}
      {activeTab === 'editor' ? (
        /* Full-bleed on mobile: cancel DashboardLayout p-4 horizontally.
           Height = viewport minus: mobile-header(80px) + tab-bar(~52px) + space-y-6(24px) + bottom-safe(~8px) */
        <div
          className="-mx-4 md:mx-0 overflow-hidden"
          style={{ height: 'calc(100svh - 164px)' }}
        >
          <Editor />
        </div>
      ) : (
        <div className="min-h-[600px]">
          {activeTab === 'overview' ? (
            <Overview onEdit={() => setActiveTab('editor')} />
          ) : (
            <CustomersPanel />
          )}
        </div>
      )}
    </div>
  );
}
