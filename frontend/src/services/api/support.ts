import { apiRequest, apiRequestBlob, buildQuery } from './client';

export type SupportStatus = 'new' | 'ai_replied' | 'waiting_agent' | 'in_progress' | 'waiting_user' | 'resolved' | 'closed';
export type SupportPriority = 'low' | 'normal' | 'high' | 'urgent';
export type SupportCategory = { id: number; slug: string; name: string; default_priority: SupportPriority; is_active: boolean; sort_order: number };
export type SupportAttachment = { id: number; name: string; mime_type: string; size_bytes: number; url: string };
export type SupportMessage = {
  id: number;
  actor_type: 'user' | 'ai' | 'agent' | 'system';
  sender?: { id: number; name: string } | null;
  body: string;
  is_internal: boolean;
  metadata?: { needs_human?: boolean; confidence?: number; article_ids?: number[]; reason?: string } | null;
  created_at: string;
  attachments: SupportAttachment[];
};
export type SupportTicket = {
  id: number;
  ticket_number: string;
  subject: string;
  category: string;
  priority: SupportPriority;
  status: SupportStatus;
  source: 'manual' | 'ai';
  assigned_agent_user_id?: number | null;
  assigned_agent?: { id: number; name: string } | null;
  requester?: { id: number; name: string; email: string };
  organization?: { id: number; name: string } | null;
  last_message?: { body: string; actor_type: string; created_at: string } | null;
  last_activity_at: string | null;
  reopen_until?: string | null;
  rating?: number | null;
  messages?: SupportMessage[];
};
export type SupportPage<T> = { data: T[]; current_page: number; last_page: number; total: number };
export type SupportAgent = { id: number; user_id: number; is_active: boolean; user: { id: number; name: string; email: string; role: string } };
export type SupportArticle = {
  id: number; title: string; slug: string; category: string; tags: string[] | null; route_path: string | null;
  content_markdown: string; status: 'draft' | 'published'; version: number;
};
export type SupportCannedResponse = { id: number; title: string; category: string | null; body: string; is_active: boolean };
export type SupportAdminOverview = {
  agents: SupportAgent[];
  users: Array<{ id: number; name: string; email: string; role: string }>;
  categories: SupportCategory[];
  canned_responses: SupportCannedResponse[];
  knowledge: { published: number; drafts: number; review_required: number };
  groq_ready: boolean;
};

