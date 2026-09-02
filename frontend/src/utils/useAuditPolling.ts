import { useCallback, useEffect, useState } from 'react';
import type { SeoAudit } from '../types';
import { auditService } from '../services/auditService';

export const useAuditPolling = (auditId: string | null, intervalMs = 4000) => {
  const [audit, setAudit] = useState<SeoAudit | null>(null);
  const [isLoading, setIsLoading] = useState(Boolean(auditId));
  const [error, setError] = useState<unknown>(null);
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => {
    let isActive = true;
    let pollTimer: number | undefined;

    setAudit(null);
    setError(null);
    setIsLoading(Boolean(auditId));

    if (!auditId) return undefined;

    const loadAudit = async () => {
      try {
        const nextAudit = await auditService.getAudit(auditId);
        if (!isActive) return;
        setAudit(nextAudit);
        setError(null);

        if (nextAudit.status === 'pending' || nextAudit.status === 'running') {
          pollTimer = window.setTimeout(() => void loadAudit(), intervalMs);
        }
      } catch (requestError) {
        if (isActive) setError(requestError);
      } finally {
        if (isActive) setIsLoading(false);
      }
    };

    void loadAudit();

    return () => {
      isActive = false;
      if (pollTimer !== undefined) window.clearTimeout(pollTimer);
    };
  }, [auditId, intervalMs, retryKey]);

  const retry = useCallback(() => setRetryKey((key) => key + 1), []);

  return { audit, setAudit, isLoading, error, retry };
};
