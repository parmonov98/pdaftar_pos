import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Local dev talks to the Laravel container through a proxy rather than
// cross-origin. Not for convenience — a same-origin dev setup means the
// browser exercises the same request shape it will in production behind one
// domain, instead of a CORS-preflighted variant that hides header and
// credential mistakes until deploy day.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5174,
    proxy: {
      '/api': {
        target: process.env.POS_API_TARGET ?? 'http://localhost:8083',
        changeOrigin: true,
      },
    },
  },
})
