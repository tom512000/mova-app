import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { cn } from '@/utils/cn'
import type { MoviePoster } from '@/types/api'

/** Rows of the wall. Three keeps a poster tall enough to read at a glance. */
const ROWS = 3

/** Space between posters, in wall pixels. */
const GAP = 14

/** Breathing room before the first poster, so the near edge is not glued to the frame. */
const INSET = 22

/** The tilt. Positive rotateY sends +X away, so the wall recedes to the right. */
const TILT_DEG = 16
const TILT_RAD = (TILT_DEG * Math.PI) / 180

const PERSPECTIVE_PX = 1600

/**
 * What focusing does to a poster. Shared with the stop below rather than written twice:
 * the far end of the walk has to know exactly how much room a lifted poster takes, and a
 * margin that drifted out of step with the animation would clip it again.
 */
const LIFT_Z = 70
const LIFT_SCALE = 1.04

/** A backstop on how much is drawn at once, however wide the window gets. */
const MAX_COLUMNS = 44

interface Props {
  posters: MoviePoster[]
  onFocus: (poster: MoviePoster | null) => void
}

/**
 * The museum wall: a plane of posters, tilted away from the viewer, that you walk along.
 *
 * The rotation is pinned to the left edge and the panning lives on a child layer, so wall
 * coordinates run plainly left to right and the near edge always lands exactly where the
 * frame starts. Pivoting about the centre instead would swing the left half towards the
 * viewer and push the first posters off the side of the frame.
 */
