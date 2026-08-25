import { useState, type FormEvent } from 'react'
import { Check } from 'lucide-react'
import { useSession } from '@/hooks/useSession'
import { changePassword } from '@/services/authService'
import { Button } from '@/components/ui/Button'
import { TextField } from '@/components/ui/TextField'
import { apiErrorMessage, apiFieldErrors } from '@/utils/apiError'

const MIN_PASSWORD_LENGTH = 8

export function AccountPage() {
  const { user } = useSession()

  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isDone, setIsDone] = useState(false)

  if (!user) return null

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setFieldErrors({})
    setIsDone(false)

    if (newPassword !== confirmation) {
      setFieldErrors({ confirmation: 'Les deux mots de passe ne correspondent pas.' })
      return
    }

    setIsSubmitting(true)
    try {
      await changePassword(currentPassword, newPassword)
      setCurrentPassword('')
      setNewPassword('')
      setConfirmation('')
      setIsDone(true)
    } catch (err) {
      const violations = apiFieldErrors(err)
      setFieldErrors(violations)
      if (Object.keys(violations).length === 0) {
        setError(apiErrorMessage(err, 'Le changement de mot de passe a échoué.'))
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="mx-auto max-w-xl">
      <div className="mb-8 border-b-2 border-ink pb-4">
        <h1 className="font-serif text-4xl font-black tracking-tighter sm:text-5xl">Mon compte</h1>
        <p className="mt-2 font-body text-sm italic text-subtle">Vos identifiants de connexion.</p>
      </div>

      <dl className="mb-10 grid grid-cols-1 gap-px border border-ink bg-ink/15 sm:grid-cols-2">
        <div className="bg-paper p-4">
          <dt className="font-mono text-[10px] uppercase tracking-widest text-subtle">Nom affiché</dt>
          <dd className="mt-1 font-serif text-lg font-bold">{user.displayName}</dd>
        </div>
        <div className="bg-paper p-4">
          <dt className="font-mono text-[10px] uppercase tracking-widest text-subtle">Email</dt>
          <dd className="mt-1 truncate font-mono text-sm">{user.email}</dd>
        </div>
      </dl>

      <section className="border border-ink p-5 sm:p-6">
        <h2 className="font-serif text-2xl font-bold">Changer de mot de passe</h2>

        <form onSubmit={handleSubmit} className="mt-5 flex flex-col gap-4">
          <TextField
            label="Mot de passe actuel"
            type="password"
            value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)}
            required
            autoComplete="current-password"
            error={fieldErrors.currentPassword}
          />

          <TextField
            label="Nouveau mot de passe"
            type="password"
            value={newPassword}
            onChange={(e) => setNewPassword(e.target.value)}
            required
            minLength={MIN_PASSWORD_LENGTH}
            autoComplete="new-password"
            error={fieldErrors.newPassword}
            hint={`${MIN_PASSWORD_LENGTH} caractères minimum.`}
          />

          <TextField
            label="Confirmer le nouveau mot de passe"
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

          {isDone && (
            <p role="status" className="inline-flex items-center gap-2 border border-ink px-3 py-2 font-mono text-xs">
              <Check className="h-4 w-4 text-accent" strokeWidth={2} />
              Mot de passe mis à jour. Vous restez connecté sur cet appareil.
            </p>
          )}

          <Button type="submit" disabled={isSubmitting} className="mt-2 self-start">
            {isSubmitting ? 'Mise à jour…' : 'Mettre à jour'}
          </Button>
        </form>
      </section>
    </div>
  )
}
