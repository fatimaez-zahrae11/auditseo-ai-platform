import React, { useState } from 'react';
import { Loader2, ShieldCheck } from 'lucide-react';
import { AppProvider, useApp } from './context/AppContext';
import { Sidebar } from './components/layout/Sidebar';
import { Topbar } from './components/layout/Topbar';
import { UserTopNavigation } from './components/layout/UserTopNavigation';
import { ErrorState } from './components/ui/ErrorState';
import { ToastContainer } from './components/ui/Toast';
import type { ViewType } from './types';
import { AnalyticsConsentBanner } from './components/analytics/AnalyticsConsentBanner';
import { PageViewTracker } from './components/analytics/PageViewTracker';

// Auth Views
import { LoginPage } from './pages/auth/LoginPage';
import { RegisterPage } from './pages/auth/RegisterPage';
import { EmailVerificationPage } from './pages/auth/EmailVerificationPage';

// User Views
import { UserDashboard } from './pages/user/UserDashboard';
import { UserHomePage } from './pages/user/UserHomePage';
import { MyAuditsPage } from './pages/user/MyAuditsPage';
import { AuditDetailPage } from './pages/user/AuditDetailPage';
import { AuditReportPreview } from './pages/user/AuditReportPreview';

// Admin Views
import { AdminDashboard } from './pages/admin/AdminDashboard';
import { UsersManagementPage } from './pages/admin/UsersManagementPage';
import { UserActivityPage } from './pages/admin/UserActivityPage';
import { AdminAuditsPage } from './pages/admin/AdminAuditsPage';
import { AdminRecommendationsPage } from './pages/admin/AdminRecommendationsPage';
import { ActiveUsersAnalyticsPage } from './pages/admin/ActiveUsersAnalyticsPage';
import { HeavyUsersAnalyticsPage } from './pages/admin/HeavyUsersAnalyticsPage';
import { SystemHealthPage } from './pages/admin/SystemHealthPage';
import { SystemLogsPage } from './pages/admin/SystemLogsPage';
import { AdminActionLogsPage } from './pages/admin/AdminActionLogsPage';
import { IpIntelligencePage } from './pages/admin/IpIntelligencePage';

const errorViews: ViewType[] = [
  'error-401',
  'error-403',
  'error-404',
  'error-409',
  'error-422',
  'error-429',
  'error-500',
  'error-502',
  'error-503',
  'account-disabled',
];

const adminViews: ViewType[] = [
  'admin-dashboard',
  'users-management',
  'user-activity',
  'admin-audits',
  'admin-recommendations',
  'active-users-analytics',
  'heavy-users-analytics',
  'system-health',
  'system-logs',
  'admin-action-logs',
  'ip-intelligence',
];

