import React, { useState } from 'react';
import { AlertCircle, ArrowRight, Loader2, Lock, Mail } from 'lucide-react';
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
      <div className="mt-8">
        <div className="text-center">
          <h1 className="text-3xl font-black tracking-[-0.035em] text-[#020C0F] sm:text-[2rem]">Welcome back</h1>
          <p className="mt-2 text-sm font-semibold text-[#0C765F]">Access your SEO workspace.</p>
        </div>

        {loginError ? (
          <div className="mt-6 rounded-2xl border border-[#FDA4AF] bg-[#FFF1F2] p-3.5 text-xs text-[#9F1239]" role="alert">
            <div className="flex items-start gap-2.5">
              <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
              <div className="flex-1">
                <p className="font-black">
                  {loginError.kind === 'verification' ? 'Verification required' : loginError.kind === 'rate-limit' ? 'Too many requests' : loginError.kind === 'credentials' ? 'Sign-in failed' : 'Authentication service unavailable'}
                </p>
                <p className="mt-1 leading-5">{loginError.message}</p>
                {loginError.kind === 'verification' ? (
                  <button type="button" onClick={() => setCurrentView('email-verification')} className="mt-2 font-black underline underline-offset-2">
                    Resend verification email
                  </button>
                ) : null}
              </div>
            </div>
          </div>
        ) : null}

        <form onSubmit={(event) => void handleSubmit(event)} className="mt-7 space-y-5" noValidate>
          <div>
            <label htmlFor="login-email-input" className="mb-2 block text-xs font-extrabold text-[#263934]">Email address</label>
            <div className="relative">
              <Mail className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[#71807C]" />
              <input id="login-email-input" type="email" autoComplete="email" value={email} onChange={(event) => { setEmail(event.target.value); setLoginError(null); }} placeholder="name@company.com" disabled={isLoading} className="min-h-[52px] w-full rounded-2xl border border-transparent bg-[#F1F4F6] py-3 pl-11 pr-4 text-sm font-semibold text-[#020C0F] outline-none transition-all placeholder:text-[#8A9692] focus:border-[#06D6A0] focus:bg-white focus:ring-4 focus:ring-[#06D6A0]/10 disabled:opacity-60" />
            </div>
          </div>

          <div>
            <label htmlFor="login-password-input" className="mb-2 block text-xs font-extrabold text-[#263934]">Password</label>
            <div className="relative">
              <Lock className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[#71807C]" />
              <input id="login-password-input" type="password" autoComplete="current-password" value={password} onChange={(event) => { setPassword(event.target.value); setLoginError(null); }} placeholder="Enter your password" disabled={isLoading} className="min-h-[52px] w-full rounded-2xl border border-transparent bg-[#F1F4F6] py-3 pl-11 pr-4 text-sm font-semibold text-[#020C0F] outline-none transition-all placeholder:text-[#8A9692] focus:border-[#06D6A0] focus:bg-white focus:ring-4 focus:ring-[#06D6A0]/10 disabled:opacity-60" />
            </div>
          </div>

          <button id="login-submit-btn" type="submit" disabled={isLoading} className="inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl bg-[#0C4137] px-5 text-sm font-black text-white shadow-[0_12px_28px_rgba(12,65,55,0.22)] transition-all hover:-translate-y-0.5 hover:bg-[#0A5849] disabled:cursor-not-allowed disabled:translate-y-0 disabled:opacity-60">
            {isLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <ArrowRight className="h-4 w-4" />}
            {isLoading ? 'Signing in...' : 'Sign In'}
          </button>
        </form>
      </div>
    </AuthFormLayout>
  );
};
