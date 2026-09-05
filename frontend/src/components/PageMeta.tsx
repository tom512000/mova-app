import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'

const SITE = 'Mova'

/**
 * Per-page title, and the noindex flag for everything that is not meant to be found.
 *
 * The two are set by different mechanisms, and the split is not arbitrary. React 19 hoists
 * a `<meta>` rendered anywhere in the tree into `<head>`, which is exactly what is wanted for
 * the robots tag — index.html carries none, so there is nothing to collide with. It would
 * hoist a `<title>` just as happily, but index.html *does* carry one, and a browser reads the
 * first title in the document: a hoisted second one is silently ignored. So the title is
 * assigned imperatively, where it always wins.
 *
 * Keeping the static title in index.html is the point of the whole arrangement — the crawlers
 * that build link previews never run JavaScript, so a title only a component knows about is
 * a title they never see.
 *
 * The canonical link works the third way again: index.html carries one pointing at the
 * homepage, and this rewrites that same element's href rather than rendering a second. Two
 * canonical tags on a page is a canonical no crawler can resolve.
 */
export function PageMeta({ title, noindex = false }: { title?: string; noindex?: boolean }) {
  const { pathname } = useLocation()

  useEffect(() => {
    const link = document.querySelector<HTMLLinkElement>('link[rel="canonical"]')
    if (null === link) {
      return
    }

    // Built from the origin actually being served rather than from a constant, so it cannot
    // name the wrong host — the failure a canonical is least forgiving of. On a development
    // machine it points at localhost, which nothing crawls.
    //
    // The path only, never the query: /movies?genre=Comedy is one view of the library, not a
    // page of its own, and telling a crawler otherwise invites it to index a filter combination.
    link.href = `${window.location.origin}${pathname}`
  }, [pathname])

  useEffect(() => {
    // The guard is load-bearing, not defensive. AppLayout renders this with `noindex` and no
    // title at all, and React flushes effects child-first — so the layout's effect runs after
    // the page's, and without this it would overwrite every title with the bare site name a
    // frame after the page had set its own.
    if (undefined === title) {
      return
    }

    document.title = `${title} · ${SITE}`

    // Leaving the last page's name in the tab after navigating away is how a browser history
    // ends up full of entries that all claim to be the same screen.
    return () => {
      document.title = SITE
    }
  }, [title])

  return noindex ? <meta name="robots" content="noindex, nofollow" /> : null
}
