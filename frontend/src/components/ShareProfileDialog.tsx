import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy, RefreshCw, X } from 'lucide-react'
import { fetchShareLink, rotateShareLink } from '@/services/profileService'
import { Button } from '@/components/ui/Button'

/**
 * Hands the owner a URL to send. The token is only minted when this opens, so an account
 * that never shares never has a link to leak.
 */
export function ShareProfileDialog({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const dialogRef = useRef<HTMLDialogElement>(null)
  const [copied, setCopied] = useState(false)

  const shareLink = useQuery({ queryKey: ['share-link'], queryFn: fetchShareLink })

  const rotate = useMutation({
    mutationFn: rotateShareLink,
    onSuccess: (link) => {
      queryClient.setQueryData(['share-link'], link)
      setCopied(false)
    },
  })

  // showModal() (rather than the `open` attribute) is what gives the focus trap, the
  // backdrop and Escape-to-close for free.
  useEffect(() => {
    dialogRef.current?.showModal()
  }, [])

  const shareUrl = shareLink.data ? `${window.location.origin}/share/${shareLink.data.token}` : ''

  async function copy() {
    try {
      await navigator.clipboard.writeText(shareUrl)
      setCopied(true)
    } catch {
      // Clipboard access is refused outside a secure context (plain http on a LAN address,
      // say). The input is selectable, so a manual copy still works — just say so.
      setCopied(false)
    }
  }

  return (
    <dialog
      ref={dialogRef}
      onClose={onClose}
      onClick={(event) => {
        // The dialog element itself covers the whole viewport; clicks that land on it
        // rather than on the panel inside are backdrop clicks.
        if (event.target === dialogRef.current) dialogRef.current?.close()
      }}
      className="m-auto w-[min(32rem,calc(100vw-2rem))] border border-ink bg-paper p-0 text-ink backdrop:bg-ink/40"
    >
      <div className="flex items-center justify-between border-b border-ink px-5 py-3">
        <h2 className="font-mono text-[10px] uppercase tracking-widest text-subtle">Partager mon profil</h2>
        <button
          onClick={() => dialogRef.current?.close()}
          aria-label="Fermer"
          className="flex h-8 w-8 items-center justify-center text-subtle hover:text-accent"
        >
          <X className="h-4 w-4" strokeWidth={1.5} />
        </button>
      </div>

      <div className="flex flex-col gap-4 p-5">
        <p className="font-body text-sm text-ink/70">
          Toute personne disposant de ce lien et d'un compte pourra consulter vos films, votre watchlist et vos
          statistiques. Vos imports et votre synchronisation Letterboxd restent privés.
        </p>

        <div className="flex flex-col gap-2 sm:flex-row">
          <input
            readOnly
            value={shareLink.isLoading ? 'Génération du lien…' : shareUrl}
            onFocus={(event) => event.target.select()}
            className="min-h-11 flex-1 border border-ink bg-transparent px-3 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-accent"
          />
          <Button variant="secondary" size="sm" onClick={copy} disabled={!shareUrl}>
            {copied ? <Check className="h-4 w-4" strokeWidth={1.5} /> : <Copy className="h-4 w-4" strokeWidth={1.5} />}
            {copied ? 'Copié' : 'Copier'}
          </Button>
        </div>

        <div className="flex items-center justify-between border-t border-ink/15 pt-4">
          <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
            Révoquer le lien n'exclut pas les personnes déjà autorisées
          </p>
          <Button variant="ghost" size="sm" onClick={() => rotate.mutate()} disabled={rotate.isPending}>
            <RefreshCw className="h-4 w-4" strokeWidth={1.5} />
            Nouveau lien
          </Button>
        </div>
      </div>
    </dialog>
  )
}
