/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
    './resources/**/*.vue',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
      },
      colors: {
        // Asos — sovuq slate (laboratoriya muhiti)
        surface: {
          DEFAULT: 'rgb(var(--surface) / <alpha-value>)',
          sunken:  'rgb(var(--surface-sunken) / <alpha-value>)',
          raised:  'rgb(var(--surface-raised) / <alpha-value>)',
        },
        ink: {
          DEFAULT: 'rgb(var(--ink) / <alpha-value>)',
          muted:   'rgb(var(--ink-muted) / <alpha-value>)',
          faint:   'rgb(var(--ink-faint) / <alpha-value>)',
        },
        line: 'rgb(var(--line) / <alpha-value>)',
        // Aksent — probirlash oltini (ЦНИЛ — oltin laboratoriyasi)
        assay: {
          50:  '#FBF6EB', 100: '#F5E9CC', 200: '#EBD49B',
          300: '#DFBA66', 400: '#D1A03F', 500: '#B8862A',
          600: '#996C21', 700: '#78531D', 800: '#5C401B',
          900: '#463317',
        },
        // Suv laboratoriyasi uchun ikkilamchi
        aqua: {
          50: '#EDF7F8', 100: '#D2ECEF', 200: '#A6D7DE',
          300: '#71BCC7', 400: '#479DAB', 500: '#2E7D8A',
          600: '#256570', 700: '#20515A', 800: '#1D4249', 900: '#1A383E',
        },
        ok:    { light: '#E7F2EB', DEFAULT: '#2F7A4F', dark: '#1E5235' },
        warn:  { light: '#FAF0DC', DEFAULT: '#B5751F', dark: '#7D4F14' },
        crit:  { light: '#F8E9E9', DEFAULT: '#B33A3A', dark: '#7C2727' },
      },
      boxShadow: {
        panel: '0 1px 2px rgb(15 26 36 / 0.04), 0 4px 16px -4px rgb(15 26 36 / 0.08)',
        pop:   '0 8px 32px -8px rgb(15 26 36 / 0.22)',
      },
      borderRadius: {
        panel: '10px',
      },
      keyframes: {
        'slide-in-right': {
          from: { transform: 'translateX(16px)', opacity: '0' },
          to:   { transform: 'translateX(0)', opacity: '1' },
        },
        'pop-in': {
          from: { transform: 'scale(.97)', opacity: '0' },
          to:   { transform: 'scale(1)', opacity: '1' },
        },
        'row-in': {
          from: { opacity: '0', transform: 'translateY(4px)' },
          to:   { opacity: '1', transform: 'translateY(0)' },
        },
        shimmer: {
          '100%': { transform: 'translateX(100%)' },
        },
      },
      animation: {
        'slide-in-right': 'slide-in-right .22s cubic-bezier(.2,.8,.3,1)',
        'pop-in': 'pop-in .18s cubic-bezier(.2,.8,.3,1)',
        'row-in': 'row-in .2s ease-out both',
      },
    },
  },
  plugins: [],
}
