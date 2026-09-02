import React, { useState } from 'react';
import { AlertCircle, Eye, EyeOff, Loader2, Lock, Mail } from 'lucide-react';
import { AuthFormLayout } from '../../components/layout/AuthFormLayout';
import { useApp } from '../../context/AppContext';
import { ApiError } from '../../services/apiClient';
import { getPublicApiErrorMessage } from '../../utils/publicApiErrors';

type LoginErrorKind = 'credentials' | 'verification' | 'rate-limit' | 'server';

interface LoginErrorState {
  kind: LoginErrorKind;
  message: string;
}

export const LoginPage: React.FC = () => {
  const { login, setCurrentView, authEmailHint } = useApp();
  const [email, setEmail] = useState(authEmailHint);
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [loginError, setLoginError] = useState<LoginErrorState | null>(null);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!email.trim() || !password) {
      setLoginError({ kind: 'credentials', message: 'Enter both your email address and password.' });
      return;
    }

    setLoginError(null);
    setIsLoading(true);
    try {
      await login(email, password);
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.status === 401 || error.status === 422) {
          setLoginError({ kind: 'credentials', message: 'Email or password is incorrect.' });
        } else if (error.status === 403 && error.message.toLowerCase().includes('verification')) {
          setLoginError({ kind: 'verification', message: 'Verify your email address before signing in.' });
        } else if (error.status === 429) {
          setLoginError({ kind: 'rate-limit', message: 'Too many login attempts. Please wait before trying again.' });
        } else if (!(error.status === 403 && error.message === 'Account disabled')) {
          setLoginError({ kind: 'server', message: getPublicApiErrorMessage(error, { fallback: 'Unable to sign in. Please try again later.' }) });
        }
      } else {
        setLoginError({ kind: 'server', message: 'An unexpected authentication error occurred.' });
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthFormLayout activeTab="login">
      <div className="mt-5 text-center">
        <h1 className="text-3xl font-semibold tracking-[-0.04em] text-white sm:text-[2.35rem]">
          Welcome <span className="auth-accent-text">back!</span>
        </h1>
        <p className="mx-auto mt-3 max-w-sm text-xs font-medium leading-5 text-white/52 sm:text-sm sm:leading-6">
          Sign in to access your SEO audits, insights,<br className="hidden sm:block" /> and optimization workspace.
        </p>
      </div>

      {loginError ? (
        <div className="mt-5 rounded-2xl border border-rose-300/25 bg-rose-950/35 p-3.5 text-xs text-rose-100" role="alert">
          <div className="flex items-start gap-2.5">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-rose-300" />
            <div className="flex-1">
              <p className="font-extrabold">
                {loginError.kind === 'verification' ? 'Verification required' : loginError.kind === 'rate-limit' ? 'Too many requests' : loginError.kind === 'credentials' ? 'Sign-in failed' : 'Authentication service unavailable'}
              </p>
              <p className="mt-1 leading-5 text-rose-100/75">{loginError.message}</p>
              {loginError.kind === 'verification' ? (
                <button type="button" onClick={() => setCurrentView('email-verification')} className="mt-2 font-extrabold text-orange-200 underline underline-offset-2">
                  Resend verification email
                </button>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}

      <form onSubmit={(event) => void handleSubmit(event)} className="mt-6 space-y-4" noValidate>
        <div>
          <label htmlFor="login-email-input" className="mb-2 block text-xs font-bold text-white/78">Email</label>
          <div className="relative">
            <Mail className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
            <input
              id="login-email-input"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(event) => { setEmail(event.target.value); setLoginError(null); }}
              placeholder="Enter your email"
              disabled={isLoading}
              className="auth-input min-h-[52px] w-full rounded-2xl py-3 pl-11 pr-4 text-sm font-semibold"
            />
          </div>
        </div>

        <div>
          <label htmlFor="login-password-input" className="mb-2 block text-xs font-bold text-white/78">Password</label>
          <div className="relative">
            <Lock className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
            <input
              id="login-password-input"
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              value={password}
              onChange={(event) => { setPassword(event.target.value); setLoginError(null); }}
              placeholder="Enter your password"
              disabled={isLoading}
              className="auth-input min-h-[52px] w-full rounded-2xl py-3 pl-11 pr-12 text-sm font-semibold"
            />
            <button
              type="button"
              onClick={() => setShowPassword((visible) => !visible)}
              disabled={isLoading}
              aria-label={showPassword ? 'Hide password' : 'Show password'}
              aria-pressed={showPassword}
              className="auth-visibility-toggle absolute right-3 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg disabled:opacity-50"
            >
              {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
        </div>

        <div className="flex items-center justify-between gap-4 py-1 text-[11px]">
          <label className="flex cursor-not-allowed items-center gap-2 text-white/48" title="Authentication remains limited to this browser session">
            <input type="checkbox" disabled className="auth-checkbox h-4 w-4 rounded" />
            Remember me
          </label>
          <button type="button" disabled title="Password reset is not available yet" className="cursor-not-allowed font-bold text-orange-200/48">
            Forgot password?
          </button>
        </div>

        <button id="login-submit-btn" type="submit" disabled={isLoading} className="auth-primary-button auth-orange-button inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl px-5 text-sm font-bold">
          {isLoading ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : null}
          {isLoading ? 'Signing in...' : 'Sign In'}
        </button>
      </form>
    </AuthFormLayout>
  );
};
