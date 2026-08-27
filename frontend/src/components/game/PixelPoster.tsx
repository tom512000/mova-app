import { useEffect, useRef } from 'react'
import type { PosterPixels } from '@/types/api'

/**
 * The answer's poster, blown back up from the handful of pixels the server sent.
 *
 * The canvas is exactly as wide as the grid — six pixels on the opening rung — and the
 * browser scales it up with nearest-neighbour, so the blocks stay hard-edged instead of
 * dissolving into a blur. Everything on screen is in the payload; there is no full-size
 * image underneath to peek at.
 */
export function PixelPoster({ pixels }: { pixels: PosterPixels }) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const { width, height, colors } = pixels

  useEffect(() => {
    const context = canvasRef.current?.getContext('2d')
    if (!context) return

    const image = context.createImageData(width, height)
    for (let index = 0; index < colors.length; index += 1) {
      const rgb = Number.parseInt(colors[index].slice(1), 16)
      const offset = index * 4
      image.data[offset] = (rgb >> 16) & 0xff
      image.data[offset + 1] = (rgb >> 8) & 0xff
      image.data[offset + 2] = rgb & 0xff
      image.data[offset + 3] = 0xff
    }
    context.putImageData(image, 0, 0)
  }, [width, height, colors])

  return (
    <canvas
      ref={canvasRef}
      width={width}
      height={height}
      role="img"
      aria-label={`L'affiche du film cherché, réduite à ${width} pixels de large`}
      // Posters are greyscale everywhere else in the app; here the palette is half the
      // clue, so this one keeps its colour on purpose.
      className="block h-auto w-full [image-rendering:pixelated]"
    />
  )
}
