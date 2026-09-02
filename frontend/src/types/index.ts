export type UserRole = 'user' | 'admin';

export type UserStatus = 'active' | 'inactive' | 'suspended';

export type EmailVerificationStatus = 'verified' | 'unverified';

export type AuditStatus = 'pending' | 'running' | 'completed' | 'failed';

export type IssueSeverity = 'critical' | 'high' | 'medium' | 'low' | 'info';

export type IssueCategory = 'technical' | 'content' | 'links' | 'performance';

export interface User {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  status: UserStatus;
  emailVerification: EmailVerificationStatus;
  avatarUrl?: string;
  createdAt: string;
  lastLoginAt: string;
  auditsCount: number;
  completedAudits: number;
  failedAudits: number;
  recommendationsCount: number;
}

export interface SeoIssue {
  id: string;
  title: string;
  category: IssueCategory;
  severity: IssueSeverity;
  description: string;
  recommendation: string;
  impactScore: number;
  location?: string;
  codeSnippet?: string;
}

export interface SeoAudit {
  id: string;
  userId: string;
  userEmail: string;
  domain: string;
  requestedUrl: string;
  finalUrl: string;
  status: AuditStatus;
  globalScore: number;
  technicalScore: number;
  contentScore: number;
  linksScore: number;
  performanceScore: number;
  createdAt: string;
  completedAt?: string;
  issues: SeoIssue[];
  aiRecommendation?: AiRecommendation;
  errorMessage?: string;
  crawlDurationMs?: number;
  pageWeightKb?: number;
  requestsCount?: number;
  startedAt?: string;
  failedAt?: string;
  failureMessage?: string;
  rawData?: Record<string, unknown>;
}

export interface AuditPagination {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  from: number | null;
  to: number | null;
  previousPageUrl: string | null;
  nextPageUrl: string | null;
}

export interface UserDashboardData {
  totalAudits: number;
  completedAudits: number;
  pendingAudits: number;
  runningAudits: number;
  failedAudits: number;
  averageGlobalScore: number;
  totalIssues: number;
  totalAiRecommendations: number;
  latestAudit: SeoAudit | null;
  latestCompletedAudit: SeoAudit | null;
}

export interface AiRecommendation {
  id: string;
  auditId: string;
  userId: string;
  userEmail: string;
  requestedUrl: string;
  finalUrl: string;
  generatedText: string;
  createdAt: string;
  modelUsed: string;
  tokensUsed: number;
  priorityActions: {
    priority: 'High' | 'Medium' | 'Low';
    title: string;
    description: string;
    expectedImpact: string;
  }[];
}

export interface UserAiRecommendation {
  id: string;
  auditId: string;
  generatedText: string;
  createdAt: string;
  updatedAt?: string;
}

export interface RecommendationPagination {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  from: number | null;
  to: number | null;
  previousPageUrl: string | null;
  nextPageUrl: string | null;
}

export interface AdminUserActivityRoute {
  route: string;
  method: string;
  statusCode: number;
  createdAt: string;
}

export interface AdminUserActivity {
  user: {
    id: string;
    email: string;
  };
  lastSeenAt: string | null;
  lastIp: string | null;
  requestCountLast24h: number;
  requestCountLast7d: number;
  recentRoutes: AdminUserActivityRoute[];
  auditsCount: number;
  completedAuditsCount: number;
  failedAuditsCount: number;
  recommendationsCount: number;
}

export interface AdminAnalyticsOverview {
  totalUsers: number;
  activeUsers: number;
  inactiveUsers: number;
  adminUsers: number;
  totalAudits: number;
  pendingAudits: number;
  runningAudits: number;
  completedAudits: number;
  failedAudits: number;
  totalRecommendations: number;
  requestsLast24h: number;
  requestsLast7d: number;
  generatedAt: string | null;
  activeUsersWindowMinutes: number;
  activeUsersDefinition: string;
}

export type AdminTrafficPeriod = '24h' | '7d' | '30d';

export type AdminTrafficGranularity = 'hour' | 'day';

export interface AdminTrafficPoint {
  period: string;
  requests: number;
  audits: number;
  recommendations: number;
  httpErrors: number;
}

