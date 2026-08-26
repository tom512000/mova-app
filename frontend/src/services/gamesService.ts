import { apiClient } from '@/services/apiClient'
import type { GameMode, GameState } from '@/types/api'

/** The API wraps the board so "no run yet" can be an explicit null rather than a 404. */
interface GameEnvelope {
  session: GameState | null
}

export async function fetchGameState(mode: GameMode): Promise<GameState | null> {
  const { data } = await apiClient.get<GameEnvelope>(`/games/film/${mode}`)
  return data.session
}

export async function startGame(mode: GameMode): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/film/${mode}/start`)
  return data.session as GameState
}

export async function submitGuess(mode: GameMode, movieId: number): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/film/${mode}/guess`, { movieId })
  return data.session as GameState
}
