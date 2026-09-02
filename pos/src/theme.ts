export type Theme = 'dark' | 'light'

const KEY = 'pos.theme'

/**
 * Light/dark for the till.
 *
 * Not a preference toy: a POS sits under whatever light the shop has. A screen
 * by a sunlit window is unreadable in dark mode, and the same screen in a
 * basement stall at night is glaring in light mode. The seller is the only one
 * who knows which, so the choice is theirs and it is remembered per device.
 *
 * Applied as `data-theme` on <html> rather than a class on <body>, so the value
 * is set before React mounts and the first paint is already correct — a white
 * flash on a dark till reads as a bug.
 */
export function getTheme(): Theme {
  const stored = localStorage.getItem(KEY)
  if (stored === 'dark' || stored === 'light') return stored

  // No stored choice: follow the operating system, which on a shop tablet is
  // usually already set to match the room.
  return window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
}

export function applyTheme(theme: Theme): void {
  document.documentElement.setAttribute('data-theme', theme)
  // Tells the browser which way to render native widgets — scrollbars, date
  // pickers, the number-input spinners. Without it those stay dark on a light
  // page and look broken.
  document.documentElement.style.colorScheme = theme
}

export function setTheme(theme: Theme): void {
  localStorage.setItem(KEY, theme)
  applyTheme(theme)
}
