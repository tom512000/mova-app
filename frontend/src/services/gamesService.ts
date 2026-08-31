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

export async function submitGuess(game: GameKind, mode: GameMode, movieId: string): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/${game}/${mode}/guess`, { movieId })
  return data.session as GameState
}

/**
 * Gives up: the run stops and the board says what it was hiding. Infinite only — the
 * route does not exist on the daily board, so a stray call is a 404 rather than a refusal.
 */
export async function revealAnswer(game: GameKind, mode: GameMode): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/${game}/${mode}/reveal`)
  return data.session as GameState
}

/**
 * Duel only: one side of the pair on the table.
 *
 * Same payload as a guess and a different route, because it means a different thing — "this
 * one of the two" rather than "this is the film you are hiding". The API refuses each at the
 * other's door.
 */
export async function submitPick(game: GameKind, mode: GameMode, movieId: string): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/${game}/${mode}/pick`, { movieId })
  return data.session as GameState
}

/** Timeline only: the whole board in the order the player believes it came out. */
export async function submitOrder(game: GameKind, mode: GameMode, order: string[]): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/${game}/${mode}/order`, { order })
  return data.session as GameState
}

/** Hangman only: a letter rather than a film, which the API keeps on its own route. */
export async function submitLetter(game: GameKind, mode: GameMode, letter: string): Promise<GameState> {
  const { data } = await apiClient.post<GameEnvelope>(`/games/${game}/${mode}/letter`, { letter })
  return data.session as GameState
}