export function getSupportCategories() {
  return apiRequest<SupportCategory[]>('/support/categories');
}
export function getSupportTickets(page = 1) {
  return apiRequest<SupportPage<SupportTicket>>(`/support/tickets${buildQuery({ page })}`);
}
export function getSupportTicket(ticketNumber: string) {
  return apiRequest<SupportTicket>(`/support/tickets/${encodeURIComponent(ticketNumber)}`);
}
export function createSupportTicket(body: FormData) {
  return apiRequest<SupportTicket>('/support/tickets', { method: 'POST', body });
}
export function sendSupportMessage(ticketNumber: string, body: FormData) {
  return apiRequest<SupportMessage>(`/support/tickets/${encodeURIComponent(ticketNumber)}/messages`, { method: 'POST', body });
}
export function sendSupportAssistantMessage(message: string, ticketNumber?: string) {
  return apiRequest<{ ticket: SupportTicket; user_message: SupportMessage; assistant_message: SupportMessage; escalated: boolean }>('/support/assistant/messages', {
    method: 'POST',
    body: { message, ticket_number: ticketNumber },
  });
}
export function escalateSupportTicket(ticketNumber: string) {
  return apiRequest<SupportTicket>(`/support/tickets/${encodeURIComponent(ticketNumber)}/escalate`, { method: 'POST', body: {} });
}
export function closeSupportTicket(ticketNumber: string) {
  return apiRequest<SupportTicket>(`/support/tickets/${encodeURIComponent(ticketNumber)}/close`, { method: 'POST', body: {} });
}
export function reopenSupportTicket(ticketNumber: string) {
  return apiRequest<SupportTicket>(`/support/tickets/${encodeURIComponent(ticketNumber)}/reopen`, { method: 'POST', body: {} });
}
export function sendSupportFeedback(ticketNumber: string, rating: number, feedback: string) {
  return apiRequest<SupportTicket>(`/support/tickets/${encodeURIComponent(ticketNumber)}/feedback`, { method: 'POST', body: { rating, feedback } });
}
export function getSupportAttachment(id: number) {
  return apiRequestBlob(`/support/attachments/${id}`);
}
export function getAgentSupportTickets(params: { page?: number; status?: string; priority?: string; q?: string } = {}) {
  return apiRequest<SupportPage<SupportTicket>>(`/support/agent/tickets${buildQuery(params)}`);
}
export function getAgentSupportTicket(ticketNumber: string) {
  return apiRequest<SupportTicket>(`/support/agent/tickets/${encodeURIComponent(ticketNumber)}`);
}
export function takeSupportTicket(ticketNumber: string) {
  return apiRequest<SupportTicket>(`/support/agent/tickets/${encodeURIComponent(ticketNumber)}/take`, { method: 'POST', body: {} });
}
export function assignSupportTicket(ticketNumber: string, agent_user_id: number) {
  return apiRequest<SupportTicket>(`/support/agent/tickets/${encodeURIComponent(ticketNumber)}/assign`, { method: 'POST', body: { agent_user_id } });
}
export function sendAgentSupportMessage(ticketNumber: string, body: string, internal = false) {
  return apiRequest<SupportMessage>(`/support/agent/tickets/${encodeURIComponent(ticketNumber)}/messages`, { method: 'POST', body: { body, internal } });
}
export function updateAgentSupportTicket(ticketNumber: string, patch: Partial<Pick<SupportTicket, 'status' | 'priority' | 'category'>>) {
  return apiRequest<SupportTicket>(`/support/agent/tickets/${encodeURIComponent(ticketNumber)}`, { method: 'PATCH', body: patch });
}
export function getSupportCannedResponses() {
  return apiRequest<SupportCannedResponse[]>('/support/agent/canned-responses');
}
export function getSupportAdminOverview() {
  return apiRequest<SupportAdminOverview>('/support/admin/overview');
}
export function getSupportStats() {
  return apiRequest<Record<string, number | Record<string, number>>>('/support/admin/stats');
}
export function activateSupportAgent(user_id: number) {
  return apiRequest<SupportAgent>('/support/admin/agents', { method: 'POST', body: { user_id } });
}
export function deactivateSupportAgent(userId: number) {
  return apiRequest<boolean>(`/support/admin/agents/${userId}`, { method: 'DELETE' });
}
export function getSupportArticles(page = 1) {
  return apiRequest<SupportPage<SupportArticle>>(`/support/admin/knowledge/articles${buildQuery({ page })}`);
}
export function saveSupportArticle(article: Partial<SupportArticle> & Pick<SupportArticle, 'title' | 'category' | 'content_markdown' | 'status'>, id?: number) {
  return apiRequest<SupportArticle>(id ? `/support/admin/knowledge/articles/${id}` : '/support/admin/knowledge/articles', {
    method: id ? 'PUT' : 'POST',
    body: article,
  });
}
export function importSupportArticle(data: { title: string; category: string; content: string; route_path?: string } | { articles: Array<{ title: string; category: string; content: string; route_path?: string }> }) {
  return apiRequest<SupportArticle[]>('/support/admin/knowledge/import', { method: 'POST', body: data });
}
export function deleteSupportArticle(id: number) {
  return apiRequest<boolean>(`/support/admin/knowledge/articles/${id}`, { method: 'DELETE' });
}
export function saveSupportCategory(category: SupportCategory, id?: number) {
  return apiRequest<SupportCategory>(id ? `/support/admin/categories/${id}` : '/support/admin/categories', {
    method: id ? 'PUT' : 'POST',
    body: category,
  });
}
export function saveSupportCannedResponse(response: SupportCannedResponse, id?: number) {
  return apiRequest<SupportCannedResponse>(id ? `/support/admin/canned-responses/${id}` : '/support/admin/canned-responses', {
    method: id ? 'PUT' : 'POST',
    body: response,
  });
}
