import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchGameState, startGame, submitGuess } from '@/services/gamesService'
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
  const guess = useMutation({ mutationFn: (movieId: number) => submitGuess(game, mode, movieId), onSuccess: cache })

  return {
    session: data,
    isLoading,
    isError,
    error,
    start,
    guess,
    isOver: data != null && data.status !== 'in_progress',
  }
}
