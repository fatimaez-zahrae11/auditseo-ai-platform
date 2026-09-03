import React, { createContext, useContext, useEffect, useState } from 'react';
import type { User, ViewType } from '../types';
import { authService, type BackendUser } from '../services/authService';
import { ApiError, clearAuthToken, getAuthToken, setAuthToken, setUnauthorizedHandler } from '../services/apiClient';
import { getPublicApiErrorMessage } from '../utils/publicApiErrors';

export interface ToastItem {
  id: string;
  title: string;
  message?: string;
  type: 'success' | 'error' | 'info' | 'warning';
}

interface AppContextType {
  currentUser: User | null;
  currentView: ViewType;
  authEmailHint: string;
  authNotice: string;
  authLoading: boolean;
  isAuthenticated: boolean;
  selectedAuditId: string | null;
  selectedUserId: string | null;
  toasts: ToastItem[];
  setCurrentView: (view: ViewType) => void;
  login: (email: string, password: string) => Promise<void>;
  startGoogleOAuth: () => Promise<void>;
  completeGoogleOAuth: (code: string) => Promise<void>;
  register: (name: string, email: string, password: string) => Promise<string>;
  resendVerificationEmail: (email: string) => Promise<string>;
  logout: () => Promise<void>;
  selectAudit: (id: string, targetView?: ViewType) => void;
  selectUser: (id: string, targetView?: ViewType) => void;
  setSelectedUserId: (id: string | null) => void;
  addToast: (toast: Omit<ToastItem, 'id'>) => void;
  removeToast: (id: string) => void;
}

const AppContext = createContext<AppContextType | undefined>(undefined);

const getInitialView = (): ViewType => {
  switch (window.location.pathname) {
    case '/auth/google/callback':
      return 'google-callback';
    case '/forgot-password':
      return 'forgot-password';
    case '/reset-password':
      return 'reset-password';
    default:
      return 'login';
  }
};

const isStandalonePublicAuthPath = () => [
  '/auth/google/callback',
  '/forgot-password',
  '/reset-password',
].includes(window.location.pathname);

const normalizeBackendUser = (user: BackendUser): User => ({
  id: String(user.id), name: user.name, email: user.email,
  role: user.role === 'admin' ? 'admin' : 'user',
  status: user.is_active === false ? 'inactive' : 'active',
  emailVerification: user.email_verified_at ? 'verified' : 'unverified',
  avatarUrl: user.avatar_url || undefined,
  createdAt: user.created_at,
  lastLoginAt: user.last_login_at || user.updated_at,
  auditsCount: user.audits_count ?? 0,
  completedAudits: user.completed_audits_count ?? 0,
  failedAudits: user.failed_audits_count ?? 0,
  recommendationsCount: user.recommendations_count ?? 0,
});

