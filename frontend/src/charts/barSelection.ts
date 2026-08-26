/**
 * A clicked bar hands back the shape's own props, with the row it was built from nested
 * under `payload`. Some recharts versions pass the row directly, so both shapes are
 * accepted rather than trusting one.
 */
export function barPayload<T>(entry: unknown): T | null {
  if (entry === null || typeof entry !== 'object') return null

  const withPayload = entry as { payload?: T }

  return withPayload.payload ?? (entry as T)
}
