import type { ReactNode } from 'react';
import { UserLogo } from '../brand/UserLogo';

interface AuthLayoutProps {
  children: ReactNode;
}

export function AuthCardBrand() {
  return (
    <div className="flex justify-center">
      <UserLogo size={52} className="drop-shadow-[0_10px_24px_rgba(0,0,0,0.35)]" />
    </div>
  );
}

export function AuthLayout({ children }: AuthLayoutProps) {
  return (
    <main className="auth-cinematic relative flex min-h-screen min-h-dvh w-full items-center justify-center overflow-x-hidden px-4 py-8 text-white sm:px-6 sm:py-12">
      <div className="relative z-10 mx-auto w-full max-w-[460px]">
        {children}
      </div>
    </main>
  );
}
