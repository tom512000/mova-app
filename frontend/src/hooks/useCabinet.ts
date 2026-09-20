import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  CABINET_KEY,
  CARD_FACETS_KEY,
  CARD_SHOWCASE_KEY,
  claimDailyGrant,
  claimSet,
  fetchCabinet,
  fetchCardFacets,
  fetchCardFeats,
  fetchCardSets,
  fetchShowcase,
  openPack,
  rebuildCatalogue,
  saveShowcase,
} from '@/services/cardsService'
import type { Cabinet, CardPackKind, CardSetFamily, Id, PackResult } from '@/types/api'

/**
 * The Cabinet's state, and the five things that change it.
 *
 * Shaped after useFilmGame: one query per surface, and mutations that write what came back
 * straight into the cache rather than invalidating and re-asking. The pack opening is why —
 * its response *is* the animation's data, so re-fetching would both waste a round trip and
 * risk the reveal starting before the answer arrived.
 *
 * Opening a pack changes the balance, what is owned, and therefore the facets and possibly
 * the feats, so those are invalidated rather than patched: they are cheap, they are not on
 * the animation's critical path, and reproducing their arithmetic here would be a second
 * copy of rules the server already owns.
 */
export function useCabinet() {
  const queryClient = useQueryClient()

  const cabinet = useQuery({
    queryKey: CABINET_KEY,
    queryFn: fetchCabinet,
    staleTime: 30_000,
  })

  const facets = useQuery({
    queryKey: CARD_FACETS_KEY,
    queryFn: fetchCardFacets,
    staleTime: 5 * 60 * 1000,
  })

  const setCabinet = (patch: Partial<Cabinet>) => {
    queryClient.setQueryData<Cabinet>(CABINET_KEY, (previous) =>
      previous === undefined ? previous : { ...previous, ...patch }
    )
  }

  const afterCollecting = () => {
    void queryClient.invalidateQueries({ queryKey: CARD_FACETS_KEY })
    void queryClient.invalidateQueries({ queryKey: ['cards', 'list'] })
    void queryClient.invalidateQueries({ queryKey: ['cards', 'sets'] })
    void queryClient.invalidateQueries({ queryKey: ['cards', 'feats'] })
  }

  const daily = useMutation({
    mutationFn: claimDailyGrant,
    onSuccess: (result) => {
      setCabinet({
        balance: result.balance,
        streakDays: result.streakDays,
        dailyGrantAvailable: false,
      })
      // Patched for the instant response, then re-read for the truth. The grant also moves
      // `lifetimeEarned` and `freeSalvageLeft`, which the response does not carry, and a
      // header that showed a fresh balance beside a stale total would be its own small lie.
      void queryClient.invalidateQueries({ queryKey: CABINET_KEY })
    },
  })

  const open = useMutation({
    mutationFn: (kind: CardPackKind) => openPack(kind),
    onSuccess: (result: PackResult) => {
      setCabinet({ balance: result.balance })
      afterCollecting()
    },
  })

  const claim = useMutation({
    mutationFn: ({ family, key }: { family: CardSetFamily; key: string }) => claimSet(family, key),
    onSuccess: (result) => {
      setCabinet({ balance: result.balance })
      void queryClient.invalidateQueries({ queryKey: ['cards', 'sets'] })
      void queryClient.invalidateQueries({ queryKey: ['cards', 'feats'] })
    },
  })

  const rebuild = useMutation({
    mutationFn: rebuildCatalogue,
  })

  return { cabinet, facets, daily, open, claim, rebuild }
}

export function useCardSets(family?: CardSetFamily) {
  return useQuery({
    queryKey: ['cards', 'sets', family ?? 'all'],
    queryFn: () => fetchCardSets(family),
    staleTime: 5 * 60 * 1000,
  })
}

export function useCardFeats() {
  return useQuery({
    queryKey: ['cards', 'feats'],
    queryFn: fetchCardFeats,
    staleTime: 5 * 60 * 1000,
  })
}

export function useShowcase() {
  const queryClient = useQueryClient()

  const showcase = useQuery({
    queryKey: CARD_SHOWCASE_KEY,
    queryFn: fetchShowcase,
    staleTime: 60_000,
  })

  const save = useMutation({
    mutationFn: (cardIds: (Id | null)[]) => saveShowcase(cardIds),
    onSuccess: (slots) =>
      queryClient.setQueryData(CARD_SHOWCASE_KEY, (previous: { ownerDisplayName: string } | undefined) =>
        previous === undefined ? previous : { ...previous, slots }
      ),
  })

  return { showcase, save }
}
