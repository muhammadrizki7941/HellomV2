import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './index.html',
    './src/**/*.{js,ts,jsx,tsx}'
  ],
  theme: {
    extend: {
      fontFamily: {
        display: ['Space Grotesk', 'sans-serif'],
        body: ['Plus Jakarta Sans', 'sans-serif'],
      },
      colors: {
        'brand-bg': '#0c0c0c',
        'brand-bg2': '#141414',
        'brand-bg3': '#1c1c1c',
        'brand-accent': '#c8ff47',
        'brand-text': '#f0ede8',
        'brand-muted': '#888580',
      }
    }
  },
  plugins: [],
};
