import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowRight } from 'lucide-react'
import { acceptShareLink } from '@/services/profileService'
import { useSession } from '@/hooks/useSession'
import { Button } from '@/components/ui/Button'
import type { ProfileSummary } from '@/types/api'
import { PageMeta } from '@/components/PageMeta'

type State =
  | { status: 'loading' }
  | { status: 'granted'; profile: ProfileSummary; alreadyGranted: boolean }
  | { status: 'error'; message: string }

/**
 * Landing page for a share link. Claiming the grant is a POST, so it only happens once the
 * visitor is logged in — RequireAuth sends them through the login page first and brings them
 * back here, which is why the token has to survive in the URL rather than in memory.
 */
export function SharePage() {
  const { token } = useParams<{ token: string }>()
  const { switchProfile } = useSession()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const [state, setState] = useState<State>({ status: 'loading' })
  // StrictMode mounts effects twice in development; without this the accept call fires
  // twice. It is idempotent server-side, but the duplicate request is noise.
  const claimed = useRef(false)

  useEffect(() => {
    if (!token || claimed.current) return
    claimed.current = true

    acceptShareLink(token)
      .then(async (result) => {
        await queryClient.invalidateQueries({ queryKey: ['profiles'] })
        setState({ status: 'granted', profile: result.profile, alreadyGranted: result.alreadyGranted })
      })
      .catch((error) => {
        const message =
          (error as { response?: { data?: { error?: string } } }).response?.data?.error ??
          'Ce lien de partage est invalide ou a expiré.'
        setState({ status: 'error', message })
      })
  }, [token, queryClient])

  function openProfile(profile: ProfileSummary) {
    switchProfile(profile.id)
    navigate('/')
  }

  return (
    <div className="mx-auto max-w-xl py-8">
      <PageMeta title="Profil partagé" />
      {state.status === 'loading' && <p className="font-mono text-sm text-subtle">Validation du lien…</p>}

      {state.status === 'error' && (
        <div className="border border-accent p-6">
          <h1 className="font-serif text-3xl font-bold text-accent">Lien invalide</h1>
          <p className="mt-2 font-body text-sm text-ink/70">{state.message}</p>
          <Link to="/" className="mt-6 inline-block font-mono text-xs uppercase tracking-widest text-accent hover:underline">
            Retour au tableau de bord
          </Link>
        </div>
      )}

      {state.status === 'granted' && (
        <div className="hard-shadow-hover border border-ink p-6 sm:p-8">
          <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
            {state.alreadyGranted ? 'Accès déjà actif' : 'Nouvel accès'}
          </p>
          <h1 className="mt-2 font-serif text-3xl font-bold sm:text-4xl">
            Vous avez maintenant accès au profil de {state.profile.displayName}.
          </h1>
          <p className="mt-3 font-body text-sm italic text-subtle">
            Il apparaît dans le sélecteur de profils, en haut de page. Vous pouvez consulter ses films, sa watchlist et
            ses statistiques ; ses imports restent privés.
          </p>

          <Button onClick={() => openProfile(state.profile)} className="mt-6">
            Ouvrir le profil de {state.profile.displayName}
            <ArrowRight className="h-4 w-4" strokeWidth={1.5} />
          </Button>
        </div>
      )}
    </div>
  )
}
