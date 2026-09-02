import { useState } from 'react';
import { acceptAnalytics, declineAnalytics, getAnalyticsConsent } from '../../services/webAnalyticsService';

export function AnalyticsConsentBanner() {
  const [visible, setVisible] = useState(() => getAnalyticsConsent() === null);

  if (!visible) return null;

  return (
    <aside
      className="fixed bottom-4 left-4 right-4 z-[80] mx-auto max-w-2xl rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 text-[var(--color-text)] shadow-2xl sm:flex sm:items-center sm:justify-between sm:gap-5"
      aria-label="Anonymous analytics consent"
    >
      <div>
        <p className="text-xs font-black">Anonymous platform analytics</p>
        <p className="mt-1 text-[11px] leading-5 text-[var(--color-muted)]">With your permission, AuditSEO uses anonymous page-view identifiers to measure platform usage. Analytics requests never include your authentication token. Declining disables analytics tracking.</p>
      </div>
      <div className="mt-3 flex shrink-0 gap-2 sm:mt-0">
        <button
          type="button"
          onClick={() => {
            declineAnalytics();
            setVisible(false);
          }}
          className="rounded-xl border border-[var(--color-border)] px-3 py-2 text-[11px] font-bold text-[var(--color-muted)] hover:text-[var(--color-text)]"
        >
          Decline
        </button>
        <button
          type="button"
          onClick={() => {
            acceptAnalytics();
            setVisible(false);
          }}
          className="rounded-xl bg-[var(--color-primary)] px-3 py-2 text-[11px] font-black text-[var(--color-on-primary)] hover:bg-[var(--color-primary-hover)]"
        >
          Accept analytics
        </button>
      </div>
    </aside>
  );
}
