import { CHART_COLORS, useIsDarkMode } from '@/charts/palette'

/**
 * The six colours every chart in this app resolves the same way. Extracted once the fifth
 * chart repeated the identical block of ternaries.
 */
export function useChartTheme() {
  const isDark = useIsDarkMode()

  return {
    isDark,
    series: isDark ? CHART_COLORS.seriesBlueDark : CHART_COLORS.seriesBlueLight,
    grid: isDark ? CHART_COLORS.gridlineDark : CHART_COLORS.gridlineLight,
    axis: isDark ? CHART_COLORS.axisDark : CHART_COLORS.axisLight,
    surface: isDark ? CHART_COLORS.surfaceDark : CHART_COLORS.surfaceLight,
    text: isDark ? CHART_COLORS.textPrimaryDark : CHART_COLORS.textPrimaryLight,
    cursorFill: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.04)',
  }
}

export function tooltipStyle(surface: string, text: string) {
  return {
    background: surface,
    border: `1px solid ${text}`,
    color: text,
    fontFamily: "'JetBrains Mono', 'Courier New', monospace",
    fontSize: 12,
  }
}