export interface AdminTrafficAnalytics {
  series: AdminTrafficPoint[];
  totals: {
    requests: number;
    audits: number;
    recommendations: number;
    httpErrors: number;
  };
  metadata: {
    period: AdminTrafficPeriod;
    granularity: AdminTrafficGranularity;
    from: string | null;
    to: string | null;
    generatedAt: string | null;
  };
}

export interface AdminWebTrafficPoint {
  period: string;
  pageViews: number;
  trackedVisitors: number;
  sessions: number;
  bounceRate: number | null;
}

export interface AdminWebTrafficTopPage {
  path: string;
  pageViews: number;
  trackedVisitors: number;
  sessions: number;
}

export interface AdminWebTrafficAnalytics {
  series: AdminWebTrafficPoint[];
  totals: {
    pageViews: number;
    trackedVisitors: number;
    sessions: number;
    bounceRate: number | null;
  };
  topPages: AdminWebTrafficTopPage[];
  metadata: {
    period: AdminTrafficPeriod;
    granularity: AdminTrafficGranularity;
    from: string | null;
    to: string | null;
    generatedAt: string | null;
    source: string;
    bounceRateDefinition: string;
  };
}

export type AdminIpRiskLevel = 'critical' | 'high' | 'medium' | 'low';

export interface AdminIpIntelligenceSummary {
  critical: number;
  high: number;
  medium: number;
  low: number;
  uniqueIps: number;
  countriesCount: number;
  requestsCount: number;
  errorsCount: number;
}

export interface AdminIpHeatmapAddress {
  ipMasked: string;
  label: string;
  requestCount: number;
  errorCount: number;
  riskScore: number;
  riskLevel: AdminIpRiskLevel;
}

export interface AdminIpMapPoint {
  countryCode: string | null;
  countryName: string;
  city: string | null;
  latitude: number;
  longitude: number;
  requestCount: number;
  errorCount: number;
  riskLevel: AdminIpRiskLevel;
}

export interface AdminIpExposure {
  title: string;
  ipMasked: string;
  riskLevel: AdminIpRiskLevel;
  reason: string;
  requestCount: number;
  lastSeenAt: string | null;
}

export interface AdminIpIntelligenceResult {
  ipMasked: string;
  countryCode: string | null;
  countryName: string;
  region: string | null;
  city: string | null;
  latitude: number | null;
  longitude: number | null;
  requestCount: number;
  errorCount: number;
  status401Count: number;
  status403Count: number;
  status404Count: number;
  status429Count: number;
  status5xxCount: number;
  distinctRoutesCount: number;
  distinctUsersCount: number;
  users: { id: string; email: string }[];
  riskLevel: AdminIpRiskLevel;
  riskScore: number;
  riskReason: string;
  lastSeenAt: string | null;
}

export interface AdminIpIntelligenceData {
  summary: AdminIpIntelligenceSummary;
  topAddressesHeatmap: AdminIpHeatmapAddress[];
  mapPoints: AdminIpMapPoint[];
  externalExposure: AdminIpExposure[];
  results: AdminIpIntelligenceResult[];
  pagination: AuditPagination;
  metadata: {
    period: AdminTrafficPeriod;
    from: string | null;
    to: string | null;
    generatedAt: string | null;
    ipDisplay: string;
    geolocation: string;
  };
}

export interface AdminActiveUser {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  isActive: boolean;
  lastSeenAt: string | null;
  lastIp: string | null;
  requestCountLast15m: number;
  requestCountLast24h: number;
}

export interface AdminHeavyUser {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  isActive: boolean;
  requestsCount: number;
  errorRequestsCount: number;
  auditsCount: number;
  completedAuditsCount: number;
  failedAuditsCount: number;
  recommendationsCount: number;
  lastSeenAt: string | null;
  usageScore: number;
}

export type AdminHeavyUserPeriod = '24h' | '7d' | '30d';

export interface AdminAuditSummary {
  id: string;
  status: AuditStatus;
  requestedUrl: string;
  finalUrl: string | null;
  globalScore: number | null;
  technicalScore: number | null;
  contentScore: number | null;
  linksScore: number | null;
  performanceScore: number | null;
  createdAt: string | null;
  updatedAt: string | null;
  completedAt: string | null;
  failedAt: string | null;
  domain: {
    name: string;
    url: string;
  } | null;
  user: {
    id: string;
    email: string;
  } | null;
}

