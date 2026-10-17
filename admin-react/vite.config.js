import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],

  // One build backs two same-origin mounts: the staff console at /admin/ and
  // the SSG President console at /ssg/ (nginx aliases both onto this folder).
  // A relative base makes the emitted asset URLs resolve against whatever
  // directory index.html was served from, so the same bundle works at either
  // prefix without rebuilding. Absolute /assets/... URLs would miss both.
  //
  // API calls are unaffected: src/lib/api.js builds origin-relative paths
  // (`${API_BASE_URL}/api/...` with API_BASE_URL defaulting to ''), so they
  // resolve against the origin root, not against the mount prefix.
  base: './',

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
