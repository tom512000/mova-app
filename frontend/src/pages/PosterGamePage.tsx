import { useParams } from 'react-router-dom'
import { PixelGame } from '@/components/game/PixelGame'
import type { GameMode } from '@/types/api'

export function PosterGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'

  return (
    <PixelGame
      game="poster"
      mode={gameMode}
      title="Le film pixelisé"
      rules="Une affiche, vue de bien trop loin. Chaque proposition la rapproche d'un cran — à toi de la reconnaître avant qu'il ne reste plus de crans."
      artworkLabel="L'affiche du film cherché"
      missing="L'affiche est introuvable chez TMDB pour l'instant."
      frameClassName="mx-auto max-w-[18rem]"
    />
  )
}
