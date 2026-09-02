import React, { useEffect, useRef, useState } from 'react';
import { Loader2, Search, Shield, UserPlus } from 'lucide-react';
import { ConfirmModal } from '../../components/ui/ConfirmModal';
import { LoadingState } from '../../components/ui/LoadingState';
import { Modal } from '../../components/ui/Modal';
import { RequestErrorState } from '../../components/ui/RequestErrorState';
import { StatusBadge } from '../../components/ui/StatusBadge';
import { useApp } from '../../context/AppContext';
import { adminUserService } from '../../services/adminUserService';
import { ApiError } from '../../services/apiClient';
import type { AuditPagination, User } from '../../types';
import { getPublicApiErrorMessage, getSafeValidationFieldMessage } from '../../utils/publicApiErrors';

interface CreateErrors {
  name?: string;
  email?: string;
  password?: string;
  confirmation?: string;
  form?: string;
}

type RoleFilter = 'all' | 'user' | 'admin';
type StatusFilter = 'all' | 'active' | 'inactive';

interface UserFilterForm {
  search: string;
  role: RoleFilter;
  status: StatusFilter;
}

const emptyFilters: UserFilterForm = { search: '', role: 'all', status: 'all' };

const emptyPagination: AuditPagination = {
  currentPage: 1,
  lastPage: 1,
  perPage: 20,
  total: 0,
  from: null,
  to: null,
  previousPageUrl: null,
  nextPageUrl: null,
};

