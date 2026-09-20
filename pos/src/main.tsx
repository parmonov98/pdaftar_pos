import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { applyTheme, getTheme } from './theme'
import { watchForUpdates } from './updater'

// Before React mounts, so the very first paint is already the right theme.
// Applying it inside a component means one frame of the wrong colours, which on
// a dark till reads as a flash of white every time the page loads.
applyTheme(getTheme())

// A kassa tab is opened in the morning and not closed again. Without this a
// deploy reaches it only if somebody happens to reload.
watchForUpdates()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
