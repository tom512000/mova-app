/**
 * The drawing, one stroke per life spent.
 *
 * Seven strokes for seven lives, so the picture and the counter can never disagree — the
 * gallows itself is standing from the start and costs nothing, since a board that begins
 * with an empty frame reads as "the game has not started hurting yet".
 */
const STROKES = [
  // The rope first: the figure needs something to hang from before it appears.
  <line key="rope" x1="86" y1="10" x2="86" y2="38" />,
  <circle key="head" cx="86" cy="48" r="10" />,
  <line key="torso" x1="86" y1="58" x2="86" y2="96" />,
  <line key="arm-left" x1="86" y1="68" x2="70" y2="84" />,
  <line key="arm-right" x1="86" y1="68" x2="102" y2="84" />,
  <line key="leg-left" x1="86" y1="96" x2="72" y2="122" />,
  <line key="leg-right" x1="86" y1="96" x2="100" y2="122" />,
]

export function Gallows({ livesLeft, lives }: { livesLeft: number; lives: number }) {
  const drawn = Math.max(0, Math.min(lives, lives - livesLeft))

  return (
    <svg
      viewBox="0 0 130 140"
      className="h-40 w-auto shrink-0 stroke-ink"
      fill="none"
      strokeWidth={3}
      strokeLinecap="square"
      role="img"
      aria-label={`Potence : ${drawn} trait${drawn > 1 ? 's' : ''} sur ${lives}`}
    >
      <g className="stroke-ink/35">
        <line x1="14" y1="132" x2="66" y2="132" />
        <line x1="34" y1="132" x2="34" y2="10" />
        <line x1="34" y1="10" x2="86" y2="10" />
        {/* The corner brace, without which the frame reads as three loose sticks. */}
        <line x1="34" y1="30" x2="54" y2="10" />
      </g>
      {STROKES.slice(0, drawn)}
    </svg>
  )
}
