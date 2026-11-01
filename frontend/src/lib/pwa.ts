export const PWA_UPDATE_AVAILABLE_EVENT = 'hellom:pwa-update-available';
export const POS_TRANSACTION_STATE_EVENT = 'hellom:pos-transaction-state';

let registration: ServiceWorkerRegistration | null = null;
let reloadAfterActivation = false;
let pwaUpdateAvailable = false;
let posTransactionInProgress = false;
let posHasUnsyncedData = false;
let workerActivatedPendingReload = false;

function announceWaitingWorker(workerRegistration: ServiceWorkerRegistration) {
  if (!navigator.serviceWorker.controller || !workerRegistration.waiting) return;
  pwaUpdateAvailable = true;
  window.dispatchEvent(new Event(PWA_UPDATE_AVAILABLE_EVENT));
}

export function registerPwaServiceWorker() {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) return;

  void navigator.serviceWorker
    .register('/sw.js', { scope: '/', updateViaCache: 'none' })
    .then((workerRegistration) => {
      registration = workerRegistration;
      announceWaitingWorker(workerRegistration);

      workerRegistration.addEventListener('updatefound', () => {
        const installing = workerRegistration.installing;
        installing?.addEventListener('statechange', () => {
          if (installing.state === 'installed') announceWaitingWorker(workerRegistration);
        });
      });

      window.addEventListener('focus', () => {
        void workerRegistration.update().catch((error: unknown) => {
          console.warn('[PWA] Failed to check for an update:', error);
        });
      });
    })
    .catch((error: unknown) => {
      console.warn('[SW] Registration failed:', error);
    });

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!reloadAfterActivation) return;
    if (posTransactionInProgress || posHasUnsyncedData) {
      reloadAfterActivation = false;
      workerActivatedPendingReload = true;
      pwaUpdateAvailable = true;
      window.dispatchEvent(new Event(PWA_UPDATE_AVAILABLE_EVENT));
      return;
    }
    window.location.reload();
  });

  window.addEventListener('pos-sync-queue-updated', () => {
    posHasUnsyncedData = true;
  });
}

export function activatePwaUpdate() {
  if (posTransactionInProgress || posHasUnsyncedData) return false;
  const waitingWorker = registration?.waiting;
  if (!waitingWorker) {
    if (!workerActivatedPendingReload) return false;
    workerActivatedPendingReload = false;
    window.location.reload();
    return true;
  }

  reloadAfterActivation = true;
  waitingWorker.postMessage({ type: 'SKIP_WAITING' });
  return true;
}

export function hasPwaUpdateAvailable() {
  return pwaUpdateAvailable;
}

export function setPosTransactionInProgress(active: boolean) {
  posTransactionInProgress = active;
  window.dispatchEvent(new CustomEvent(POS_TRANSACTION_STATE_EVENT, { detail: active }));
}

export function isPosTransactionInProgress() {
  return posTransactionInProgress;
}

export function setPosHasUnsyncedData(hasUnsyncedData: boolean) {
  posHasUnsyncedData = hasUnsyncedData;
}
