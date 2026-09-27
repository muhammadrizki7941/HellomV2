export type BlockType =
  | 'hero'
  | 'features'
  | 'cta'
  | 'content'
  | 'banner'
  | 'product'
  | 'video'
  | 'text'
  | 'image'
  | 'pdf'
  | 'social'
  | 'form'
  | 'button'
  | 'divider'
  | 'testimonials'
  | 'faq'
  | 'list'
  | 'slider'
  | 'countdown'
  | 'gif'
  | 'html';

export interface BlockStyles {
  backgroundColor?: string;
  backgroundImage?: string;
  textColor?: string;
  buttonColor?: string;
  buttonTextColor?: string;
  paddingY?: string; // 'py-8', 'py-16', 'py-24', etc.
  textAlign?: 'left' | 'center' | 'right';
}

export interface Block {
  id: string;
  type: BlockType;
  content: Record<string, any>;
  styles?: BlockStyles;
}

// All block types known to the builder. Used for save/load whitelists so adding
// a new block in one place keeps editor + public renderer in sync.
export const BLOCK_TYPES: BlockType[] = [
  'hero',
  'features',
  'cta',
  'content',
  'banner',
  'product',
  'video',
  'text',
  'image',
  'pdf',
  'social',
  'form',
  'button',
  'divider',
  'testimonials',
  'faq',
  'list',
  'slider',
  'countdown',
  'gif',
  'html',
];
