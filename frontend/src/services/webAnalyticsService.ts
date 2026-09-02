import type { ViewType } from '../types';
import { apiRequest } from './apiClient';

export type AnalyticsConsent = 'accepted' | 'declined' | null;
export const ANALYTICS_CONSENT_EVENT = 'auditseo:analytics-consent-changed';

const VISITOR_COOKIE = 'auditseo_visitor_id';
const CONSENT_COOKIE = 'auditseo_analytics_consent';
const CONSENT_SESSION_KEY = 'auditseo_analytics_consent';
const SESSION_VISITOR_KEY = 'auditseo_analytics_session_visitor_id';
const SESSION_ID_KEY = 'auditseo_analytics_session_id';
const COOKIE_MAX_AGE_SECONDS = 365 * 24 * 60 * 60;
const DEDUPE_WINDOW_MS = 2_000;

let memoryVisitorId = '';
let memorySessionId = '';
const recentPageViews = new Map<string, number>();

const routeDetails: Record<ViewType, { path: string; title: string }> = {
  'user-home': { path: '/analyze', title: 'Home / Analyze' },
  'user-dashboard': { path: '/dashboard', title: 'Dashboard' },
  'create-audit': { path: '/analyze', title: 'Home / Analyze' },
  'my-audits': { path: '/audits', title: 'My Audits' },
  'audit-detail': { path: '/audits/detail', title: 'SEO Audit Report' },
  'audit-report': { path: '/audits/report', title: 'PDF Report' },
  login: { path: '/login', title: 'Login' },
  register: { path: '/register', title: 'Register' },
  'email-verification': { path: '/email-verification', title: 'Email Verification' },
  'admin-dashboard': { path: '/admin/dashboard', title: 'Admin Dashboard' },
  'users-management': { path: '/admin/users', title: 'Users Management' },
  'user-activity': { path: '/admin/users/activity', title: 'User Activity' },
  'admin-audits': { path: '/admin/audits', title: 'Audit Supervision' },
  'admin-recommendations': { path: '/admin/recommendations', title: 'AI Recommendations' },
  'active-users-analytics': { path: '/admin/analytics/active-users', title: 'Active Users' },
  'heavy-users-analytics': { path: '/admin/analytics/heavy-users', title: 'Heavy Users' },
  'system-health': { path: '/admin/system/health', title: 'System Health' },
  'system-logs': { path: '/admin/system/logs', title: 'System Logs' },
  'admin-action-logs': { path: '/admin/action-logs', title: 'Admin Action Logs' },
  'ip-intelligence': { path: '/admin/security/ip-intelligence', title: 'IP Intelligence' },
  'error-401': { path: '/error/401', title: 'Unauthorized' },
  'error-403': { path: '/error/403', title: 'Forbidden' },
  'error-404': { path: '/error/404', title: 'Not Found' },
  'error-409': { path: '/error/409', title: 'Conflict' },
  'error-422': { path: '/error/422', title: 'Validation Error' },
  'error-429': { path: '/error/429', title: 'Rate Limited' },
  'error-500': { path: '/error/500', title: 'Server Error' },
  'error-502': { path: '/error/502', title: 'Bad Gateway' },
  'error-503': { path: '/error/503', title: 'Service Unavailable' },
  'account-disabled': { path: '/account-disabled', title: 'Account Disabled' },
};

