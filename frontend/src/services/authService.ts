import { apiRequest } from './apiClient';

export interface BackendUser {
  id: number | string;
  name: string;
  email: string;
  role: 'user' | 'admin';
  is_active: boolean;
  email_verified_at: string | null;
  created_at: string;
  updated_at: string;
  last_login_at?: string | null;
  avatar_url?: string | null;
  audits_count?: number;
  completed_audits_count?: number;
  failed_audits_count?: number;
  recommendations_count?: number;
}

export interface RegisterPayload {
  name: string;
  email: string;
  password: string;
}

export interface LoginPayload {
  email: string;
  password: string;
}

export interface ResetPasswordPayload {
  email: string;
  token: string;
  password: string;
  password_confirmation: string;
}

interface MessageResponse {
  message: string;
}

export interface LoginResponse extends MessageResponse {
  user: BackendUser;
  token: string;
}

interface GoogleRedirectResponse {
  url: string;
}

interface MeResponse {
  user: BackendUser;
}

export const authService = {
  register: (payload: RegisterPayload) => apiRequest<MessageResponse>('/register', {
    method: 'POST',
    body: payload,
  }, { auth: 'none' }),

  login: (payload: LoginPayload) => apiRequest<LoginResponse>('/login', {
    method: 'POST',
    body: payload,
  }, { auth: 'none' }),

  requestPasswordReset: (email: string) => apiRequest<MessageResponse>('/forgot-password', {
    method: 'POST',
    body: { email },
  }, { auth: 'none' }),

  resetPassword: (payload: ResetPasswordPayload) => apiRequest<MessageResponse>('/reset-password', {
    method: 'POST',
    body: payload,
  }, { auth: 'none' }),

  googleRedirect: () => apiRequest<GoogleRedirectResponse>('/auth/google/redirect', {}, { auth: 'none' }),

  exchangeGoogleCode: (code: string) => apiRequest<LoginResponse>('/auth/google/exchange', {
    method: 'POST',
    body: { code },
  }, { auth: 'none' }),

  me: () => apiRequest<MeResponse>('/me', {}, { auth: 'required' }),

  logout: () => apiRequest<MessageResponse>('/logout', { method: 'POST' }, { auth: 'required' }),

  resendVerificationEmail: (email: string) => apiRequest<MessageResponse>(
    '/email/verification-notification',
    {
      method: 'POST',
      body: { email },
    },
    { auth: 'none' },
  ),
};
