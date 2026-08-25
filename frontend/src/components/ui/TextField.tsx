import { useId, type InputHTMLAttributes } from 'react'
import { cn } from '@/utils/cn'

export interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string
  /** Server-side violation for this field, rendered under the input and announced. */
  error?: string
  hint?: string
}

/**
 * The one input shape used by every auth form, so a validation message always lands in the
 * same place. aria-invalid + aria-describedby are what let a screen reader tie the message
 * to the field rather than reading it as loose text.
 */
export function TextField({ label, error, hint, className, ...props }: TextFieldProps) {
  const id = useId()
  const errorId = `${id}-error`
  const hintId = `${id}-hint`

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="font-mono text-[10px] uppercase tracking-widest text-subtle">
        {label}
      </label>

      <input
        {...props}
        id={id}
        aria-invalid={error ? true : undefined}
        aria-describedby={cn(error && errorId, hint && hintId) || undefined}
        className={cn(
          'min-h-11 border bg-transparent px-3 font-mono text-sm focus:outline-none focus:ring-2',
          error ? 'border-accent focus:ring-accent' : 'border-ink focus:ring-accent',
          className
        )}
      />

      {hint && !error && (
        <p id={hintId} className="font-mono text-[10px] text-subtle">
          {hint}
        </p>
      )}

      {error && (
        <p id={errorId} className="font-mono text-[10px] text-accent">
          {error}
        </p>
      )}
    </div>
  )
}
