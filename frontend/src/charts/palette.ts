import { useEffect, useState } from 'react'

// Validated default palette (see dataviz skill, references/palette.md).
// Single-series charts use the blue sequential hue; multi-series ones would
// pull additional slots in this fixed order (never cycled/reassigned).
export const CHART_COLORS = {
  seriesBlueLight: '#2a78d6',
  seriesBlueDark: '#3987e5',
  gridlineLight: '#e1e0d9',
  gridlineDark: '#2c2c2a',
  axisLight: '#898781',
  axisDark: '#898781',
  surfaceLight: '#fcfcfb',
  surfaceDark: '#1a1a19',
  textPrimaryLight: '#0b0b0b',
  textPrimaryDark: '#ffffff',
}

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