export const UsersManagementPage: React.FC = () => {
  const { addToast, selectUser, setCurrentView } = useApp();
  const [users, setUsers] = useState<User[]>([]);
  const [pagination, setPagination] = useState<AuditPagination>(emptyPagination);
  const [page, setPage] = useState(1);
  const [refreshKey, setRefreshKey] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [listError, setListError] = useState('');
  const [filterForm, setFilterForm] = useState<UserFilterForm>(emptyFilters);
  const [filters, setFilters] = useState<UserFilterForm>(emptyFilters);
  const [deactivateTarget, setDeactivateTarget] = useState<User | null>(null);
  const [reactivateTarget, setReactivateTarget] = useState<User | null>(null);
  const [actionUserId, setActionUserId] = useState<string | null>(null);
  const actionInFlight = useRef(false);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [newName, setNewName] = useState('');
  const [newEmail, setNewEmail] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [createErrors, setCreateErrors] = useState<CreateErrors>({});
  const [isCreating, setIsCreating] = useState(false);

  useEffect(() => {
    let isActive = true;
    setIsLoading(true);
    setListError('');

    adminUserService.list({
      page,
      perPage: 20,
      search: filters.search || undefined,
      role: filters.role === 'all' ? undefined : filters.role,
      status: filters.status === 'all' ? undefined : filters.status,
    })
      .then((response) => {
        if (!isActive) return;
        setUsers(response.users);
        setPagination(response.pagination);
      })
      .catch((error: unknown) => {
        if (!isActive) return;
        if (error instanceof ApiError && error.status === 403) {
          if (error.message === 'Account disabled') setCurrentView('account-disabled');
          else setCurrentView('error-403');
          return;
        }
        setListError(getPublicApiErrorMessage(error, {
          fallback: 'The user directory could not be loaded. Please try again.',
          rateLimitMessage: 'User management requests are temporarily rate limited. Please wait and try again.',
        }));
      })
      .finally(() => {
        if (isActive) setIsLoading(false);
      });

    return () => { isActive = false; };
  }, [filters, page, refreshKey, setCurrentView]);

  const applyFilters = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setPage(1);
    setFilters({ ...filterForm, search: filterForm.search.trim() });
  };

  const clearFilters = () => {
    setFilterForm(emptyFilters);
    setFilters(emptyFilters);
    setPage(1);
  };

  const resetCreateForm = () => {
    setNewName('');
    setNewEmail('');
    setNewPassword('');
    setPasswordConfirmation('');
    setCreateErrors({});
  };

  const handleCreateUser = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const errors: CreateErrors = {};
    if (newName.trim().length < 2) errors.name = 'Enter the user’s full name.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(newEmail.trim())) errors.email = 'Enter a valid email address.';
    if (!/^(?=.*[A-Z])(?=.*\d).{8,}$/.test(newPassword)) errors.password = 'Minimum 8 characters, one uppercase letter, and one digit.';
    if (newPassword !== passwordConfirmation) errors.confirmation = 'Passwords do not match.';
    if (Object.keys(errors).length) {
      setCreateErrors(errors);
      return;
    }

    setIsCreating(true);
    setCreateErrors({});
    try {
      const response = await adminUserService.create({
        name: newName.trim(),
        email: newEmail.trim(),
        password: newPassword,
      });
      addToast({ title: 'Regular User Created', message: response.message, type: 'success' });
      resetCreateForm();
      setShowCreateModal(false);
      setPage(1);
      setRefreshKey((key) => key + 1);
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) {
        setShowCreateModal(false);
        setCurrentView(error.message === 'Account disabled' ? 'account-disabled' : 'error-403');
      } else if (error instanceof ApiError && error.status === 422) {
        setCreateErrors({
          name: getSafeValidationFieldMessage(error, 'name'),
          email: getSafeValidationFieldMessage(error, 'email'),
          password: getSafeValidationFieldMessage(error, 'password'),
          form: error.errors ? undefined : getPublicApiErrorMessage(error, {
            validationFallback: 'Check the submitted account information.',
          }),
        });
      } else {
        setCreateErrors({ form: error instanceof ApiError && error.status === 429 ? 'User creation is temporarily rate limited.' : 'The user could not be created. Please try again.' });
      }
    } finally {
      setIsCreating(false);
    }
  };

  const updateUserStatus = async (user: User, action: 'deactivate' | 'reactivate') => {
    if (actionInFlight.current) return;
    actionInFlight.current = true;
    setActionUserId(user.id);
    try {
      const response = action === 'deactivate'
        ? await adminUserService.deactivate(user.id, 'Deactivated via admin panel review')
        : await adminUserService.reactivate(user.id);
      addToast({ title: action === 'deactivate' ? 'User Deactivated' : 'User Reactivated', message: response.message, type: 'success' });
      setRefreshKey((key) => key + 1);
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) {
        setCurrentView(error.message === 'Account disabled' ? 'account-disabled' : 'error-403');
      }
      const message = error instanceof ApiError && error.status === 404
        ? 'User not found.'
        : error instanceof ApiError && error.status === 422
          ? getPublicApiErrorMessage(error, { validationFallback: 'Check the account update and try again.' })
          : error instanceof ApiError && error.status === 429
            ? 'This action is temporarily rate limited. Please wait and try again.'
            : 'The account status could not be updated. Please try again.';
      addToast({ title: 'Account Update Failed', message, type: 'error' });
      throw error;
    } finally {
      actionInFlight.current = false;
      setActionUserId(null);
    }
  };

  return (
    <div id="users-management-view" className="mx-auto max-w-7xl space-y-6 text-[var(--color-text)]">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div><h1 className="text-2xl font-black tracking-tight">Users Management</h1><p className="text-xs text-[var(--color-muted)]">Supervise real Laravel accounts, audit consumption, status flags, and activity summaries.</p></div><button id="admin-create-user-btn" onClick={() => setShowCreateModal(true)} className="inline-flex items-center gap-2 rounded-xl bg-[var(--color-primary)] px-4 py-2.5 text-xs font-bold text-[var(--color-on-primary)] shadow-md hover:bg-[var(--color-primary-hover)]"><UserPlus className="h-4 w-4" />Provision Regular User</button></div>

      <form onSubmit={applyFilters} className="flex flex-col gap-3 rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 shadow-md lg:flex-row lg:items-center lg:justify-between">
        <div className="w-full lg:w-80">
          <div className="relative"><Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-muted)]" /><input value={filterForm.search} onChange={(event) => setFilterForm((value) => ({ ...value, search: event.target.value }))} placeholder="Search all users by name or email" className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] py-2 pl-10 pr-4 text-xs outline-none focus:border-[var(--color-primary)]" /></div>
          <p className="mt-1.5 text-[10px] text-[var(--color-muted)]">Filters are applied by the backend to the complete user directory.</p>
        </div>
        <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto">
          <div className="flex items-center gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-1"><span className="px-2 text-[10px] font-bold uppercase text-[var(--color-muted)]">Role:</span>{(['all', 'user', 'admin'] as RoleFilter[]).map((role) => <button type="button" key={role} onClick={() => setFilterForm((value) => ({ ...value, role }))} className={`rounded-lg px-2.5 py-1 text-xs font-bold capitalize ${filterForm.role === role ? 'bg-[var(--color-primary)] text-[var(--color-on-primary)]' : 'text-[var(--color-muted)]'}`}>{role === 'all' ? 'All Roles' : role}</button>)}</div>
          <div className="flex items-center gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-1"><span className="px-2 text-[10px] font-bold uppercase text-[var(--color-muted)]">Status:</span>{(['all', 'active', 'inactive'] as StatusFilter[]).map((status) => <button type="button" key={status} onClick={() => setFilterForm((value) => ({ ...value, status }))} className={`rounded-lg px-2.5 py-1 text-xs font-bold capitalize ${filterForm.status === status ? 'bg-[var(--color-primary)] text-[var(--color-on-primary)]' : 'text-[var(--color-muted)]'}`}>{status === 'all' ? 'All Status' : status}</button>)}</div>
          <button type="button" onClick={clearFilters} disabled={isLoading} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 text-xs font-bold disabled:opacity-50">Clear</button>
          <button type="submit" disabled={isLoading} className="rounded-lg bg-[var(--color-primary)] px-3 py-1.5 text-xs font-bold text-[var(--color-on-primary)] disabled:opacity-50">Apply filters</button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Loading the user directory..." /> : listError ? <RequestErrorState title="User directory unavailable" message={listError} onRetry={() => setRefreshKey((key) => key + 1)} /> : (
        <section className="overflow-hidden rounded-3xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl">
          <div className="overflow-x-auto">
            <table id="admin-users-table" className="w-full min-w-[1080px] border-collapse text-left text-xs">
              <thead><tr className="border-b border-[var(--color-border)] bg-[var(--color-canvas)]/70 text-[11px] font-bold uppercase tracking-wider text-[var(--color-muted)]"><th className="py-3.5 pl-6 pr-4">User Profile</th><th className="px-3 py-3.5">User ID</th><th className="px-3 py-3.5">Role</th><th className="px-3 py-3.5">Status</th><th className="px-3 py-3.5">Email</th><th className="px-3 py-3.5">Created</th><th className="px-3 py-3.5 text-center">Audits</th><th className="px-3 py-3.5 text-center">Completed</th><th className="px-3 py-3.5 text-center">Failed</th><th className="px-3 py-3.5 text-center">AI Recs</th><th className="py-3.5 pl-4 pr-6 text-right">Actions</th></tr></thead>
              <tbody className="divide-y divide-[var(--color-border)]/60">{users.length ? users.map((user) => <tr key={user.id} className="transition-colors hover:bg-[var(--color-surface-muted)]/40"><td className="py-4 pl-6 pr-4"><div className="flex items-center gap-3"><div className="flex h-9 w-9 items-center justify-center rounded-xl bg-[var(--color-primary)]/15 font-black text-[var(--color-primary)]">{user.name.slice(0, 1).toUpperCase()}</div><div><div className="flex items-center gap-1.5 font-bold"><span>{user.name}</span>{user.role === 'admin' ? <Shield className="h-3 w-3 text-rose-400" /> : null}</div><div className="max-w-xs truncate font-mono text-[11px] text-[var(--color-muted)]">{user.email}</div></div></div></td><td className="whitespace-nowrap px-3 py-4"><span className="inline-flex rounded-md border border-[#FF8A00]/25 bg-[#FF8A00]/10 px-2 py-1 font-mono text-[10px] font-black text-[#FF8A00]">#{user.id}</span></td><td className="px-3 py-4"><span className={`rounded border px-2 py-0.5 text-[10px] font-bold uppercase ${user.role === 'admin' ? 'border-rose-800 bg-rose-950 text-rose-300' : 'border-[var(--color-border)] bg-[var(--color-canvas)] text-[var(--color-primary)]'}`}>{user.role}</span></td><td className="px-3 py-4"><StatusBadge status={user.status} size="sm" /></td><td className="px-3 py-4"><StatusBadge status={user.emailVerification} size="sm" /></td><td className="whitespace-nowrap px-3 py-4 text-[var(--color-muted)]">{user.createdAt ? new Date(user.createdAt).toLocaleDateString() : 'Not available'}</td><td className="px-3 py-4 text-center font-bold">{user.auditsCount}</td><td className="px-3 py-4 text-center font-bold text-[var(--color-primary)]">{user.completedAudits}</td><td className="px-3 py-4 text-center font-bold text-rose-400">{user.failedAudits}</td><td className="px-3 py-4 text-center font-bold text-[var(--color-muted)]">{user.recommendationsCount}</td><td className="py-4 pl-4 pr-6 text-right"><div className="flex justify-end gap-1.5"><button onClick={() => selectUser(user.id)} className="rounded-lg px-2.5 py-1 text-xs font-bold text-[var(--color-primary)] hover:bg-[var(--color-surface-muted)]">Activity</button>{user.status === 'active' ? <button disabled={actionUserId === user.id} onClick={() => setDeactivateTarget(user)} className="rounded-lg border border-[var(--color-warning-border)] px-2.5 py-1 text-xs font-bold text-[var(--color-warning-text)] disabled:opacity-40">Deactivate</button> : <button disabled={actionUserId === user.id} onClick={() => setReactivateTarget(user)} className="rounded-lg border border-[var(--color-success-border)] px-2.5 py-1 text-xs font-bold text-[var(--color-success-text)] disabled:opacity-40">Reactivate</button>}</div></td></tr>) : <tr><td colSpan={11} className="py-14 text-center text-[var(--color-muted)]">{filters.search || filters.role !== 'all' || filters.status !== 'all' ? 'No users match the selected backend filters.' : 'No users exist in the Laravel user directory.'}</td></tr>}</tbody>
            </table>
          </div>
          <div className="flex flex-col items-center justify-between gap-3 border-t border-[var(--color-border)] bg-[var(--color-canvas)]/50 p-4 text-xs text-[var(--color-muted)] sm:flex-row"><span>Showing <strong className="text-[var(--color-text)]">{pagination.from ?? 0}–{pagination.to ?? 0}</strong> of <strong className="text-[var(--color-text)]">{pagination.total}</strong> accounts</span><div className="flex items-center gap-2"><button disabled={!pagination.previousPageUrl} onClick={() => setPage((current) => Math.max(1, current - 1))} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 font-bold disabled:opacity-35">Previous</button><span>Page {pagination.currentPage} of {pagination.lastPage}</span><button disabled={!pagination.nextPageUrl} onClick={() => setPage((current) => current + 1)} className="rounded-lg border border-[var(--color-border)] px-3 py-1.5 font-bold disabled:opacity-35">Next</button></div></div>
        </section>
      )}

      {deactivateTarget ? <ConfirmModal isOpen onClose={() => setDeactivateTarget(null)} onConfirm={() => updateUserStatus(deactivateTarget, 'deactivate')} isLoading={actionUserId === deactivateTarget.id} title="Confirm Account Deactivation" message={`Deactivate ${deactivateTarget.name} (${deactivateTarget.email})? Existing bearer tokens will be revoked by the backend.`} confirmLabel="Deactivate User" variant="danger" /> : null}
      {reactivateTarget ? <ConfirmModal isOpen onClose={() => setReactivateTarget(null)} onConfirm={() => updateUserStatus(reactivateTarget, 'reactivate')} isLoading={actionUserId === reactivateTarget.id} title="Reactivate User Account" message={`Restore active access for ${reactivateTarget.name} (${reactivateTarget.email})?`} confirmLabel="Reactivate Account" variant="primary" /> : null}

      <Modal isOpen={showCreateModal} onClose={() => { if (!isCreating) { setShowCreateModal(false); resetCreateForm(); } }} title="Provision Regular User Account" subtitle="Creates an active, unverified regular account and sends verification email">
        <form onSubmit={(event) => void handleCreateUser(event)} className="space-y-4 text-[var(--color-text)]" noValidate>
          <div className="flex items-start gap-2 rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] p-3 text-xs text-[var(--color-muted)]"><Shield className="mt-0.5 h-4 w-4 shrink-0 text-[var(--color-primary)]" /><span><strong className="text-[var(--color-text)]">Security rule:</strong> Only name, email, and password are sent. No role field is submitted and this form cannot create administrators.</span></div>
          {createErrors.form ? <p className="rounded-xl border border-[var(--color-danger-border)] bg-[var(--color-danger-bg)] p-3 text-xs font-bold text-[var(--color-danger-text)]" role="alert">{createErrors.form}</p> : null}
          <div><label className="mb-1 block text-xs font-bold uppercase tracking-wider text-[var(--color-muted)]">Full Name</label><input value={newName} onChange={(event) => { setNewName(event.target.value); setCreateErrors((current) => ({ ...current, name: undefined, form: undefined })); }} disabled={isCreating} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-sm outline-none focus:border-[var(--color-primary)]" />{createErrors.name ? <p className="mt-1 text-[11px] font-bold text-[var(--color-danger-text)]">{createErrors.name}</p> : null}</div>
          <div><label className="mb-1 block text-xs font-bold uppercase tracking-wider text-[var(--color-muted)]">Corporate Email Address</label><input type="email" value={newEmail} onChange={(event) => { setNewEmail(event.target.value); setCreateErrors((current) => ({ ...current, email: undefined, form: undefined })); }} disabled={isCreating} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-sm outline-none focus:border-[var(--color-primary)]" />{createErrors.email ? <p className="mt-1 text-[11px] font-bold text-[var(--color-danger-text)]">{createErrors.email}</p> : null}</div>
          <div><label className="mb-1 block text-xs font-bold uppercase tracking-wider text-[var(--color-muted)]">Temporary Password</label><input type="password" autoComplete="new-password" value={newPassword} onChange={(event) => { setNewPassword(event.target.value); setCreateErrors((current) => ({ ...current, password: undefined, form: undefined })); }} disabled={isCreating} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-sm outline-none focus:border-[var(--color-primary)]" /><p className={`mt-1 text-[11px] ${createErrors.password ? 'font-bold text-[var(--color-danger-text)]' : 'text-[var(--color-muted)]'}`}>{createErrors.password || 'Minimum 8 characters, one uppercase letter, and one digit.'}</p></div>
          <div><label className="mb-1 block text-xs font-bold uppercase tracking-wider text-[var(--color-muted)]">Confirm Password</label><input type="password" autoComplete="new-password" value={passwordConfirmation} onChange={(event) => { setPasswordConfirmation(event.target.value); setCreateErrors((current) => ({ ...current, confirmation: undefined, form: undefined })); }} disabled={isCreating} className="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-canvas)] px-3 py-2 text-sm outline-none focus:border-[var(--color-primary)]" />{createErrors.confirmation ? <p className="mt-1 text-[11px] font-bold text-[var(--color-danger-text)]">{createErrors.confirmation}</p> : null}</div>
          <div className="flex justify-end gap-2 border-t border-[var(--color-border)] pt-3"><button type="button" disabled={isCreating} onClick={() => { setShowCreateModal(false); resetCreateForm(); }} className="rounded-xl px-4 py-2 text-xs font-semibold text-[var(--color-muted)] disabled:opacity-40">Cancel</button><button type="submit" disabled={isCreating} className="inline-flex items-center gap-2 rounded-xl bg-[var(--color-primary)] px-5 py-2 text-xs font-bold text-[var(--color-on-primary)] disabled:opacity-50">{isCreating ? <Loader2 className="h-4 w-4 animate-spin" /> : null}{isCreating ? 'Creating User...' : 'Create Regular User'}</button></div>
        </form>
      </Modal>
    </div>
  );
};