export const AppProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [currentUser, setCurrentUser] = useState<User | null>(null);
  const [currentView, setCurrentView] = useState<ViewType>(getInitialView);
  const [authEmailHint, setAuthEmailHint] = useState('');
  const [authNotice, setAuthNotice] = useState('');
  const [authLoading, setAuthLoading] = useState(true);
  const [selectedAuditId, setSelectedAuditId] = useState<string | null>(null);
  const [selectedUserId, setSelectedUserId] = useState<string | null>(null);
  const [toasts, setToasts] = useState<ToastItem[]>([]);

  const removeToast = (id: string) => setToasts((items) => items.filter((item) => item.id !== id));
  const addToast = (toast: Omit<ToastItem, 'id'>) => {
    const id = `toast_${Math.random().toString(36).slice(2, 9)}`;
    setToasts((items) => [...items, { ...toast, id }]);
    window.setTimeout(() => removeToast(id), 4000);
  };

  useEffect(() => {
    let mounted = true;
    const unauthenticated = () => {
      if (!mounted) return;
      setCurrentUser(null); setCurrentView('login'); setAuthLoading(false);
    };
    setUnauthorizedHandler(unauthenticated);

    const restoreSession = async () => {
      if (isStandalonePublicAuthPath()) { setAuthLoading(false); return; }
      if (!getAuthToken()) { setAuthLoading(false); return; }
      try {
        const response = await authService.me();
        if (!mounted) return;
        const user = normalizeBackendUser(response.user);
        setCurrentUser(user);
        setCurrentView(user.role === 'admin' ? 'admin-dashboard' : 'user-home');
      } catch (error) {
        if (!mounted) return;
        if (error instanceof ApiError && error.status === 403 && error.message === 'Account disabled') {
          clearAuthToken(); setCurrentUser(null); setCurrentView('account-disabled');
        } else if (!(error instanceof ApiError) || error.status !== 401) {
          setCurrentUser(null); setCurrentView('login');
          addToast({ title: 'Session Restore Unavailable', message: getPublicApiErrorMessage(error, { fallback: 'Unable to restore the current session.' }), type: 'warning' });
        }
      } finally { if (mounted) setAuthLoading(false); }
    };
    void restoreSession();
    return () => { mounted = false; setUnauthorizedHandler(null); };
  }, []);

  const login = async (email: string, password: string) => {
    const normalizedEmail = email.trim().toLowerCase();
    setAuthEmailHint(normalizedEmail); setAuthNotice('');
    try {
      const response = await authService.login({ email: normalizedEmail, password });
      setAuthToken(response.token);
      const user = normalizeBackendUser(response.user);
      setCurrentUser(user); setCurrentView(user.role === 'admin' ? 'admin-dashboard' : 'user-home');
      addToast({ title: `Welcome back, ${user.name}`, message: response.message, type: 'success' });
    } catch (error) {
      if (error instanceof ApiError && error.status === 403 && error.message === 'Account disabled') {
        clearAuthToken(); setCurrentUser(null); setCurrentView('account-disabled');
      }
      throw error;
    }
  };

  const register = async (name: string, email: string, password: string) => {
    const normalizedEmail = email.trim().toLowerCase();
    const response = await authService.register({ name, email: normalizedEmail, password });
    setAuthEmailHint(normalizedEmail); setAuthNotice(response.message); setCurrentUser(null); setCurrentView('email-verification');
    addToast({ title: 'Account Created', message: response.message, type: 'info' });
    return response.message;
  };

  const startGoogleOAuth = async () => {
    const response = await authService.googleRedirect();
    let authorizationUrl: URL;
    try {
      authorizationUrl = new URL(response.url);
    } catch {
      throw new ApiError('Google sign-in could not be completed. Please try again.', 502);
    }

    if (authorizationUrl.protocol !== 'https:' || authorizationUrl.hostname !== 'accounts.google.com') {
      throw new ApiError('Google sign-in could not be completed. Please try again.', 502);
    }

    window.location.assign(authorizationUrl.toString());
  };

  const completeGoogleOAuth = async (code: string) => {
    const response = await authService.exchangeGoogleCode(code);
    setAuthToken(response.token);
    const user = normalizeBackendUser(response.user);
    setCurrentUser(user);
    setCurrentView(user.role === 'admin' ? 'admin-dashboard' : 'user-home');
    addToast({ title: `Welcome, ${user.name}`, message: response.message, type: 'success' });
  };

  const resendVerificationEmail = async (email: string) => {
    const response = await authService.resendVerificationEmail(email.trim().toLowerCase());
    setAuthNotice(response.message);
    return response.message;
  };

  const logout = async () => {
    let message = 'Your session has been securely ended.';
    let type: ToastItem['type'] = 'info';
    try { if (getAuthToken()) message = (await authService.logout()).message; }
    catch (error) { if (!(error instanceof ApiError) || error.status !== 401) { message = 'The local session was cleared, but the API logout request could not be completed.'; type = 'warning'; } }
    finally { clearAuthToken(); setCurrentUser(null); setCurrentView('login'); addToast({ title: 'Logged Out', message, type }); }
  };

  const selectAudit = (id: string, targetView: ViewType = 'audit-detail') => { setSelectedAuditId(id); setCurrentView(targetView); };
  const selectUser = (id: string, targetView: ViewType = 'user-activity') => { setSelectedUserId(id); setCurrentView(targetView); };

  return <AppContext.Provider value={{ currentUser, currentView, authEmailHint, authNotice, authLoading, isAuthenticated: Boolean(currentUser && getAuthToken()), selectedAuditId, selectedUserId, toasts, setCurrentView, login, startGoogleOAuth, completeGoogleOAuth, register, resendVerificationEmail, logout, selectAudit, selectUser, setSelectedUserId, addToast, removeToast }}>{children}</AppContext.Provider>;
};

export const useApp = () => {
  const context = useContext(AppContext);
  if (!context) throw new Error('useApp must be used within an AppProvider');
  return context;
};