export interface AdminRecommendationSummary {
  id: string;
  auditId: string;
  user: { id: string; email: string } | null;
  audit: { requestedUrl: string; finalUrl: string | null } | null;
  generatedTextPreview: string;
  createdAt: string | null;
  updatedAt: string | null;
}

export interface AdminSystemLogsData {
  lines: string[];
  count: number;
  generatedAt: string | null;
  note: string;
}

export interface AdminSystemHealthDetailed {
  appEnvironment: string;
  debugEnabled: boolean;
  databaseStatus: string;
  redisStatus: string;
  cacheStatus: string;
  queueConnection: string;
  cacheDriver: string;
  stalePendingAudits: number | null;
  staleRunningAudits: number | null;
  recentFailedAudits: number | null;
  recentFailedJobs: number | null;
  accessLogsLast24h: number | null;
  generatedAt: string | null;
}

export interface AdminActionLogRecord {
  id: string;
  actorUserId: string | null;
  actorName: string;
  actorEmail: string | null;
  actorRole: 'admin' | 'user' | 'system';
  action: string;
  entityType: string | null;
  entityId: string | null;
  status: 'success' | 'failure' | null;
  details: string | null;
  createdAt: string | null;
}

export interface SystemHealthData {
  appEnvironment: 'production' | 'staging' | 'development';
  debugEnabled: boolean;
  databaseStatus: 'healthy' | 'degraded' | 'unhealthy';
  databaseLatencyMs: number;
  redisStatus: 'healthy' | 'degraded' | 'unhealthy';
  redisLatencyMs: number;
  cacheStatus: 'healthy' | 'warning' | 'error';
  queueConnection: 'connected' | 'reconnecting' | 'disconnected';
  cacheDriver: 'redis' | 'memcached' | 'array';
  stalePendingAudits: number;
  staleRunningAudits: number;
  recentFailedAudits: number;
  recentFailedJobs: number;
  accessLogsLast24h: number;
  cpuUsagePct: number;
  memoryUsagePct: number;
  uptimeSeconds: number;
}

export interface SystemLogEntry {
  id: string;
  timestamp: string;
  level: 'INFO' | 'WARN' | 'ERROR' | 'SECURITY';
  service: string;
  message: string;
  context?: string;
  traceId?: string;
}

export interface AdminActionLog {
  id: string;
  adminEmail: string;
  action: 'USER_DEACTIVATE' | 'USER_REACTIVATE' | 'USER_CREATE' | 'AUDIT_RERUN' | 'AUDIT_DELETE' | 'SYSTEM_CONFIG_UPDATE' | 'CACHE_FLUSH' | 'QUEUE_RESTART';
  targetType: 'user' | 'audit' | 'recommendation' | 'system' | 'cache';
  targetId: string;
  metadataPreview: string;
  ipAddress: string;
  createdAt: string;
}

export interface UserActivityRecord {
  id: string;
  userId: string;
  userEmail: string;
  action: string;
  ipAddress: string;
  userAgent: string;
  timestamp: string;
  details: string;
}

export type ViewType =
  // User views
  | 'user-home'
  | 'user-dashboard'
  | 'create-audit'
  | 'my-audits'
  | 'audit-detail'
  | 'audit-report'
  // Auth views
  | 'login'
  | 'register'
  | 'email-verification'
  // Admin views
  | 'admin-dashboard'
  | 'users-management'
  | 'user-activity'
  | 'admin-audits'
  | 'admin-recommendations'
  | 'active-users-analytics'
  | 'heavy-users-analytics'
  | 'system-health'
  | 'system-logs'
  | 'admin-action-logs'
  | 'ip-intelligence'
  // Error test views
  | 'error-401'
  | 'error-403'
  | 'error-404'
  | 'error-409'
  | 'error-422'
  | 'error-429'
  | 'error-500'
  | 'error-502'
  | 'error-503'
  | 'account-disabled';
