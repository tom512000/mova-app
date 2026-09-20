import { Link } from 'react-router-dom'
import type { ReactNode } from 'react'
import { cn } from '@/utils/cn'

/**
 * The "and the rest of it" link that sits at the end of a section heading.
 *
 * Lives here rather than beside one page's markup because three surfaces now offer the same
 * move — the badge strip, the Cabinet's recent finds, and whatever comes next — and the same
 * reasoning FilterSelect gives applies: a link that looked slightly different on each would
 * read as three different applications.
 *
 * Underlined at rest and not only on hover, which is the part worth insisting on. The
 * Button component's `link` variant reverses that, and next to a heavy serif heading it
 * leaves something that reads as plain text nobody would think to click.
 */
export function SectionLink({
  to,
  children,
  className,
}: {
  to: string
  children: ReactNode
  className?: string
}) {
  return (
    <Link
      to={to}
      className={cn(
        'font-mono text-xs uppercase tracking-widest text-accent underline decoration-2 underline-offset-4 hover:no-underline',
        className
      )}
    >
      {children}
    </Link>
  )
}
