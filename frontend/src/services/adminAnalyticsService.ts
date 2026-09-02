import type {
  AdminActiveUser,
  AdminAnalyticsOverview,
  AdminAuditSummary,
  AdminHeavyUser,
  AdminHeavyUserPeriod,
  AdminTrafficAnalytics,
  AdminTrafficPeriod,
  AdminWebTrafficAnalytics,
  AuditPagination,
  AuditStatus,
} from '../types';
import { apiRequest, getAuthGeneration, subscribeToAuthState } from './apiClient';

type UnknownRecord = Record<string, unknown>;

interface BackendPagination extends UnknownRecord {
  current_page?: unknown;
  last_page?: unknown;
  per_page?: unknown;
  total?: unknown;
  from?: unknown;
  to?: unknown;
  previous_page_url?: unknown;
  next_page_url?: unknown;
}

const asRecord = (value: unknown): UnknownRecord =>
  typeof value === 'object' && value !== null ? value as UnknownRecord : {};
const asArray = (value: unknown): unknown[] => Array.isArray(value) ? value : [];
const asString = (value: unknown, fallback = ''): string => typeof value === 'string' ? value : fallback;
const asOptionalString = (value: unknown): string | null => typeof value === 'string' && value ? value : null;
const asNumber = (value: unknown): number => {
  const parsed = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};
const asOptionalNumber = (value: unknown): number | null =>
  value === null || value === undefined ? null : asNumber(value);
const asBoolean = (value: unknown): boolean => value === true || value === 1 || value === '1';
const asStatus = (value: unknown): AuditStatus =>
  value === 'running' || value === 'completed' || value === 'failed' ? value : 'pending';

const mapPagination = (value: unknown): AuditPagination => {
  const pagination = asRecord(value) as BackendPagination;
  return {
    currentPage: asNumber(pagination.current_page) || 1,
    lastPage: asNumber(pagination.last_page) || 1,
    perPage: asNumber(pagination.per_page) || 20,
    total: asNumber(pagination.total),
    from: pagination.from === null || pagination.from === undefined ? null : asNumber(pagination.from),
    to: pagination.to === null || pagination.to === undefined ? null : asNumber(pagination.to),
    previousPageUrl: asOptionalString(pagination.previous_page_url),
    nextPageUrl: asOptionalString(pagination.next_page_url),
  };
};

const queryString = (params: Record<string, string | number | undefined>) => {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value));
  });
  const serialized = query.toString();
  return serialized ? `?${serialized}` : '';
};

const inFlightGetRequests = new Map<string, Promise<unknown>>();

subscribeToAuthState(() => inFlightGetRequests.clear());

const dedupedApiGet = (path: string) => {
  const requestKey = `${getAuthGeneration()}:${path}`;
  const inFlightRequest = inFlightGetRequests.get(requestKey);
  if (inFlightRequest) return inFlightRequest;

  const request = apiRequest<unknown>(path, {}, { auth: 'required' })
    .finally(() => {
      if (inFlightGetRequests.get(requestKey) === request) inFlightGetRequests.delete(requestKey);
    });

  inFlightGetRequests.set(requestKey, request);
  return request;
};

const mapActiveUser = (value: unknown): AdminActiveUser => {
  const user = asRecord(value);
  return {
    id: String(user.id ?? ''),
    name: asString(user.name, 'Unnamed user'),
    email: asString(user.email),
    role: user.role === 'admin' ? 'admin' : 'user',
    isActive: asBoolean(user.is_active),
    lastSeenAt: asOptionalString(user.last_seen_at),
    lastIp: asOptionalString(user.last_ip),
    requestCountLast15m: asNumber(user.request_count_last_15m),
    requestCountLast24h: asNumber(user.request_count_last_24h),
  };
};

