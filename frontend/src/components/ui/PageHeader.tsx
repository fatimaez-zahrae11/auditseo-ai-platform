import type { ReactNode } from 'react';

interface PageHeaderProps {
  eyebrow?: string;
  title: string;
  description?: string;
  actions?: ReactNode;
  dense?: boolean;
}

export function PageHeader({ eyebrow, title, description, actions, dense = false }: PageHeaderProps) {
  return (
    <header className={`flex flex-col justify-between gap-4 sm:flex-row sm:items-start ${dense ? 'mb-5' : 'mb-7'}`}>
      <div className="max-w-3xl">
        {eyebrow ? (
          <p className="mb-2 text-[11px] font-extrabold uppercase tracking-[0.18em] text-[var(--color-primary)]">{eyebrow}</p>
        ) : null}
        <h1 className={`${dense ? 'text-2xl' : 'text-3xl'} font-black tracking-tight text-[var(--color-text)]`}>{title}</h1>
        {description ? <p className="mt-2 text-sm leading-6 text-[var(--color-muted)]">{description}</p> : null}
      </div>
      {actions ? <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div> : null}
    </header>
  );
}
