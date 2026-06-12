import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import ProdukPublicPage from '@/pages/produk/index';

export default function PublicRouter() {
  return (
    <Router>
      <Routes>
        <Route path="/produk" element={<ProdukPublicPage />} />
        <Route path="*" element={<Navigate to="/produk" />} />
      </Routes>
    </Router>
  );
}
