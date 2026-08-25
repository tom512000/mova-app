import { useState, type FormEvent } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useSession } from '@/hooks/useSession'
import { AuthLayout } from '@/layouts/AuthLayout'
import { Button } from '@/components/ui/Button'
import { TextField } from '@/components/ui/TextField'

export function LoginPage() {
  const { user, isLoading, login } = useSession()
  const navigate = useNavigate()
  const location = useLocation()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  // Comes back from RequireAuth, which stashes wherever the visitor was actually heading —
  // notably a /share/<token> link opened while logged out.
  const target = (location.state as { from?: string } | null)?.from ?? '/'

  if (isLoading) return null
  if (user) return <Navigate to={target} replace />

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)

    try {
      await login(email, password)
      navigate(target, { replace: true })
    } catch {
      // The API deliberately answers the same way for an unknown email and a wrong
      // password, so there is nothing more specific to show here.
      setError('Email ou mot de passe incorrect.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <AuthLayout subtitle="Connectez-vous pour retrouver votre journal de visionnage.">
      <form onSubmit={handleSubmit} className="mt-10 flex flex-col gap-4 border border-ink bg-paper p-6">
        <TextField
          label="Email"
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
          autoFocus
          autoComplete="username"
        />

        <TextField
          label="Mot de passe"
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
          autoComplete="current-password"
        />

        {error && (
          <p role="alert" className="border border-accent px-3 py-2 font-mono text-xs text-accent">
            {error}
          </p>
        )}

        <Button type="submit" disabled={isSubmitting} className="mt-2">
          {isSubmitting ? 'Connexion…' : 'Se connecter'}
        </Button>
      </form>

      <p className="mt-6 text-center font-mono text-[11px] text-subtle">
        Pas encore de compte ?{' '}
        <Link to="/register" state={location.state} className="uppercase tracking-widest text-accent hover:underline">
          Créer un compte
        </Link>
      </p>
    </AuthLayout>
  )
}
