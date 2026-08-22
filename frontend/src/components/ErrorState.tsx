export function ErrorState({ message }: { message?: string }) {
  return (
    <div className="rounded-xl border border-red-200 bg-red-50 p-6 text-center dark:border-red-900/50 dark:bg-red-950/30">
      <p className="font-medium text-red-700 dark:text-red-400">Une erreur est survenue</p>
      {message && <p className="mt-1 text-sm text-red-600/80 dark:text-red-400/70">{message}</p>}
    </div>
  )
}
