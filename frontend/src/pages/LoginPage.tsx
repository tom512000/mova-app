import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { Moon, Sun } from 'lucide-react'
import { useSession } from '@/hooks/useSession'
import { useTheme } from '@/hooks/useTheme'
import { Button } from '@/components/ui/Button'

const EDITION_DATE = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

export function LoginPage() {
  const { user, isLoading, login } = useSession()
  const { theme, toggleTheme } = useTheme()
  const navigate = useNavigate()
  const location = useLocation()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  if (isLoading) return null

  if (user) {
    // Comes back from RequireAuth, which stashes wherever the visitor was actually heading —
    // notably a /share/<token> link opened while logged out.
    const target = (location.state as { from?: string } | null)?.from ?? '/'
    return <Navigate to={target} replace />
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)

    try {
      await login(email, password)
      navigate((location.state as { from?: string } | null)?.from ?? '/', { replace: true })
    } catch {
      setError('Email ou mot de passe incorrect.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-screen flex-col bg-paper text-ink">
      <header className="border-b-4 border-ink">
        <div className="mx-auto flex max-w-screen-xl flex-wrap items-center justify-between gap-2 px-4 py-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
          <span>Vol. I &middot; Édition numérique</span>
          <span>{EDITION_DATE}</span>
        </div>
      </header>

      <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-4 py-12">
        <h1 className="text-center font-serif text-5xl font-black tracking-tighter">Ciné-Stats</h1>
        <p className="mt-2 text-center font-body text-sm italic text-subtle">
          Connectez-vous pour retrouver votre journal de visionnage.
        </p>

        <form onSubmit={handleSubmit} className="mt-10 flex flex-col gap-4 border border-ink bg-paper p-6">
          <label className="flex flex-col gap-1.5">
            <span className="font-mono text-[10px] uppercase tracking-widest text-subtle">Email</span>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              autoFocus
              autoComplete="username"
              className="min-h-11 border border-ink bg-transparent px-3 font-mono text-sm focus:outline-none focus:ring-2 focus:ring-accent"
            />
          </label>

          <label className="flex flex-col gap-1.5">
            <span className="font-mono text-[10px] uppercase tracking-widest text-subtle">Mot de passe</span>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              autoComplete="current-password"
              className="min-h-11 border border-ink bg-transparent px-3 font-mono text-sm focus:outline-none focus:ring-2 focus:ring-accent"
            />
          </label>

          {error && (
            <p role="alert" className="border border-accent px-3 py-2 font-mono text-xs text-accent">
              {error}
            </p>
          )}

          <Button type="submit" disabled={isSubmitting} className="mt-2">
            {isSubmitting ? 'Connexion…' : 'Se connecter'}
          </Button>
        </form>
      </main>

      <footer className="border-t-4 border-ink">
        <div className="mx-auto flex max-w-screen-xl items-center justify-between px-4 py-6 font-mono text-[10px] uppercase tracking-widest text-subtle">
          <span>Ciné-Stats</span>
          <button
            onClick={toggleTheme}
            aria-label={theme === 'dark' ? 'Passer en édition papier (clair)' : 'Passer en édition nuit (sombre)'}
            className="flex h-11 w-11 items-center justify-center border border-ink text-ink transition-colors duration-200 hover:bg-ink hover:text-paper"
          >
            {theme === 'dark' ? <Sun className="h-4 w-4" strokeWidth={1.5} /> : <Moon className="h-4 w-4" strokeWidth={1.5} />}
          </button>
        </div>
      </footer>
    </div>
  )
}