const mapHeavyUser = (value: unknown): AdminHeavyUser => {
  const user = asRecord(value);
  return {
    id: String(user.id ?? ''),
    name: asString(user.name, 'Unnamed user'),
    email: asString(user.email),
    role: user.role === 'admin' ? 'admin' : 'user',
    isActive: asBoolean(user.is_active),
    requestsCount: asNumber(user.requests_count),
    errorRequestsCount: asNumber(user.error_requests_count),
    auditsCount: asNumber(user.audits_count),
    completedAuditsCount: asNumber(user.completed_audits_count),
    failedAuditsCount: asNumber(user.failed_audits_count),
    recommendationsCount: asNumber(user.recommendations_count),
    lastSeenAt: asOptionalString(user.last_seen_at),
    usageScore: asNumber(user.usage_score),
  };
};

const mapAudit = (value: unknown): AdminAuditSummary => {
  const audit = asRecord(value);
  const domainValue = asRecord(audit.domain);
  const userValue = asRecord(audit.user);
  const hasDomain = Object.keys(domainValue).length > 0;
  const hasUser = Object.keys(userValue).length > 0;
  return {
    id: String(audit.id ?? ''),
    status: asStatus(audit.status),
    requestedUrl: asString(audit.requested_url),
    finalUrl: asOptionalString(audit.final_url),
    globalScore: asOptionalNumber(audit.global_score),
    technicalScore: asOptionalNumber(audit.technical_score),
    contentScore: asOptionalNumber(audit.content_score),
    linksScore: asOptionalNumber(audit.links_score),
    performanceScore: asOptionalNumber(audit.performance_score),
    createdAt: asOptionalString(audit.created_at),
    updatedAt: asOptionalString(audit.updated_at),
    completedAt: asOptionalString(audit.completed_at),
    failedAt: asOptionalString(audit.failed_at),
    domain: hasDomain ? { name: asString(domainValue.name), url: asString(domainValue.url) } : null,
    user: hasUser ? { id: String(userValue.id ?? ''), email: asString(userValue.email) } : null,
  };
};

export interface AdminAuditFilters {
  page?: number;
  perPage?: number;
  status?: AuditStatus;
  userId?: number;
  search?: string;
  createdFrom?: string;
  createdTo?: string;
}

