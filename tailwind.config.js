/** @type {import('tailwindcss').Config} */
module.exports = {
  theme: {
    extend: {
      colors: {
        'cosmic-bg': '#0a0a0f',
        'void-dark': '#11111a',
        'nebula-purple': '#533483',
        'grok-red': '#e94560',
        'grok-cyan': '#0fffc1',
        'star-white': '#e0e0ff',
      },
      backgroundImage: {
        'cosmic-gradient': 'linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #16213e 100%)',
      },
      fontFamily: {
        'sans': ['Space Grotesk', 'Inter', 'system-ui', 'sans-serif'],
      },
      spacing: {
        '4': '4px',
        '8': '8px',
        '12': '12px',
        '16': '16px',
        '24': '24px',
        '32': '32px',
        '48': '48px',
        '64': '64px',
        '96': '96px',
      },
      boxShadow: {
        'glow-sm': '0 2px 8px rgba(233, 69, 96, 0.15)',
        'glow-md': '0 4px 16px rgba(233, 69, 96, 0.2)',
        'glow-lg': '0 8px 32px rgba(233, 69, 96, 0.25)',
      },
      keyframes: {
        'orbit-spin': {
          '0%': { transform: 'rotate(0deg)' },
          '100%': { transform: 'rotate(360deg)' },
        },
        'star-pulse': {
          '0%, 100%': { opacity: '0.4' },
          '50%': { opacity: '0.8' },
        },
        'fade-in-up': {
          '0%': { opacity: '0', transform: 'translateY(20px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        'float': {
          '0%, 100%': { transform: 'translateY(0)' },
          '50%': { transform: 'translateY(-10px)' },
        },
      },
      animation: {
        'orbit-spin': 'orbit-spin 20s linear infinite',
        'star-pulse': 'star-pulse 3s ease-in-out infinite',
        'fade-in-up': 'fade-in-up 0.5s ease-out',
        'float': 'float 3s ease-in-out infinite',
      },
    },
  },
}

