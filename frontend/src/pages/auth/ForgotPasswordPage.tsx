import React, { useState } from 'react';
import { AlertCircle, ArrowLeft, CheckCircle2, Loader2, Mail, Send } from 'lucide-react';
import { AuthCardBrand, AuthLayout } from '../../components/layout/AuthLayout';
import { ApiError } from '../../services/apiClient';
import { authService } from '../../services/authService';
import { getPublicApiErrorMessage, getSafeValidationFieldMessage } from '../../utils/publicApiErrors';

const FORGOT_PASSWORD_MESSAGE = 'If an account exists for this email, a password reset link has been sent.';

export const ForgotPasswordPage: React.FC = () => {
  const [email, setEmail] = useState('');
  const [emailError, setEmailError] = useState('');
  const [formError, setFormError] = useState('');
  const [successMessage, setSuccessMessage] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const normalizedEmail = email.trim().toLowerCase();

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail)) {
      setEmailError('Enter a valid email address.');
      return;
    }

    setEmailError('');
    setFormError('');
    setSuccessMessage('');
    setIsLoading(true);

    try {
      await authService.requestPasswordReset(normalizedEmail);
      setSuccessMessage(FORGOT_PASSWORD_MESSAGE);
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        setEmailError(getSafeValidationFieldMessage(error, 'email', 'Enter a valid email address.') || 'Enter a valid email address.');
      } else {
        setFormError(getPublicApiErrorMessage(error, {
          fallback: 'Unable to request a password reset. Please try again later.',
          rateLimitMessage: 'Too many password reset requests. Please wait before trying again.',
        }));
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthLayout>
      <section className="auth-glass-card relative overflow-hidden rounded-[28px] px-7 py-9 sm:px-10 sm:py-10">
        <AuthCardBrand />

        <div className="mt-6 text-center">
          <h1 className="text-3xl font-semibold tracking-[-0.04em] text-white sm:text-[2.35rem]">
            Reset your <span className="auth-accent-text">password</span>
          </h1>
          <p className="mx-auto mt-3 max-w-sm text-xs font-medium leading-5 text-white/52 sm:text-sm sm:leading-6">
            Enter your account email and we will send password reset instructions if the account exists.
          </p>
        </div>

        {successMessage ? (
          <div className="mt-6 flex items-start gap-2.5 rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-3.5 text-xs font-semibold leading-5 text-emerald-100" role="status">
            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-300" />{successMessage}
          </div>
        ) : null}
        {formError ? (
          <div className="mt-6 flex items-start gap-2.5 rounded-2xl border border-rose-300/25 bg-rose-950/35 p-3.5 text-xs font-semibold leading-5 text-rose-100" role="alert">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-rose-300" />{formError}
          </div>
        ) : null}

        <form onSubmit={(event) => void handleSubmit(event)} className="mt-6 space-y-4" noValidate>
          <div>
            <label htmlFor="forgot-password-email-input" className="mb-2 block text-xs font-bold text-white/78">Email</label>
            <div className="relative">
              <Mail className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
              <input
                id="forgot-password-email-input"
                type="email"
                autoComplete="email"
                value={email}
                onChange={(event) => { setEmail(event.target.value); setEmailError(''); setFormError(''); setSuccessMessage(''); }}
                placeholder="Enter your email"
                disabled={isLoading}
                aria-invalid={Boolean(emailError)}
                className={`auth-input min-h-[52px] w-full rounded-2xl py-3 pl-11 pr-4 text-sm font-semibold ${emailError ? 'auth-input-error' : ''}`}
              />
            </div>
            {emailError ? <p className="mt-1.5 text-[11px] font-bold text-rose-200">{emailError}</p> : null}
          </div>

          <button type="submit" disabled={isLoading} className="auth-primary-button auth-orange-button inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl px-5 text-sm font-bold">
            {isLoading ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : <Send className="h-4 w-4" />}
            {isLoading ? 'Requesting reset link...' : 'Send reset link'}
          </button>
        </form>

        <a href="/login" className="auth-secondary-button mt-3 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl px-5 text-xs font-extrabold text-white/65 transition-colors hover:text-white">
          <ArrowLeft className="h-4 w-4" /> Return to Sign In
        </a>
      </section>
    </AuthLayout>
  );
};
