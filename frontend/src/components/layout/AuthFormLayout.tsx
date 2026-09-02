import type { ReactNode } from 'react';
import { ShieldCheck } from 'lucide-react';
import { useApp } from '../../context/AppContext';
import { AppLogo } from '../ui/AppLogo';

interface AuthFormLayoutProps {
  activeTab: 'login' | 'register';
  children: ReactNode;
}

export function AuthFormLayout({ activeTab, children }: AuthFormLayoutProps) {
  const { setCurrentView } = useApp();

  const tabClass = (tab: 'login' | 'register') => `flex min-h-11 flex-1 items-center justify-center rounded-xl px-4 text-sm font-extrabold transition-all ${
    activeTab === tab
      ? 'bg-white text-[#0C4137] shadow-[0_4px_14px_rgba(2,12,15,0.10)]'
      : 'text-[#71807C] hover:text-[#0C4137]'
  }`;

  return (
    <main className="relative flex min-h-screen w-full items-center justify-center overflow-hidden bg-[#E6FBF6] px-4 py-6 text-[#020C0F] sm:px-6 sm:py-10">
      <div className="pointer-events-none absolute -left-24 -top-24 h-72 w-72 rounded-full bg-[#06D6A0]/15 blur-3xl" />
      <div className="pointer-events-none absolute -bottom-32 -right-20 h-96 w-96 rounded-full bg-[#0C4137]/10 blur-3xl" />

      <section className="relative w-full max-w-[600px] overflow-hidden rounded-[2rem] border border-[#0C4137]/10 border-t-4 border-t-[#06D6A0] bg-white px-5 py-7 shadow-[0_28px_80px_rgba(2,12,15,0.14)] sm:px-10 sm:py-9">
        <header className="text-center">
          <AppLogo size={56} className="mx-auto drop-shadow-md" />
          <p className="mt-3 text-lg font-black tracking-[-0.025em] text-[#020C0F]">AuditSEO AI Platform</p>
          <p className="mt-1 text-xs font-semibold tracking-wide text-[#64746F]">SEO Audit Platform</p>
        </header>

        <div className="mt-7 flex rounded-2xl bg-[#F1F4F6] p-1.5" role="tablist" aria-label="Authentication">
          <button
            type="button"
            role="tab"
            aria-selected={activeTab === 'login'}
            onClick={() => setCurrentView('login')}
            className={tabClass('login')}
          >
            Sign In
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={activeTab === 'register'}
            onClick={() => setCurrentView('register')}
            className={tabClass('register')}
          >
            Sign Up
          </button>
        </div>

        {children}

        <footer className="mt-7 flex items-center justify-center gap-1.5 border-t border-[#E4EAE8] pt-5 text-center text-[11px] font-semibold text-[#71807C]">
          <ShieldCheck className="h-3.5 w-3.5 text-[#0C4137]" />
          Secure access to AuditSEO AI Platform
        </footer>
      </section>
    </main>
  );
}
