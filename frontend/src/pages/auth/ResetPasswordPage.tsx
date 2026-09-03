import React, { useState } from 'react';
import { AlertCircle, ArrowLeft, Check, CheckCircle2, Eye, EyeOff, Loader2, Lock, Mail } from 'lucide-react';
import { AuthCardBrand, AuthLayout } from '../../components/layout/AuthLayout';
import { ApiError } from '../../services/apiClient';
import { authService } from '../../services/authService';
import { getPublicApiErrorMessage, getSafeValidationFieldMessage } from '../../utils/publicApiErrors';

const INVALID_RESET_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

interface ResetErrors {
  password?: string;
  confirmation?: string;
  form?: string;
}

export const ResetPasswordPage: React.FC = () => {
  const query = new URLSearchParams(window.location.search);
  const token = query.get('token') || '';
  const email = query.get('email') || '';
  const hasRequiredLinkParameters = Boolean(token && email);
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);
  const [errors, setErrors] = useState<ResetErrors>({});
  const [successMessage, setSuccessMessage] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const nextErrors: ResetErrors = {};
    if (!/^(?=.*[A-Z])(?=.*\d).{8,}$/.test(password)) nextErrors.password = 'Password does not meet the minimum requirements.';
    if (passwordConfirmation !== password) nextErrors.confirmation = 'Passwords do not match.';
    if (Object.keys(nextErrors).length > 0) {
      setErrors(nextErrors);
      return;
    }

    setErrors({});
    setIsLoading(true);
    try {
      const response = await authService.resetPassword({
        email,
        token,
        password,
        password_confirmation: passwordConfirmation,
      });
      setPassword('');
      setPasswordConfirmation('');
      setSuccessMessage(response.message);
    } catch (error) {
      if (error instanceof ApiError && error.status === 422 && error.errors) {
        setErrors({
          password: getSafeValidationFieldMessage(error, 'password'),
          confirmation: getSafeValidationFieldMessage(error, 'password_confirmation'),
          form: getSafeValidationFieldMessage(error, 'email') || getSafeValidationFieldMessage(error, 'token'),
        });
      } else if (error instanceof ApiError && error.status === 422) {
        setErrors({ form: INVALID_RESET_LINK_MESSAGE });
      } else {
        setErrors({
          form: getPublicApiErrorMessage(error, {
            fallback: 'Unable to reset the password. Please try again later.',
            rateLimitMessage: 'Too many password reset attempts. Please wait before trying again.',
          }),
        });
      }
    } finally {
      setIsLoading(false);
    }
  };

  if (!hasRequiredLinkParameters) {
    return (
      <AuthLayout>
        <section className="auth-glass-card relative overflow-hidden rounded-[28px] px-7 py-9 text-center sm:px-10 sm:py-10">
          <AuthCardBrand />
          <div className="mx-auto mt-7 flex h-16 w-16 items-center justify-center rounded-full border border-rose-300/25 bg-rose-950/35 text-rose-200">
            <AlertCircle className="h-7 w-7" />
          </div>
          <h1 className="mt-6 text-3xl font-semibold tracking-[-0.04em] text-white">Invalid reset link</h1>
          <p className="mt-3 text-sm font-medium leading-6 text-white/55">{INVALID_RESET_LINK_MESSAGE}</p>
          <a href="/forgot-password" className="auth-primary-button auth-orange-button mt-7 inline-flex min-h-[52px] w-full items-center justify-center rounded-2xl px-5 text-sm font-bold">
            Request a new reset link
          </a>
        </section>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout>
      <section className="auth-glass-card relative overflow-hidden rounded-[28px] px-7 py-9 sm:px-10 sm:py-10">
        <AuthCardBrand />

        <div className="mt-6 text-center">
          <h1 className="text-3xl font-semibold tracking-[-0.04em] text-white sm:text-[2.35rem]">
            Choose a new <span className="auth-accent-text">password</span>
          </h1>
          <p className="mx-auto mt-3 max-w-sm text-xs font-medium leading-5 text-white/52 sm:text-sm sm:leading-6">
            Create a strong password for your AuditSEO account.
          </p>
        </div>

        {successMessage ? (
          <div className="mt-6">
            <div className="flex items-start gap-2.5 rounded-2xl border border-emerald-300/20 bg-emerald-950/35 p-3.5 text-xs font-semibold leading-5 text-emerald-100" role="status">
              <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-300" />{successMessage}
            </div>
            <a href="/login" className="auth-primary-button auth-orange-button mt-4 inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl px-5 text-sm font-bold">
              Sign In
            </a>
          </div>
        ) : (
          <form onSubmit={(event) => void handleSubmit(event)} className="mt-6 space-y-4" noValidate>
            <div>
              <label htmlFor="reset-password-email-input" className="mb-2 block text-xs font-bold text-white/78">Account email</label>
              <div className="relative">
                <Mail className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
                <input id="reset-password-email-input" type="email" value={email} readOnly tabIndex={-1} className="auth-input min-h-[52px] w-full rounded-2xl py-3 pl-11 pr-4 text-sm font-semibold opacity-75" />
              </div>
            </div>

            <div>
              <label htmlFor="reset-password-input" className="mb-2 block text-xs font-bold text-white/78">New password</label>
              <div className="relative">
                <Lock className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
                <input id="reset-password-input" type={showPassword ? 'text' : 'password'} autoComplete="new-password" value={password} onChange={(event) => { setPassword(event.target.value); setErrors((current) => ({ ...current, password: undefined, form: undefined })); }} placeholder="Create a new password" disabled={isLoading} aria-invalid={Boolean(errors.password)} className={`auth-input min-h-[52px] w-full rounded-2xl py-3 pl-11 pr-12 text-sm font-semibold ${errors.password ? 'auth-input-error' : ''}`} />
                <button type="button" onClick={() => setShowPassword((visible) => !visible)} disabled={isLoading} aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} className="auth-visibility-toggle absolute right-3 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg disabled:opacity-50">
                  {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
              <p className={`mt-1.5 text-[10px] leading-4 ${errors.password ? 'font-bold text-rose-200' : 'text-white/40'}`}>{errors.password || 'Minimum 8 characters, at least one uppercase letter and one digit.'}</p>
            </div>

            <div>
              <label htmlFor="reset-password-confirmation-input" className="mb-2 block text-xs font-bold text-white/78">Confirm new password</label>
              <div className="relative">
                <Check className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
                <input id="reset-password-confirmation-input" type={showPasswordConfirmation ? 'text' : 'password'} autoComplete="new-password" value={passwordConfirmation} onChange={(event) => { setPasswordConfirmation(event.target.value); setErrors((current) => ({ ...current, confirmation: undefined, form: undefined })); }} placeholder="Repeat your new password" disabled={isLoading} aria-invalid={Boolean(errors.confirmation)} className={`auth-input min-h-[52px] w-full rounded-2xl py-3 pl-11 pr-12 text-sm font-semibold ${errors.confirmation ? 'auth-input-error' : ''}`} />
                <button type="button" onClick={() => setShowPasswordConfirmation((visible) => !visible)} disabled={isLoading} aria-label={showPasswordConfirmation ? 'Hide password confirmation' : 'Show password confirmation'} aria-pressed={showPasswordConfirmation} className="auth-visibility-toggle absolute right-3 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg disabled:opacity-50">
                  {showPasswordConfirmation ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
              {errors.confirmation ? <p className="mt-1.5 text-[11px] font-bold text-rose-200">{errors.confirmation}</p> : null}
            </div>

            {errors.form ? (
              <div className="flex items-start gap-2.5 rounded-2xl border border-rose-300/25 bg-rose-950/35 p-3.5 text-xs font-semibold leading-5 text-rose-100" role="alert">
                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-rose-300" />{errors.form}
              </div>
            ) : null}

            <button type="submit" disabled={isLoading} className="auth-primary-button auth-orange-button inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl px-5 text-sm font-bold">
              {isLoading ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : <Lock className="h-4 w-4" />}
              {isLoading ? 'Resetting password...' : 'Reset password'}
            </button>
          </form>
        )}

        {!successMessage ? (
          <a href="/login" className="auth-secondary-button mt-3 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl px-5 text-xs font-extrabold text-white/65 transition-colors hover:text-white">
            <ArrowLeft className="h-4 w-4" /> Return to Sign In
          </a>
        ) : null}
      </section>
    </AuthLayout>
  );
};