const AppContent: React.FC = () => {
  const { authLoading, currentUser, currentView, isAuthenticated } = useApp();
  const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);

  const isAdmin = currentUser?.role === 'admin';
  const isUserHome = !isAdmin && (currentView === 'user-home' || currentView === 'create-audit');
  const themeClass = isAdmin ? 'theme-admin' : 'theme-user';

  if (authLoading) {
    return (
      <div className="theme-user min-h-screen bg-gradient-to-br from-[var(--color-canvas)] via-[var(--color-secondary)] to-[var(--color-soft)] text-[var(--color-text)] flex items-center justify-center p-6">
        <div className="rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] px-8 py-7 text-center shadow-2xl">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--color-primary)] text-[var(--color-on-primary)]">
            <ShieldCheck className="h-5 w-5" />
          </div>
          <div className="mt-4 flex items-center justify-center gap-2 text-sm font-black">
            <Loader2 className="h-4 w-4 animate-spin text-[var(--color-cta)]" />
            Restoring secure session
          </div>
          <p className="mt-2 text-xs text-[var(--color-muted)]">AuditSEO AI Platform</p>
        </div>
      </div>
    );
  }

  // Handle Full-Screen Error State (e.g. 401, 403, 404, 409, 422, 429, 500, 502, 503, or account disabled)
  if (errorViews.includes(currentView)) {
    return (
      <div className={`${themeClass} min-h-screen bg-[var(--color-canvas)] text-[var(--color-text)] flex items-center justify-center p-4`}>
        <ErrorState errorType={currentView} />
        <ToastContainer />
      </div>
    );
  }

  // Handle Public Auth Views
  if (currentView === 'login') {
    return (
      <div className="theme-user min-h-screen bg-gradient-to-br from-[var(--color-canvas)] via-[var(--color-secondary)] to-[var(--color-soft)] text-[var(--color-text)] flex flex-col justify-center">
        <LoginPage />
        <ToastContainer />
      </div>
    );
  }

  if (currentView === 'register') {
    return (
      <div className="theme-user min-h-screen bg-gradient-to-br from-[var(--color-canvas)] via-[var(--color-secondary)] to-[var(--color-soft)] text-[var(--color-text)] flex flex-col justify-center">
        <RegisterPage />
        <ToastContainer />
      </div>
    );
  }

  if (currentView === 'email-verification') {
    return (
      <div className="theme-user min-h-screen bg-gradient-to-br from-[var(--color-canvas)] via-[var(--color-secondary)] to-[var(--color-soft)] text-[var(--color-text)] flex flex-col justify-center">
        <EmailVerificationPage />
        <ToastContainer />
      </div>
    );
  }

  // Protected route boundary: dashboard views require a verified bearer-token session.
  if (!isAuthenticated || !currentUser) {
    return (
      <div className="theme-user min-h-screen bg-[var(--color-canvas)] text-[var(--color-text)]">
        <LoginPage />
        <ToastContainer />
      </div>
    );
  }

  if (!isAdmin && adminViews.includes(currentView)) {
    return (
      <div className="theme-user min-h-screen bg-[var(--color-canvas)] text-[var(--color-text)] flex items-center justify-center p-4">
        <ErrorState errorType="error-403" />
        <ToastContainer />
      </div>
    );
  }

  // Render Core Application View Router
  const renderCurrentView = () => {
    switch (currentView) {
      // User Views
      case 'user-home':
        return <UserHomePage />;
      case 'user-dashboard':
        return <UserDashboard />;
      case 'create-audit':
        return <UserHomePage />;
      case 'my-audits':
        return <MyAuditsPage />;
      case 'audit-detail':
        return <AuditDetailPage />;
      case 'audit-report':
        return <AuditReportPreview />;

      // Admin Views
      case 'admin-dashboard':
        return <AdminDashboard />;
      case 'users-management':
        return <UsersManagementPage />;
      case 'user-activity':
        return <UserActivityPage />;
      case 'admin-audits':
        return <AdminAuditsPage />;
      case 'admin-recommendations':
        return <AdminRecommendationsPage />;
      case 'active-users-analytics':
        return <ActiveUsersAnalyticsPage />;
      case 'heavy-users-analytics':
        return <HeavyUsersAnalyticsPage />;
      case 'system-health':
        return <SystemHealthPage />;
      case 'system-logs':
        return <SystemLogsPage />;
      case 'admin-action-logs':
        return <AdminActionLogsPage />;
      case 'ip-intelligence':
        return <IpIntelligencePage />;

      default:
        return isAdmin ? <AdminDashboard /> : <UserHomePage />;
    }
  };

  return (
    <div
      id="app-root-layout"
      className={`${themeClass} flex h-screen w-full flex-col overflow-hidden bg-[var(--color-canvas)] text-[var(--color-text)] transition-colors duration-300 ${isAdmin ? 'md:flex-row' : ''}`}
    >
      {/* Desktop Sidebar (hidden on mobile) */}
      {isAdmin ? <div className="hidden h-screen shrink-0 md:block">
        <Sidebar />
      </div> : null}

      {/* Mobile Drawer Sidebar */}
      {isAdmin && isMobileSidebarOpen && (
        <div className="fixed inset-0 z-50 flex md:hidden">
          <div
            className="fixed inset-0 bg-[var(--color-overlay)] backdrop-blur-sm"
            onClick={() => setIsMobileSidebarOpen(false)}
          />
          <div className="relative z-10 h-screen w-72 max-w-full">
            <Sidebar onNavigate={() => setIsMobileSidebarOpen(false)} />
          </div>
        </div>
      )}

      {/* Main Content Area */}
      <div className="flex h-screen min-w-0 flex-1 flex-col overflow-hidden">
        {/* Top Header */}
        {isAdmin ? <Topbar onOpenMobileMenu={() => setIsMobileSidebarOpen((open) => !open)} /> : <UserTopNavigation />}

        {/* Dynamic Page Container */}
        <main
          id="main-viewport-content"
          className={`min-h-0 max-w-full flex-1 overflow-y-auto ${isAdmin ? 'p-4 sm:p-6 lg:p-8' : isUserHome ? 'user-home-viewport p-0' : 'px-4 py-5 sm:px-6 sm:py-6 lg:px-8 lg:py-7'}`}
        >
          {renderCurrentView()}
        </main>
      </div>

      {/* Floating System Notification Toast Container */}
      <ToastContainer />
    </div>
  );
};

export default function App() {
  return (
    <AppProvider>
      <PageViewTracker />
      <AppContent />
      <AnalyticsConsentBanner />
    </AppProvider>
  );
}
