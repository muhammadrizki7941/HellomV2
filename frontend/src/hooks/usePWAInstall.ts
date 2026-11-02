import { useEffect, useState } from 'react';

interface DeferredPrompt extends Event {
  prompt(): Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

export type PWAInstallState = 'available' | 'ios' | 'manual' | 'installed';

let deferredPrompt: DeferredPrompt | null = null;
let listenersInitialized = false;
let installed = false;
const listeners = new Set<() => void>();

function isStandalone(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches ||
    (navigator as { standalone?: boolean }).standalone === true;
}

function isIosSafari(): boolean {
  const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  return ios && /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
}

function emitChange(): void {
  listeners.forEach((listener) => listener());
}

export function initializePWAInstallPrompt(): void {
  if (listenersInitialized || typeof window === 'undefined') return;
  listenersInitialized = true;
  installed = isStandalone();

  window.addEventListener('beforeinstallprompt', (event: Event) => {
    event.preventDefault();
    deferredPrompt = event as DeferredPrompt;
    emitChange();
  });
  window.addEventListener('appinstalled', () => {
    installed = true;
    deferredPrompt = null;
    emitChange();
  });
  window.matchMedia('(display-mode: standalone)').addEventListener?.('change', (event) => {
    installed = event.matches;
    if (installed) deferredPrompt = null;
    emitChange();
  });
}

function getInstallState(): PWAInstallState {
  if (installed || isStandalone()) return 'installed';
  if (isIosSafari()) return 'ios';
  return deferredPrompt ? 'available' : 'manual';
}

export function usePWAInstall() {
  const [state, setState] = useState<PWAInstallState>(getInstallState);

  useEffect(() => {
    initializePWAInstallPrompt();
    const refresh = () => setState(getInstallState());
    listeners.add(refresh);
    refresh();
    return () => {
      listeners.delete(refresh);
    };
  }, []);

  const install = async (): Promise<'accepted' | 'dismissed' | null> => {
    if (!deferredPrompt) return null;
    const prompt = deferredPrompt;
    deferredPrompt = null;
    emitChange();
    await prompt.prompt();
    const { outcome } = await prompt.userChoice;
    if (outcome === 'accepted') {
      installed = true;
    }
    emitChange();
    return outcome;
  };

  return { state, install };
}
