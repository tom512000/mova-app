import type { CSSProperties } from 'react'
import { cn } from '@/utils/cn'

/**
 * A list's cover: its first posters dealt out like a hand of cards, overlapping, the first
 * on top. Hovering the card fans them out.
 *
 * Square-cornered and hard-shadowed like everything else in the sheet — the fan is drawn
 * with offsets, never with rotation, so the 90-degree rule survives.
 */
export function ListCover({ posterUrls, name, className }: { posterUrls: string[]; name: string; className?: string }) {
  return (
    <div
      className={cn(
        'relative flex h-52 items-end overflow-hidden border-b border-ink bg-surface px-5 pt-6',
        // The same dot screen the poster placeholders use: an empty cover reads as an
        // absence of artwork, not as a broken image.
        'bg-[radial-gradient(color-mix(in_srgb,var(--ink)_12%,transparent)_1px,transparent_1px)] bg-size-[10px_10px]',
        className
      )}
    >
      {posterUrls.length === 0 ? (
        <span className="mb-auto mt-auto self-center bg-paper px-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
          Pas encore d&rsquo;affiches
        </span>
      ) : (
        posterUrls.map((url, index) => (
          <img
            key={`${index}-${url}`}
            src={url}
            // The first poster names the list for a screen reader; the rest are decoration.
            alt={index === 0 ? `Affiches de la liste ${name}` : ''}
            loading="lazy"
            style={{ zIndex: posterUrls.length - index, '--fan': `${index * 14}px` } as CSSProperties}
            className={cn(
              'aspect-2/3 h-44 shrink-0 border border-ink object-cover shadow-[3px_3px_0_0_var(--ink)]',
              'grayscale transition-all duration-300 ease-out group-hover:translate-x-(--fan) group-hover:grayscale-0',
              'motion-reduce:transition-none',
              index > 0 && '-ml-16'
            )}
          />
        ))
      )}
    </div>
  )
}
