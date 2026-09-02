import { ApiError } from '../services/apiClient';

interface PublicApiErrorOptions {
  fallback?: string;
  validationFallback?: string;
  forbiddenMessage?: string;
  notFoundMessage?: string;
  rateLimitMessage?: string;
}

const unsafeDiagnosticPattern = /\b(exception|stack\s*trace|sqlstate|authorization|bearer|api[_ -]?key|access[_ -]?token|provider payload|curl error|connection refused|database query|localhost)\b|https?:\/\/|\b(?:127\.\d{1,3}(?:\.\d{1,3}){2}|10\.\d{1,3}(?:\.\d{1,3}){2}|192\.168\.\d{1,3}\.\d{1,3})\b|(?:^|[\\/])(?:vendor|storage|app)[\\/]/i;

const safeValidationMessage = (value: unknown) => {
  if (typeof value !== 'string') return null;
  const message = value.trim();
  if (!message || message.length > 240 || unsafeDiagnosticPattern.test(message)) return null;
  return message;
};

export const getSafeValidationFieldMessage = (
  error: unknown,
  field: string,
  fallback?: string,
) => {
  if (!(error instanceof ApiError)) return fallback;
  return safeValidationMessage(error.errors?.[field]?.[0]) || fallback;
};

export const getPublicApiErrorMessage = (
  error: unknown,
  options: PublicApiErrorOptions = {},
) => {
  const fallback = options.fallback || 'Something went wrong. Please try again later.';
  if (!(error instanceof ApiError)) return fallback;

  if (error.status === 0) return 'Unable to reach the service. Check your connection and try again.';
  if (error.status === 401) return 'Your session has expired. Please sign in again.';
  if (error.status === 403) return options.forbiddenMessage || 'You do not have permission to perform this action.';
  if (error.status === 404) return options.notFoundMessage || 'The requested resource was not found.';
  if (error.status === 422) {
    const validationMessage = error.errors
      ? Object.values(error.errors).flat().map(safeValidationMessage).find(Boolean)
      : null;
    return validationMessage || options.validationFallback || 'Please check the submitted information and try again.';
  }
  if (error.status === 429) return options.rateLimitMessage || 'Too many requests. Please try again later.';
  if (error.status >= 500) return 'Something went wrong. Please try again later.';
  return fallback;
};

export const PUBLIC_AUDIT_FAILURE_MESSAGE = 'The audit could not be completed for this URL. Please verify the site is reachable and try again.';
