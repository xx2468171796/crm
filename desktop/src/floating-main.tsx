import React from 'react';
import ReactDOM from 'react-dom/client';
import FloatingWindowV2 from './pages/FloatingWindowV2';
import './index.css';

ReactDOM.createRoot(document.getElementById('floating-root')!).render(
  <React.StrictMode>
    <FloatingWindowV2 />
  </React.StrictMode>
);
