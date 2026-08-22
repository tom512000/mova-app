import { useEffect, useState } from 'react'

// Newsprint palette: charts stay ink-on-paper like the rest of the app —
// solid black bars on the light "paper" edition, off-white on the dark
// "midnight" edition. No color-coding; the accent red is reserved for UI
// interaction states, not data series, per the design system's "99% black
// and white" rule. Mirrors the CSS custom properties in index.css.
export const CHART_COLORS = {
  seriesBlueLight: '#111111',
  seriesBlueDark: '#f3f1ea',
  gridlineLight: '#e5e5e0',
  gridlineDark: '#2b2a27',
  axisLight: '#737373',
  axisDark: '#a8a6a0',
  surfaceLight: '#f9f9f7',
  surfaceDark: '#131211',
  textPrimaryLight: '#111111',
  textPrimaryDark: '#f3f1ea',
}

export const CHART_FONT_MONO = "'JetBrains Mono', 'Courier New', monospace"

/** Reactively tracks the `.dark` class on <html>, so mounted charts re-color on theme toggle. */
export function useIsDarkMode(): boolean {
  const [isDark, setIsDark] = useState(() => document.documentElement.classList.contains('dark'))

  useEffect(() => {
    const target = document.documentElement
    const observer = new MutationObserver(() => setIsDark(target.classList.contains('dark')))
    observer.observe(target, { attributes: true, attributeFilter: ['class'] })
    return () => observer.disconnect()
  }, [])

  return isDark
}
