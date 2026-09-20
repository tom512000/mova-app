import type {
  CardFeatFamily,
  CardFeatKey,
  CardPackKind,
  CardRarity,
  CardSetFamily,
  CardSubject,
} from '@/types/api'

/**
 * Every French word the Cabinet uses, client-side — the same arrangement TROPHY_COPY makes.
 * The API sends keys and numbers; the wording is the frontend's job, so a rename here is
 * never a migration.
 */

/* ------------------------------------------------------------------ raretés */

/**
 * Six tiers on a page that is 99 % black and white, where the accent red is a UI colour and
 * never carries meaning about data. So rarity is built out of print production values
 * instead, across four channels that each identify a tier on their own:
 *
 *   - the mono code in the corner block, because a tier should be *readable* and not merely
 *     signalled;
 *   - the pip count, which stays legible at thumbnail size when a word does not;
 *   - the frame weight, from a hairline to a heavy rule;
 *   - the ground, escalating from flat through halftone and hatch to crosshatch.
 *
 * Inversion — printing the whole card in negative — is reserved for Légendaire and nothing
 * else. It is the loudest move this palette has, unmistakable at any size, identical in both
 * editions, and it costs no hue. Spending it anywhere else would waste it.
 */
export const RARITY_ORDER: CardRarity[] = [
  'common',
  'uncommon',
  'rare',
  'super_rare',
  'ultra_rare',
  'legendary',
]

export const RARITY_COPY: Record<CardRarity, { name: string; code: string; pips: number }> = {
  common: { name: 'Commune', code: 'C', pips: 1 },
  uncommon: { name: 'Peu Commune', code: 'PC', pips: 2 },
  rare: { name: 'Rare', code: 'R', pips: 3 },
  super_rare: { name: 'Super Rare', code: 'SR', pips: 4 },
  ultra_rare: { name: 'Ultra Rare', code: 'UR', pips: 5 },
  legendary: { name: 'Légendaire', code: 'L', pips: 6 },
}

export function rarityRank(rarity: CardRarity): number {
  return RARITY_ORDER.indexOf(rarity)
}

/** The frame and the stock. Légendaire is the only one printed in negative. */
export const RARITY_FRAME: Record<CardRarity, string> = {
  common: 'border border-ink/40 bg-surface',
  uncommon: 'border border-ink bg-surface-2',
  rare: 'border-2 border-ink bg-surface',
  super_rare: 'border-2 border-ink bg-surface',
  ultra_rare: 'border-[3px] border-ink bg-surface',
  legendary: 'border-4 border-ink bg-ink text-paper',
}

/**
 * The texture, which belongs to the picture area and never to the caption.
 *
 * It started on the card itself, and that was wrong twice over: behind a poster it is
 * invisible, and behind the name it is a field of dots under text. Confined to the artwork
 * and given a mat to show in, it does its job in the one place it can be seen without
 * costing legibility anywhere.
 */
export const RARITY_GROUND: Record<CardRarity, string> = {
  common: '',
  uncommon: '',
  rare: 'card-ground-halftone',
  super_rare: 'card-ground-hatch',
  ultra_rare: 'card-ground-crosshatch',
  legendary: 'card-ground-crosshatch',
}

/**
 * How wide a mat the picture sits in.
 *
 * A hairline for a Rare, a little more for the top tiers — the same thing a better print
 * run buys on a real collectible, and the only reason the ground above is ever visible on a
 * card that has artwork.
 */
export const RARITY_MAT: Record<CardRarity, string> = {
  common: '',
  uncommon: '',
  rare: 'p-[2px]',
  super_rare: 'p-[3px]',
  ultra_rare: 'p-[4px]',
  legendary: 'p-[4px]',
}

/** Corner squares are the Ultra Rare and Légendaire tell, on top of everything else. */
export function hasCornerMarks(rarity: CardRarity): boolean {
  return rarity === 'ultra_rare' || rarity === 'legendary'
}

/** An inner rule appears from Super Rare up. */
export function hasInnerRule(rarity: CardRarity): boolean {
  return rarityRank(rarity) >= rarityRank('super_rare')
}

/* ------------------------------------------------------------------- sujets */

export const SUBJECT_LABEL: Record<CardSubject, string> = {
  work: 'Films et séries',
  person: 'Personnes',
  studio: 'Studios',
  franchise: 'Sagas',
}

export const SUBJECT_SINGULAR: Record<CardSubject, string> = {
  work: 'Œuvre',
  person: 'Personne',
  studio: 'Studio',
  franchise: 'Saga',
}

/* ------------------------------------------------------------------ paquets */

export const PACK_COPY: Record<
  CardPackKind,
  { name: string; tagline: string; cards: number; price: number; floor: CardRarity }
