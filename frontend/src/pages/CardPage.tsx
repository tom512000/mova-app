import { useParams } from 'react-router-dom'
import { PageMeta } from '@/components/PageMeta'
import { ErrorState } from '@/components/ErrorState'
import { CardDetailPanel } from '@/components/cards/CardDetailPanel'

/**
 * A card on its own page.
 *
 * Clicking a card in the album opens a dialog instead — a glance should not cost a
 * navigation — so the only way to arrive here is a shared or bookmarked URL. It stays for
 * exactly that: both render CardDetailPanel, so there is one thing to keep correct.
 */
export function CardPage() {
  const { id } = useParams<{ id: string }>()

  if (id === undefined) {
    return <ErrorState message="Cette carte est introuvable." />
  }

  return (
    <div className="flex flex-col gap-8">
      <PageMeta title="La carte" />
      <CardDetailPanel cardId={id} />
    </div>
  )
}
