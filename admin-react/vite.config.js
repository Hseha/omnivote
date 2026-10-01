import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],

  // The console is served same-origin from https://<host>/admin/ by the nginx
  // vhost in deploy/nginx-omnivote.conf. `base` must match that prefix or the
  // built asset URLs resolve to /assets/... at the origin root, where the API
  // vhost's `default-src 'none'` CSP has no script/style source to allow them.
  //
  // API calls are unaffected: src/lib/api.js builds origin-relative paths
  // (`${API_BASE_URL}/api/...` with API_BASE_URL defaulting to ''), so they
  // resolve against the origin root, not against this prefix.
  base: '/admin/',

  build: {
    // Emit into the Laravel public/ folder so the console shares the existing
    // document root. `emptyOutDir` only ever clears this admin/ subfolder —
    // pointing outDir at public/ itself would delete index.php and .htaccess
    // and take the whole API down with it.
    outDir: '../backend-laravel/public/admin',
    emptyOutDir: true,
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
