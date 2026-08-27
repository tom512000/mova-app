import { apiClient } from '@/services/apiClient'
import type { GameKind, GameMode, GameState } from '@/types/api'

/** The API wraps the board so "no run yet" can be an explicit null rather than a 404. */
interface GameEnvelope {
  session: GameState | null
}

export async function fetchGameState(game: GameKind, mode: GameMode): Promise<GameState | null> {
  const { data } = await apiClient.get<GameEnvelope>(`/games/${game}/${mode}`)
  return data.session
}

export async function startGame(game: GameKind, mode: GameMode): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/${game}/${mode}/start`)
  return data.session as GameState
}

export async function submitGuess(game: GameKind, mode: GameMode, movieId: number): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/${game}/${mode}/guess`, { movieId })
  return data.session as GameState
}

/** Hangman only: a letter rather than a film, which the API keeps on its own route. */
export async function submitLetter(game: GameKind, mode: GameMode, letter: string): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/${game}/${mode}/letter`, { letter })
  return data.session as GameState
}
