export interface FieldViolation {
  field: string
  message: string
}

interface ApiErrorBody {
  error?: string
  message?: string
  violations?: FieldViolation[]
}

function bodyOf(error: unknown): ApiErrorBody | undefined {
  return (error as { response?: { data?: ApiErrorBody } })?.response?.data
}

export function apiErrorMessage(error: unknown, fallback: string): string {
  const body = bodyOf(error)
  return body?.error ?? body?.message ?? fallback
}

/**
 * Field-level violations from a 422, keyed by field name so a form can put each message
 * under the input it belongs to. Empty when the failure wasn't a validation one.
 */
export function apiFieldErrors(error: unknown): Record<string, string> {
  const violations = bodyOf(error)?.violations ?? []
  return Object.fromEntries(violations.map((v) => [v.field, v.message]))
}
