import type { ReactNode } from 'react';
import {
  Bot,
  KeyRound,
  LockKeyhole,
  Radar,
  ShieldCheck,
} from 'lucide-react';
import { AppLogo } from '../ui/AppLogo';

interface AuthLayoutProps {
  children: ReactNode;
  modeLabel: string;
}

const trustIndicators = [
  { label: 'Bearer token authentication', icon: KeyRound },
  { label: 'Verified email required', icon: ShieldCheck },
  { label: 'Protected admin routes', icon: LockKeyhole },
  { label: 'SSRF-safe audit requests', icon: Radar },
];

export function AuthLayout({ children, modeLabel }: AuthLayoutProps) {
  return (
    <div className="grid min-h-screen bg-[var(--color-canvas)] lg:grid-cols-[minmax(360px,0.9fr)_minmax(520px,1.1fr)]">
      <section className="relative hidden overflow-hidden bg-[var(--color-text)] px-10 py-12 text-white lg:flex lg:flex-col lg:justify-between xl:px-16 xl:py-16">
        <div className="pointer-events-none absolute -left-32 top-1/4 h-80 w-80 rounded-full bg-[var(--color-primary)]/45 blur-3xl" />
        <div className="pointer-events-none absolute -right-32 -top-24 h-96 w-96 rounded-full bg-[var(--color-highlight)]/20 blur-3xl" />
        <div className="pointer-events-none absolute bottom-0 right-0 h-72 w-72 rounded-full border border-[var(--color-primary)]/30" />

        <div className="relative">
          <div className="flex items-center gap-3">
            <AppLogo size={48} className="drop-shadow-xl" />
            <div>
              <p className="text-lg font-black tracking-tight">AuditSEO AI Platform</p>
              <p className="text-xs font-semibold text-white/65">SEO Workspace</p>
            </div>
          </div>
        </div>

        <div className="relative my-16 max-w-xl">
          <div className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-[11px] font-black uppercase tracking-[0.16em] text-[var(--color-highlight)]">
            <Bot className="h-3.5 w-3.5" /> Secure AI-powered SEO audit platform
          </div>
          <h1 className="mt-6 text-4xl font-black leading-tight tracking-[-0.04em] xl:text-5xl">
            Turn every crawl into a confident SEO action plan.
          </h1>
          <p className="mt-5 max-w-lg text-sm leading-7 text-white/70">
            Review technical health, content quality, links, performance signals, indexability,
            crawlability, and AI Recommendations in one protected workspace.
          </p>

          <div className="mt-9 grid gap-3 sm:grid-cols-2">
            {trustIndicators.map(({ label, icon: Icon }) => (
              <div key={label} className="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-3.5 backdrop-blur-sm">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[var(--color-primary)]/35 text-[var(--color-highlight)]">
                  <Icon className="h-4 w-4" />
                </span>
                <span className="text-xs font-bold text-white/85">{label}</span>
              </div>
            ))}
          </div>
        </div>

        <div className="relative flex items-center justify-between border-t border-white/10 pt-6 text-[11px] font-semibold text-white/55">
          <span>Protected Laravel API</span>
          <span>Session-scoped authentication</span>
        </div>
      </section>

      <main className="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-8 sm:px-8 lg:px-12">
        <div className="pointer-events-none absolute -right-28 top-12 h-72 w-72 rounded-full bg-[var(--color-highlight)]/12 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-28 left-0 h-80 w-80 rounded-full bg-[var(--color-soft)]/12 blur-3xl" />
        <div className="relative w-full max-w-lg">
          <div className="mb-4 flex items-center justify-between lg:hidden">
            <div className="flex items-center gap-2.5">
              <AppLogo size={40} />
              <div>
                <p className="text-sm font-black text-[var(--color-text)]">AuditSEO AI Platform</p>
                <p className="text-[10px] font-bold text-[var(--color-muted)]">Secure AI-powered SEO audit platform</p>
              </div>
            </div>
          </div>
          <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1 text-[10px] font-black uppercase tracking-[0.15em] text-[var(--color-muted)] shadow-sm">
            <ShieldCheck className="h-3.5 w-3.5 text-[var(--color-accent)]" /> {modeLabel}
          </div>
          {children}
        </div>
      </main>
    </div>
  );
}
