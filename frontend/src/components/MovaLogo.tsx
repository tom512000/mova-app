import { cn } from '@/utils/cn'

type Mark = 'wordmark' | 'lockup' | 'monogram'

interface MovaLogoProps {
  /**
   * `wordmark` is "Mova" over the red rule — the masthead. `lockup` adds the
   * "STATS. FILMS. YOU." line under it and only earns its space on a page with
   * nothing else on it. `monogram` is the M alone, for the small signatures.
   */
  mark?: Mark
  /** Height utility, e.g. `h-11 w-auto`. Both colourways receive it. */
  className?: string
}

/**
 * The artwork is cream on transparent — the dark edition's ink — so it would vanish on the
 * paper edition. Each mark therefore ships in two colourways, and the theme picks between
 * them in CSS rather than in JS: swapping an `src` on toggle re-fetches and blinks, whereas
 * a hidden sibling is already decoded. Whichever one is hidden is `display: none`, so it
 * leaves the accessibility tree too and the name is announced exactly once.
 */
export function MovaLogo({ mark = 'wordmark', className }: MovaLogoProps) {
  return (
    <>
      <img src={`/mova-${mark}-ink.png`} alt="Mova" className={cn('dark:hidden', className)} />
      <img src={`/mova-${mark}.png`} alt="Mova" className={cn('hidden dark:block', className)} />
    </>
  )
}
