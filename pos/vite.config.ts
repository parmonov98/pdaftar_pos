import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// Local dev talks to the Laravel container through a proxy rather than
// cross-origin. Not for convenience — a same-origin dev setup means the
// browser exercises the same request shape it will in production behind one
// domain, instead of a CORS-preflighted variant that hides header and
// credential mistakes until deploy day.
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      // Without a service worker, IndexedDB alone does not make a web till
      // offline-capable: the queued sales survive, but reloading or reopening
      // the browser with no connection serves a blank page and the cashier
      // cannot reach them. Precaching the app shell is what makes the till
      // start up at all when the internet is gone.
      // 'prompt', not 'autoUpdate' — and the "prompt" is src/updater.ts,
      // which reloads by itself once the till is idle. autoUpdate skips the
      // wait and swaps the worker under a running page, so a cashier mid-sale
      // ends up on a page whose chunks no longer match the worker serving
      // them, until something forces a reload. Here the new worker waits, and
      // the page chooses the moment.
      registerType: 'prompt',
      // Registered from main.tsx instead, so the same code owns the timing.
      // Left on 'auto' the plugin injects its own registerSW.js and the
      // worker would be registered twice.
      injectRegister: null,
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico,woff2}'],
        // API calls must NEVER be served from a cache. A cached /sync/pull
        // would show yesterday's stock as today's, and a cached POST response
        // would report a sale as written when it never left the device.
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [],
      },
      // Dev keeps the SW on so offline behaviour is testable without a
      // production build — otherwise the one feature that most needs
      // exercising is the one nobody exercises until it ships.
      devOptions: { enabled: true, type: 'module' },
      manifest: {
        name: 'pDaftar POS',
        short_name: 'pDaftar POS',
        description: 'pDaftar kassa ilovasi',
        lang: 'uz',
        theme_color: '#0f1419',
        background_color: '#0f1419',
        display: 'standalone',
        orientation: 'landscape',
        start_url: '/',
        icons: [{ src: '/favicon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' }],
      },
    }),
  ],
  server: {
    port: 5174,
    proxy: {
      // The POS backend is its own service now, on its own port. Everything
      // under /api/pos goes there.
      '/api/pos': {
        target: process.env.POS_API_TARGET ?? 'http://localhost:8090',
        changeOrigin: true,
      },
      // Login and the shop list are still pDaftar's — the POS deliberately does
      // not reimplement authentication, it exchanges a pDaftar user token for a
      // device token. More specific rules are matched first, so this only picks
      // up what /api/pos did not.
      '/api': {
        target: process.env.PDAFTAR_API_TARGET ?? 'http://localhost:8083',
        changeOrigin: true,
      },
    },
  },
})
