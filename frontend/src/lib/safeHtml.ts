// Seller HTML (landing page "HTML kustom" blocks). The server already sanitises it;
// this is the second layer, because public pages share the dashboard origin.
import DOMPurify from 'dompurify';

export function safeHtml(html: unknown): string {
  if (typeof html !== 'string' || html.trim() === '') return '';
  return DOMPurify.sanitize(html, {
    USE_PROFILES: { html: true },
    FORBID_TAGS: ['style', 'form', 'input', 'button', 'textarea', 'select', 'iframe', 'object', 'embed'],
    FORBID_ATTR: ['style'],
    ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|tel:|\/|#)/i,
  });
}
