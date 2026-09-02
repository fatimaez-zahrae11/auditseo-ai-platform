import type { ReactNode } from 'react';
import { useApp } from '../../context/AppContext';
import { AuthCardBrand, AuthLayout } from './AuthLayout';

interface AuthFormLayoutProps {
  activeTab: 'login' | 'register';
  children: ReactNode;
}

function GoogleMark() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" className="h-4 w-4 shrink-0">
      <path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.4-.2-2.06H12v3.9h5.37a4.6 4.6 0 0 1-1.99 3.01v2.54h3.23c1.89-1.74 2.99-4.31 2.99-7.39Z" />
      <path fill="#34A853" d="M12 22c2.7 0 4.96-.89 6.61-2.38l-3.23-2.54c-.9.6-2.04.96-3.38.96-2.61 0-4.82-1.76-5.61-4.13H3.05v2.62A9.99 9.99 0 0 0 12 22Z" />
      <path fill="#FBBC05" d="M6.39 13.91A6.02 6.02 0 0 1 6.07 12c0-.66.11-1.31.32-1.91V7.47H3.05A10 10 0 0 0 2 12c0 1.61.38 3.14 1.05 4.53l3.34-2.62Z" />
      <path fill="#EA4335" d="M12 5.96c1.47 0 2.78.5 3.82 1.49l2.86-2.87A9.6 9.6 0 0 0 12 2a9.99 9.99 0 0 0-8.95 5.47l3.34 2.62C7.18 7.72 9.39 5.96 12 5.96Z" />
    </svg>
  );
}

export function AuthFormLayout({ activeTab, children }: AuthFormLayoutProps) {
  const { setCurrentView } = useApp();
  const isLogin = activeTab === 'login';

  return (
    <AuthLayout>
      <section className="auth-glass-card relative overflow-hidden rounded-[28px] px-7 py-9 sm:px-10 sm:py-10">
        <AuthCardBrand />

        {children}

        <div className="my-6 flex items-center gap-3" aria-hidden="true">
          <span className="h-px flex-1 bg-white/10" />
          <span className="text-[10px] font-extrabold uppercase tracking-[0.2em] text-white/42">Or</span>
          <span className="h-px flex-1 bg-white/10" />
        </div>

        <button
          type="button"
          disabled
          aria-disabled="true"
          className="auth-google-button flex min-h-12 w-full cursor-not-allowed items-center justify-center gap-2.5 rounded-2xl px-4 text-xs font-bold text-white/60"
        >
          <GoogleMark />
          {isLogin ? 'Sign In with Google' : 'Sign Up with Google'}
        </button>

        <p className="mt-6 text-center text-xs font-medium text-white/55">
          {isLogin ? "Don't have an account?" : 'Already have an account?'}{' '}
          <button
            type="button"
            onClick={() => setCurrentView(isLogin ? 'register' : 'login')}
            className="font-extrabold text-orange-300 underline decoration-orange-300/30 underline-offset-4 transition-colors hover:text-orange-200"
          >
            {isLogin ? 'Sign Up' : 'Sign In'}
          </button>
        </p>
      </section>
    </AuthLayout>
  );
}
