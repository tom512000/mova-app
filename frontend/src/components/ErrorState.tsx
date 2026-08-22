export function ErrorState({ message }: { message?: string }) {
  return (
    <div className="border border-accent bg-accent/5 p-6 text-center">
      <p className="font-mono text-xs uppercase tracking-widest text-accent">Une erreur est survenue</p>
      {message && <p className="mt-2 font-body text-sm text-ink/70">{message}</p>}
    </div>
  )
}
