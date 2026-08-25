import { apiClient } from '@/services/apiClient'
import type { AuthUser } from '@/types/api'

export async function fetchCurrentUser(): Promise<AuthUser> {
  const { data } = await apiClient.get<AuthUser>('/auth/me')
  return data
}

export async function login(email: string, password: string): Promise<AuthUser> {
  const { data } = await apiClient.post<AuthUser>('/auth/login', { email, password })
  return data
}

export async function logout(): Promise<void> {
  await apiClient.post('/auth/logout')
}
