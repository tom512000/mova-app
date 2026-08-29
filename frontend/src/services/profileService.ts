import { apiClient } from '@/services/apiClient'
import type { LetterboxdProfile, ProfileSummary, ShareAcceptResult, ShareLink } from '@/types/api'

export async function fetchProfiles(): Promise<ProfileSummary[]> {
  const { data } = await apiClient.get<ProfileSummary[]>('/profiles')
  return data
}

export async function fetchShareLink(): Promise<ShareLink> {
  const { data } = await apiClient.get<ShareLink>('/profiles/share-link')
  return data
}

export async function rotateShareLink(): Promise<ShareLink> {
  const { data } = await apiClient.post<ShareLink>('/profiles/share-link/rotate')
  return data
}

export async function acceptShareLink(token: string): Promise<ShareAcceptResult> {
  const { data } = await apiClient.post<ShareAcceptResult>(`/profiles/share-link/${token}/accept`)
  return data
}

export async function revokeProfileAccess(profileId: string): Promise<void> {
  await apiClient.delete(`/profiles/${profileId}/access`)
}

/**
 * What profile.csv said about my Letterboxd account, or null when none has been imported.
 *
 * The API wraps it so "never imported" can be an explicit null rather than a 404 — it is an
 * ordinary state the account screen has a panel for, not an error.
 */
export async function fetchLetterboxdProfile(): Promise<LetterboxdProfile | null> {
  const { data } = await apiClient.get<{ profile: LetterboxdProfile | null }>('/profiles/letterboxd')
  return data.profile
}
