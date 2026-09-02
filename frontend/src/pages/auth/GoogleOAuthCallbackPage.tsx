import { useEffect, useRef, useState } from 'react';
import { AlertCircle, Loader2 } from 'lucide-react';
import { AuthCardBrand, AuthLayout } from '../../components/layout/AuthLayout';
import { useApp } from '../../context/AppContext';
import { ApiError } from '../../services/apiClient';

const GENERIC_ERROR = 'Google sign-in could not be completed. Please try again.';
const DISABLED_ERROR = 'This account is disabled. Please contact an administrator.';

export function GoogleOAuthCallbackPage() {
  const { completeGoogleOAuth, setCurrentView, startGoogleOAuth } = useApp();
  const started = useRef(false);
  const [error, setError] = useState('');
  const [retrying, setRetrying] = useState(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    const code = new URLSearchParams(window.location.search).get('code');
    window.history.replaceState({}, document.title, '/auth/google/callback');

    if (!code) {
      setError(GENERIC_ERROR);
      return;
    }

    void completeGoogleOAuth(code).catch((requestError: unknown) => {
      setError(requestError instanceof ApiError && requestError.status === 403
        ? DISABLED_ERROR
        : GENERIC_ERROR);
    });
  }, [completeGoogleOAuth]);

  const retry = async () => {
    setError('');
    setRetrying(true);
    try {
      await startGoogleOAuth();
    } catch {
      setError(GENERIC_ERROR);
      setRetrying(false);
    }
  };

  const returnToLogin = () => {
    window.history.replaceState({}, document.title, '/');
    setCurrentView('login');
  };

  return (
    <AuthLayout>
      <section className="auth-glass-card relative overflow-hidden rounded-[28px] px-7 py-9 text-center sm:px-10 sm:py-10">
        <AuthCardBrand />

        {error ? (
          <div className="mt-7">
            <AlertCircle className="mx-auto h-9 w-9 text-rose-300" aria-hidden="true" />
            <h1 className="mt-4 text-2xl font-semibold text-white">Unable to sign in</h1>
            <p className="mt-3 text-sm leading-6 text-white/60" role="alert">{error}</p>
            <div className="mt-6 space-y-3">
              <button type="button" onClick={() => void retry()} disabled={retrying} className="auth-primary-button auth-orange-button inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl px-5 text-sm font-bold">
                {retrying ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : null}
                Try Google again
              </button>
              <button type="button" onClick={returnToLogin} disabled={retrying} className="auth-secondary-button min-h-12 w-full rounded-2xl px-5 text-xs font-bold text-white/70 disabled:opacity-60">
                Return to Sign In
              </button>
            </div>
          </div>
        ) : (
          <div className="mt-8" role="status" aria-live="polite">
            <Loader2 className="mx-auto h-9 w-9 animate-spin text-orange-300 motion-reduce:animate-none" aria-hidden="true" />
            <h1 className="mt-5 text-2xl font-semibold text-white">Completing Google sign-in</h1>
            <p className="mt-3 text-sm text-white/55">Securely connecting your AuditSEO account…</p>
          </div>
        )}
      </section>
    </AuthLayout>
  );
}
