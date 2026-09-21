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
      registerType: 'autoUpdate',
      /*
       * `autoUpdate` — the new worker activates immediately instead of
       * waiting, and claims the open pages.
       *
       * This was 'prompt', which let src/updater.ts pick the moment the
       * worker swapped. It was the tidier design and it had one fatal
       * property: a till running a build with NO updater in it could never
       * apply the waiting worker, because nothing on that page knew to tell
       * it to go. Those tills sat on old code indefinitely and a refresh did
       * not help them — measured, not guessed: two refreshes left the new
       * worker in `waiting` and the page on the old bundle, and only closing
       * the tab released it.
       *
       * Skipping the wait costs the clean swap: the new worker takes over
       * while the old page is still running and `cleanupOutdatedCaches`
       * drops the previous precache, so a page that asks for an asset it has
       * not already loaded can miss. The blast radius here is small — the
       * till ships one JS and one CSS bundle with no code-splitting, so the
       * running page already holds its code, and what is left is the odd
       * icon for the few seconds until the reload below fires.
       *
       * The page still chooses WHEN to reload. src/updater.ts waits for the
       * till to be idle; all that changed is the signal it waits for.
       */
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
