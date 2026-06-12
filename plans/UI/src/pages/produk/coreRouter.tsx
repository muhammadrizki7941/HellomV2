import { createBrowserRouter } from 'react-router-dom';
import ProdukPublicPage from '@/pages/produk/index';

export const router = createBrowserRouter([
  { path: '/', element: <ProdukPublicPage /> },
  { path: '/produk', element: <ProdukPublicPage /> },
]);
