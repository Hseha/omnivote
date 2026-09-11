import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: 'https://100.84.115.25:8000',
        changeOrigin: true,
        secure: false,
      },
      '/sanctum': {
        target: 'https://100.84.115.25:8000',
        changeOrigin: true,
        secure: false,
      },
    },
  },
})
