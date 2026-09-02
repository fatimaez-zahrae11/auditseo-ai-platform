import React, { useState } from 'react';
import { AlertCircle, Check, Eye, EyeOff, Loader2, Lock, Mail, User } from 'lucide-react';
import { AuthFormLayout } from '../../components/layout/AuthFormLayout';
import { useApp } from '../../context/AppContext';
import { ApiError } from '../../services/apiClient';
import { getPublicApiErrorMessage, getSafeValidationFieldMessage } from '../../utils/publicApiErrors';

interface RegisterErrors {
  name?: string;
  email?: string;
  password?: string;
  confirmation?: string;
  form?: string;
}

export const RegisterPage: React.FC = () => {
  const { register } = useApp();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);
  const [errors, setErrors] = useState<RegisterErrors>({});
  const [isLoading, setIsLoading] = useState(false);

  const validate = () => {
    const nextErrors: RegisterErrors = {};
    if (name.trim().length < 2) nextErrors.name = 'Enter your full name.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) nextErrors.email = 'Enter a valid email address.';
    if (!/^(?=.*[A-Z])(?=.*\d).{8,}$/.test(password)) nextErrors.password = 'Password does not meet the minimum requirements.';
    if (passwordConfirmation !== password) nextErrors.confirmation = 'Passwords do not match.';
    setErrors(nextErrors);
    return Object.keys(nextErrors).length === 0;
  };

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!validate()) return;

    setIsLoading(true);
    try {
      await register(name.trim(), email.trim(), password);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors({
          name: getSafeValidationFieldMessage(error, 'name'),
          email: getSafeValidationFieldMessage(error, 'email'),
          password: getSafeValidationFieldMessage(error, 'password'),
          form: error.status === 422 && error.errors
            ? undefined
            : getPublicApiErrorMessage(error, {
              fallback: 'Unable to create the account. Please try again later.',
              rateLimitMessage: 'Too many registration attempts. Please wait before trying again.',
            }),
        });
      } else {
        setErrors({ form: 'An unexpected registration error occurred.' });
      }
    } finally {
      setIsLoading(false);
    }
  };

  const fieldClass = (hasError: boolean) => `auth-input min-h-[52px] w-full rounded-2xl py-3 pl-11 pr-4 text-sm font-semibold ${hasError ? 'auth-input-error' : ''}`;
  const passwordFieldClass = (hasError: boolean) => `auth-input min-h-[52px] w-full rounded-2xl py-3 pl-11 pr-12 text-sm font-semibold ${hasError ? 'auth-input-error' : ''}`;

  return (
    <AuthFormLayout activeTab="register">
      <div className="mt-5 text-center">
        <h1 className="text-3xl font-semibold tracking-[-0.04em] text-white sm:text-[2.35rem]">
          Create your <span className="auth-accent-text">account</span>
        </h1>
        <p className="mx-auto mt-3 max-w-sm text-xs font-medium leading-5 text-white/52 sm:text-sm sm:leading-6">
          Start tracking technical SEO issues, scores,<br className="hidden sm:block" /> and AI-powered recommendations.
        </p>
      </div>

      {errors.form ? (
        <div className="mt-5 flex items-start gap-2 rounded-2xl border border-rose-300/25 bg-rose-950/35 p-3.5 text-xs font-semibold text-rose-100" role="alert">
          <AlertCircle className="h-4 w-4 shrink-0 text-rose-300" />{errors.form}
        </div>
      ) : null}

      <form onSubmit={(event) => void handleSubmit(event)} className="mt-6 space-y-3.5" noValidate>
        <div>
          <label htmlFor="register-name-input" className="mb-1.5 block text-xs font-bold text-white/78">Full name</label>
          <div className="relative">
            <User className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
            <input id="register-name-input" autoComplete="name" value={name} onChange={(event) => { setName(event.target.value); setErrors((current) => ({ ...current, name: undefined, form: undefined })); }} placeholder="Enter your full name" disabled={isLoading} aria-invalid={Boolean(errors.name)} className={fieldClass(Boolean(errors.name))} />
          </div>
          {errors.name ? <p className="mt-1.5 text-[11px] font-bold text-rose-200">{errors.name}</p> : null}
        </div>

        <div>
          <label htmlFor="register-email-input" className="mb-1.5 block text-xs font-bold text-white/78">Email</label>
          <div className="relative">
            <Mail className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
            <input id="register-email-input" type="email" autoComplete="email" value={email} onChange={(event) => { setEmail(event.target.value); setErrors((current) => ({ ...current, email: undefined, form: undefined })); }} placeholder="Enter your email" disabled={isLoading} aria-invalid={Boolean(errors.email)} className={fieldClass(Boolean(errors.email))} />
          </div>
          {errors.email ? <p className="mt-1.5 text-[11px] font-bold text-rose-200">{errors.email}</p> : null}
        </div>

        <div>
          <label htmlFor="register-password-input" className="mb-1.5 block text-xs font-bold text-white/78">Password</label>
          <div className="relative">
            <Lock className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
            <input id="register-password-input" type={showPassword ? 'text' : 'password'} autoComplete="new-password" value={password} onChange={(event) => { setPassword(event.target.value); setErrors((current) => ({ ...current, password: undefined, form: undefined })); }} placeholder="Create a password" disabled={isLoading} aria-invalid={Boolean(errors.password)} className={passwordFieldClass(Boolean(errors.password))} />
            <button type="button" onClick={() => setShowPassword((visible) => !visible)} disabled={isLoading} aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} className="auth-visibility-toggle absolute right-3 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg disabled:opacity-50">
              {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
          <p className={`mt-1.5 text-[10px] leading-4 ${errors.password ? 'font-bold text-rose-200' : 'text-white/40'}`}>{errors.password || 'Minimum 8 characters, at least one uppercase letter and one digit.'}</p>
        </div>

        <div>
          <label htmlFor="register-password-confirmation-input" className="mb-1.5 block text-xs font-bold text-white/78">Confirm password</label>
          <div className="relative">
            <Check className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-orange-200/55" />
            <input id="register-password-confirmation-input" type={showPasswordConfirmation ? 'text' : 'password'} autoComplete="new-password" value={passwordConfirmation} onChange={(event) => { setPasswordConfirmation(event.target.value); setErrors((current) => ({ ...current, confirmation: undefined, form: undefined })); }} placeholder="Repeat your password" disabled={isLoading} aria-invalid={Boolean(errors.confirmation)} className={passwordFieldClass(Boolean(errors.confirmation))} />
            <button type="button" onClick={() => setShowPasswordConfirmation((visible) => !visible)} disabled={isLoading} aria-label={showPasswordConfirmation ? 'Hide password confirmation' : 'Show password confirmation'} aria-pressed={showPasswordConfirmation} className="auth-visibility-toggle absolute right-3 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg disabled:opacity-50">
              {showPasswordConfirmation ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
          {errors.confirmation ? <p className="mt-1.5 text-[11px] font-bold text-rose-200">{errors.confirmation}</p> : null}
        </div>

        <button id="register-submit-btn" type="submit" disabled={isLoading} className="auth-primary-button auth-orange-button inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl px-5 text-sm font-bold">
          {isLoading ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : null}
          {isLoading ? 'Signing up...' : 'Sign Up'}
        </button>
      </form>
    </AuthFormLayout>
  );
};
