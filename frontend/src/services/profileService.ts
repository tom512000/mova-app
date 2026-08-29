import { apiClient } from '@/services/apiClient'
import type { ProfileSummary, ShareAcceptResult, ShareLink } from '@/types/api'

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
