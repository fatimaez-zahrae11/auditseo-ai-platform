const DEFAULT_API_BASE_URL = 'http://127.0.0.1:8000/api';
const TOKEN_STORAGE_KEY = 'auditseo_auth_token';

type ValidationErrors = Record<string, string[]>;
type UnauthorizedHandler = () => void;
type AuthStateListener = () => void;
export type ApiAuthMode = 'required' | 'optional' | 'none';

let unauthorizedHandler: UnauthorizedHandler | null = null;
let authGeneration = 0;
const authStateListeners = new Set<AuthStateListener>();

const configuredApiBaseUrl = import.meta.env.VITE_API_BASE_URL?.trim();
const apiBaseUrl = (configuredApiBaseUrl || DEFAULT_API_BASE_URL).replace(/\/$/, '');

const validateApiBaseUrl = (value: string) => {
  let parsed: URL;
  try {
    parsed = new URL(value);
  } catch {
    throw new Error('VITE_API_BASE_URL must be a valid absolute URL.');
  }

  const developmentLoopback = !import.meta.env.PROD
    && parsed.protocol === 'http:'
    && (parsed.hostname === '127.0.0.1' || parsed.hostname === 'localhost');
  if (parsed.protocol !== 'https:' && !developmentLoopback) {
    throw new Error('VITE_API_BASE_URL must use HTTPS outside local development.');
  }
};

if (import.meta.env.PROD && !configuredApiBaseUrl) {
  throw new Error('VITE_API_BASE_URL is required for production builds.');
}
validateApiBaseUrl(apiBaseUrl);

export class ApiError extends Error {
  readonly status: number;
  readonly errors?: ValidationErrors;

  constructor(message: string, status: number, errors?: ValidationErrors) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

export const getAuthToken = () => sessionStorage.getItem(TOKEN_STORAGE_KEY);

export const getAuthGeneration = () => authGeneration;

export const subscribeToAuthState = (listener: AuthStateListener) => {
  authStateListeners.add(listener);
  return () => authStateListeners.delete(listener);
};

const notifyAuthStateChanged = () => {
  authGeneration += 1;
  authStateListeners.forEach((listener) => listener());
};

export const setAuthToken = (token: string) => {
  sessionStorage.setItem(TOKEN_STORAGE_KEY, token);
  notifyAuthStateChanged();
};

export const clearAuthToken = () => {
  sessionStorage.removeItem(TOKEN_STORAGE_KEY);
  notifyAuthStateChanged();
};

export const setUnauthorizedHandler = (handler: UnauthorizedHandler | null) => {
  unauthorizedHandler = handler;
};

interface ApiRequestOptions extends Omit<RequestInit, 'body' | 'credentials'> {
  body?: unknown;
}

interface ApiRequestSecurity {
  auth?: ApiAuthMode;
}

interface ErrorResponse {
  message?: string;
  errors?: ValidationErrors;
}

export const apiRequest = async <T>(
  path: string,
  options: ApiRequestOptions = {},
  security: ApiRequestSecurity = {},
): Promise<T> => {
  const authMode = security.auth ?? 'required';
  const token = getAuthToken();
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');

  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json');
  }

  if (authMode !== 'none' && token) {
    headers.set('Authorization', `Bearer ${token}`);
  } else {
    headers.delete('Authorization');
  }

  let response: Response;
  try {
    response = await fetch(`${apiBaseUrl}${path}`, {
      ...options,
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
  } catch {
    throw new ApiError('Unable to reach the authentication service. Check that the Laravel API is running.', 0);
  }

  const data = response.status === 204
    ? null
    : await response.json().catch(() => null) as T | ErrorResponse | null;

  if (!response.ok) {
    const errorResponse = data as ErrorResponse | null;
    const message = errorResponse?.message || `Authentication request failed (${response.status}).`;

    if (response.status === 401 && authMode !== 'none' && token) {
      clearAuthToken();
      unauthorizedHandler?.();
    }

    throw new ApiError(message, response.status, errorResponse?.errors);
  }

  return data as T;
};