> = {
  free: {
    name: 'Pochette',
    tagline: 'Gratuite, sans limite. Cinq cartes, dont une au-dessus de Commune.',
    cards: 5,
    price: 0,
    floor: 'uncommon',
  },
  reel: {
    name: 'Bobine',
    tagline: 'Cinq cartes, dont une Rare garantie.',
    cards: 5,
    price: 500,
    floor: 'rare',
  },
  boxset: {
    name: 'Coffret',
    tagline: 'Sept cartes, aucune Commune, une Super Rare garantie.',
    cards: 7,
    price: 1500,
    floor: 'super_rare',
  },
}

/** Free packs are unlimited but poor — said plainly rather than discovered. */
export const FREE_PACK_NOTE =
  'Les Ultra Rares et les Légendaires ne sortent jamais d’une Pochette, et les doublons qu’elle donne ne rapportent que jusqu’à 100 jetons par jour.'

/* ------------------------------------------------------------------- séries */

export const SET_FAMILY_LABEL: Record<CardSetFamily, string> = {
  decade: 'Décennies',
  genre: 'Genres',
  country: 'Pays',
  studio: 'Studios',
  franchise: 'Sagas',
}

/** A decade's key is its first year; everything else is already a name. */
export function setTitle(family: CardSetFamily, label: string): string {
  return family === 'decade' ? `Années ${label.slice(2)}` : label
}

/* --------------------------------------------------------------- hauts faits */

export const FEAT_FAMILY_LABEL: Record<CardFeatFamily, string> = {
  collection: 'Collection',
  completion: 'Complétion',
  economy: 'Comptoir',
  speciality: 'Spécialité',
}

export const FEAT_FAMILIES: CardFeatFamily[] = ['collection', 'completion', 'economy', 'speciality']

export const FEAT_COPY: Record<
  CardFeatKey,
  { name: string; condition: string; tagline: string; unit: (value: number) => string }
> = {
  collector: {
    name: 'Le Collectionneur',
    condition: 'Posséder des cartes, beaucoup de cartes.',
    tagline: 'Les murs ne suffisent plus.',
    unit: (n) => `${n} cartes`,
  },
  pack_rat: {
    name: 'Le Déchireur',
    condition: 'Ouvrir des paquets.',
    tagline: 'Le bruit du papier est la moitié du plaisir.',
    unit: (n) => `${n} paquets`,
  },
  first_legendary: {
    name: 'Première Légende',
    condition: 'Tirer une carte Légendaire.',
    tagline: 'Il y en a une vingtaine. Tu en as une.',
    unit: (n) => `${n} légendaire${n > 1 ? 's' : ''}`,
  },
  completionist: {
    name: 'Le Complétiste',
    condition: 'Terminer des séries.',
    tagline: 'Une case vide, et la nuit est fichue.',
    unit: (n) => `${n} série${n > 1 ? 's' : ''}`,
  },
  full_house: {
    name: 'Carton Plein',
    condition: 'Terminer une série entière.',
    tagline: 'La première est la plus dure.',
    unit: (n) => `${n} série${n > 1 ? 's' : ''}`,
  },
  saga: {
    name: 'La Saga',
    condition: 'Réunir une saga complète, sa carte comprise.',
    tagline: 'Du premier au dernier, sans en sauter.',
    unit: (n) => `${n} saga${n > 1 ? 's' : ''}`,
  },
  big_spender: {
    name: 'Le Flambeur',
    condition: 'Dépenser des jetons.',
    tagline: 'L’argent ne fait pas le bonheur, mais il ouvre des Coffrets.',
    unit: (n) => `${n} jetons`,
  },
  salvage: {
    name: 'La Récupération',
    condition: 'Convertir des doublons en jetons.',
    tagline: 'Rien ne se perd.',
    unit: (n) => `${n} jetons`,
  },
  auteur: {
    name: 'L’Auteur',
    condition: 'Collectionner des réalisateur·rice·s et des créateur·rice·s.',
    tagline: 'Derrière chaque film, quelqu’un.',
    unit: (n) => `${n} carte${n > 1 ? 's' : ''}`,
  },
  mogul: {
    name: 'Le Nabab',
    condition: 'Collectionner des studios.',
    tagline: 'Les logos avant le générique.',
    unit: (n) => `${n} studio${n > 1 ? 's' : ''}`,
  },
}

/* ------------------------------------------------------------------- divers */

/** Jetons, always with a thin space before the unit the way French sets numbers. */
export function formatJetons(value: number): string {
  return `${value.toLocaleString('fr-FR')} j`
}

/** "top 0,4 %" — what a percentile is actually worth saying. */
export function formatPercentile(percentile: number): string {
  const share = Math.max(0.1, percentile * 100)
  return `top ${share.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %`
}