export const adminAnalyticsService = {
  overview: async (): Promise<AdminAnalyticsOverview> => {
    const data = asRecord(await dedupedApiGet('/admin/analytics/overview'));
    const metadata = asRecord(data.metadata);
    return {
      totalUsers: asNumber(data.total_users),
      activeUsers: asNumber(data.active_users),
      inactiveUsers: asNumber(data.inactive_users),
      adminUsers: asNumber(data.admin_users),
      totalAudits: asNumber(data.total_audits),
      pendingAudits: asNumber(data.pending_audits),
      runningAudits: asNumber(data.running_audits),
      completedAudits: asNumber(data.completed_audits),
      failedAudits: asNumber(data.failed_audits),
      totalRecommendations: asNumber(data.total_recommendations),
      requestsLast24h: asNumber(data.requests_last_24h),
      requestsLast7d: asNumber(data.requests_last_7d),
      generatedAt: asOptionalString(data.generated_at),
      activeUsersWindowMinutes: asNumber(metadata.active_users_window_minutes) || 15,
      activeUsersDefinition: asString(metadata.active_users_definition),
    };
  },

  traffic: async (period: AdminTrafficPeriod = '7d'): Promise<AdminTrafficAnalytics> => {
    const data = asRecord(await dedupedApiGet(`/admin/analytics/traffic${queryString({ period })}`));
    const totals = asRecord(data.totals);
    const metadata = asRecord(data.metadata);
    return {
      series: asArray(data.series).map((value) => {
        const point = asRecord(value);
        return {
          period: asString(point.period),
          requests: asNumber(point.requests),
          audits: asNumber(point.audits),
          recommendations: asNumber(point.recommendations),
          httpErrors: asNumber(point.http_errors),
        };
      }),
      totals: {
        requests: asNumber(totals.requests),
        audits: asNumber(totals.audits),
        recommendations: asNumber(totals.recommendations),
        httpErrors: asNumber(totals.http_errors),
      },
      metadata: {
        period: metadata.period === '24h' || metadata.period === '30d' ? metadata.period : '7d',
        granularity: metadata.granularity === 'hour' ? 'hour' : 'day',
        from: asOptionalString(metadata.from),
        to: asOptionalString(metadata.to),
        generatedAt: asOptionalString(metadata.generated_at),
      },
    };
  },

  webTraffic: async (period: AdminTrafficPeriod = '30d'): Promise<AdminWebTrafficAnalytics> => {
    const data = asRecord(await dedupedApiGet(`/admin/analytics/web-traffic${queryString({ period })}`));
    const totals = asRecord(data.totals);
    const metadata = asRecord(data.metadata);
    return {
      series: asArray(data.series).map((value) => {
        const point = asRecord(value);
        return {
          period: asString(point.period),
          pageViews: asNumber(point.page_views),
          trackedVisitors: asNumber(point.tracked_visitors),
          sessions: asNumber(point.sessions),
          bounceRate: asOptionalNumber(point.bounce_rate),
        };
      }),
      totals: {
        pageViews: asNumber(totals.page_views),
        trackedVisitors: asNumber(totals.tracked_visitors),
        sessions: asNumber(totals.sessions),
        bounceRate: asOptionalNumber(totals.bounce_rate),
      },
      topPages: asArray(data.top_pages).map((value) => {
        const page = asRecord(value);
        return {
          path: asString(page.path, '/'),
          pageViews: asNumber(page.page_views),
          trackedVisitors: asNumber(page.tracked_visitors),
          sessions: asNumber(page.sessions),
        };
      }),
      metadata: {
        period: metadata.period === '24h' || metadata.period === '7d' ? metadata.period : '30d',
        granularity: metadata.granularity === 'hour' ? 'hour' : 'day',
        from: asOptionalString(metadata.from),
        to: asOptionalString(metadata.to),
        generatedAt: asOptionalString(metadata.generated_at),
        source: asString(metadata.source, 'web_analytics_events'),
        bounceRateDefinition: asString(metadata.bounce_rate_definition),
      },
    };
  },

  activeUsers: async (page = 1, perPage = 20) => {
    const path = `/admin/analytics/active-users${queryString({ page, per_page: perPage })}`;
    const data = asRecord(await dedupedApiGet(path));
    const metadata = asRecord(data.metadata);
    return {
      users: asArray(data.users).map(mapActiveUser),
      pagination: mapPagination(data.pagination),
      metadata: {
        generatedAt: asOptionalString(metadata.generated_at),
        windowMinutes: asNumber(metadata.window_minutes) || 15,
        definition: asString(metadata.definition),
      },
    };
  },

  heavyUsers: async (params: { page?: number; perPage?: number; period?: AdminHeavyUserPeriod } = {}) => {
    const path = `/admin/analytics/heavy-users${queryString({
      page: params.page ?? 1,
      per_page: params.perPage ?? 20,
      period: params.period ?? '7d',
    })}`;
    const data = asRecord(await dedupedApiGet(path));
    const metadata = asRecord(data.metadata);
    return {
      users: asArray(data.users).map(mapHeavyUser),
      pagination: mapPagination(data.pagination),
      metadata: {
        period: asString(metadata.period, '7d') as AdminHeavyUserPeriod,
        from: asOptionalString(metadata.from),
        to: asOptionalString(metadata.to),
        generatedAt: asOptionalString(metadata.generated_at),
        ranking: asString(metadata.ranking),
        usageScoreFormula: asString(metadata.usage_score_formula),
        apiActivityAvailable: asBoolean(metadata.api_activity_available),
        dataSources: asArray(metadata.data_sources).filter((value): value is string => typeof value === 'string'),
      },
    };
  },

  audits: async (filters: AdminAuditFilters = {}) => {
    const path = `/admin/audits${queryString({
      page: filters.page ?? 1,
      per_page: filters.perPage ?? 20,
      status: filters.status,
      user_id: filters.userId,
      search: filters.search?.trim() || undefined,
      created_from: filters.createdFrom,
      created_to: filters.createdTo,
    })}`;
    const data = asRecord(await dedupedApiGet(path));
    return {
      audits: asArray(data.audits).map(mapAudit),
      pagination: mapPagination(data.pagination),
    };
  },
};
