import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, X } from 'lucide-react';
import { dismissLiveToast, subscribeLiveToasts, type LiveNotification } from '@/lib/liveNotifications';

/** Small stack of "new notification" toasts (bottom-right). Mount once per layout. */
export default function LiveToasts() {
  const navigate = useNavigate();
  const [toasts, setToasts] = useState<LiveNotification[]>([]);

  useEffect(() => subscribeLiveToasts(setToasts), []);

  const visible = toasts.filter((toast) => toast.id > 0);
  if (visible.length === 0) return null;

  const open = (toast: LiveNotification) => {
    dismissLiveToast(toast.key);
    const url = toast.action_url ?? (toast.channel === 'admin' ? '/admin/notifications' : null);
    if (url?.startsWith('/')) navigate(url);
  };

  return (
    <div className="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[calc(100vw-2rem)] max-w-sm flex-col gap-2" aria-live="polite">
      {visible.map((toast) => (
        <div
          key={toast.key}
          className="pointer-events-auto flex items-start gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-xl"
        >
          <div className="rounded-xl bg-zinc-900 p-2 text-white">
            <Bell className="h-4 w-4" />
          </div>
          <button type="button" onClick={() => open(toast)} className="min-w-0 flex-1 text-left">
            <p className="truncate text-sm font-semibold text-zinc-900">{toast.title}</p>
            {toast.body && <p className="mt-0.5 line-clamp-2 text-sm text-zinc-600">{toast.body}</p>}
          </button>
          <button
            type="button"
            onClick={() => dismissLiveToast(toast.key)}
            className="rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
            aria-label="Tutup notifikasi"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      ))}
    </div>
  );
}
