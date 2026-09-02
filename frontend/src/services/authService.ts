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

interface MessageResponse {
  message: string;
}

interface LoginResponse extends MessageResponse {
  user: BackendUser;
  token: string;
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
