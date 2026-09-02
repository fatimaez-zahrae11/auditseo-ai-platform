import React, { useState } from 'react';
import { AlertCircle, ArrowRight, Check, Loader2, Lock, Mail, User } from 'lucide-react';
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

  const fieldClass = (hasError: boolean) => `min-h-[52px] w-full rounded-2xl border bg-[#F1F4F6] py-3 pl-11 pr-4 text-sm font-semibold text-[#020C0F] outline-none transition-all placeholder:text-[#8A9692] focus:bg-white focus:ring-4 focus:ring-[#06D6A0]/10 disabled:opacity-60 ${hasError ? 'border-[#FB7185]' : 'border-transparent focus:border-[#06D6A0]'}`;

  return (
    <AuthFormLayout activeTab="register">
      <div className="mt-8">
        <div className="text-center">
          <h1 className="text-3xl font-black tracking-[-0.035em] text-[#020C0F] sm:text-[2rem]">Create your account</h1>
          <p className="mt-2 text-sm font-semibold text-[#0C765F]">Start analyzing your website today.</p>
        </div>

        {errors.form ? <div className="mt-6 flex items-start gap-2 rounded-2xl border border-[#FDA4AF] bg-[#FFF1F2] p-3.5 text-xs font-semibold text-[#9F1239]" role="alert"><AlertCircle className="h-4 w-4 shrink-0" />{errors.form}</div> : null}

        <form onSubmit={(event) => void handleSubmit(event)} className="mt-7 space-y-4" noValidate>
          <div>
            <label htmlFor="register-name-input" className="mb-2 block text-xs font-extrabold text-[#263934]">Full name</label>
            <div className="relative"><User className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[#71807C]" /><input id="register-name-input" autoComplete="name" value={name} onChange={(event) => { setName(event.target.value); setErrors((current) => ({ ...current, name: undefined, form: undefined })); }} placeholder="Alex Morgan" disabled={isLoading} className={fieldClass(Boolean(errors.name))} /></div>
            {errors.name ? <p className="mt-1.5 text-[11px] font-bold text-[#BE123C]">{errors.name}</p> : null}
          </div>
          <div>
            <label htmlFor="register-email-input" className="mb-2 block text-xs font-extrabold text-[#263934]">Email address</label>
            <div className="relative"><Mail className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[#71807C]" /><input id="register-email-input" type="email" autoComplete="email" value={email} onChange={(event) => { setEmail(event.target.value); setErrors((current) => ({ ...current, email: undefined, form: undefined })); }} placeholder="name@company.com" disabled={isLoading} className={fieldClass(Boolean(errors.email))} /></div>
            {errors.email ? <p className="mt-1.5 text-[11px] font-bold text-[#BE123C]">{errors.email}</p> : null}
          </div>
          <div>
            <label htmlFor="register-password-input" className="mb-2 block text-xs font-extrabold text-[#263934]">Password</label>
            <div className="relative"><Lock className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[#71807C]" /><input id="register-password-input" type="password" autoComplete="new-password" value={password} onChange={(event) => { setPassword(event.target.value); setErrors((current) => ({ ...current, password: undefined, form: undefined })); }} placeholder="Create a password" disabled={isLoading} className={fieldClass(Boolean(errors.password))} /></div>
            <p className={`mt-1.5 text-[11px] ${errors.password ? 'font-bold text-[#BE123C]' : 'text-[#71807C]'}`}>{errors.password || 'Minimum 8 characters, at least one uppercase letter and one digit.'}</p>
          </div>
          <div>
            <label htmlFor="register-password-confirmation-input" className="mb-2 block text-xs font-extrabold text-[#263934]">Confirm password</label>
            <div className="relative"><Check className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[#71807C]" /><input id="register-password-confirmation-input" type="password" autoComplete="new-password" value={passwordConfirmation} onChange={(event) => { setPasswordConfirmation(event.target.value); setErrors((current) => ({ ...current, confirmation: undefined, form: undefined })); }} placeholder="Repeat your password" disabled={isLoading} className={fieldClass(Boolean(errors.confirmation))} /></div>
            {errors.confirmation ? <p className="mt-1.5 text-[11px] font-bold text-[#BE123C]">{errors.confirmation}</p> : null}
          </div>

          <button id="register-submit-btn" type="submit" disabled={isLoading} className="inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl bg-[#0C4137] px-5 text-sm font-black text-white shadow-[0_12px_28px_rgba(12,65,55,0.22)] transition-all hover:-translate-y-0.5 hover:bg-[#0A5849] disabled:cursor-not-allowed disabled:translate-y-0 disabled:opacity-60">
            {isLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <ArrowRight className="h-4 w-4" />}
            {isLoading ? 'Creating account...' : 'Create Account'}
          </button>
        </form>
      </div>
    </AuthFormLayout>
  );
};
