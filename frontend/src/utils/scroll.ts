/**
 * Back to the top of the document, without the animated glide.
 *
 * 'instant' rather than the default: what was on screen is being replaced, so there is
 * nothing to follow on the way up — gliding through content that is already gone reads as a
 * glitch, and a long smooth scroll is exactly the kind of motion `prefers-reduced-motion`
 * asks us not to invent.
 */
export function scrollToTop(): void {
  window.scrollTo({ top: 0, left: 0, behavior: 'instant' })
}
