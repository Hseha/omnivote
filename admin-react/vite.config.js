import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
    server: {
    proxy: {
      '/api': {
        target: 'https://debian.tail7e9e1e.ts.net',
        changeOrigin: true,
        secure: false,
      },
      '/sanctum': {
        target: 'https://debian.tail7e9e1e.ts.net',
        changeOrigin: true,
        secure: false,
      },
    },
  },
})
