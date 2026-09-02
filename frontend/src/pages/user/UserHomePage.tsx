import React, { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { Activity, AlertTriangle, ArrowRight, Globe2, LayoutDashboard, ListOrdered, Loader2, ScanSearch, ShieldCheck } from 'lucide-react';
import { useApp } from '../../context/AppContext';
import { ApiError } from '../../services/apiClient';
import { auditService } from '../../services/auditService';
import { getPublicApiErrorMessage } from '../../utils/publicApiErrors';

const RotatingGlobeHero = lazy(() => import('../../components/visuals/RotatingGlobeHero')
  .then((module) => ({ default: module.RotatingGlobeHero })));

const SCHEME_PATTERN = /^[a-z][a-z\d+.-]*:/i;
const HTTP_SCHEME_PATTERN = /^https?:\/\//i;

const isValidAuditHostname = (hostname: string) => {
  const normalizedHostname = hostname.toLowerCase().replace(/\.$/, '');
  if (!normalizedHostname || normalizedHostname === 'localhost' || normalizedHostname.endsWith('.localhost')) return false;
  if (/^127(?:\.\d{1,3}){3}$/.test(normalizedHostname)) return false;

  const ipv4Parts = normalizedHostname.split('.');
  const isIpv4 = ipv4Parts.length === 4
    && ipv4Parts.every((part) => /^\d{1,3}$/.test(part) && Number(part) <= 255);
  if (isIpv4) return true;

  if (!normalizedHostname.includes('.')) return false;
  return normalizedHostname
    .split('.')
    .every((label) => /^[a-z\d](?:[a-z\d-]{0,61}[a-z\d])?$/i.test(label));
};

const normalizeAuditUrl = (value: string) => {
  const target = value.trim();
  if (!target) return null;

  if (SCHEME_PATTERN.test(target) && !HTTP_SCHEME_PATTERN.test(target)) return null;
  const candidate = HTTP_SCHEME_PATTERN.test(target) ? target : `https://${target}`;

  try {
    const parsed = new URL(candidate);
    if ((parsed.protocol !== 'http:' && parsed.protocol !== 'https:') || !isValidAuditHostname(parsed.hostname)) return null;
    if (parsed.username || parsed.password) return null;
    // SEO audits operate on the page path; query parameters and fragments may contain secrets.
    parsed.search = '';
    parsed.hash = '';
    const normalizedUrl = parsed.toString();
    return parsed.pathname === '/' && !parsed.search && !parsed.hash && !candidate.endsWith('/')
      ? normalizedUrl.slice(0, -1)
      : normalizedUrl;
  } catch {
    return null;
  }
};

const validateUrl = (value: string) => {
  const target = value.trim();
  if (!target) return 'Please enter a valid public domain or URL.';
  if (SCHEME_PATTERN.test(target) && !HTTP_SCHEME_PATTERN.test(target)) {
    return 'Only http and https URLs are supported.';
  }
  if (!normalizeAuditUrl(target)) return 'Please enter a valid public domain or URL.';

  return '';
};

export const UserHomePage: React.FC = () => {
  const { addToast, selectAudit, setCurrentView } = useApp();
  const [url, setUrl] = useState('');
  const [validationError, setValidationError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [currentAuditId, setCurrentAuditId] = useState<string | null>(null);
  const urlInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    let active = true;

    auditService.listAudits(1)
      .then(({ audits }) => {
        if (!active) return;
        const currentAudit = audits.find((audit) => audit.status === 'pending' || audit.status === 'running');
        setCurrentAuditId(currentAudit?.id || null);
      })
      .catch(() => {
        if (active) setCurrentAuditId(null);
      });

    return () => { active = false; };
  }, []);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const error = validateUrl(url);
    if (error) {
      setValidationError(error);
      return;
    }

    setValidationError('');
    setIsSubmitting(true);

    try {
      const normalizedUrl = normalizeAuditUrl(url);
      if (!normalizedUrl) throw new Error('The website URL could not be normalized.');
      const response = await auditService.createAudit(normalizedUrl);
      if (!/^\d+$/.test(response.audit.id)) {
        throw new Error('The audit service returned an invalid audit identifier.');
      }

      addToast({
        title: 'Audit queued',
        message: 'Audit queued successfully. Processing has started.',
        type: 'success',
      });
      selectAudit(response.audit.id, 'audit-detail');
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        if (requestError.status === 403 && requestError.message === 'Account disabled') {
          setCurrentView('account-disabled');
        } else if (requestError.status === 403 && requestError.message.toLowerCase().includes('verif')) {
          setCurrentView('email-verification');
        } else if (requestError.status === 403) {
          setCurrentView('error-403');
        } else if (requestError.status === 422) {
          setValidationError(getPublicApiErrorMessage(requestError, {
            validationFallback: 'Please enter a valid public domain or URL.',
          }));
        } else if (requestError.status === 429) {
          setValidationError('Too many audit requests. Please wait a moment and try again.');
        } else {
          setValidationError(requestError.status === 0
            ? 'The audit service is unreachable. Check your connection and try again.'
            : 'Unable to start the audit right now. Please try again.');
        }
      } else {
        setValidationError('Unable to start the audit right now. Please try again.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div id="user-home-view" className="user-hero-stage relative min-h-[calc(100vh-4rem)] w-full overflow-hidden px-4 sm:px-6 lg:px-8">
      <section className="relative isolate flex min-h-[calc(100vh-4rem)] w-full items-center py-12 sm:py-16 lg:py-20">
        <Suspense fallback={null}>
          <RotatingGlobeHero className="absolute inset-0 -z-10 hidden opacity-65 md:block lg:opacity-80" />
        </Suspense>
        <div className="pointer-events-none absolute inset-0 -z-[5] bg-[radial-gradient(ellipse_at_center,rgba(2,12,15,0.12)_0%,rgba(2,12,15,0.3)_62%,rgba(2,12,15,0.5)_100%)]" aria-hidden="true" />

        <div className="relative z-10 mx-auto max-w-5xl text-center">
          <div className="inline-flex items-center gap-2 rounded-full border border-[var(--color-primary)]/35 bg-[var(--color-primary)]/8 px-3.5 py-1.5 text-[10px] font-black uppercase tracking-[0.16em] text-[var(--color-primary)] shadow-[0_0_28px_rgba(255,138,0,0.1)]">
            <ScanSearch className="h-3.5 w-3.5" /> SEO Audit Workspace
          </div>
          <h1 className="mx-auto mt-6 max-w-5xl text-4xl font-black leading-[1.04] tracking-[-0.055em] text-white sm:text-5xl lg:text-6xl">
            Launch, track, and improve your SEO audits from <span className="text-[var(--color-primary)]">one clean workspace.</span>
          </h1>
          <p className="mx-auto mt-5 max-w-3xl text-sm leading-7 text-[#A3A3A3] sm:text-base">
            Run structured SEO audits, follow your scores, review recommendations, and keep every report organized.
          </p>

          <form noValidate onSubmit={handleSubmit} className="mx-auto mt-9 max-w-4xl rounded-2xl border border-[var(--color-border)] bg-[#050A0B]/88 p-2.5 text-left shadow-[0_22px_60px_rgba(0,0,0,0.38)] backdrop-blur-xl sm:p-3">
            <label htmlFor="workspace-audit-url" className="sr-only">Website URL</label>
            <div className={`flex flex-col gap-2 rounded-xl border bg-[var(--color-surface)] p-1.5 transition-colors sm:flex-row sm:items-center ${validationError ? 'border-[var(--color-danger-border)]' : 'border-white/8 focus-within:border-[var(--color-primary)]/55'}`}>
              <div className="flex min-w-0 flex-1 items-center gap-3 px-3">
                <Globe2 className="h-5 w-5 shrink-0 text-[var(--color-primary)]" />
                <input
                  ref={urlInputRef}
                  id="workspace-audit-url"
                  type="text"
                  inputMode="url"
                  value={url}
                  onChange={(event) => {
                    setUrl(event.target.value);
                    if (validationError) setValidationError('');
                  }}
                  placeholder="example.com or https://example.com"
                  autoComplete="url"
                  disabled={isSubmitting}
                  aria-invalid={Boolean(validationError)}
                  aria-describedby={validationError ? 'workspace-url-error' : 'workspace-url-helper'}
                  className="min-w-0 flex-1 bg-transparent py-3 text-sm font-semibold text-white outline-none placeholder:text-[var(--color-muted)]/70"
                />
              </div>
              <button type="submit" disabled={isSubmitting} className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--color-cta)] px-6 text-sm font-black text-[var(--color-on-cta)] shadow-[0_10px_26px_rgba(255,138,0,0.2)] transition-colors hover:bg-[var(--color-cta-hover)] disabled:cursor-wait disabled:opacity-65">
                {isSubmitting ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                {isSubmitting ? 'Starting audit...' : 'Analyze URL'}
                {!isSubmitting ? <ArrowRight className="h-4 w-4" /> : null}
              </button>
            </div>
            {validationError ? (
              <p id="workspace-url-error" className="mt-2 flex items-center justify-center gap-1.5 px-2 text-xs font-semibold text-[var(--color-danger-text)]" role="alert"><AlertTriangle className="h-3.5 w-3.5" />{validationError}</p>
            ) : (
              <p id="workspace-url-helper" className="mt-2 flex items-center justify-center gap-1.5 px-2 text-[10px] font-semibold text-[var(--color-muted)]"><ShieldCheck className="h-3.5 w-3.5 text-[var(--color-primary)]" />Public domains and http/https URLs are supported. Query parameters and fragments are removed for safety.</p>
            )}
          </form>

          <nav className="mt-4 flex flex-wrap items-center justify-center gap-2" aria-label="Home quick actions">
            <button type="button" onClick={() => setCurrentView('user-dashboard')} className="inline-flex min-h-9 items-center gap-2 rounded-full border border-[var(--color-primary)]/25 bg-[#071416]/72 px-4 text-[11px] font-bold text-white/90 backdrop-blur-md transition-colors hover:border-[var(--color-primary)]/55 hover:bg-[var(--color-primary)]/10 hover:text-[var(--color-primary)]">
              <LayoutDashboard className="h-3.5 w-3.5 text-[var(--color-primary)]" />Dashboard
            </button>
            <button type="button" onClick={() => setCurrentView('my-audits')} className="inline-flex min-h-9 items-center gap-2 rounded-full border border-[var(--color-primary)]/25 bg-[#071416]/72 px-4 text-[11px] font-bold text-white/90 backdrop-blur-md transition-colors hover:border-[var(--color-primary)]/55 hover:bg-[var(--color-primary)]/10 hover:text-[var(--color-primary)]">
              <ListOrdered className="h-3.5 w-3.5 text-[var(--color-primary)]" />My Audits
            </button>
            {currentAuditId ? (
              <button type="button" onClick={() => selectAudit(currentAuditId, 'audit-detail')} className="inline-flex min-h-9 items-center gap-2 rounded-full border border-[var(--color-primary)]/40 bg-[var(--color-primary)]/10 px-4 text-[11px] font-black text-[var(--color-primary)] backdrop-blur-md transition-colors hover:border-[var(--color-primary)]/70 hover:bg-[var(--color-primary)]/15">
                <Activity className="h-3.5 w-3.5" />View audit progress
              </button>
            ) : null}
          </nav>
        </div>
      </section>
    </div>
  );
};
