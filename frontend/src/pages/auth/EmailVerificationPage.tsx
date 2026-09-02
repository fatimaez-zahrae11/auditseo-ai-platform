import React, { useState } from 'react';
import { AlertCircle, ArrowLeft, CheckCircle2, Loader2, Mail, MailCheck, RefreshCw } from 'lucide-react';
import { AuthCardBrand, AuthLayout } from '../../components/layout/AuthLayout';
import { useApp } from '../../context/AppContext';
import { getPublicApiErrorMessage } from '../../utils/publicApiErrors';

export const EmailVerificationPage: React.FC = () => {
  const { addToast, authEmailHint, authNotice, resendVerificationEmail, setCurrentView } = useApp();
  const [email, setEmail] = useState(authEmailHint);
  const [isResending, setIsResending] = useState(false);
  const [resendMessage, setResendMessage] = useState(authNotice);
  const [errorMessage, setErrorMessage] = useState('');

  const handleResend = async () => {
    if (!email.trim()) {
      setErrorMessage('Enter the email address used for registration.');
      return;
    }

    setIsResending(true);
    setErrorMessage('');
    setResendMessage('');
    try {
      const message = await resendVerificationEmail(email);
      setResendMessage(message);
      addToast({ title: 'Verification Email Requested', message, type: 'success' });
    } catch (error) {
      const message = getPublicApiErrorMessage(error, {
        fallback: 'Unable to request a verification email. Please try again later.',
        rateLimitMessage: 'Too many verification requests. Please wait before trying again.',
        validationFallback: 'Enter a valid email address.',
      });
      setErrorMessage(message);
    } finally {
      setIsResending(false);
    }
  };

  return (
    <AuthLayout>
      <section className="auth-glass-card relative overflow-hidden rounded-[28px] px-7 py-9 sm:px-10 sm:py-10">
        <AuthCardBrand />

        <div className="mx-auto mt-7 flex h-16 w-16 items-center justify-center rounded-full border border-orange-200/25 bg-orange-300/10 text-orange-200 shadow-[0_0_32px_rgba(251,146,60,0.18)]">
          <MailCheck className="h-7 w-7" />
        </div>

        <div className="mt-6 text-center">
          <h1 className="text-3xl font-extrabold tracking-[-0.045em] text-white sm:text-[2.2rem]">
            Check your <span className="auth-accent-text">inbox</span>
          </h1>
          <p className="mx-auto mt-3 max-w-sm text-xs font-medium leading-5 text-white/52 sm:text-sm sm:leading-6">
            Registration creates an active but unverified regular user. Verify your email through the secure link before logging in.
          </p>
        </div>

        <div className="mt-6">
          <label htmlFor="verification-email-input" className="mb-2 block text-xs font-bold text-white/78">Email</label>
          <div className="relative">
            <Mail className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
            <input
              id="verification-email-input"
              type="email"
              value={email}
              onChange={(event) => { setEmail(event.target.value); setErrorMessage(''); }}
              placeholder="Enter your email"
              disabled={isResending}
              className="auth-input min-h-[52px] w-full rounded-2xl py-3 pl-11 pr-4 text-sm font-semibold"
            />
          </div>
        </div>

        {resendMessage ? (
          <div className="mt-4 flex items-start gap-2.5 rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-3.5 text-xs font-semibold leading-5 text-emerald-100" role="status">
            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-300" />{resendMessage}
          </div>
        ) : null}
        {errorMessage ? (
          <div className="mt-4 flex items-start gap-2.5 rounded-2xl border border-rose-300/25 bg-rose-950/35 p-3.5 text-xs font-semibold leading-5 text-rose-100" role="alert">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-rose-300" />{errorMessage}
          </div>
        ) : null}

        <div className="mt-6 space-y-3">
          <button type="button" onClick={() => void handleResend()} disabled={isResending} className="auth-primary-button group inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl px-5 text-sm font-extrabold text-[#21130b]">
            {isResending ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : <RefreshCw className="h-4 w-4 transition-transform group-hover:rotate-12 motion-reduce:transform-none" />}
            {isResending ? 'Requesting verification email...' : 'Resend verification email'}
          </button>
          <button type="button" onClick={() => setCurrentView('login')} className="auth-secondary-button inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl px-5 text-xs font-extrabold text-white/65 transition-colors hover:text-white">
            <ArrowLeft className="h-4 w-4" /> Return to Login
          </button>
        </div>
      </section>
    </AuthLayout>
  );
};
