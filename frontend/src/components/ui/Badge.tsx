import { cva, type VariantProps } from 'class-variance-authority'
import type { HTMLAttributes } from 'react'
import { cn } from '@/utils/cn'

const badgeVariants = cva(
  'inline-flex items-center gap-1 border px-2 py-0.5 font-mono text-[10px] uppercase tracking-widest',
  {
    variants: {
      variant: {
        outline: 'border-ink/40 text-ink/70',
        solid: 'border-ink bg-ink text-paper',
        accent: 'border-accent text-accent',
      },
    },
    defaultVariants: {
      variant: 'outline',
    },
  }
)

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...props }: BadgeProps) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />
}
