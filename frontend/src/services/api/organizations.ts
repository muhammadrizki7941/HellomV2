// Organizations, team and invitations.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest, buildQuery } from './client';

// ─── Types (OrganizationTeamController payloads) ───

export type OrganizationRef = { id: number; name: string; slug: string };

export type TeamMember = {
  id: number;
  name: string;
  email: string;
  role: string;
  joined_at: string | null;
};

export type TeamInvitation = {
  id: number;
  organization_id: number;
  email: string;
  role: string;
  status: string;
  expires_at: string | null;
  accepted_at: string | null;
  accepted_by_user_id: number | null;
  invited_by_user_id: number;
  created_at: string | null;
  updated_at: string | null;
  token?: string;
};

export type EmailDelivery = { sent: boolean; error: string | null; mailer?: string };

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
  return apiRequest<{ organization: OrganizationRef; requester_role: string; items: TeamMember[] }>('/organizations/current/team');
}

// NOTE: POST /team/invite adds an already-registered user directly (no token).
export function createOrganizationInvitation(payload: { email: string; role?: string; expires_in_days?: number }) {
  return apiRequest<{ organization_id: number; member: TeamMember | null }>('/organizations/current/team/invite', {
    method: 'POST',
    body: payload,
  });
}

export function getOrganizationInvitations(params?: { status?: string; email?: string; limit?: number; cursor?: number }) {
  return apiRequest<{ organization: OrganizationRef; items: TeamInvitation[]; pagination: Record<string, unknown> }>(`/organizations/current/team/invitations${buildQuery(params)}`);
}

export function resendOrganizationInvitation(invitationId: number) {
  return apiRequest<{ organization_id: number; invitation: TeamInvitation; email_delivery: EmailDelivery }>(`/organizations/current/team/invitations/${invitationId}/resend`, {
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