export function PosterWall({ posters, onFocus }: Props) {
  const viewportRef = useRef<HTMLDivElement>(null)
  const [size, setSize] = useState({ width: 0, height: 0 })
  const [offset, setOffset] = useState(0)
  const [hovered, setHovered] = useState<number | null>(null)
  const navigate = useNavigate()

  useEffect(() => {
    const element = viewportRef.current
    if (!element) return

    // Measured rather than assumed: the cell size is derived from the height so the three
    // rows always fill the frame exactly, whatever the window is doing.
    const observer = new ResizeObserver(([entry]) => {
      setSize({ width: entry.contentRect.width, height: entry.contentRect.height })
    })
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  const layout = useMemo(() => {
    const cellHeight = Math.max(80, (size.height - GAP * (ROWS + 1)) / ROWS)
    const cellWidth = (cellHeight * 2) / 3
    const columnWidth = cellWidth + GAP
    const columns = Math.ceil(posters.length / ROWS)
    const wallWidth = INSET + columns * columnWidth

    /**
     * How much wall it takes to reach a given point of the frame.
     *
     * Inverting the projection rather than guessing at it: a point at wall-x X lands at
     * `W/2 + (X·cos - W/2)·p/(p + X·sin)`, since the vanishing point sits at the middle of
     * the frame and not at the pivot. Solved for X, with d the distance from that middle.
     * The tilt compresses the far side, so a frame always shows more wall than it is wide.
     */
    const spanTo = (screenX: number) => {
      const d = screenX - size.width / 2
      const denominator = Math.cos(TILT_RAD) * PERSPECTIVE_PX - Math.sin(TILT_RAD) * d

      return denominator <= 0 ? wallWidth : (PERSPECTIVE_PX * (d + size.width / 2)) / denominator
    }

    const visibleSpan = spanTo(size.width)

    /**
     * Where to stop walking.
     *
     * Not simply "the wall's far edge meets the frame's": the last poster still has to be
     * focusable there, and focusing squares it up, scales it and pushes it LIFT_Z off the
     * wall — along a normal that, once the wall is tilted, points partly sideways. So the
     * stop is solved for the *lifted* right edge landing on the frame edge, by running the
     * same projection forwards and inverting it. It leaves a sliver of bare wall, a few
     * dozen pixels, rather than the empty column a whole extra step would cost.
     */
    const halfLifted = (cellWidth / 2) * LIFT_SCALE
    const depth = halfLifted * Math.sin(TILT_RAD) + LIFT_Z
    const denominator = PERSPECTIVE_PX * Math.cos(TILT_RAD) - (size.width / 2) * Math.sin(TILT_RAD)
    const reach =
      denominator <= 0
        ? wallWidth
        : (size.width * PERSPECTIVE_PX -
            depth * (PERSPECTIVE_PX * Math.sin(TILT_RAD) + (size.width / 2) * Math.cos(TILT_RAD))) /
          denominator
    const lastColumnLeft = INSET + Math.max(0, columns - 1) * columnWidth

    return {
      cellHeight,
      cellWidth,
      columnWidth,
      columns,
      wallWidth,
      visibleSpan,
      visibleColumns: Math.min(MAX_COLUMNS, Math.ceil(visibleSpan / columnWidth) + 2),
      maxOffset: Math.max(0, lastColumnLeft + cellWidth / 2 + halfLifted * Math.cos(TILT_RAD) - reach),
    }
  }, [posters.length, size.height, size.width])

  const pan = useCallback(
    (delta: number) => setOffset((current) => clamp(current + delta, layout.maxOffset)),
    [layout.maxOffset]
  )

  // Wheel and trackpad. A vertical wheel walks the wall too: on a horizontal surface it is
  // the gesture people reach for, and refusing it would leave a mouse unable to move.
  useEffect(() => {
    const element = viewportRef.current
    if (!element) return

    const onWheel = (event: WheelEvent) => {
      event.preventDefault()
      pan(Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY)
    }

    element.addEventListener('wheel', onWheel, { passive: false })
    return () => element.removeEventListener('wheel', onWheel)
  }, [pan])

  const drag = useRef<{ pointerId: number; x: number; velocity: number; time: number } | null>(null)
  const glide = useRef<number | null>(null)

  const stopGlide = () => {
    if (glide.current !== null) cancelAnimationFrame(glide.current)
    glide.current = null
  }

  useEffect(() => stopGlide, [])

  const onPointerDown = (event: React.PointerEvent<HTMLDivElement>) => {
    if (event.button !== 0) return
    stopGlide()
    drag.current = { pointerId: event.pointerId, x: event.clientX, velocity: 0, time: performance.now() }
    event.currentTarget.setPointerCapture(event.pointerId)
  }

  const onPointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
    const state = drag.current
    if (!state || state.pointerId !== event.pointerId) return

    const dx = event.clientX - state.x
    const elapsed = Math.max(1, performance.now() - state.time)
    // Dragging left walks forward, the way you would push a physical rail.
    pan(-dx)
    drag.current = { ...state, x: event.clientX, velocity: -dx / elapsed, time: performance.now() }
  }

  const onPointerUp = () => {
    const state = drag.current
    drag.current = null
    if (!state || Math.abs(state.velocity) < 0.05) return

    // Momentum, decayed per frame. Without it a long wall takes a dozen drags to cross.
    let velocity = state.velocity * 16
    const step = () => {
      velocity *= 0.94
      pan(velocity)
      glide.current = Math.abs(velocity) > 0.4 ? requestAnimationFrame(step) : null
    }
    glide.current = requestAnimationFrame(step)
  }

  // The columns the frame actually holds, which is what the counter reports; the drawing
  // then starts one column earlier so nothing pops in at the near edge.
  const firstInFrame = Math.max(0, Math.floor((offset - INSET) / layout.columnWidth))
  const lastInFrame = Math.min(
    layout.columns - 1,
    Math.floor((offset - INSET + layout.visibleSpan) / layout.columnWidth)
  )

  const first = Math.max(0, firstInFrame - 1)
  const last = Math.min(layout.columns, first + layout.visibleColumns)
  const visible = []
  for (let column = first; column < last; column += 1) visible.push(column)

  // How much wall is still to come. At the very end there is none, so the haze — which
  // exists only to hide where the drawing stops — has nothing left to hide and lifts.
  const hazeOpacity = Math.min(1, (layout.maxOffset - offset) / (layout.columnWidth * 6))

  const focus = (poster: MoviePoster | null, index: number | null) => {
    setHovered(index)
    onFocus(poster)
  }

  return (
    <div className="flex flex-col gap-3">
      <div
        ref={viewportRef}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
        onMouseLeave={() => focus(null, null)}
        className="relative h-[62vh] min-h-[340px] cursor-grab touch-pan-y overflow-hidden border border-ink bg-surface-2 active:cursor-grabbing"
        style={{ perspective: `${PERSPECTIVE_PX}px` }}
      >
        <div
          className="absolute inset-0"
          style={{
            transformStyle: 'preserve-3d',
            // Pinned to the left edge: the near end of the wall then starts exactly at the
            // frame instead of swinging past it.
            transformOrigin: '0% 50%',
            transform: `rotateY(${TILT_DEG}deg)`,
          }}
        >
          <div
            className="absolute inset-y-0 left-0 will-change-transform"
            style={{ transformStyle: 'preserve-3d', transform: `translate3d(${-offset}px, 0, 0)` }}
          >
            {visible.map((column) => (
              <Column
                key={column}
                column={column}
                posters={posters}
                layout={layout}
                hovered={hovered}
                onEnter={focus}
                onOpen={(id) => navigate(`/movies/${id}`)}
              />
            ))}
          </div>
        </div>

        {/* The haze. It hides where the drawing stops, and a wall that fades into its own
            backing is the most honest depth cue this palette has — hence to-surface-2,
            which is exactly the frame's own colour in either theme. Only on the far side:
            the near edge is the front of the wall and stays crisp. */}
        <div
          className="pointer-events-none absolute inset-y-0 right-0 w-1/3 bg-linear-to-r from-transparent to-surface-2 transition-opacity duration-200"
          style={{ opacity: hazeOpacity }}
        />
      </div>

      <label className="flex items-center gap-3">
        <span className="sr-only">Position sur le mur</span>
        <input
          type="range"
          min={0}
          max={Math.max(1, Math.round(layout.maxOffset))}
          value={Math.round(offset)}
          onChange={(event) => setOffset(clamp(Number(event.target.value), layout.maxOffset))}
          className="h-1 w-full appearance-none bg-ink/20 accent-accent"
        />
        {/* Counted in films, not columns: a column index is an artefact of the hanging, and
            the one at the near edge can never reach the total however far you walk. */}
        <span className="shrink-0 font-mono text-[10px] uppercase tracking-widest text-subtle tabular-nums">
          {firstInFrame * ROWS + 1}&ndash;{Math.min(posters.length, (lastInFrame + 1) * ROWS)} / {posters.length}
        </span>
      </label>
    </div>
  )
}

interface Layout {
  cellHeight: number
  cellWidth: number
  columnWidth: number
  columns: number
  wallWidth: number
  visibleColumns: number
  maxOffset: number
}

/**
 * One column of the wall. Memoised because the wall re-renders on every frame of a pan and
 * a column's own props never change while it is on screen.
 */
const Column = memo(function Column({
  column,
  posters,
  layout,
  hovered,
  onEnter,
  onOpen,
}: {
  column: number
  posters: MoviePoster[]
  layout: Layout
  hovered: number | null
  onEnter: (poster: MoviePoster | null, index: number | null) => void
  onOpen: (id: number) => void
}) {
  return (
    <div
      className="absolute top-0"
      style={{
        left: INSET + column * layout.columnWidth,
        width: layout.cellWidth,
        transformStyle: 'preserve-3d',
      }}
    >
      {Array.from({ length: ROWS }, (_, row) => {
        const index = column * ROWS + row
        const poster = posters[index]
        if (!poster) return null

        const isFocused = hovered === index

        return (
          <button
            key={poster.id}
            type="button"
            onMouseEnter={() => onEnter(poster, index)}
            onFocus={() => onEnter(poster, index)}
            onBlur={() => onEnter(null, null)}
            onClick={() => onOpen(poster.id)}
            title={`${poster.title}${poster.releaseYear ? ` (${poster.releaseYear})` : ''}`}
            className={cn(
              'absolute block overflow-hidden border-2 transition-[transform,filter,opacity,border-color] duration-200 ease-out',
              isFocused ? 'z-10 border-ink' : 'border-transparent'
            )}
            style={{
              top: GAP + row * (layout.cellHeight + GAP),
              width: layout.cellWidth,
              height: layout.cellHeight,
              // Counter-rotating squares the poster back up to the viewer, and the push
              // forward is what lifts it off the wall rather than merely enlarging it.
              transform: isFocused
                ? `translateZ(${LIFT_Z}px) rotateY(${-TILT_DEG}deg) scale(${LIFT_SCALE})`
                : undefined,
              // The wall is held near the paper's monochrome; only what you are looking at
              // gets its colour back.
              filter: isFocused ? 'none' : 'grayscale(0.82) contrast(0.94)',
              opacity: isFocused ? 1 : 0.88,
            }}
          >
            <img
              src={poster.posterUrl}
              alt={poster.title}
              loading="lazy"
              draggable={false}
              className="h-full w-full object-cover"
            />
          </button>
        )
      })}
    </div>
  )
})

function clamp(value: number, max: number): number {
  return Math.max(0, Math.min(value, max))
}
