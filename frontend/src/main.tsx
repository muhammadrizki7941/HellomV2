import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App.tsx';
import { registerPwaServiceWorker } from './lib/pwa';
import { initializePWAInstallPrompt } from './hooks/usePWAInstall';
import './index.css';
import './styles/dashboard-dark.css';

const basename = window.location.pathname.startsWith('/hellom') ? '/hellom' : '/';

initializePWAInstallPrompt();
registerPwaServiceWorker();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter basename={basename}>
      <App />
    </BrowserRouter>
  </StrictMode>,
);
