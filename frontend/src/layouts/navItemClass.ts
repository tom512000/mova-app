import { cn } from '@/utils/cn'

/**
 * Shared by the masthead's links and by the Jeux dropdown's trigger, so a menu sitting in
 * the nav row is indistinguishable from a link until it opens.
 *
 * Tighter at lg than at xl: at 1024 pixels the wider padding is what pushed the row past
 * the width of the page, and a nav that wraps under a sticky masthead doubles its height.
 */
export function navItemClass(isActive: boolean): string {
  return cn(
    'border-b-2 px-2.5 py-2.5 font-sans text-xs font-semibold uppercase tracking-widest transition-colors duration-200 xl:px-3.5',
    isActive ? 'border-accent text-accent' : 'border-transparent text-ink hover:text-accent'
  )
}

/**
 * The same entries in the phone menu. The active one is marked down its left edge rather
 * than under it, since the rows stack instead of standing side by side.
 */
export function menuItemClass(isActive: boolean): string {
  return cn(
    'flex min-h-11 items-center border-l-2 pl-3 font-sans text-xs font-semibold uppercase tracking-widest transition-colors duration-200',
    isActive ? 'border-accent text-accent' : 'border-transparent text-ink hover:text-accent'
  )
}
