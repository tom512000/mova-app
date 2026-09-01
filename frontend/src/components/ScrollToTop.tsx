import { useLayoutEffect } from 'react'
import { useLocation, useNavigationType } from 'react-router-dom'
import { scrollToTop } from '@/utils/scroll'

/**
 * Puts every navigation back at the top of the page.
 *
 * A browser does this on its own for a real page load. A router that swaps the document's
 * contents underneath a scroll position it keeps does not, so page two of the library used
 * to open halfway down itself.
 *
 * `search` sits in the dependencies alongside `pathname`, not as an afterthought: the
 * library holds its page number, its filters and its sort in the URL, so paging through it
 * is a navigation that never touches the path.
 *
 * POP is deliberately left alone. Coming back from a film has to land on the card it was
 * opened from — the library goes to some trouble to keep the exact list you left, and
 * throwing its scroll position away would undo most of that.
 */
export function ScrollToTop() {
  const { pathname, search } = useLocation()
  const navigationType = useNavigationType()

  // A layout effect rather than an effect: it runs before paint, so the new page is drawn at
  // the top instead of being drawn where the old one stood and then yanked upwards.
  useLayoutEffect(() => {
    if (navigationType === 'POP') return
    scrollToTop()
  }, [pathname, search, navigationType])

  return null
}
