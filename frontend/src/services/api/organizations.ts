// Organizations, team and invitations.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest } from './client';

// ─── Organizations ───

export function getOrganizations() {
  return apiRequest<Array<Record<string, unknown>>>('/organizations');
}

export function getCurrentOrganization() {
  return apiRequest<Record<string, unknown> | null>('/organizations/current');
}

export function switchOrganization(payload: { organization_id: number }) {
  return apiRequest<Record<string, unknown>>('/organizations/switch', {
    method: 'POST',
    body: payload,
  });
}

export function getOrganizationTeam() {
  return apiRequest<Record<string, unknown>>('/organizations/current/team');
}

export function createOrganizationInvitation(payload: { email: string; role?: string }) {
  return apiRequest<Record<string, unknown>>('/organizations/current/team/invite', {
    method: 'POST',
    body: payload,
  });
}

export function getOrganizationInvitations() {
  return apiRequest<Record<string, unknown>>('/organizations/current/team/invitations');
}

export function resendOrganizationInvitation(invitationId: number) {
  return apiRequest<Record<string, unknown>>(`/organizations/current/team/invitations/${invitationId}/resend`, {
    method: 'POST',
    body: {},
  });
}

export function revokeOrganizationInvitation(invitationId: number) {
  return apiRequest<Record<string, unknown>>(`/organizations/current/team/invitations/${invitationId}`, {
    method: 'DELETE',
  });
}

export function acceptOrganizationInvitation(payload: { token: string }) {
  return apiRequest<Record<string, unknown>>('/organizations/current/team/invitations/accept', {
    method: 'POST',
    body: payload,
  });
}

export function inviteOrganizationMember(payload: { email: string; role?: string }) {
  return createOrganizationInvitation(payload);
}

export function removeOrganizationMember(userId: number) {
  return apiRequest<Record<string, unknown>>(`/organizations/current/team/${userId}`, {
    method: 'DELETE',
  });
}

export function getOrganizationDetail(organizationId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/organizations/${organizationId}`);
}

export function updateOrganizationOutletLimit(organizationId: number, maxOutletsOverride: number | null) {
  return apiRequest<Record<string, unknown>>(`/admin/organizations/${organizationId}/outlet-limit`, {
    method: 'PATCH',
    body: { max_outlets_override: maxOutletsOverride },
  });
}
