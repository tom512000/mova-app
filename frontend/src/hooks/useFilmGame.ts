import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchGameState,
  startGame,
  submitGuess,
  submitLetter,
  submitOrder,
  submitPick,
} from '@/services/gamesService'
import type { GameKind, GameMode, GameState } from '@/types/api'

/**
 * Board state for one game in one mode. Every call answers with the whole board, so the
 * responses are written straight into the cache rather than triggering a refetch.
 */
export function useFilmGame(game: GameKind, mode: GameMode) {
  const queryClient = useQueryClient()
  const queryKey = ['game', game, mode]

  const { data, isLoading, isError, error } = useQuery({
    queryKey,
    queryFn: () => fetchGameState(game, mode),
  })

  const cache = (state: GameState) => queryClient.setQueryData(queryKey, state)

  const start = useMutation({ mutationFn: () => startGame(game, mode), onSuccess: cache })
  const guess = useMutation({ mutationFn: (movieId: string) => submitGuess(game, mode, movieId), onSuccess: cache })
  // Hangman only; the other seven have nothing to do with letters.
  const letter = useMutation({ mutationFn: (value: string) => submitLetter(game, mode, value), onSuccess: cache })
  // Duel only: backing one of the two films on the table.
  const pick = useMutation({ mutationFn: (movieId: string) => submitPick(game, mode, movieId), onSuccess: cache })
  // Timeline only: the whole board, oldest first.
  const order = useMutation({ mutationFn: (ids: string[]) => submitOrder(game, mode, ids), onSuccess: cache })

  return {
    session: data,
    isLoading,
    isError,
    error,
    start,
    guess,
    letter,
    pick,
    order,
    isOver: data != null && data.status !== 'in_progress',
  }
}
