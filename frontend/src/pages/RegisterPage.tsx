import { useState, type FormEvent } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useSession } from '@/hooks/useSession'
import { AuthLayout } from '@/layouts/AuthLayout'
import { Button } from '@/components/ui/Button'
import { TextField } from '@/components/ui/TextField'
import { apiErrorMessage, apiFieldErrors } from '@/utils/apiError'

const MIN_PASSWORD_LENGTH = 8

export function RegisterPage() {
  const { user, isLoading, register } = useSession()
  const navigate = useNavigate()
  const location = useLocation()

  const [email, setEmail] = useState('')
  const [displayName, setDisplayName] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [isSubmitting, setIsSubmitting] = useState(false)

  const target = (location.state as { from?: string } | null)?.from ?? '/'

  if (isLoading) return null
  if (user) return <Navigate to={target} replace />

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setFieldErrors({})

    // Purely a client-side courtesy: the API has no concept of a confirmation field, since
    // it only ever receives the one password.
    if (password !== confirmation) {
      setFieldErrors({ confirmation: 'Les deux mots de passe ne correspondent pas.' })
      return
    }

    setIsSubmitting(true)
    try {
      await register(email, displayName, password)
      navigate(target, { replace: true })
    } catch (err) {
      const violations = apiFieldErrors(err)
      setFieldErrors(violations)
      // A 409 (email taken) carries a message but no violations; a 422 carries violations
      // that are already shown under each field, so a banner on top would just repeat them.
      if (Object.keys(violations).length === 0) {
        setError(apiErrorMessage(err, 'La création du compte a échoué.'))
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <AuthLayout subtitle="Créez un compte pour importer et suivre vos visionnages.">
      <form onSubmit={handleSubmit} className="mt-10 flex flex-col gap-4 border border-ink bg-paper p-6">
        <TextField
          label="Nom affiché"
          value={displayName}
          onChange={(e) => setDisplayName(e.target.value)}
          required
          autoFocus
          autoComplete="nickname"
          error={fieldErrors.displayName}
          hint="Le nom que verront les personnes avec qui vous partagez votre profil."
        />

        <TextField
          label="Email"
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
          autoComplete="username"
          error={fieldErrors.email}
        />

        <TextField
          label="Mot de passe"
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
          minLength={MIN_PASSWORD_LENGTH}
          autoComplete="new-password"
          error={fieldErrors.password}
          hint={`${MIN_PASSWORD_LENGTH} caractères minimum.`}
        />

        <TextField
          label="Confirmer le mot de passe"
          type="password"
          value={confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
          required
          autoComplete="new-password"
          error={fieldErrors.confirmation}
        />

        {error && (
          <p role="alert" className="border border-accent px-3 py-2 font-mono text-xs text-accent">
            {error}
          </p>
        )}

        <Button type="submit" disabled={isSubmitting} className="mt-2">
          {isSubmitting ? 'Création…' : 'Créer mon compte'}
        </Button>
      </form>

      <p className="mt-6 text-center font-mono text-[11px] text-subtle">
        Déjà inscrit ?{' '}
        <Link to="/login" state={location.state} className="uppercase tracking-widest text-accent hover:underline">
          Se connecter
        </Link>
      </p>
    </AuthLayout>
  )
}
