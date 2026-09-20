import { apiClient } from '@/services/apiClient'
import type {
  Cabinet,
  Card,
  CardDetail,
  CardFacets,
  CardFeat,
  CardListResponse,
  CardPackKind,
  CardRarity,
  CardSet,
  CardSetFamily,
  CardShowcase,
  CardSubject,
  DailyGrantResult,
  Id,
  PackResult,
} from '@/types/api'

export interface CardSearchParams {
  subject?: CardSubject
  rarity?: CardRarity
  /** Absent means both; true is the collection, false is what is still missing. */
  owned?: boolean
  q?: string
  sort?: 'rank' | 'recent' | 'score' | 'name'
  page?: number
  perPage?: number
}

export async function fetchCards(params: CardSearchParams): Promise<CardListResponse> {
  const { data } = await apiClient.get<CardListResponse>('/cards', { params })
  return data
}

export async function fetchCardFacets(): Promise<CardFacets> {
  const { data } = await apiClient.get<CardFacets>('/cards/facets')
  return data
}

export async function fetchCard(id: Id): Promise<CardDetail> {
  const { data } = await apiClient.get<CardDetail>(`/cards/${id}`)
  return data
}

export async function fetchCardSets(family?: CardSetFamily): Promise<CardSet[]> {
  const { data } = await apiClient.get<{ items: CardSet[] }>('/cards/sets', { params: { family } })
  return data.items
}

export async function fetchCardFeats(): Promise<CardFeat[]> {
  const { data } = await apiClient.get<CardFeat[]>('/cards/feats')
  return data
}

export async function fetchShowcase(): Promise<CardShowcase> {
  const { data } = await apiClient.get<CardShowcase>('/cards/showcase')
  return data
}

export async function fetchCabinet(): Promise<Cabinet> {
  const { data } = await apiClient.get<Cabinet>('/cards/cabinet')
  return data
}

export async function claimDailyGrant(): Promise<DailyGrantResult> {
  const { data } = await apiClient.post<DailyGrantResult>('/cards/cabinet/daily')
  return data
}

export async function openPack(kind: CardPackKind): Promise<PackResult> {
  const { data } = await apiClient.post<PackResult>(`/cards/cabinet/packs/${kind}`)
  return data
}

export async function claimSet(family: CardSetFamily, key: string): Promise<{ balance: number }> {
  const { data } = await apiClient.post<{ balance: number }>('/cards/cabinet/sets/claim', { family, key })
  return data
}

/**
 * Replaces the whole showcase in one call.
 *
 * Wholesale rather than slot by slot because the server applies an arrangement, and applying
 * one a slot at a time would transiently put two cards in the same position.
 */
export async function saveShowcase(cardIds: (Id | null)[]): Promise<(Card | null)[]> {
  const { data } = await apiClient.put<{ slots: (Card | null)[] }>('/cards/cabinet/showcase', { cardIds })
  return data.slots
}

export async function rebuildCatalogue(): Promise<void> {
  await apiClient.post('/cards/cabinet/rebuild')
}

/** Shared between the cabinet hall and the album header, so opening one does not re-ask. */
export const CABINET_KEY = ['cards', 'cabinet'] as const
export const CARD_FACETS_KEY = ['cards', 'facets'] as const
export const CARD_SHOWCASE_KEY = ['cards', 'showcase'] as const
