import { Link } from 'react-router-dom'
import { PageMeta } from '@/components/PageMeta'
import { buttonVariants } from '@/components/ui/Button'
import { cn } from '@/utils/cn'

/**
 * The catch-all. Without it an unknown path matched no route at all and rendered a blank
 * page under the masthead — and, once this is served as static files, a blank page returned
 * with a 200. That is the "soft 404" search engines penalise: a URL that reports success and
 * holds nothing, which is how a site ends up with hundreds of indexed ghosts.
 *
 * The noindex here is what actually keeps it out of an index. A real 404 status has to come
 * from the web server, which cannot know the client's route table — see the SPA fallback
 * rules in docker/frontend/.
 */
export function NotFoundPage() {
  return (
    <div className="flex flex-col items-center gap-6 py-16 text-center">
      <PageMeta title="Page introuvable" noindex />

      <p className="font-mono text-xs uppercase tracking-widest text-accent">Erreur 404</p>
      <h1 className="max-w-xl text-balance font-serif text-5xl font-black leading-[0.95] tracking-tighter sm:text-6xl">
        Cette page n'a jamais été imprimée
      </h1>
      <p className="max-w-md font-body text-sm italic text-subtle">
        L'adresse ne correspond à rien dans Mova. Un lien a peut-être vieilli, ou une lettre s'est perdue en
        chemin.
      </p>

      <div className="flex flex-wrap justify-center gap-3">
        <Link to="/" className={buttonVariants({ variant: 'primary', size: 'sm' })}>
          Retour au dashboard
        </Link>
        <Link to="/movies" className={cn(buttonVariants({ variant: 'secondary', size: 'sm' }))}>
          Parcourir la bibliothèque
        </Link>
      </div>
    </div>
  )
}
