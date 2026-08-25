import { createContext, use, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchCurrentUser, login as loginRequest, logout as logoutRequest } from '@/services/authService'
import { fetchProfiles } from '@/services/profileService'
import { setActiveProfileId } from '@/services/apiClient'
import type { AuthUser, ProfileSummary } from '@/types/api'

interface SessionValue {
  user: AuthUser | null
  isLoading: boolean
  profiles: ProfileSummary[]
  /** The profile currently being read. Equals the logged-in account unless one was picked. */
  activeProfile: ProfileSummary | null
  isViewingOtherProfile: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  switchProfile: (profileId: number) => void
}

const SessionContext = createContext<SessionValue | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [activeProfileId, setActiveProfileIdState] = useState<number | null>(null)

  const session = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: fetchCurrentUser,
    // A 401 here is the normal "not logged in" answer, not a transient failure, so retrying
    // it just delays the redirect to the login page.
    retry: false,
    staleTime: Infinity,
  })

  const user = session.data ?? null

  const profiles = useQuery({
    queryKey: ['profiles'],
    queryFn: fetchProfiles,
    enabled: user !== null,
  })

  const profileList = useMemo(() => profiles.data ?? [], [profiles.data])

  // The interceptor reads a module variable, so it has to be written before any query using
  // it runs. Doing it in an effect (rather than during render) keeps render side-effect-free.
  useEffect(() => {
    setActiveProfileId(activeProfileId)
  }, [activeProfileId])

  const switchProfile = useCallback(
    (profileId: number) => {
      const isSelf = profileList.find((p) => p.id === profileId)?.isSelf ?? false
      setActiveProfileId(isSelf ? null : profileId)
      setActiveProfileIdState(isSelf ? null : profileId)
      // Every cached film, stat and watchlist page belongs to the profile that was active
      // when it was fetched, so none of it survives the switch.
      void queryClient.invalidateQueries()
    },
    [profileList, queryClient]
  )

  const login = useCallback(
    async (email: string, password: string) => {
      const loggedIn = await loginRequest(email, password)
      setActiveProfileId(null)
      setActiveProfileIdState(null)
      queryClient.setQueryData(['auth', 'me'], loggedIn)
      await queryClient.invalidateQueries()
    },
    [queryClient]
  )

  const logout = useCallback(async () => {
    await logoutRequest()
    setActiveProfileId(null)
    setActiveProfileIdState(null)
    // clear(), not invalidate: the next account must not briefly see the previous one's
    // cached films while its own requests are still in flight.
    queryClient.clear()
  }, [queryClient])

  const activeProfile = useMemo(() => {
    if (profileList.length === 0) return null
    return profileList.find((p) => p.id === activeProfileId) ?? profileList.find((p) => p.isSelf) ?? null
  }, [profileList, activeProfileId])

  const value = useMemo<SessionValue>(
    () => ({
      user,
      isLoading: session.isLoading,
      profiles: profileList,
      activeProfile,
      isViewingOtherProfile: activeProfileId !== null,
      login,
      logout,
      switchProfile,
    }),
    [user, session.isLoading, profileList, activeProfile, activeProfileId, login, logout, switchProfile]
  )

  return <SessionContext value={value}>{children}</SessionContext>
}

export function useSession(): SessionValue {
  const context = use(SessionContext)
  if (context === null) throw new Error('useSession doit être utilisé dans un <SessionProvider>.')
  return context
}
