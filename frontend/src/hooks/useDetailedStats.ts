import { useCallback, useEffect, useState } from 'react'

const STORAGE_KEY = 'letterboxd-app:detailed-stats'

function getInitialPreference(): boolean {
  const stored = localStorage.getItem(STORAGE_KEY)
  // On by default. These blocks were already on the dashboard before the setting existed,
  // and having them disappear on the first load after a deploy reads as something broken
  // rather than as a new option. Turning them off has to be a decision the reader made.
  if (stored === null) return true
  return stored === 'true'
}

/**
 * Whether the dashboard shows its fine-grained blocks — the decades and the activity
 * calendar — or stops at the headline figures.
 *
 * Kept in localStorage rather than in the URL or on the server: it is a reading preference
 * for this browser, not part of the address of the page, and a shared profile link should
 * arrive looking the way its reader left the dashboard, not the way the sender did.
 */
export function useDetailedStats() {
  const [detailed, setDetailed] = useState<boolean>(getInitialPreference)

  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, String(detailed))
  }, [detailed])

  const toggleDetailed = useCallback(() => {
    setDetailed((current) => !current)
  }, [])

  return { detailed, toggleDetailed }
}
