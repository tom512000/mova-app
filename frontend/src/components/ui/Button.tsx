import { cva, type VariantProps } from 'class-variance-authority'
import type { ButtonHTMLAttributes } from 'react'
import { cn } from '@/utils/cn'

export const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap border font-sans text-xs font-semibold uppercase tracking-widest transition-all duration-200 ease-out disabled:pointer-events-none disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink focus-visible:ring-offset-2 focus-visible:ring-offset-paper',
  {
    variants: {
      variant: {
        primary: 'border-transparent bg-ink text-paper hover:border-ink hover:bg-paper hover:text-ink',
        secondary: 'border-ink bg-transparent text-ink hover:bg-ink hover:text-paper',
        ghost: 'border-transparent text-ink hover:bg-surface',
        link: 'border-transparent p-0 text-ink underline-offset-4 decoration-2 decoration-accent normal-case tracking-normal hover:underline',
      },
      size: {
        default: 'min-h-11 px-5 py-3',
        sm: 'min-h-11 px-3 py-2 text-[11px]',
        icon: 'h-11 w-11',
      },
    },
    defaultVariants: {
      variant: 'primary',
      size: 'default',
    },
  }
)

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {}

export function Button({ className, variant, size, ...props }: ButtonProps) {
  return <button className={cn(buttonVariants({ variant, size }), className)} {...props} />
}
