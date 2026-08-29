import { useParams } from 'react-router-dom'
import { PixelGame } from '@/components/game/PixelGame'
import type { GameMode } from '@/types/api'

export function BackdropGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'

  return (
    <PixelGame
      game="backdrop"
      mode={gameMode}
      title="Le décor"
      rules={
        <>
          Le même principe que l'affiche, mais sur l'image de fond : pas de titre, pas de cadrage vertical autour
          d'un visage. Juste un plan du film. C'est nettement plus dur.
        </>
      }
      artworkLabel="L'image de fond du film cherché"
      missing="L'image de fond est introuvable chez TMDB pour l'instant."
      // Wider than the poster's frame and 16:9 rather than 2:3 — the picture is a different
      // shape, and squeezing it into the poster's column would waste half the pixels earned.
      frameClassName="mx-auto max-w-2xl"
    />
  )
}
