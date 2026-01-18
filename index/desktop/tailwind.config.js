/** @type {import('tailwindcss').Config} */
export default {
  darkMode: ['class'],
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        primary: '#13b6ec',
        'background-light': '#f6f8f8',
        'background-dark': '#101d22',
        'surface-light': '#ffffff',
        'surface-dark': '#1a2c32',
        'border-light': '#e2e8f0',
        'border-dark': '#2a3c42',
        'text-main': '#0d181b',
        'text-secondary': '#4c869a',
        'status-running': '#13b6ec',
        'status-success': '#22c55e',
        'status-warning': '#f59e0b',
        'status-error': '#ef4444',
        'status-pending': '#94a3b8',
      },
      fontFamily: {
        sans: ['Inter', 'Noto Sans SC', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'monospace'],
      },
      borderRadius: {
        DEFAULT: '0.25rem',
        lg: '0.5rem',
        xl: '0.75rem',
      },
    },
  },
  plugins: [],
};
