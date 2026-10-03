import { useEffect, useRef } from 'react';
import { HELLOM_REALTIME_PUBLIC_URL, getRealtimeToken } from '@/lib/hellomApi';

type Socket = {
  on: (event: string, handler: (...args: unknown[]) => void) => void;
  off?: (event: string, handler?: (...args: unknown[]) => void) => void;
  disconnect: () => void;
};

function loadSocketIo(): Promise<boolean> {
  if (window.io) return Promise.resolve(true);
  const scriptUrl = `${HELLOM_REALTIME_PUBLIC_URL.replace(/\/$/, '')}/socket.io/socket.io.js`;

  return new Promise<boolean>((resolve) => {
    const existing = document.querySelector<HTMLScriptElement>(`script[data-socket-io="${scriptUrl}"]`);
    if (existing) {
      if (window.io) return resolve(true);
      existing.addEventListener('load', () => resolve(true), { once: true });
      existing.addEventListener('error', () => resolve(false), { once: true });
      return;
    }
    const script = document.createElement('script');
    script.src = scriptUrl;
    script.async = true;
    script.dataset.socketIo = scriptUrl;
    script.onload = () => resolve(true);
    script.onerror = () => resolve(false);
    document.head.appendChild(script);
  });
}

/**
 * Calls `handler` when the realtime server pushes `event` to the private super admin
 * room. Silent when realtime is not reachable (pages keep their polling).
 */
export function useAdminRealtimeEvent(event: string, handler: () => void) {
  const handlerRef = useRef(handler);
  useEffect(() => {
    handlerRef.current = handler;
  });

  useEffect(() => {
    let cancelled = false;
    let socket: Socket | null = null;
    const onEvent = () => handlerRef.current();

    void loadSocketIo().then((ready) => {
      if (!ready || cancelled || !window.io) return;
      socket = window.io(HELLOM_REALTIME_PUBLIC_URL, {
        transports: ['websocket', 'polling'],
        timeout: 2000,
        // The token puts this socket in the "admins" room; refreshed on every reconnect.
        auth: (cb: (data: { token?: string }) => void) => {
          getRealtimeToken()
            .then((result) => cb({ token: result.token }))
            .catch(() => cb({}));
        },
      });
      socket.on(event, onEvent);
    });

    return () => {
      cancelled = true;
      socket?.off?.(event, onEvent);
      socket?.disconnect();
    };
  }, [event]);
}
