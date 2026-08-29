import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchGameState, startGame, submitGuess, submitLetter } from '@/services/gamesService'
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
  // Hangman only; the other three have nothing to do with letters.
  const letter = useMutation({ mutationFn: (value: string) => submitLetter(game, mode, value), onSuccess: cache })

  return {
    session: data,
    isLoading,
    isError,
    error,
    start,
    guess,
    letter,
    isOver: data != null && data.status !== 'in_progress',
  }
}
