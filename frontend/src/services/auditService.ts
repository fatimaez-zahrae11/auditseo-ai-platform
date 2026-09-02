import type {
  AuditPagination,
  AuditStatus,
  IssueCategory,
  IssueSeverity,
  SeoAudit,
  SeoIssue,
  UserDashboardData,
} from '../types';
import {
  PUBLIC_AUDIT_FAILURE_MESSAGE,
  PUBLIC_CRAWL_UNAVAILABLE_MESSAGE,
} from '../utils/publicApiErrors';
import { apiRequest, getAuthGeneration, subscribeToAuthState } from './apiClient';

interface BackendDomain {
  domain_name?: unknown;
  url?: unknown;
}

interface BackendAuditIssue {
  id?: unknown;
  category?: unknown;
  title?: unknown;
  severity?: unknown;
  description?: unknown;
  recommendation?: unknown;
}

interface BackendAudit {
  id?: unknown;
  domain_id?: unknown;
  requested_url?: unknown;
  final_url?: unknown;
  status?: unknown;
  global_score?: unknown;
  technical_score?: unknown;
  content_score?: unknown;
  links_score?: unknown;
  performance_score?: unknown;
  raw_data?: unknown;
  created_at?: unknown;
  updated_at?: unknown;
  started_at?: unknown;
  completed_at?: unknown;
  failed_at?: unknown;
  failure_message?: unknown;
  failure_reason?: unknown;
  error_message?: unknown;
  domain?: BackendDomain | null;
  issues?: BackendAuditIssue[] | null;
}

interface BackendPagination {
  current_page?: unknown;
  last_page?: unknown;
  per_page?: unknown;
  total?: unknown;
  from?: unknown;
  to?: unknown;
  previous_page_url?: unknown;
  next_page_url?: unknown;
}

interface AuditListResponse {
  audits?: BackendAudit[];
  pagination?: BackendPagination;
}

interface AuditDetailResponse {
  audit: BackendAudit;
}

interface CreateAuditResponse {
  message: string;
  audit: BackendAudit;
  poll_url: string;
}

interface DashboardResponse {
  total_audits?: unknown;
  completed_audits?: unknown;
  pending_audits?: unknown;
  running_audits?: unknown;
  failed_audits?: unknown;
  average_global_score?: unknown;
  total_issues?: unknown;
  total_ai_recommendations?: unknown;
  latest_audit?: BackendAudit | null;
  latest_completed_audit?: BackendAudit | null;
}

interface AuditListData {
  audits: SeoAudit[];
  pagination: AuditPagination;
}

export interface AuditListFilters {
  search?: string;
  status?: AuditStatus;
}

