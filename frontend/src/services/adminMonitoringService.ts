import type {
  AdminActionLogRecord,
  AdminRecommendationSummary,
  AdminSystemHealthDetailed,
  AdminSystemLogsData,
  AuditPagination,
} from '../types';
import { apiRequest, getAuthGeneration, subscribeToAuthState } from './apiClient';

type UnknownRecord = Record<string, unknown>;
const asRecord = (value: unknown): UnknownRecord => typeof value === 'object' && value !== null ? value as UnknownRecord : {};
const asArray = (value: unknown): unknown[] => Array.isArray(value) ? value : [];
const asString = (value: unknown, fallback = '') => typeof value === 'string' ? value : fallback;
const optionalString = (value: unknown) => typeof value === 'string' && value ? value : null;
const asNumber = (value: unknown) => {
  const number = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(number) ? number : 0;
};
const optionalNumber = (value: unknown) => value === null || value === undefined ? null : asNumber(value);

const pagination = (value: unknown): AuditPagination => {
  const data = asRecord(value);
  return {
    currentPage: asNumber(data.current_page) || 1,
    lastPage: asNumber(data.last_page) || 1,
    perPage: asNumber(data.per_page) || 20,
    total: asNumber(data.total),
    from: data.from === null || data.from === undefined ? null : asNumber(data.from),
    to: data.to === null || data.to === undefined ? null : asNumber(data.to),
    previousPageUrl: optionalString(data.previous_page_url),
    nextPageUrl: optionalString(data.next_page_url),
  };
};

const queryString = (params: Record<string, string | number | undefined>) => {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value));
  });
  const value = query.toString();
  return value ? `?${value}` : '';
};

const inFlightGetRequests = new Map<string, Promise<unknown>>();

subscribeToAuthState(() => inFlightGetRequests.clear());

const dedupedApiGet = (path: string) => {
  const requestKey = `${getAuthGeneration()}:${path}`;
  const inFlightRequest = inFlightGetRequests.get(requestKey);
  if (inFlightRequest) return inFlightRequest;

  const request = apiRequest<unknown>(path, {}, { auth: 'required' }).finally(() => {
    if (inFlightGetRequests.get(requestKey) === request) inFlightGetRequests.delete(requestKey);
  });
  inFlightGetRequests.set(requestKey, request);
  return request;
};

const mapRecommendation = (value: unknown): AdminRecommendationSummary => {
  const item = asRecord(value);
  const user = asRecord(item.user);
  const audit = asRecord(item.audit);
  return {
    id: String(item.id ?? ''),
    auditId: String(item.audit_id ?? ''),
    user: Object.keys(user).length ? { id: String(user.id ?? ''), email: asString(user.email) } : null,
    audit: Object.keys(audit).length ? { requestedUrl: asString(audit.requested_url), finalUrl: optionalString(audit.final_url) } : null,
    generatedTextPreview: asString(item.generated_text_preview),
    createdAt: optionalString(item.created_at),
    updatedAt: optionalString(item.updated_at),
  };
};

const mapActionLog = (value: unknown): AdminActionLogRecord => {
  const item = asRecord(value);
  const actor = asRecord(item.actor);
  const actorRole = actor.role === 'admin' || actor.role === 'user' ? actor.role : 'system';
  return {
    id: String(item.id ?? ''),
    actorUserId: actor.id === null || actor.id === undefined ? null : String(actor.id),
    actorName: asString(actor.name, 'System'),
    actorEmail: optionalString(actor.email),
    actorRole,
    action: asString(item.action, 'UNKNOWN_ACTION'),
    entityType: optionalString(item.entity_type),
    entityId: item.entity_id === null || item.entity_id === undefined ? null : String(item.entity_id),
    status: item.status === 'success' || item.status === 'failure' ? item.status : null,
    details: optionalString(item.metadata_summary),
    createdAt: optionalString(item.created_at),
  };
};

export interface RecommendationFilters {
  page?: number; perPage?: number; userId?: string; auditId?: string; search?: string; createdFrom?: string; createdTo?: string;
}
export interface ActionLogFilters {
  page?: number;
  perPage?: number;
  role?: 'admin' | 'user' | 'system';
  actorUserId?: number;
  search?: string;
  action?: string;
  entityType?: string;
  status?: 'success' | 'failure';
  dateFrom?: string;
  dateTo?: string;
}

export const adminMonitoringService = {
  recommendations: async (filters: RecommendationFilters = {}) => {
    const data = asRecord(await dedupedApiGet(`/admin/recommendations${queryString({
      page: filters.page ?? 1, per_page: filters.perPage ?? 20, user_id: filters.userId,
      audit_id: filters.auditId, search: filters.search?.trim() || undefined,
      created_from: filters.createdFrom, created_to: filters.createdTo,
    })}`));
    return { recommendations: asArray(data.recommendations).map(mapRecommendation), pagination: pagination(data.pagination) };
  },

  systemLogs: async (lines = 100): Promise<AdminSystemLogsData> => {
    const safeLines = Math.min(200, Math.max(1, Math.trunc(lines)));
    const data = asRecord(await dedupedApiGet(`/admin/system/logs?lines=${safeLines}`));
    return {
      lines: asArray(data.lines).filter((line): line is string => typeof line === 'string'),
      count: asNumber(data.count), generatedAt: optionalString(data.generated_at), note: asString(data.note),
    };
  },

  systemHealth: async (): Promise<AdminSystemHealthDetailed> => {
    const data = asRecord(await dedupedApiGet('/admin/system/health-detailed'));
    return {
      appEnvironment: asString(data.app_env, 'unknown'), debugEnabled: data.debug_enabled === true,
      databaseStatus: asString(data.database_status, 'unknown'), redisStatus: asString(data.redis_status, 'unknown'),
      cacheStatus: asString(data.cache_status, 'unknown'), queueConnection: asString(data.queue_connection, 'unknown'),
      cacheDriver: asString(data.cache_driver, 'unknown'), stalePendingAudits: optionalNumber(data.stale_pending_audits),
      staleRunningAudits: optionalNumber(data.stale_running_audits), recentFailedAudits: optionalNumber(data.recent_failed_audits),
      recentFailedJobs: optionalNumber(data.recent_failed_jobs), accessLogsLast24h: optionalNumber(data.access_logs_last_24h),
      generatedAt: optionalString(data.generated_at),
    };
  },

  actionLogs: async (filters: ActionLogFilters = {}) => {
    const data = asRecord(await dedupedApiGet(`/admin/action-logs${queryString({
      page: filters.page ?? 1, per_page: filters.perPage ?? 20, role: filters.role,
      actor_user_id: filters.actorUserId, q: filters.search?.trim() || undefined,
      action: filters.action?.trim() || undefined, entity_type: filters.entityType?.trim() || undefined,
      status: filters.status, date_from: filters.dateFrom, date_to: filters.dateTo,
    })}`));
    return { actionLogs: asArray(data.data).map(mapActionLog), pagination: pagination(data.meta) };
  },
};
