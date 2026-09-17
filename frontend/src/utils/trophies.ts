import type { TrophyFamily, TrophyKey } from '@/types/api'

/** The shelves, in the order the page draws them. */
export const TROPHY_FAMILIES: { family: TrophyFamily; label: string; hint: string }[] = [
  { family: 'regularity', label: 'Régularité', hint: 'Ce que ton journal dit de ton rythme' },
  { family: 'special_dates', label: 'Dates spéciales', hint: 'Les soirs où tout le monde fait autre chose' },
]

interface TrophyCopy {
  /** A film title, every time: that is the joke the whole shelf is built on. */
  name: string
  /** What it takes, shown while the trophy is locked. */
  condition: string
  /** The line under a trophy once it is won, in place of the condition. */
  tagline: string
  /** The figure the rungs are measured against, in words. */
  progress: (value: number) => string
  /** One rung, in words — "30 jours d'affilée" — for the next one to reach. */
  rung: (tier: number) => string
}

function plural(count: number, singular: string, pluralForm = `${singular}s`): string {
  return count > 1 ? pluralForm : singular
}

/** For the trophies won by being in front of a film on one particular day of the year. */
function onTheDay(name: string, condition: string, tagline: string): TrophyCopy {
  return {
    name,
    condition,
    tagline,
    progress: (value) => (value === 0 ? 'Jamais' : `${value} fois`),
    rung: (tier) => `${tier} fois`,
  }
}

/**
 * Every trophy's French, in one place.
 *
 * The backend sends keys and numbers and nothing else, like it does for badges: the names are
 * film titles and the lines under them are jokes, and both are copy.
 */
export const TROPHY_COPY: Record<TrophyKey, TrophyCopy> = {
  groundhog_day: {
    name: 'Un jour sans fin',
    condition: 'Enchaîner des jours avec au moins un film',
    tagline: 'Le même réveil chaque matin, et un film chaque soir. Bill Murray compatit.',
    progress: (value) => `Record : ${value} ${plural(value, 'jour')} d'affilée`,
    rung: (tier) => `${tier} jours d'affilée`,
  },
  weekends: {
    name: 'Samedi soir, dimanche matin',
    condition: 'Un film le samedi et un le dimanche du même week-end',
    tagline: "Le week-end, c'est fait pour ça. Et pour rien d'autre.",
    progress: (value) => `${value} ${plural(value, 'week-end')} ${plural(value, 'complet')}`,
    rung: (tier) => `${tier} week-ends`,
  },
  dirty_dozen: {
    name: 'Les Douze Salopards',
    condition: "Au moins un film chaque mois, douze mois d'affilée",
    tagline: 'Douze mois, pas un déserteur.',
    progress: (value) => `Record : ${value} mois d'affilée`,
    rung: (tier) => `${tier} mois d'affilée`,
  },
  return_of_the_jedi: {
    name: 'Le Retour du Jedi',
    condition: 'Revenir après trente jours sans film',
    tagline: 'Trente jours loin des écrans, puis le retour. La Force était restée allumée.',
    progress: (value) => `Plus longue pause : ${value} ${plural(value, 'jour')} sans film`,
    rung: (tier) => `${tier} jours sans film`,
  },
  old_timers: {
    name: 'Les Vieux de la vieille',
    condition: 'Des années depuis ton premier film au journal',
    tagline: 'Un journal qui commence à avoir de la bouteille.',
    progress: (value) => (value === 0 ? "Moins d'un an de journal" : `${value} ${plural(value, 'an')} de journal`),
    rung: (tier) => `${tier} ${plural(tier, 'an')}`,
  },
  christmas: onTheDay(
    'Le Père Noël est une ordure',
    'Un film le 24 ou le 25 décembre',
    'Un film à Noël plutôt que la bûche en famille. Ou les deux, soyons honnêtes.'
  ),
  new_year: onTheDay('La Bonne Année', 'Un film le 31 décembre ou le 1er janvier', "Changer d'année devant un générique."),
  valentine: onTheDay('Seul au monde', 'Un film le 14 février', "Un film le soir de la Saint-Valentin. Wilson était d'accord."),
  easter: onTheDay(
    'Qui veut la peau de Roger Rabbit ?',
    'Un film le dimanche de Pâques',
    'Pendant que les autres cherchaient des œufs dans le jardin.'
  ),
  labour_day: onTheDay('Les Temps modernes', 'Un film le 1er mai', 'Le 1er mai, on ne travaille pas. On regarde.'),
  bastille_day: onTheDay('Quatorze Juillet', 'Un film le 14 juillet', "Le feu d'artifice attendra la fin du générique."),
  halloween: onTheDay('La Nuit des morts-vivants', 'Un film le 31 octobre', 'Les bonbons étaient pour qui, au juste ?'),
  friday_the_13th: {
    name: 'Vendredi 13',
    condition: 'Un film un vendredi 13',
    tagline: 'Pas superstitieux, visiblement.',
    // Days, not films: a double feature on the 13th is still one Friday the 13th.
    progress: (value) => (value === 0 ? 'Jamais' : `${value} ${plural(value, 'vendredi')} 13`),
    rung: (tier) => `${tier} ${plural(tier, 'vendredi')} 13`,
  },
  leap_day: onTheDay(
    'Le Jour le plus long',
    'Un film un 29 février',
    "Le jour qui n'existe qu'une fois tous les quatre ans, passé devant un film."
  ),
}
