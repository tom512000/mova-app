import { cn } from '@/utils/cn'

/**
 * Shared by the masthead's links and by the Jeux dropdown's trigger, so a menu sitting in
 * the nav row is indistinguishable from a link until it opens.
 */
export function navItemClass(isActive: boolean): string {
  return cn(
    'border-b-2 px-3.5 py-2.5 font-sans text-xs font-semibold uppercase tracking-widest transition-colors duration-200',
    isActive ? 'border-accent text-accent' : 'border-transparent text-ink hover:text-accent'
  )
}
