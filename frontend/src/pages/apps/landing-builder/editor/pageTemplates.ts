import { nanoid } from 'nanoid';
import { getPageTemplates } from '@/lib/hellomApi';
import type { LandingDocBlock, PageTemplate, PageTemplateList } from '@/lib/hellomApi';

// Template gallery data (Fase 7.4): templates live on the server (resources/landing/templates/*.json,
// images/visibility/order set by super admin). Loaded once per session.
let cached: Promise<PageTemplateList> | null = null;

export function loadPageTemplates(): Promise<PageTemplateList> {
  cached ??= getPageTemplates().catch((err) => {
    cached = null;
    throw err;
  });
  return cached;
}

/**
 * The template's blocks for this page, with fresh ids. The seller's own name, photo, and bio
 * (from the current profile block, else the shop name) replace the template's example ones.
 */
export function templateBlocks(template: PageTemplate, current: Array<{ type: string; content: Record<string, unknown> }>, shopName = ''): LandingDocBlock[] {
  const own = current.find((b) => b.type === 'profile')?.content ?? {};
  const keep = (key: string) => (typeof own[key] === 'string' && own[key] !== '' ? { [key]: own[key] } : {});
  return template.document.blocks.map((b) => {
    const content = { ...b.content };
    if (b.type === 'profile') {
      Object.assign(content, keep('avatarUrl'), keep('bio'), keep('coverUrl'));
      content.name = (typeof own.name === 'string' && own.name) || shopName || content.name;
    }
    return { id: nanoid(10), type: b.type, hidden: false, content, styles: Array.isArray(b.styles) ? {} : { ...(b.styles ?? {}) } };
  });
}
