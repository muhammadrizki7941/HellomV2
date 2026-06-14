import { useEffect, useState } from 'react';

interface DeferredPrompt extends Event {
  prompt(): Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

export type PWAInstallState = 'checking' | 'available' | 'ios' | 'installed' | 'unavailable';

export function usePWAInstall() {
  const [state, setState] = useState<PWAInstallState>('checking');
  const [prompt, setPrompt] = useState<DeferredPrompt | null>(null);

  useEffect(() => {
    const isStandalone =
      window.matchMedia('(display-mode: standalone)').matches ||
      (navigator as { standalone?: boolean }).standalone === true;

    if (isStandalone) {
      setState('installed');
      return;
    }

    const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    if (isIos && isSafari) {
      setState('ios');
      return;
    }

    const onBeforeInstall = (e: Event) => {
      e.preventDefault();
      setPrompt(e as DeferredPrompt);
      setState('available');
    };

    const onInstalled = () => {
      setState('installed');
      setPrompt(null);
    };

    window.addEventListener('beforeinstallprompt', onBeforeInstall);
    window.addEventListener('appinstalled', onInstalled);

    const timer = window.setTimeout(() => {
      setState((cur) => (cur === 'checking' ? 'unavailable' : cur));
    }, 3500);

    return () => {
      window.removeEventListener('beforeinstallprompt', onBeforeInstall);
      window.removeEventListener('appinstalled', onInstalled);
      clearTimeout(timer);
    };
  }, []);

  const install = async () => {
    if (!prompt) return;
    await prompt.prompt();
    const { outcome } = await prompt.userChoice;
    if (outcome === 'accepted') {
      setState('installed');
      setPrompt(null);
    }
  };

  return { state, install };
}
