import type { AdminUserActivity, AuditPagination, User } from '../types';
import { apiRequest } from './apiClient';

interface BackendAdminUser {
  id?: unknown;
  name?: unknown;
  email?: unknown;
  role?: unknown;
  is_active?: unknown;
  email_verified_at?: unknown;
  created_at?: unknown;
  audits_count?: unknown;
  completed_audits_count?: unknown;
  failed_audits_count?: unknown;
  recommendations_count?: unknown;
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

interface UserListResponse {
  users?: BackendAdminUser[];
  pagination?: BackendPagination;
}

interface UserMutationResponse {
  message: string;
  user: BackendAdminUser;
}

interface BackendActivityRoute {
  route?: unknown;
  method?: unknown;
  status_code?: unknown;
  created_at?: unknown;
}

interface UserActivityResponse {
  user?: { id?: unknown; email?: unknown };
  last_seen_at?: unknown;
  last_ip?: unknown;
  request_count_last_24h?: unknown;
  request_count_last_7d?: unknown;
  recent_routes?: BackendActivityRoute[];
  audits_count?: unknown;
  completed_audits_count?: unknown;
  failed_audits_count?: unknown;
  recommendations_count?: unknown;
}

export interface CreateAdminUserPayload {
  name: string;
  email: string;
  password: string;
}

export interface AdminUserFilters {
  page?: number;
  perPage?: number;
  search?: string;
  role?: 'user' | 'admin';
  status?: 'active' | 'inactive';
}

const numberValue = (value: unknown) => {
  const parsed = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

const stringValue = (value: unknown, fallback = '') => typeof value === 'string' ? value : fallback;
const optionalString = (value: unknown) => typeof value === 'string' && value ? value : null;

const mapUser = (user: BackendAdminUser): User => {
  const createdAt = stringValue(user.created_at, new Date(0).toISOString());
  return {
    id: String(user.id ?? ''),
    name: stringValue(user.name, 'Unnamed user'),
    email: stringValue(user.email),
    role: user.role === 'admin' ? 'admin' : 'user',
    status: user.is_active === false ? 'inactive' : 'active',
    emailVerification: user.email_verified_at ? 'verified' : 'unverified',
    createdAt,
    lastLoginAt: createdAt,
    auditsCount: numberValue(user.audits_count),
    completedAudits: numberValue(user.completed_audits_count),
    failedAudits: numberValue(user.failed_audits_count),
    recommendationsCount: numberValue(user.recommendations_count),
  };
};

const mapPagination = (pagination?: BackendPagination): AuditPagination => ({
  currentPage: numberValue(pagination?.current_page) || 1,
  lastPage: numberValue(pagination?.last_page) || 1,
  perPage: numberValue(pagination?.per_page) || 20,
  total: numberValue(pagination?.total),
  from: pagination?.from === null || pagination?.from === undefined ? null : numberValue(pagination.from),
  to: pagination?.to === null || pagination?.to === undefined ? null : numberValue(pagination.to),
  previousPageUrl: optionalString(pagination?.previous_page_url),
  nextPageUrl: optionalString(pagination?.next_page_url),
});

const mapActivity = (data: UserActivityResponse): AdminUserActivity => ({
  user: {
    id: String(data.user?.id ?? ''),
    email: stringValue(data.user?.email),
  },
  lastSeenAt: optionalString(data.last_seen_at),
  lastIp: optionalString(data.last_ip),
  requestCountLast24h: numberValue(data.request_count_last_24h),
  requestCountLast7d: numberValue(data.request_count_last_7d),
  recentRoutes: Array.isArray(data.recent_routes) ? data.recent_routes.map((route) => ({
    route: stringValue(route.route, 'Unknown route'),
    method: stringValue(route.method, '—'),
    statusCode: numberValue(route.status_code),
    createdAt: stringValue(route.created_at, new Date(0).toISOString()),
  })) : [],
  auditsCount: numberValue(data.audits_count),
  completedAuditsCount: numberValue(data.completed_audits_count),
  failedAuditsCount: numberValue(data.failed_audits_count),
  recommendationsCount: numberValue(data.recommendations_count),
});

const userListQuery = (filters: AdminUserFilters) => {
  const query = new URLSearchParams();
  query.set('page', String(filters.page ?? 1));
  query.set('per_page', String(filters.perPage ?? 20));
  if (filters.search?.trim()) query.set('search', filters.search.trim());
  if (filters.role) query.set('role', filters.role);
  if (filters.status) query.set('status', filters.status);
  return query.toString();
};

export const adminUserService = {
  list: async (filters: AdminUserFilters = {}) => {
    const data = await apiRequest<UserListResponse>(`/admin/users?${userListQuery(filters)}`, {}, { auth: 'required' });
    return {
      users: Array.isArray(data.users) ? data.users.map(mapUser) : [],
      pagination: mapPagination(data.pagination),
    };
  },

  create: async (payload: CreateAdminUserPayload) => {
    const data = await apiRequest<UserMutationResponse>('/admin/users', {
      method: 'POST',
      body: payload,
    }, { auth: 'required' });
    return { message: data.message, user: mapUser(data.user) };
  },

  deactivate: async (userId: string, blockedReason?: string) => {
    const data = await apiRequest<UserMutationResponse>(
      `/admin/users/${encodeURIComponent(userId)}/deactivate`,
      {
        method: 'PATCH',
        body: blockedReason ? { blocked_reason: blockedReason } : {},
      },
      { auth: 'required' },
    );
    return { message: data.message, user: mapUser(data.user) };
  },

  reactivate: async (userId: string) => {
    const data = await apiRequest<UserMutationResponse>(
      `/admin/users/${encodeURIComponent(userId)}/reactivate`,
      { method: 'PATCH' },
      { auth: 'required' },
    );
    return { message: data.message, user: mapUser(data.user) };
  },

  activity: async (userId: string) => {
    const data = await apiRequest<UserActivityResponse>(
      `/admin/users/${encodeURIComponent(userId)}/activity`,
      {},
      { auth: 'required' },
    );
    return mapActivity(data);
  },
};