const numberValue = (value: unknown) => {
  const parsed = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

const optionalNumber = (value: unknown) => {
  if (value === null || value === undefined || value === '') return undefined;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
};

const stringValue = (value: unknown, fallback = '') => typeof value === 'string' ? value : fallback;
const optionalString = (value: unknown) => typeof value === 'string' && value ? value : undefined;

const mapStatus = (value: unknown): AuditStatus => (
  value === 'running' || value === 'completed' || value === 'failed' ? value : 'pending'
);

const mapSeverity = (value: unknown): IssueSeverity => {
  if (value === 'critical') return 'critical';
  if (value === 'important' || value === 'high') return 'high';
  if (value === 'medium') return 'medium';
  if (value === 'minor' || value === 'low') return 'low';
  return 'info';
};

const mapCategory = (value: unknown): IssueCategory => {
  if (value === 'content' || value === 'links' || value === 'performance') return value;
  return 'technical';
};

const issueImpact = (severity: IssueSeverity) => ({
  critical: 20,
  high: 12,
  medium: 8,
  low: 4,
  info: 0,
}[severity]);

const mapIssue = (issue: BackendAuditIssue, index: number): SeoIssue => {
  const severity = mapSeverity(issue.severity);
  return {
    id: String(issue.id ?? `issue-${index}`),
    title: stringValue(issue.title, 'SEO issue'),
    category: mapCategory(issue.category),
    severity,
    description: stringValue(issue.description, 'No additional issue detail was provided.'),
    recommendation: stringValue(issue.recommendation, 'Review this finding and apply the appropriate SEO correction.'),
    impactScore: issueImpact(severity),
    location: typeof issue.category === 'string' ? issue.category.replaceAll('_', ' ') : undefined,
  };
};

const domainFromUrl = (url: string) => {
  try {
    return new URL(url).hostname;
  } catch {
    return url.replace(/^https?:\/\//i, '').split('/')[0] || 'Unknown domain';
  }
};

const mapRawData = (value: unknown): Record<string, unknown> | undefined => (
  value && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown>
    : undefined
);

const publicFailureMessage = (value: unknown) => (
  value === PUBLIC_CRAWL_UNAVAILABLE_MESSAGE
    ? PUBLIC_CRAWL_UNAVAILABLE_MESSAGE
    : PUBLIC_AUDIT_FAILURE_MESSAGE
);

export const mapBackendAudit = (audit: BackendAudit): SeoAudit => {
  const requestedUrl = stringValue(audit.requested_url, stringValue(audit.domain?.url));
  const rawData = mapRawData(audit.raw_data);
  const domain = stringValue(audit.domain?.domain_name, domainFromUrl(requestedUrl));
  const pageSizeBytes = optionalNumber(rawData?.page_size_bytes);
  const status = mapStatus(audit.status);

  return {
    id: String(audit.id ?? ''),
    userId: '',
    userEmail: '',
    domain,
    requestedUrl,
    finalUrl: stringValue(audit.final_url, requestedUrl),
    status,
    globalScore: numberValue(audit.global_score),
    technicalScore: numberValue(audit.technical_score),
    contentScore: numberValue(audit.content_score),
    linksScore: numberValue(audit.links_score),
    performanceScore: numberValue(audit.performance_score),
    createdAt: stringValue(audit.created_at, new Date(0).toISOString()),
    completedAt: optionalString(audit.completed_at),
    startedAt: optionalString(audit.started_at),
    failedAt: optionalString(audit.failed_at),
    failureMessage: status === 'failed' ? publicFailureMessage(audit.failure_message) : undefined,
    issues: Array.isArray(audit.issues) ? audit.issues.map(mapIssue) : [],
    rawData,
    crawlDurationMs: optionalNumber(rawData?.response_time_ms),
    pageWeightKb: pageSizeBytes === undefined ? undefined : Math.round(pageSizeBytes / 1024),
    requestsCount: optionalNumber(rawData?.pages_crawled_count),
  };
};

const mapPagination = (pagination?: BackendPagination): AuditPagination => ({
  currentPage: numberValue(pagination?.current_page) || 1,
  lastPage: numberValue(pagination?.last_page) || 1,
  perPage: numberValue(pagination?.per_page) || 20,
  total: numberValue(pagination?.total),
  from: pagination?.from === null || pagination?.from === undefined ? null : numberValue(pagination.from),
  to: pagination?.to === null || pagination?.to === undefined ? null : numberValue(pagination.to),
  previousPageUrl: optionalString(pagination?.previous_page_url) || null,
  nextPageUrl: optionalString(pagination?.next_page_url) || null,
});

const mapDashboard = (data: DashboardResponse): UserDashboardData => ({
  totalAudits: numberValue(data.total_audits),
  completedAudits: numberValue(data.completed_audits),
  pendingAudits: numberValue(data.pending_audits),
  runningAudits: numberValue(data.running_audits),
  failedAudits: numberValue(data.failed_audits),
  averageGlobalScore: numberValue(data.average_global_score),
  totalIssues: numberValue(data.total_issues),
  totalAiRecommendations: numberValue(data.total_ai_recommendations),
  latestAudit: data.latest_audit ? mapBackendAudit(data.latest_audit) : null,
  latestCompletedAudit: data.latest_completed_audit ? mapBackendAudit(data.latest_completed_audit) : null,
});

let dashboardRequest: { session: number; promise: Promise<UserDashboardData> } | null = null;
const auditListRequests = new Map<string, Promise<AuditListData>>();
const auditDetailRequests = new Map<string, Promise<SeoAudit>>();

subscribeToAuthState(() => {
  dashboardRequest = null;
  auditListRequests.clear();
  auditDetailRequests.clear();
});

const requestSession = () => getAuthGeneration();

const getDashboard = () => {
  const session = requestSession();
  if (dashboardRequest?.session === session) return dashboardRequest.promise;

  const request = apiRequest<DashboardResponse>('/dashboard', {}, { auth: 'required' })
    .then(mapDashboard)
    .finally(() => {
      if (dashboardRequest?.promise === request) dashboardRequest = null;
    });

  dashboardRequest = { session, promise: request };
  return request;
};

const listAudits = (page = 1, filters: AuditListFilters = {}) => {
  const query = new URLSearchParams({ page: String(page) });
  if (filters.search?.trim()) query.set('search', filters.search.trim());
  if (filters.status) query.set('status', filters.status);
  const path = `/audits?${query.toString()}`;
  const requestKey = `${requestSession()}:${path}`;
  const inFlightRequest = auditListRequests.get(requestKey);
  if (inFlightRequest) return inFlightRequest;

  const request = apiRequest<AuditListResponse>(path, {}, { auth: 'required' })
    .then((data): AuditListData => ({
      audits: Array.isArray(data.audits) ? data.audits.map(mapBackendAudit) : [],
      pagination: mapPagination(data.pagination),
    }))
    .finally(() => {
      if (auditListRequests.get(requestKey) === request) auditListRequests.delete(requestKey);
    });

  auditListRequests.set(requestKey, request);
  return request;
};

const getAudit = (id: string) => {
  const requestKey = `${requestSession()}:${id}`;
  const inFlightRequest = auditDetailRequests.get(requestKey);
  if (inFlightRequest) return inFlightRequest;

  const request = apiRequest<AuditDetailResponse>(`/audits/${encodeURIComponent(id)}`, {}, { auth: 'required' })
    .then((data) => mapBackendAudit(data.audit))
    .finally(() => {
      if (auditDetailRequests.get(requestKey) === request) auditDetailRequests.delete(requestKey);
    });

  auditDetailRequests.set(requestKey, request);
  return request;
};

export const auditService = {
  getDashboard,

  createAudit: async (url: string) => {
    const data = await apiRequest<CreateAuditResponse>('/audits', {
      method: 'POST',
      body: { url },
    }, { auth: 'required' });
    return { message: data.message, audit: mapBackendAudit(data.audit), pollUrl: data.poll_url };
  },

  listAudits,

  getAudit,
};