const randomId = () => {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

const sessionValue = (key: string) => {
  try {
    return sessionStorage.getItem(key);
  } catch {
    return null;
  }
};

const setSessionValue = (key: string, value: string) => {
  try {
    sessionStorage.setItem(key, value);
    return true;
  } catch {
    return false;
  }
};

const removeSessionValue = (key: string) => {
  try {
    sessionStorage.removeItem(key);
  } catch {
    // Storage can be unavailable in privacy-restricted browser contexts.
  }
};

const cookieValue = (name: string) => {
  const prefix = `${encodeURIComponent(name)}=`;
  const match = document.cookie.split(';').map((part) => part.trim()).find((part) => part.startsWith(prefix));
  return match ? decodeURIComponent(match.slice(prefix.length)) : null;
};

const writeCookie = (name: string, value: string, maxAge = COOKIE_MAX_AGE_SECONDS) => {
  const secure = window.location.protocol === 'https:' || import.meta.env.PROD ? '; Secure' : '';
  document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}; Path=/; Max-Age=${maxAge}; SameSite=Lax${secure}`;
};

const deleteCookie = (name: string) => writeCookie(name, '', 0);

const sessionIdentifier = (key: string, fallback: 'visitor' | 'session') => {
  const existing = sessionValue(key);
  if (existing) return existing;

  const created = randomId();
  if (setSessionValue(key, created)) return created;

  if (fallback === 'visitor') {
    memoryVisitorId ||= created;
    return memoryVisitorId;
  }
  memorySessionId ||= created;
  return memorySessionId;
};

export const getAnalyticsConsent = (): AnalyticsConsent => {
  const sessionConsent = sessionValue(CONSENT_SESSION_KEY);
  if (sessionConsent === 'accepted' || sessionConsent === 'declined') return sessionConsent;
  const cookieConsent = cookieValue(CONSENT_COOKIE);
  return cookieConsent === 'accepted' || cookieConsent === 'declined' ? cookieConsent : null;
};

const announceConsentChange = () => {
  window.dispatchEvent(new Event(ANALYTICS_CONSENT_EVENT));
};

export const acceptAnalytics = () => {
  writeCookie(CONSENT_COOKIE, 'accepted');
  setSessionValue(CONSENT_SESSION_KEY, 'accepted');
  const visitorId = cookieValue(VISITOR_COOKIE) || randomId();
  writeCookie(VISITOR_COOKIE, visitorId);
  announceConsentChange();
  return cookieValue(VISITOR_COOKIE) !== null;
};

export const declineAnalytics = () => {
  deleteCookie(VISITOR_COOKIE);
  writeCookie(CONSENT_COOKIE, 'declined');
  setSessionValue(CONSENT_SESSION_KEY, 'declined');
  removeSessionValue(SESSION_VISITOR_KEY);
  removeSessionValue(SESSION_ID_KEY);
  memoryVisitorId = '';
  memorySessionId = '';
  recentPageViews.clear();
  announceConsentChange();
};

const analyticsIdentity = () => {
  if (getAnalyticsConsent() !== 'accepted') return null;
  const sessionId = sessionIdentifier(SESSION_ID_KEY, 'session');
  const existing = cookieValue(VISITOR_COOKIE);
  if (existing) return { visitorId: existing, sessionId };

  const created = randomId();
  writeCookie(VISITOR_COOKIE, created);
  const persisted = cookieValue(VISITOR_COOKIE);
  if (persisted) return { visitorId: persisted, sessionId };

  return {
    visitorId: sessionIdentifier(SESSION_VISITOR_KEY, 'visitor'),
    sessionId,
  };
};

const safeReferrer = () => {
  if (!document.referrer) return null;
  try {
    const referrer = new URL(document.referrer);
    if (referrer.protocol !== 'http:' && referrer.protocol !== 'https:') return null;
    return `${referrer.origin}${referrer.pathname}`;
  } catch {
    return null;
  }
};

export const trackPageView = async (view: ViewType): Promise<void> => {
  if (getAnalyticsConsent() !== 'accepted') return;
  const route = routeDetails[view];
  const identity = analyticsIdentity();
  if (!identity) return;
  const dedupeKey = `${identity.sessionId}:${route.path}`;
  const now = Date.now();
  const previous = recentPageViews.get(dedupeKey) ?? 0;
  if (now - previous < DEDUPE_WINDOW_MS) return;

  recentPageViews.set(dedupeKey, now);
  recentPageViews.forEach((trackedAt, key) => {
    if (now - trackedAt > DEDUPE_WINDOW_MS * 5) recentPageViews.delete(key);
  });

  try {
    await apiRequest<void>('/analytics/page-view', {
      method: 'POST',
      body: {
        visitor_id: identity.visitorId,
        session_id: identity.sessionId,
        path: route.path,
        page_title: route.title,
        referrer: safeReferrer(),
      },
    }, { auth: 'none' });
  } catch {
    // Analytics is best-effort and must never interrupt navigation or show an app error.
  }
};
