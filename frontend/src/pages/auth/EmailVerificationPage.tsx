import React, { useState } from 'react';
import { AlertCircle, CheckCircle2, Loader2, Mail, MailCheck, RefreshCw } from 'lucide-react';
import { AuthLayout } from '../../components/layout/AuthLayout';
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
    <AuthLayout modeLabel="Email verification notice">
      <section className="rounded-[2rem] border border-[var(--color-border)] bg-[var(--color-surface)] p-6 shadow-2xl sm:p-8">
        <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--color-cta)] text-[var(--color-on-cta)] shadow-lg"><MailCheck className="h-7 w-7" /></div>
        <h2 className="mt-5 text-3xl font-black tracking-[-0.03em] text-[var(--color-text)]">Check your inbox</h2>
        <p className="mt-3 text-sm leading-6 text-[var(--color-muted)]">Registration creates an active but unverified regular user. Verify your email through the secure link before logging in.</p>

        <div className="mt-5 rounded-2xl border border-[var(--color-border)] bg-[var(--color-secondary)] p-4">
          <label htmlFor="verification-email-input" className="text-[10px] font-black uppercase tracking-[0.14em] text-[var(--color-muted)]">Verification destination</label>
          <div className="relative mt-2"><Mail className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input id="verification-email-input" type="email" value={email} onChange={(event) => { setEmail(event.target.value); setErrorMessage(''); }} placeholder="name@company.com" disabled={isResending} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] py-3 pl-10 pr-4 text-sm font-semibold text-[var(--color-text)] outline-none focus:border-[var(--color-primary)] disabled:opacity-60" /></div>
        </div>

        {resendMessage ? <div className="mt-4 flex items-start gap-2.5 rounded-2xl border border-[var(--color-success-border)] bg-[var(--color-success-bg)] p-3.5 text-xs font-semibold leading-5 text-[var(--color-success-text)]" role="status"><CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />{resendMessage}</div> : null}
        {errorMessage ? <div className="mt-4 flex items-start gap-2.5 rounded-2xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-3.5 text-xs font-semibold leading-5 text-[var(--color-danger-text)]" role="alert"><AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />{errorMessage}</div> : null}

        <div className="mt-6 space-y-3">
          <button type="button" onClick={() => void handleResend()} disabled={isResending} className="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--color-cta)] px-5 text-sm font-black text-[var(--color-on-cta)] shadow-lg transition-all hover:-translate-y-0.5 hover:bg-[var(--color-cta-hover)] disabled:cursor-not-allowed disabled:opacity-60">
            {isResending ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
            {isResending ? 'Requesting verification email...' : 'Resend verification email'}
          </button>
          <button type="button" onClick={() => setCurrentView('login')} className="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-[var(--color-border)] bg-[var(--color-secondary)] px-5 text-xs font-black text-[var(--color-text)] transition-colors hover:border-[var(--color-primary)]">Return to Login</button>
        </div>

      </section>
    </AuthLayout>
  );
};
