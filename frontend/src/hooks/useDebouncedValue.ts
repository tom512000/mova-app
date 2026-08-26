import { useEffect, useState } from 'react'

/**
 * Trails `value` by `delay`, so a search box drives one request per pause in typing
 * rather than one per keystroke.
 */
export function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay)
    return () => window.clearTimeout(timer)
  }, [value, delay])

  return debounced
}
