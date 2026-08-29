import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { ChevronDown } from 'lucide-react'
import { navItemClass } from '@/layouts/navItemClass'

export interface NavDropdownEntry {
  to: string
  label: string
  hint?: string
}

/** Space left between the bottom of an open menu and the bottom of the window. */
const GUTTER = 16

/**
 * A nav entry that opens a short list instead of navigating. Deliberately hand-rolled
 * rather than a <details>: it has to close when you click elsewhere or press Escape, which
 * <details> does not do, and it has to sit flush in the masthead's rule.
 */
export function NavDropdown({
  label,
  items,
  matchPrefix,
}: {
  label: string
  items: NavDropdownEntry[]
  /** Path prefix that lights the trigger up, e.g. '/games'. */
  matchPrefix: string
}) {
  const [isOpen, setIsOpen] = useState(false)
  const [maxHeight, setMaxHeight] = useState<number>()
  const containerRef = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const menuId = useId()
  const { pathname } = useLocation()

  const isActive = pathname.startsWith(matchPrefix)

  /**
   * Caps the menu at whatever room is left below it, so a long one scrolls instead of
   * running off the bottom of the window.
   *
   * The height is measured rather than written down as a vh fraction. The masthead is
   * sticky, so the menu always opens the same distance from the top of the viewport — but
   * that distance is the masthead's own height, which changes with the breakpoint and with
   * the reader's font size, and is not a number worth guessing at.
   */
  useLayoutEffect(() => {
    if (!isOpen) return

    function fit() {
      const panel = panelRef.current
      if (!panel) return

      // Reading `top` and not `height`: the cap must not depend on the cap already applied.
      setMaxHeight(window.innerHeight - panel.getBoundingClientRect().top - GUTTER)
    }

    fit()
    window.addEventListener('resize', fit)
    return () => window.removeEventListener('resize', fit)
  }, [isOpen])

  useEffect(() => {
    if (!isOpen) return

    function onPointerDown(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setIsOpen(false)
    }
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setIsOpen(false)
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [isOpen])

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setIsOpen((open) => !open)}
        aria-haspopup="menu"
        aria-expanded={isOpen}
        aria-controls={menuId}
        className={`${navItemClass(isActive)} inline-flex items-center gap-1.5`}
      >
        {label}
        <ChevronDown
          className={`h-3 w-3 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`}
          strokeWidth={2}
          aria-hidden
        />
      </button>

      {isOpen && (
        <div
          ref={panelRef}
          id={menuId}
          role="menu"
          style={{ maxHeight }}
          // overscroll-contain so reaching the end of the list stops there rather than
          // handing the wheel to the page underneath.
          className="absolute left-1/2 z-50 mt-1 w-60 -translate-x-1/2 overflow-y-auto overscroll-contain border border-ink bg-paper shadow-[4px_4px_0_0_var(--ink)]"
        >
          {items.map((item) => (
            <Link
              key={item.to}
              to={item.to}
              role="menuitem"
              // Choosing an item is also what closes the menu; clicking anywhere else is
              // caught by the outside-pointer handler.
              onClick={() => setIsOpen(false)}
              className="block border-b border-ink/15 px-4 py-3 text-left last:border-b-0 hover:bg-ink hover:text-paper"
            >
              <span className="block font-sans text-xs font-semibold uppercase tracking-widest">{item.label}</span>
              {/* opacity rather than a colour token, so it stays legible on the inverted hover state. */}
              {item.hint && <span className="mt-0.5 block font-mono text-[10px] opacity-60">{item.hint}</span>}
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
