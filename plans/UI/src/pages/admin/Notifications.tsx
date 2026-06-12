import { useEffect, useState } from 'react';
import {
  Bell, User, CreditCard, Clock, CheckCheck, Trash2,
  Filter, Search, MoreHorizontal
} from 'lucide-react';
import { useNotifications, Notification } from '@/hooks/useNotifications';

const typeIcons = {
  new_user: User,
  new_transaction: CreditCard,
  expiry_reminder: Clock,
};

const typeLabels = {
  new_user: 'Pendaftar Baru',
  new_transaction: 'Transaksi Masuk',
  expiry_reminder: 'Masa Aktif Tenggat',
};

export default function Notifications() {
  const { notifications, loading, error, fetchNotifications, markAsRead, markAllAsRead } = useNotifications();
  const [filter, setFilter] = useState<'all' | 'new_user' | 'new_transaction' | 'expiry_reminder'>('all');
  const [search, setSearch] = useState('');
  const [currentPage, setCurrentPage] = useState(1);

  useEffect(() => {
    fetchNotifications(currentPage, filter !== 'all' ? filter : undefined);
  }, [fetchNotifications, filter, currentPage]);

  const filteredNotifications = notifications.filter(notif =>
    search === '' ||
    notif.title.toLowerCase().includes(search.toLowerCase()) ||
    notif.message.toLowerCase().includes(search.toLowerCase())
  );

  const handleMarkAsRead = async (id: number) => {
    await markAsRead(id);
  };

  const handleMarkAllAsRead = async () => {
    if (confirm('Tandai semua notifikasi sebagai dibaca?')) {
      await markAllAsRead();
    }
  };

  const formatTime = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleString('id-ID', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Notifikasi</h1>
          <p className="text-zinc-500">Pantau aktivitas dan update penting platform.</p>
        </div>
        {notifications.some(n => !n.is_read) && (
          <button
            onClick={handleMarkAllAsRead}
            className="px-4 py-2 bg-zinc-900 text-white font-bold rounded-lg hover:bg-zinc-800 transition-colors flex items-center gap-2"
          >
            <CheckCheck className="w-4 h-4" />
            Tandai Semua Dibaca
          </button>
        )}
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">
          {error}
        </div>
      )}

      {/* Filters */}
      <div className="bg-white p-4 rounded-xl border border-zinc-200 shadow-sm">
        <div className="flex flex-col sm:flex-row gap-4">
          <div className="flex-1">
            <div className="relative">
              <Search className="absolute left-3 top-2.5 w-4 h-4 text-zinc-400" />
              <input
                type="text"
                placeholder="Cari notifikasi..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full pl-9 pr-4 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-zinc-900 outline-none"
              />
            </div>
          </div>
          <div className="flex gap-2">
            {[
              { key: 'all' as const, label: 'Semua', count: notifications.length },
              { key: 'new_user' as const, label: 'Pendaftar', count: notifications.filter(n => n.type === 'new_user').length },
              { key: 'new_transaction' as const, label: 'Transaksi', count: notifications.filter(n => n.type === 'new_transaction').length },
              { key: 'expiry_reminder' as const, label: 'Tenggat', count: notifications.filter(n => n.type === 'expiry_reminder').length },
            ].map(({ key, label, count }) => (
              <button
                key={key}
                onClick={() => setFilter(key)}
                className={`px-4 py-2 text-sm rounded-lg transition-colors flex items-center gap-2 ${
                  filter === key
                    ? 'bg-zinc-900 text-white'
                    : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200'
                }`}
              >
                <Filter className="w-4 h-4" />
                {label}
                <span className="bg-zinc-500/20 px-2 py-0.5 rounded text-xs">
                  {count}
                </span>
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Table */}
      <div className="bg-white border border-zinc-200 rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-8 text-center">
            <div className="animate-spin w-8 h-8 border-4 border-zinc-300 border-t-zinc-600 rounded-full mx-auto mb-4"></div>
            <p className="text-zinc-500">Memuat notifikasi...</p>
          </div>
        ) : filteredNotifications.length === 0 ? (
          <div className="p-12 text-center">
            <Bell className="w-12 h-12 text-zinc-400 mx-auto mb-4" />
            <h3 className="text-lg font-semibold text-zinc-900 mb-2">Tidak ada notifikasi</h3>
            <p className="text-zinc-500">Belum ada notifikasi yang sesuai dengan filter.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-zinc-50 border-b border-zinc-200">
                <tr>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                    Tipe
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                    Judul
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                    Pesan
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                    Waktu
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                    Status
                  </th>
                  <th className="px-6 py-4 text-right text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                    Aksi
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-100">
                {filteredNotifications.map((notification) => {
                  const Icon = typeIcons[notification.type];
                  return (
                    <tr key={notification.id} className={`hover:bg-zinc-50 ${!notification.is_read ? 'bg-blue-50/30' : ''}`}>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className={`p-2 rounded-lg ${
                            notification.type === 'new_user' ? 'bg-green-100 text-green-600' :
                            notification.type === 'new_transaction' ? 'bg-blue-100 text-blue-600' :
                            'bg-amber-100 text-amber-600'
                          }`}>
                            <Icon className="w-4 h-4" />
                          </div>
                          <span className="text-sm font-medium text-zinc-900">
                            {typeLabels[notification.type]}
                          </span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm font-semibold text-zinc-900">
                          {notification.title}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm text-zinc-600 max-w-md truncate">
                          {notification.message}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm text-zinc-500">
                          {formatTime(notification.created_at)}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        {!notification.is_read ? (
                          <span className="inline-flex px-2 py-1 text-xs font-semibold bg-blue-100 text-blue-800 rounded-full">
                            Belum Dibaca
                          </span>
                        ) : (
                          <span className="inline-flex px-2 py-1 text-xs font-semibold bg-zinc-100 text-zinc-800 rounded-full">
                            Dibaca
                          </span>
                        )}
                      </td>
                      <td className="px-6 py-4 text-right">
                        {!notification.is_read && (
                          <button
                            onClick={() => handleMarkAsRead(notification.id)}
                            className="text-zinc-600 hover:text-zinc-900 p-1"
                            title="Tandai sebagai dibaca"
                          >
                            <CheckCheck className="w-4 h-4" />
                          </button>
                        )}
                        <button className="text-zinc-400 hover:text-zinc-600 p-1 ml-2">
                          <MoreHorizontal className="w-4 h-4" />
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination placeholder */}
        {notifications.length > 0 && (
          <div className="px-6 py-4 border-t border-zinc-200 bg-zinc-50">
            <div className="flex items-center justify-between">
              <p className="text-sm text-zinc-700">
                Menampilkan {filteredNotifications.length} notifikasi
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                  disabled={currentPage === 1}
                  className="px-3 py-1 text-sm border border-zinc-300 rounded hover:bg-zinc-50 disabled:opacity-50"
                >
                  Previous
                </button>
                <span className="px-3 py-1 text-sm text-zinc-700">
                  Halaman {currentPage}
                </span>
                <button
                  onClick={() => setCurrentPage(prev => prev + 1)}
                  className="px-3 py-1 text-sm border border-zinc-300 rounded hover:bg-zinc-50"
                >
                  Next
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}