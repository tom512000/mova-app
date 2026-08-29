import axios from 'axios'

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api',
  // The session lives in a cookie, and axios drops cookies on cross-origin requests unless
  // asked; the SPA (:5173) and the API (:8000) are different origins in development.
  withCredentials: true,
})

/**
 * Which profile reads should target. Held in a module variable rather than passed through
 * every call site because it has to reach dozens of react-query hooks that know nothing
 * about profiles — see the request interceptor below.
 *
 * Null means "me", and the param is then omitted entirely so the API falls back to the
 * authenticated user.
 */
let activeProfileId: string | null = null

export function setActiveProfileId(profileId: string | null): void {
  activeProfileId = profileId
}

export function getActiveProfileId(): string | null {
  return activeProfileId
}

const PROFILE_EXEMPT_PREFIXES = ['/auth', '/profiles', '/import', '/sync']

apiClient.interceptors.request.use((config) => {
  if (activeProfileId === null) return config

  // Auth, profile management, import and sync always act on the logged-in account. Tagging
  // them with a profileId would be meaningless at best; the API ignores it for those routes
  // anyway, and leaving it off keeps the intent visible in the network tab.
  const path = config.url ?? ''
  if (PROFILE_EXEMPT_PREFIXES.some((prefix) => path.startsWith(prefix))) return config

  config.params = { ...config.params, profileId: activeProfileId }
  return config
})
