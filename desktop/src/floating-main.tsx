import React from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import FloatingWindowV2 from './pages/FloatingWindowV2';
import './index.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5,
      retry: 1,
    },
  },
});

ReactDOM.createRoot(document.getElementById('floating-root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <FloatingWindowV2 />
    </QueryClientProvider>
  </React.StrictMode>
);
