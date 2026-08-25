import { ChevronDown } from 'lucide-react'
import { useSession } from '@/hooks/useSession'

/**
 * The profile picker in the masthead's top rule. A native <select> on purpose: it is one
 * tab stop, it opens correctly on touch, and it inherits the masthead's typography — a
 * custom dropdown would only add markup to reproduce what the platform already does.
 *
 * Renders nothing until at least one other profile has been shared, so the strip stays as
 * quiet as it was for anyone not using sharing.
 */
export function ProfileSwitcher() {
  const { profiles, activeProfile, switchProfile } = useSession()

  if (profiles.length < 2 || !activeProfile) return null

  return (
    <label className="group relative inline-flex items-center gap-1.5">
      <span className="sr-only">Profil consulté</span>

      <select
        value={activeProfile.id}
        onChange={(event) => switchProfile(Number(event.target.value))}
        // appearance-none removes the platform arrow so the lucide chevron below can sit
        // flush with the rest of the strip; pr-5 reserves its room.
        className="cursor-pointer appearance-none border-0 bg-transparent py-0 pl-0 pr-5 font-mono text-[10px] uppercase tracking-widest text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
      >
        {profiles.map((profile) => (
          <option key={profile.id} value={profile.id} className="bg-paper text-ink">
            {profile.isSelf ? `${profile.displayName} (moi)` : profile.displayName}
          </option>
        ))}
      </select>

      <ChevronDown
        aria-hidden
        className="pointer-events-none absolute right-0 h-3 w-3 text-subtle transition-colors group-hover:text-accent"
        strokeWidth={2}
      />
    </label>
  )
}
