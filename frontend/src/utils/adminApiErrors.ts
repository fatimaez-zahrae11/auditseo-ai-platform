import { ApiError } from '../services/apiClient';
import { getPublicApiErrorMessage } from './publicApiErrors';

export const adminReadErrorMessage = (error: unknown, resource: string) => {
  if (!(error instanceof ApiError)) return `Unable to load ${resource}.`;
  return getPublicApiErrorMessage(error, {
    fallback: `Unable to load ${resource} right now.`,
    validationFallback: `The ${resource} request was rejected.`,
    forbiddenMessage: 'Access denied. An administrator account is required.',
    notFoundMessage: `${resource.charAt(0).toUpperCase()}${resource.slice(1)} were not found.`,
    rateLimitMessage: `Too many requests. Please wait before refreshing ${resource}.`,
  });
};
